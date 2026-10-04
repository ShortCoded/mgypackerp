<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\ReconciliationCenterService;
use Modules\Accounting\Services\ReconciliationComparisonService;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrDepartmentCostCenterDefault;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Models\HrEmploymentTaxPolicy;
use Modules\HR\Models\HrPayrollAttendancePolicy;
use Modules\HR\Models\HrShift;
use Modules\HR\Models\HrSocialInsurancePolicy;
use Modules\HR\Services\HrFoundationRegistry;
use Modules\HR\Services\HrFoundationService;
use Modules\HR\Services\PayrollCalculationService;
use Modules\HR\Services\PayrollCorrectionService;
use Modules\HR\Services\PayrollCostAllocationService;
use Modules\HR\Services\PayrollLifecycleService;
use Modules\HR\Services\PayrollPaymentService;
use Modules\HR\Services\PayrollReconciliationService;
use Modules\HR\Services\PayrollReportService;
use Modules\HR\Services\PayrollStatutoryCalculationService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/PayrollFinancialSupport.php';
require dirname(__DIR__, 2).'/PayrollManufacturingCostCases.php';

test('posted payroll correction reverses advances preserves history and pays the linked replacement through real routes', function (): void {
    $fixture = payrollFinancialFixture();
    Carbon::setTestNow('2026-10-01 12:00:00');
    $permissions = ['hr.payroll_preparation.view', 'hr.payroll_preparation.calculate', 'hr.payroll_approval.review', 'hr.payroll_approval.approve',
        'hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve', 'hr.payroll_payment.create', 'cash_payment_vouchers.create',
        'cash_payment_vouchers.approve', 'cash_payment_vouchers.cancel', 'hr.payroll_reports.view'];
    $preparer = payrollFinancialActor($permissions);
    $approver = payrollFinancialActor($permissions);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $runId = payrollCorrectionPostedRun($fixture);
    $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
    $before = app(PayrollCorrectionService::class)->preview($runId);
    $frozen = json_encode([$before['snapshot']['slips'], $before['snapshot']['items'], $before['snapshot']['inputs'], $before['snapshot']['attendance_inputs']], JSON_THROW_ON_ERROR);
    $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk()->assertSee(__('hr_payroll_correction.title'));
    $proposalId = $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), [
        'fingerprint' => $before['fingerprint'], 'reversal_date' => '2026-10-01', 'reason' => 'SYNTHETIC acceptance correction',
    ])->assertOk()->assertJsonPath('data.status', 'prepared')->json('data.correction_id');
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertStatus(422);
    $this->actingAs($approver)->withSession(payrollFinancialContext($fixture));
    $reversalId = $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))
        ->assertOk()->assertJsonPath('data.status', 'approved')->json('data.journal_entry_id');
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertOk()->assertJsonPath('data.journal_entry_id', $reversalId);
    $after = app(PayrollCorrectionService::class)->preview($runId);
    expect(json_encode([$after['snapshot']['slips'], $after['snapshot']['items'], $after['snapshot']['inputs'], $after['snapshot']['attendance_inputs']], JSON_THROW_ON_ERROR))->toBe($frozen)
        ->and((float) DB::table('hr_salary_advances')->where('id', $fixture['advance_id'])->value('balance'))->toBe(500.0)
        ->and(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('reversed')
        ->and(DB::table('hr_payroll_advance_applications')->where('payroll_run_id', $runId)->value('reversed_at'))->not->toBeNull()
        ->and(JournalEntry::query()->where('source_type', 'hr_payroll_run_reversal')->count())->toBe(1);
    $oldAsOf = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-09-30');
    $voidAsOf = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-10-01');
    expect($oldAsOf['status'])->toBe('matched')->and($oldAsOf['summary']['payable'])->toBe('9900.0000')
        ->and($voidAsOf['status'])->toBe('matched')->and($voidAsOf['summary']['gl_ending'])->toBe('0.0000')
        ->and($voidAsOf['summary']['ending_payable'])->toBe('0.0000');
    $replacement = $this->postJson(route('admin.hr.payroll-runs.calculate'), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'branch_doc_num' => $fixture['branch']->doc_num,
        'adjustments' => [[
            'employee_doc_num' => $fixture['employee']->doc_num,
            'deductions' => [['payroll_item_code' => 'PAYROLL-TAX', 'amount' => '250.0000', 'reference' => 'SYNTHETIC correction']],
            'advance_applications' => [['salary_advance_id' => $fixture['advance_id'], 'payroll_item_code' => 'SALARY-ADVANCE', 'amount' => '300.0000']],
        ]],
    ])->assertOk()->json('data.run_id');
    expect($replacement)->not->toBe($runId)->and((int) DB::table('hr_payroll_runs')->where('id', $replacement)->value('correction_of_run_id'))->toBe($runId);
    $this->postJson(route('admin.hr.payroll-runs.review', $replacement))->assertOk();
    $newJournalId = $this->postJson(route('admin.hr.payroll-runs.approve', $replacement))->assertOk()->json('data.journal_entry_id');
    expect(JournalEntry::query()->findOrFail($newJournalId)->entry_date->toDateString())->toBe('2026-10-01');
    $payslipId = (int) DB::table('hr_payslips')->where('payroll_run_id', $replacement)->value('id');
    $payment = $this->postJson(route('admin.hr.payroll-runs.payments.store', $replacement), [
        'payslip_id' => $payslipId, 'cashbox_doc_num' => $fixture['cashbox']->doc_num, 'amount' => '9650.0000',
        'payment_date' => '2026-10-01', 'idempotency_key' => (string) Str::uuid(),
    ])->assertOk()->assertJsonPath('data.voucher_url', null)
        ->assertJsonPath('data.payroll_url', route('admin.hr.payroll-preparation.index', ['run' => $replacement, 'as_of' => '2026-10-01']))
        ->json('data.voucher_doc_num');
    app(CashVoucherService::class)->approve(CashVoucher::TypePayment, CashVoucher::query()->where('doc_num', $payment)->firstOrFail(), $fixture['company']->getKey());
    $settled = app(PayrollReconciliationService::class)->forRun($replacement, $fixture['company']->getKey(), '2026-10-01');
    expect($settled['status'])->toBe('matched')->and($settled['summary']['remaining'])->toBe('0.0000')
        ->and($settled['summary']['gl_difference'])->toBe('0.0000')->and($settled['summary']['cash_bank_difference'])->toBe('0.0000');
    $historical = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-09-30');
    expect($historical['summary']['ending_payable'])->toBe('9900.0000')->and($historical['summary']['gl_ending'])->toBe('9900.0000');
    $report = app(PayrollReportService::class)->payroll($fixture['company']->getKey(), $approver, []);
    expect($report['rows']->total())->toBe(1)->and($report['totals'][0]['net'])->toBe('9650.0000');
    $archived = app(PayrollReportService::class)->payroll($fixture['company']->getKey(), $approver, ['status' => 'reversed']);
    expect($archived['rows']->total())->toBe(1)->and($archived['rows']->items()[0]->status)->toBe('reversed');
});

test('payroll correction rejects stale payments permissions and closed periods without partial reversal', function (): void {
    $fixture = payrollFinancialFixture();
    Carbon::setTestNow('2026-10-01 12:00:00');
    $preparer = payrollFinancialActor(['hr.payroll_approval.correct']);
    $approver = payrollFinancialActor(['hr.payroll_approval.correct_approve', 'cash_payment_vouchers.cancel', 'cash_payment_vouchers.approve']);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $runId = payrollCorrectionPostedRun($fixture);
    $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
    $plan = app(PayrollCorrectionService::class)->preview($runId);
    $proposal = app(PayrollCorrectionService::class)->propose($runId, '2026-10-01', 'SYNTHETIC stale proposal', $plan['fingerprint']);
    $draft = app(PayrollPaymentService::class)->createCashPayment($runId, $fixture['company']->getKey(), [
        'cashbox_doc_num' => $fixture['cashbox']->doc_num, 'amount' => '100.0000', 'payment_date' => '2026-10-01', 'idempotency_key' => (string) Str::uuid(),
    ]);
    $this->actingAs($approver)->withSession(payrollFinancialContext($fixture));
    expect(fn () => app(PayrollCorrectionService::class)->approve($runId, $proposal->id))->toThrow(DomainException::class, __('hr_payroll_correction.cancel_payments_first'));
    app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $draft['voucher'], $fixture['company']->getKey());
    app(CashVoucherService::class)->cancel(CashVoucher::TypePayment, $draft['voucher']->refresh(), 'SYNTHETIC cancelled payment');
    expect(fn () => app(PayrollCorrectionService::class)->approve($runId, $proposal->id))->toThrow(DomainException::class, __('hr_payroll_correction.stale'));
    app(PayrollCorrectionService::class)->reject($runId, $proposal->id);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $plan = app(PayrollCorrectionService::class)->preview($runId);
    $proposal = app(PayrollCorrectionService::class)->propose($runId, '2026-10-01', 'SYNTHETIC replacement proposal', $plan['fingerprint']);
    $fixture['period']->update(['is_closed' => true]);
    $this->actingAs($approver)->withSession(payrollFinancialContext($fixture));
    expect(fn () => app(PayrollCorrectionService::class)->approve($runId, $proposal->id))->toThrow(DomainException::class, __('hr_payroll_correction.original_period_closed'));
    expect(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('posted')
        ->and(JournalEntry::query()->where('source_type', 'hr_payroll_run_reversal')->count())->toBe(0)
        ->and((float) DB::table('hr_salary_advances')->where('id', $fixture['advance_id'])->value('balance'))->toBe(200.0);
    $fixture['period']->update(['is_closed' => false]);
    $denied = payrollFinancialActor([]);
    $this->actingAs($denied)->withSession(payrollFinancialContext($fixture));
    $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertForbidden();
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposal->id]))->assertForbidden();
    $this->actingAs($approver)->withSession(payrollFinancialContext($fixture));
    DB::statement("CREATE TRIGGER synthetic_payroll_audit_failure BEFORE INSERT ON activity_log WHEN NEW.action = 'hr.payroll.correction_approved' BEGIN SELECT RAISE(ABORT, 'SYNTHETIC audit failure'); END");
    expect(fn () => app(PayrollCorrectionService::class)->approve($runId, $proposal->id))->toThrow(QueryException::class, 'SYNTHETIC audit failure');
    expect(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('posted')
        ->and(JournalEntry::query()->where('source_type', 'hr_payroll_run_reversal')->count())->toBe(0)
        ->and((float) DB::table('hr_salary_advances')->where('id', $fixture['advance_id'])->value('balance'))->toBe(200.0)
        ->and(DB::table('hr_payroll_advance_applications')->where('payroll_run_id', $runId)->value('reversed_at'))->toBeNull();
    DB::statement('DROP TRIGGER synthetic_payroll_audit_failure');
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposal->id]))->assertOk();
    $reconciled = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-10-01');
    expect($reconciled['status'])->toBe('matched')->and($reconciled['summary']['ending_payable'])->toBe('0.0000')
        ->and($reconciled['summary']['gl_ending'])->toBe('0.0000')->and($reconciled['summary']['cash_bank_effect'])->toBe('0.0000');
});

test('payroll correction can remove an unpaid draft through Finance without inventing a payment or deleting payroll history', function (): void {
    $fixture = payrollFinancialFixture();
    Carbon::setTestNow('2026-10-01 12:00:00');
    $preparer = payrollFinancialActor(['hr.payroll_approval.correct']);
    $approver = payrollFinancialActor(['hr.payroll_approval.correct_approve']);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $runId = payrollCorrectionPostedRun($fixture);
    $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
    $draft = app(PayrollPaymentService::class)->createCashPayment($runId, $fixture['company']->getKey(), [
        'cashbox_doc_num' => $fixture['cashbox']->doc_num, 'amount' => '100.0000', 'payment_date' => '2026-10-01', 'idempotency_key' => (string) Str::uuid(),
    ]);
    $deleteUrl = route('admin.finance.cash-payment-vouchers.destroy', $draft['voucher']->doc_num);
    $this->deleteJson($deleteUrl)->assertForbidden();
    Permission::findOrCreate('cash_payment_vouchers.delete', 'web');
    $preparer->givePermissionTo('cash_payment_vouchers.delete');
    $this->deleteJson($deleteUrl)->assertOk();
    expect(CashVoucher::withTrashed()->findOrFail($draft['voucher']->getKey())->trashed())->toBeTrue()
        ->and(DB::table('hr_payroll_payments')->where('id', $draft['payment']->id)->value('status'))->toBe('cancelled')
        ->and(DB::table('hr_payroll_payments')->where('id', $draft['payment']->id)->value('journal_entry_id'))->toBeNull()
        ->and(JournalEntry::query()->whereIn('source_type', ['hr_payroll_payment', 'hr_payroll_payment_reversal'])->count())->toBe(0);
    $unpaid = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-10-01');
    expect($unpaid['status'])->toBe('matched')->and($unpaid['summary']['remaining'])->toBe('9900.0000')
        ->and($unpaid['summary']['settlements_adjustments'])->toBe('0.0000')
        ->and($unpaid['summary']['gl_ending'])->toBe('9900.0000')
        ->and($unpaid['summary']['cash_bank_effect'])->toBe('0.0000');
    $plan = app(PayrollCorrectionService::class)->preview($runId);
    $proposal = app(PayrollCorrectionService::class)->propose($runId, '2026-10-01', 'SYNTHETIC correction after draft void', $plan['fingerprint']);
    $this->actingAs($approver)->withSession(payrollFinancialContext($fixture));
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposal->id]))->assertOk();
    expect(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('reversed')
        ->and(DB::table('hr_payslips')->where('payroll_run_id', $runId)->count())->toBe(1);
    $reversed = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-10-01');
    expect($reversed['status'])->toBe('matched')->and($reversed['summary']['remaining'])->toBe('0.0000')
        ->and($reversed['summary']['gl_difference'])->toBe('0.0000')->and($reversed['summary']['cash_bank_difference'])->toBe('0.0000');
});

test('payroll calculation approval finance payment and both reconciliations are exact and idempotent', function (): void {
    $fixture = payrollFinancialFixture();
    $actor = payrollFinancialActor([
        'hr.payroll_preparation.view',
        'hr.payroll_preparation.calculate',
        'hr.payroll_approval.review',
        'hr.payroll_approval.approve',
        'hr.payroll_payment.create',
        'hr.payroll_reconciliation.view',
        'cash_payment_vouchers.create',
        'cash_payment_vouchers.approve',
        'cash_payment_vouchers.cancel',
        'cash_payment_vouchers.view',
    ]);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture));

    $calculated = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
        'adjustments' => [[
            'employee_doc_num' => $fixture['employee']->doc_num,
            'deductions' => [[
                'payroll_item_code' => 'PAYROLL-TAX',
                'amount' => '200.0000',
                'reference' => 'September payroll tax',
            ]],
            'advance_applications' => [[
                'salary_advance_id' => $fixture['advance_id'],
                'payroll_item_code' => 'SALARY-ADVANCE',
                'amount' => '300.0000',
            ]],
        ]],
    ]);
    $runId = $calculated['run_id'];

    expect($calculated)->toMatchArray([
        'employee_count' => 1,
        'gross' => '10200.0000',
        'deductions' => '500.0000',
        'payable' => '9700.0000',
    ])->and((float) DB::table('hr_payslip_items')->where('source_type', 'approved_overtime')->value('amount'))->toBe(200.0)
        ->and((float) DB::table('hr_salary_advances')->where('id', $fixture['advance_id'])->value('balance'))->toBe(500.0);

    expect(fn () => app(PayrollCostAllocationService::class)->postRun($runId))
        ->toThrow(DomainException::class, __('hr_payroll.messages.approval_required_before_posting'));

    app(PayrollLifecycleService::class)->submitForReview($runId, $fixture['company']->getKey());
    $firstApproval = app(PayrollLifecycleService::class)->approve($runId, $fixture['company']->getKey());
    $duplicateApproval = app(PayrollLifecycleService::class)->approve($runId, $fixture['company']->getKey());
    $payrollJournal = JournalEntry::query()->with('lines')->findOrFail($firstApproval['journal_entry_id']);

    expect($duplicateApproval['journal_entry_id'])->toBe($firstApproval['journal_entry_id'])
        ->and(JournalEntry::query()->where('source_type', 'hr_payroll_run')->where('source_id', $runId)->count())->toBe(1)
        ->and(DB::table('hr_payroll_advance_applications')->where('payroll_run_id', $runId)->count())->toBe(1)
        ->and((float) DB::table('hr_salary_advances')->where('id', $fixture['advance_id'])->value('balance'))->toBe(200.0)
        ->and((float) $payrollJournal->lines->sum('debit_amount'))->toBe(10200.0)
        ->and((float) $payrollJournal->lines->sum('credit_amount'))->toBe(10200.0)
        ->and((string) $payrollJournal->lines->firstWhere('account_id', $fixture['payable_account']->getKey())?->credit_amount)->toBe('9700.0000')
        ->and((string) $payrollJournal->lines->firstWhere('account_id', $fixture['deduction_account']->getKey())?->credit_amount)->toBe('200.0000')
        ->and((string) $payrollJournal->lines->firstWhere('account_id', $fixture['advance_account']->getKey())?->credit_amount)->toBe('300.0000')
        ->and($payrollJournal->lines->every(fn ($line): bool => (int) $line->branch_id === (int) $fixture['branch']->getKey()))->toBeTrue();

    $beforePayment = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-09-30');
    expect($beforePayment['status'])->toBe('matched')
        ->and($beforePayment['summary'])->toMatchArray([
            'approved_payroll' => '9700.0000',
            'payable' => '9700.0000',
            'paid' => '0.0000',
            'remaining' => '9700.0000',
            'gl_ending' => '9700.0000',
            'gl_difference' => '0.0000',
            'cash_bank_effect' => '0.0000',
            'cash_bank_difference' => '0.0000',
        ]);

    $paymentKey = (string) Str::uuid();
    $paymentPayload = [
        'cashbox_doc_num' => $fixture['cashbox']->doc_num,
        'amount' => '4000.0000',
        'payment_date' => '2026-09-17',
        'idempotency_key' => $paymentKey,
        'reference' => 'September payroll partial settlement',
    ];
    $draft = app(PayrollPaymentService::class)->createCashPayment($runId, $fixture['company']->getKey(), $paymentPayload);
    $duplicateDraft = app(PayrollPaymentService::class)->createCashPayment($runId, $fixture['company']->getKey(), $paymentPayload);
    $payslipId = (int) DB::table('hr_payslips')->where('payroll_run_id', $runId)->value('id');

    expect($duplicateDraft['voucher']->getKey())->toBe($draft['voucher']->getKey())
        ->and((int) $draft['payment']->payslip_id)->toBe($payslipId)
        ->and($draft['voucher']->person_name)->toBe($fixture['employee']->full_name)
        ->and(DB::table('hr_payroll_payments')->where('company_id', $fixture['company']->getKey())->where('idempotency_key', $paymentKey)->count())->toBe(1);
    expect(fn () => app(PayrollPaymentService::class)->createCashPayment($runId, $fixture['company']->getKey(), [
        ...$paymentPayload,
        'amount' => '4001.0000',
    ]))->toThrow(DomainException::class, __('hr_payroll.messages.payment_idempotency_conflict'));

    $globalCashAccount = payrollFinancialAccount($fixture['company'], 'cash_in_transit', 'PAY-1112', 1112);
    $globalCashbox = Cashbox::query()->create([
        'doc_number' => 7002,
        'doc_num' => 'PAY-CASH-GLOBAL-07002',
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => null,
        'account_id' => $globalCashAccount->getKey(),
        'name' => 'Global Payroll Cashbox',
        'status' => 'active',
    ]);
    CashboxCurrency::query()->create([
        'cashbox_id' => $globalCashbox->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'status' => 'active',
    ]);
    expect(fn () => app(PayrollPaymentService::class)->createCashPayment($runId, $fixture['company']->getKey(), [
        ...$paymentPayload,
        'cashbox_doc_num' => $globalCashbox->doc_num,
        'idempotency_key' => (string) Str::uuid(),
    ]))->toThrow(DomainException::class, __('hr_payroll.messages.payment_branch_mismatch'));
    expect(fn () => app(PayrollPaymentService::class)->postApprovedVoucher($draft['voucher']))
        ->toThrow(DomainException::class, __('hr_payroll.messages.payment_approval_invalid'));
    expect(Activity::query()->whereIn('action', ['hr.payroll.payment_approved', 'hr.payroll.payment_posted'])->exists())->toBeFalse();

    $draftReconciliation = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-09-30');
    expect($draftReconciliation['summary']['paid'])->toBe('0.0000')
        ->and($draftReconciliation['summary']['remaining'])->toBe('9700.0000');

    app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $draft['voucher'], $fixture['company']->getKey());
    app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $draft['voucher']->refresh(), $fixture['company']->getKey());
    $duplicatePaymentJournal = app(PayrollPaymentService::class)->postApprovedVoucher($draft['voucher']->refresh());

    $approvedActivity = Activity::query()->where('action', 'hr.payroll.payment_approved')->sole();
    $postedActivity = Activity::query()->where('action', 'hr.payroll.payment_posted')->sole();
    expect($duplicatePaymentJournal)->toBeInstanceOf(JournalEntry::class)
        ->and($approvedActivity->company_id)->toBe($fixture['company']->getKey())
        ->and($approvedActivity->causer_id)->toBe($actor->getKey())
        ->and($approvedActivity->subject_type)->toBe($draft['voucher']->getMorphClass())
        ->and($approvedActivity->subject_id)->toBe($draft['voucher']->getKey())
        ->and($approvedActivity->properties->toArray())->not->toHaveKeys(['amount', 'reference', 'reason'])
        ->and($postedActivity->properties->get('journal_entry_id'))->not->toBeNull()
        ->and(Activity::query()->where('action', 'hr.payroll.payment_approved')->count())->toBe(1)
        ->and(Activity::query()->where('action', 'hr.payroll.payment_posted')->count())->toBe(1);

    $partial = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-09-30');
    expect($partial['status'])->toBe('matched')
        ->and($partial['summary'])->toMatchArray([
            'payable' => '9700.0000',
            'paid' => '4000.0000',
            'remaining' => '5700.0000',
            'gl_ending' => '5700.0000',
            'gl_difference' => '0.0000',
            'cash_bank_effect' => '4000.0000',
            'cash_bank_difference' => '0.0000',
        ])
        ->and(JournalEntry::query()->where('source_type', 'hr_payroll_payment')->count())->toBe(1)
        ->and((float) DB::table('journal_entry_lines as line')
            ->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')
            ->where('journal.source_type', 'hr_payroll_payment')
            ->where('line.account_id', $fixture['cash_account']->getKey())
            ->sum('line.credit_amount'))->toBe(4000.0);

    $center = app(ReconciliationCenterService::class)->report(
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
        $fixture['branch']->getKey(),
        '2026-09-01',
        '2026-09-30',
    );
    $centerResults = collect($center['results'])->keyBy('key');

    expect($centerResults[ReconciliationCenterService::PayrollPayable]['status'])->toBe(ReconciliationComparisonService::Matched)
        ->and($centerResults[ReconciliationCenterService::PayrollSettlement]['status'])->toBe(ReconciliationComparisonService::Matched)
        ->and($centerResults[ReconciliationCenterService::PayrollSettlement]['rows'][0])->toMatchArray([
            'source_ending' => '4000.0000',
            'gl_ending' => '4000.0000',
            'ending_difference' => '0.0000',
        ]);

    $finalDraft = app(PayrollPaymentService::class)->createCashPayment($runId, $fixture['company']->getKey(), [
        ...$paymentPayload,
        'amount' => '5700.0000',
        'idempotency_key' => (string) Str::uuid(),
        'reference' => 'September payroll final settlement',
    ]);
    app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $finalDraft['voucher'], $fixture['company']->getKey());

    $fullyPaid = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-09-30');
    expect($fullyPaid['summary'])->toMatchArray([
        'payable' => '9700.0000',
        'paid' => '9700.0000',
        'remaining' => '0.0000',
        'gl_ending' => '0.0000',
        'cash_bank_effect' => '9700.0000',
        'cash_bank_difference' => '0.0000',
    ]);

    $this->actingAs($actor)
        ->withSession(payrollFinancialContext($fixture))
        ->postJson(route('admin.finance.cash-payment-vouchers.cancel', $finalDraft['voucher']->doc_num), ['cancel_reason' => 'Fixture cancellation'])
        ->assertOk();
    $duplicateReversal = app(PayrollPaymentService::class)->reverseCancelledVoucher($finalDraft['voucher']->refresh());
    $cancelledActivity = Activity::query()->where('action', 'hr.payroll.payment_cancelled')->sole();
    $reversedActivity = Activity::query()->where('action', 'hr.payroll.payment_reversed')->sole();
    expect($duplicateReversal)->toBeInstanceOf(JournalEntry::class)
        ->and($cancelledActivity->company_id)->toBe($fixture['company']->getKey())
        ->and($cancelledActivity->causer_id)->toBe($actor->getKey())
        ->and($cancelledActivity->subject_id)->toBe($finalDraft['voucher']->getKey())
        ->and($cancelledActivity->properties->toArray())->not->toHaveKeys(['amount', 'cancel_reason', 'reason'])
        ->and($reversedActivity->properties->get('reversal_journal_entry_id'))->not->toBeNull()
        ->and(Activity::query()->where('action', 'hr.payroll.payment_cancelled')->count())->toBe(1)
        ->and(Activity::query()->where('action', 'hr.payroll.payment_reversed')->count())->toBe(1);

    $afterCancellation = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-09-30');
    expect($afterCancellation['status'])->toBe('matched')
        ->and($afterCancellation['summary'])->toMatchArray([
            'payable' => '9700.0000',
            'paid' => '4000.0000',
            'remaining' => '5700.0000',
            'gl_ending' => '5700.0000',
            'gl_difference' => '0.0000',
            'cash_bank_effect' => '4000.0000',
            'cash_bank_difference' => '0.0000',
        ])
        ->and(JournalEntry::query()->where('source_type', 'hr_payroll_payment_reversal')->count())->toBe(1)
        ->and((float) DB::table('hr_salary_advances')->where('id', $fixture['advance_id'])->value('balance'))->toBe(200.0);
});

test('authorized reviewer can return an unposted payroll for recalculation and cannot return it twice', function (): void {
    $fixture = payrollFinancialFixture();
    $actor = payrollFinancialActor([
        'hr.payroll_preparation.view',
        'hr.payroll_preparation.calculate',
        'hr.payroll_approval.review',
    ]);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture));
    $run = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $runId = $run['run_id'];

    $this->postJson(route('admin.hr.payroll-runs.review', $runId))->assertOk();
    $this->postJson(route('admin.hr.payroll-runs.return-for-recalculation', $runId))
        ->assertOk()->assertJsonPath('data.status', 'calculated');
    $this->postJson(route('admin.hr.payroll-runs.return-for-recalculation', $runId))
        ->assertStatus(422);
    $this->postJson(route('admin.hr.payroll-runs.review', $runId))
        ->assertOk()->assertJsonPath('data.status', 'under_review');

    expect(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('under_review')
        ->and(DB::table('hr_payslips')->where('payroll_run_id', $runId)->value('status'))->toBe('under_review')
        ->and(Activity::query()->where('action', 'hr.payroll.returned_for_recalculation')->count())->toBe(1)
        ->and(Activity::query()->where('action', 'hr.payroll.reviewed')->count())->toBe(2);
});

test('a payment replay that arrives while waiting for the payroll run lock returns the original voucher', function (): void {
    $fixture = payrollFinancialFixture();
    $actor = payrollFinancialActor(['hr.payroll_preparation.calculate', 'hr.payroll_approval.review', 'hr.payroll_approval.approve']);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture));

    $calculated = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $runId = (int) $calculated['run_id'];
    app(PayrollLifecycleService::class)->submitForReview($runId, $fixture['company']->getKey());
    app(PayrollLifecycleService::class)->approve($runId, $fixture['company']->getKey());
    $payslipId = (int) DB::table('hr_payslips')->where('payroll_run_id', $runId)->value('id');
    $payload = [
        'payslip_id' => $payslipId,
        'cashbox_doc_num' => $fixture['cashbox']->doc_num,
        'amount' => '100.0000',
        'payment_date' => '2026-09-17',
        'idempotency_key' => (string) Str::uuid(),
        'reference' => 'Synthetic replay while locked',
    ];
    $first = app(PayrollPaymentService::class)->createCashPayment($runId, $fixture['company']->getKey(), $payload);
    $voucherCount = CashVoucher::query()->count();
    $paymentRow = (array) DB::table('hr_payroll_payments')->where('id', $first['payment']->id)->first();
    DB::table('hr_payroll_payments')->where('id', $first['payment']->id)->delete();

    $restored = false;
    DB::listen(function ($query) use (&$restored, $paymentRow): void {
        if ($restored || ! str_starts_with(ltrim($query->sql), 'select') || ! str_contains($query->sql, 'hr_payroll_runs')) {
            return;
        }
        $restored = true;
        DB::table('hr_payroll_payments')->insert($paymentRow);
    });

    $replay = app(PayrollPaymentService::class)->createCashPayment($runId, $fixture['company']->getKey(), $payload);
    expect($restored)->toBeTrue()
        ->and($replay['voucher']->getKey())->toBe($first['voucher']->getKey())
        ->and(CashVoucher::query()->count())->toBe($voucherCount)
        ->and(DB::table('hr_payroll_payments')->where('idempotency_key', $payload['idempotency_key'])->count())->toBe(1);
});

test('configured synthetic tax and insurance calculate and post both employee and employer liabilities', function (): void {
    $fixture = payrollFinancialFixture();
    $this->actingAs(payrollFinancialActor([]))->withSession(payrollFinancialContext($fixture));
    $employee = $fixture['employee'];
    $employee->update([
        'insurance_status' => 'subject',
        'insurance_start_date' => '2026-01-01',
        'insurance_contribution_wage' => '10000.00',
        'tax_status' => 'subject',
        'tax_start_date' => '2026-01-01',
    ]);
    $insurancePayable = payrollFinancialAccount($fixture['company'], 'social_insurance_payable', 'PAY-2122', 2122);
    $insuranceExpense = payrollFinancialAccount($fixture['company'], 'insurance_expense', 'PAY-5122', 5122);
    foreach ([
        ['SOCIAL-INSURANCE', 'deduction', 'social_insurance_payable'],
        ['EMPLOYER-INSURANCE', 'employer', 'insurance_expense'],
    ] as [$code, $kind, $classification]) {
        DB::table('hr_payroll_items')->insert([
            'code' => $code,
            'name' => $code,
            'item_kind' => $kind,
            'account_classification_id' => AccountClassification::query()->where('code', $classification)->value('id'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $insurance = HrSocialInsurancePolicy::query()->create([
        'doc_number' => 8001,
        'doc_num' => 'TEST-INS-8001',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Synthetic insurance rates',
        'effective_from' => '2026-01-01',
        'employee_contribution_rate' => '10.0000',
        'employer_contribution_rate' => '15.0000',
        'rounding_rule' => 'nearest',
        'status' => 'active',
    ]);
    $insurance->components()->create([
        'name' => 'Synthetic contribution',
        'employee_rate' => '10.0000',
        'employer_rate' => '15.0000',
        'calculation_basis' => 'contribution_wage',
        'is_active' => true,
    ]);
    $tax = HrEmploymentTaxPolicy::query()->create([
        'doc_number' => 8001,
        'doc_num' => 'TEST-TAX-8001',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Synthetic tax bands',
        'tax_year' => 2026,
        'effective_from' => '2026-01-01',
        'annual_exemption_amount' => '0.00',
        'taxable_basis' => 'gross_after_employee_insurance',
        'annualization_method' => 'twelve_equal_periods',
        'rounding_rule' => 'nearest',
        'status' => 'active',
    ]);
    $tax->brackets()->createMany([
        ['from_amount' => '0.00', 'to_amount' => '100000.00', 'rate' => '0.0000', 'sort_order' => 0],
        ['from_amount' => '100000.00', 'to_amount' => null, 'rate' => '10.0000', 'sort_order' => 1],
    ]);

    $calculated = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    expect($calculated)->toMatchArray([
        'gross' => '10200.0000',
        'deductions' => '1086.6700',
        'payable' => '9113.3300',
    ]);
    $items = DB::table('hr_payslip_items as line')
        ->join('hr_payroll_items as item', 'item.id', '=', 'line.payroll_item_id')
        ->whereIn('item.code', ['SOCIAL-INSURANCE', 'EMPLOYER-INSURANCE', 'PAYROLL-TAX'])
        ->pluck('line.amount', 'item.code');
    expect((float) $items['SOCIAL-INSURANCE'])->toBe(1000.0)
        ->and((float) $items['EMPLOYER-INSURANCE'])->toBe(1500.0)
        ->and((float) $items['PAYROLL-TAX'])->toBe(86.67);
    $snapshot = json_decode((string) DB::table('hr_payroll_inputs')->where('payroll_run_id', $calculated['run_id'])->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    expect(data_get($snapshot, 'statutory.tax_sources.0.policy_doc_num'))->toBe('TEST-TAX-8001')
        ->and(data_get($snapshot, 'statutory.insurance_sources.0.policy_doc_num'))->toBe('TEST-INS-8001');
    expect(DB::table('hr_payroll_statutory_policy_usages')->where('payroll_run_id', $calculated['run_id'])->count())->toBe(2);
    expect(fn () => app(HrFoundationService::class)->delete($insurance))->toThrow(ValidationException::class)
        ->and(fn () => app(HrFoundationService::class)->delete($tax))->toThrow(ValidationException::class);
    expect(fn () => app(HrFoundationService::class)->update(
        app(HrFoundationRegistry::class)->get('employment-tax-policies'),
        $tax,
        ['name' => 'Altered historical policy'],
    ))->toThrow(ValidationException::class);
    expect($tax->fresh()->name)->toBe('Synthetic tax bands');
    $unchangedBrackets = $tax->brackets()->get()->map(fn ($bracket): array => [
        'public_uuid' => $bracket->public_uuid,
        'from_amount' => $bracket->from_amount,
        'to_amount' => $bracket->to_amount,
        'rate' => $bracket->rate,
        'notes' => $bracket->notes,
    ])->all();
    expect(fn () => app(HrFoundationService::class)->update(
        app(HrFoundationRegistry::class)->get('employment-tax-policies'),
        $tax->fresh(),
        ['effective_to' => '2026-09-15', 'tax_brackets' => $unchangedBrackets],
    ))->toThrow(ValidationException::class);
    app(HrFoundationService::class)->update(
        app(HrFoundationRegistry::class)->get('employment-tax-policies'),
        $tax->fresh(),
        ['effective_to' => '2026-12-31', 'tax_brackets' => $unchangedBrackets],
    );
    expect($tax->fresh()->effective_to?->toDateString())->toBe('2026-12-31');
    $allocationPreview = app(PayrollCostAllocationService::class)->previewRun($calculated['run_id']);
    expect($allocationPreview['errors'])->toBe([])
        ->and(collect($allocationPreview['lines'])->firstWhere('direction', 'employer')['classification'])->toBe('insurance_expense');
    app(PayrollLifecycleService::class)->submitForReview($calculated['run_id'], $fixture['company']->getKey());
    $insuranceExpense->update(['status' => 'inactive']);
    expect(fn () => app(PayrollLifecycleService::class)->approve($calculated['run_id'], $fixture['company']->getKey()))
        ->toThrow(DomainException::class);
    expect(DB::table('hr_payroll_runs')->where('id', $calculated['run_id'])->value('status'))->toBe('under_review')
        ->and(DB::table('hr_payroll_postings')->where('payroll_run_id', $calculated['run_id'])->count())->toBe(0);
    $insuranceExpense->update(['status' => 'active']);
    $approved = app(PayrollLifecycleService::class)->approve($calculated['run_id'], $fixture['company']->getKey());
    $payslipId = (int) DB::table('hr_payslips')->where('payroll_run_id', $calculated['run_id'])->value('id');
    $payslip = app(PayrollReportService::class)->payslipForEmployee($payslipId, (int) $employee->getKey());
    expect($payslip['items']->firstWhere('code', 'EMPLOYER-INSURANCE')?->display_name)->toBe(__('hr_payroll_reports.item_names.EMPLOYER-INSURANCE'))
        ->and($payslip['items']->firstWhere('code', 'EMPLOYER-INSURANCE')?->direction)->toBe('employer')
        ->and($payslip['attendance'])->toHaveKeys(['record_ids', 'effect_record_ids', 'finalized_records', 'policy_snapshots', 'summary']);
    $journal = JournalEntry::query()->with('lines')->findOrFail($approved['journal_entry_id']);
    expect((float) $journal->lines->sum('debit_amount'))->toBe(11700.0)
        ->and((float) $journal->lines->sum('credit_amount'))->toBe(11700.0)
        ->and((float) $journal->lines->where('account_id', $insurancePayable->getKey())->sum('credit_amount'))->toBe(2500.0)
        ->and((float) $journal->lines->where('account_id', $insuranceExpense->getKey())->sum('debit_amount'))->toBe(1500.0)
        ->and((float) $journal->lines->where('account_id', $fixture['deduction_account']->getKey())->sum('credit_amount'))->toBe(86.67)
        ->and((string) $journal->lines->firstWhere('account_id', $fixture['payable_account']->getKey())?->credit_amount)->toBe('9113.3300');

    $viewer = payrollFinancialActor(['hr.payslips.view', 'hr.payroll_reports.view', 'hr.payroll_reports.export']);
    $report = $this->actingAs($viewer)->withSession(payrollFinancialContext($fixture))
        ->get(route('admin.hr.reports.payroll', ['run_id' => $calculated['run_id']]));
    $report->assertOk()->assertSee($employee->full_name)
        ->assertSee(app(NumericFormatService::class)->format('1086.6700'))
        ->assertSee(app(NumericFormatService::class)->format('9113.3300'));

    $detail = $this->withSession(payrollFinancialContext($fixture))->get(route('admin.hr.payslips.show', $payslipId));
    $detail->assertOk()
        ->assertSee(__('hr_payroll_reports.item_names.SOCIAL-INSURANCE'))
        ->assertSee(__('hr_payroll_reports.item_names.PAYROLL-TAX'))
        ->assertSee(__('hr_payroll_reports.item_names.EMPLOYER-INSURANCE'));

    $csv = $this->withSession(payrollFinancialContext($fixture))
        ->get(route('admin.hr.reports.payroll.export', ['format' => 'csv', 'run_id' => $calculated['run_id']]));
    $csv->assertOk();
    $csvContent = file_get_contents($csv->baseResponse->getFile()->getPathname());
    expect($csvContent)->toContain($employee->full_name, '1086.6700', '9113.3300');

    $xlsx = $this->withSession(payrollFinancialContext($fixture))
        ->get(route('admin.hr.reports.payroll.export', ['format' => 'xlsx', 'run_id' => $calculated['run_id']]));
    $xlsx->assertOk();
    $xlsxRows = IOFactory::load($xlsx->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();
    expect($xlsxRows[1][4])->toBe($employee->full_name)
        ->and(bccomp((string) $xlsxRows[1][7], '1086.6700', 4))->toBe(0)
        ->and(bccomp((string) $xlsxRows[1][8], '9113.3300', 4))->toBe(0);

    $payrollPdf = $this->withSession(payrollFinancialContext($fixture))
        ->get(route('admin.hr.reports.payroll.export', ['format' => 'pdf', 'run_id' => $calculated['run_id']]));
    $payrollPdf->assertOk()->assertHeader('content-type', 'application/pdf');
    $payrollExtract = new Process(['pdftotext', '-layout', '-', '-']);
    $payrollExtract->setInput($payrollPdf->getContent());
    $payrollExtract->run();
    expect($payrollExtract->isSuccessful())->toBeTrue()
        ->and($payrollExtract->getOutput())->toContain(
            $employee->full_name,
            app(NumericFormatService::class)->format('1086.6700'),
            app(NumericFormatService::class)->format('9113.3300'),
        );

    $pdf = $this->withSession(payrollFinancialContext($fixture))->get(route('admin.hr.payslips.pdf', $payslipId));
    $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
    $extract = new Process(['pdftotext', '-layout', '-', '-']);
    $extract->setInput($pdf->getContent());
    $extract->run();
    expect($extract->isSuccessful())->toBeTrue()
        ->and($extract->getOutput())->toContain(
            __('hr_payroll_reports.item_names.SOCIAL-INSURANCE'),
            __('hr_payroll_reports.item_names.PAYROLL-TAX'),
            __('hr_payroll_reports.item_names.EMPLOYER-INSURANCE'),
        );
});

test('dated synthetic insurance versions and employee coverage prorate without inventing a missing policy day', function (): void {
    $fixture = payrollFinancialFixture();
    $employee = $fixture['employee'];
    $employee->update([
        'insurance_status' => 'subject',
        'insurance_start_date' => '2026-09-11',
        'insurance_contribution_wage' => '10000.00',
    ]);
    foreach ([
        [8001, '2026-09-01', '2026-09-15', '10.0000', '15.0000'],
        [8002, '2026-09-16', '2026-09-30', '20.0000', '25.0000'],
    ] as [$number, $from, $to, $employeeRate, $employerRate]) {
        $policy = HrSocialInsurancePolicy::query()->create([
            'doc_number' => $number,
            'doc_num' => 'TEST-INS-'.$number,
            'company_id' => $fixture['company']->getKey(),
            'name' => 'Synthetic insurance '.$number,
            'effective_from' => $from,
            'effective_to' => $to,
            'employee_contribution_rate' => $employeeRate,
            'employer_contribution_rate' => $employerRate,
            'rounding_rule' => 'nearest',
            'status' => 'active',
        ]);
        $policy->components()->create([
            'name' => 'Synthetic component',
            'employee_rate' => $employeeRate,
            'employer_rate' => $employerRate,
            'calculation_basis' => 'contribution_wage',
            'is_active' => true,
        ]);
    }

    $result = app(PayrollStatutoryCalculationService::class)->calculate(
        $fixture['company']->getKey(), $employee->fresh(), '2026-09-01', '2026-09-30', '10000.0000',
    );
    expect($result['employee_insurance'])->toBe('1166.6666')
        ->and($result['employer_insurance'])->toBe('1500.0000')
        ->and($result['insurance_sources'])->toHaveCount(2)
        ->and($result['insurance_sources'][0]['covered_days'])->toBe(5)
        ->and($result['insurance_sources'][1]['covered_days'])->toBe(15);

    HrSocialInsurancePolicy::query()->where('doc_num', 'TEST-INS-8002')->update(['effective_from' => '2026-09-17']);
    expect(fn () => app(PayrollStatutoryCalculationService::class)->calculate(
        $fixture['company']->getKey(), $employee->fresh(), '2026-09-01', '2026-09-30', '10000.0000',
    ))->toThrow(DomainException::class, __('hr_payroll.messages.statutory_policy_coverage_required', ['date' => '2026-09-16']));
});

test('tax uses dated gross once for a partial hire and ignores overtime before tax coverage', function (): void {
    $fixture = payrollFinancialFixture();
    $this->actingAs(payrollFinancialActor([]))->withSession(payrollFinancialContext($fixture));
    $employee = $fixture['employee'];
    $employee->update(['tax_status' => 'subject', 'tax_start_date' => '2026-09-11']);
    $tax = HrEmploymentTaxPolicy::query()->create([
        'doc_number' => 8101, 'doc_num' => 'TEST-TAX-8101',
        'company_id' => $fixture['company']->getKey(), 'name' => 'Synthetic dated gross tax',
        'tax_year' => 2026, 'effective_from' => '2026-01-01',
        'annual_exemption_amount' => '0.00', 'taxable_basis' => 'gross',
        'annualization_method' => 'twelve_equal_periods', 'rounding_rule' => 'nearest', 'status' => 'active',
    ]);
    $tax->brackets()->create(['from_amount' => '0.00', 'to_amount' => null, 'rate' => '10.0000', 'sort_order' => 0]);

    $first = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $firstSource = json_decode((string) DB::table('hr_payroll_inputs')->where('payroll_run_id', $first['run_id'])->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    expect($first['gross'])->toBe('10200.0000')
        ->and($first['deductions'])->toBe('666.6700')
        ->and(data_get($firstSource, 'statutory.tax_sources.0.covered_gross'))->toBe('6666.667000000000');

    DB::table('hr_attendance_daily_records')->where('employee_id', $employee->getKey())->update([
        'work_date' => '2026-09-20', 'check_in_at' => '2026-09-20 08:00:00', 'check_out_at' => '2026-09-20 18:00:00',
    ]);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);
    HrEmployeeServiceRequest::query()->where('employee_id', $employee->getKey())->update([
        'requested_from' => '2026-09-20', 'requested_to' => '2026-09-20',
    ]);
    $employee->update(['hire_date' => '2026-09-11']);
    $second = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $secondSource = json_decode((string) DB::table('hr_payroll_inputs')->where('payroll_run_id', $second['run_id'])->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    expect($second['gross'])->toBe('6866.6667')
        ->and($second['deductions'])->toBe('686.6700')
        ->and(data_get($secondSource, 'statutory.tax_sources.0.covered_gross'))->toBe('6866.666700000000');
});

test('subject employees cannot calculate payroll from an unconfigured tax policy', function (): void {
    $fixture = payrollFinancialFixture();
    $fixture['employee']->update(['tax_status' => 'subject', 'tax_start_date' => '2026-01-01']);
    $this->actingAs(payrollFinancialActor([]))->withSession(payrollFinancialContext($fixture));

    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]))->toThrow(DomainException::class, __('hr_payroll.messages.statutory_policy_coverage_required', ['date' => '2026-09-01']));
    expect(DB::table('hr_payslips')->count())->toBe(0)
        ->and(DB::table('hr_payroll_runs')->count())->toBe(0);
});

test('dated organization cost center is frozen across basic salary components overtime and posting', function (): void {
    $fixture = payrollFinancialFixture();
    $datedCostCenter = CostCenter::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 7002,
        'doc_num' => 'PAY-CC-07002',
        'cost_center_code' => '12',
        'name' => 'Dated production cost center',
        'name_en' => 'Dated production cost center',
        'is_group' => false,
        'status' => 'active',
    ]);
    DB::table('hr_employee_organization_assignments')->insert([
        'company_id' => $fixture['company']->getKey(),
        'employee_id' => $fixture['employee']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'department_id' => $fixture['employee']->department_id,
        'cost_center_id' => $datedCostCenter->getKey(),
        'effective_from' => '2026-01-01',
        'source_type' => 'initial_verified',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_payroll_items')->insert([
        'code' => 'PAY-BONUS',
        'name' => 'Dated assignment bonus',
        'item_kind' => 'earning',
        'account_classification_id' => AccountClassification::query()->where('code', 'salary_expense')->value('id'),
        'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_employee_salary_assignments')->where('employee_id', $fixture['employee']->getKey())->update([
        'components' => json_encode([
            'items' => [['payroll_item_code' => 'PAY-BONUS', 'amount' => '1000.0000', 'direction' => 'earning']],
            'overtime_hourly_rate' => '100.0000',
        ], JSON_THROW_ON_ERROR),
    ]);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $preview = app(PayrollCostAllocationService::class)->previewRun($result['run_id']);
    $snapshots = DB::table('hr_payslip_items')->where('direction', 'earning')->pluck('source_snapshot')
        ->map(fn (string $snapshot): array => json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR));

    expect($result['gross'])->toBe('11200.0000')
        ->and($preview['errors'])->toBe([])
        ->and(collect($preview['lines'])->pluck('cost_center_id')->unique()->all())->toBe([$datedCostCenter->getKey()])
        ->and($snapshots->pluck('cost_center_id')->unique()->all())->toBe([$datedCostCenter->getKey()]);

    app(PayrollLifecycleService::class)->submitForReview($result['run_id'], $fixture['company']->getKey());
    $approved = app(PayrollLifecycleService::class)->approve($result['run_id'], $fixture['company']->getKey());
    $postedCostCenters = DB::table('journal_entry_lines')
        ->where('journal_entry_id', $approved['journal_entry_id'])
        ->where('debit_amount', '>', 0)
        ->pluck('cost_center_id')->unique()->all();
    expect($postedCostCenters)->toBe([$datedCostCenter->getKey()]);
});

test('a mid-month cost-center transfer splits salary and approved overtime into matching journal lines', function (): void {
    $fixture = payrollFinancialFixture();
    $firstCostCenterId = (int) HrDepartmentCostCenterDefault::query()
        ->where('company_id', $fixture['company']->getKey())
        ->where('department_id', $fixture['employee']->department_id)
        ->value('cost_center_id');
    $nextCostCenter = CostCenter::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 7002,
        'doc_num' => 'PAY-CC-07002',
        'cost_center_code' => '12',
        'name' => 'Transferred production cost center',
        'name_en' => 'Transferred production cost center',
        'is_group' => false,
        'status' => 'active',
    ]);
    $nextDepartment = HrDepartment::query()->create([
        'doc_number' => 7002, 'doc_num' => 'PAY-DEPT-07002',
        'name' => 'Transferred payroll department', 'status' => 'active',
    ]);
    HrDepartmentCostCenterDefault::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'department_id' => $nextDepartment->getKey(),
        'cost_center_id' => $nextCostCenter->getKey(),
    ]);
    DB::table('hr_employee_organization_assignments')->insert([
        [
            'company_id' => $fixture['company']->getKey(), 'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $fixture['branch']->getKey(), 'department_id' => $fixture['employee']->department_id,
            'cost_center_id' => $firstCostCenterId, 'effective_from' => '2026-01-01', 'effective_to' => '2026-09-15',
            'source_type' => 'initial_verified', 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'company_id' => $fixture['company']->getKey(), 'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $fixture['branch']->getKey(), 'department_id' => $nextDepartment->getKey(),
            'cost_center_id' => $nextCostCenter->getKey(), 'effective_from' => '2026-09-16',
            'effective_to' => null,
            'source_type' => 'transfer', 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);
    DB::table('hr_attendance_daily_records')->insert([
        'employee_id' => $fixture['employee']->getKey(), 'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'work_date' => '2026-09-20',
        'check_in_at' => '2026-09-20 08:00:00', 'check_out_at' => '2026-09-20 17:00:00',
        'worked_minutes' => 540, 'overtime_minutes' => 60, 'status' => 'present',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    HrEmployeeServiceRequest::query()->create([
        'employee_id' => $fixture['employee']->getKey(), 'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'request_type' => 'overtime',
        'subject' => 'Dated second-center overtime', 'details' => 'Synthetic approved overtime',
        'requested_from' => '2026-09-20', 'requested_to' => '2026-09-20',
        'requested_minutes' => 60, 'status' => HrEmployeeServiceRequest::StatusApproved,
        'submitted_at' => now(), 'resolved_at' => now(),
    ]);

    $fixture['employee']->update([
        'insurance_status' => 'subject', 'insurance_start_date' => '2026-01-01',
        'insurance_contribution_wage' => '10000.00',
    ]);
    $insurancePayable = payrollFinancialAccount($fixture['company'], 'social_insurance_payable', 'PAY-2122', 2122);
    $insuranceExpense = payrollFinancialAccount($fixture['company'], 'insurance_expense', 'PAY-5122', 5122);
    foreach ([['SOCIAL-INSURANCE', 'deduction', 'social_insurance_payable'], ['EMPLOYER-INSURANCE', 'employer', 'insurance_expense']] as [$code, $kind, $classification]) {
        DB::table('hr_payroll_items')->insert([
            'code' => $code, 'name' => $code, 'item_kind' => $kind,
            'account_classification_id' => AccountClassification::query()->where('code', $classification)->value('id'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $insurance = HrSocialInsurancePolicy::query()->create([
        'doc_number' => 8102, 'doc_num' => 'TEST-INS-8102',
        'company_id' => $fixture['company']->getKey(), 'name' => 'Synthetic transfer insurance',
        'effective_from' => '2026-01-01', 'employee_contribution_rate' => '10.0000',
        'employer_contribution_rate' => '15.0000', 'rounding_rule' => 'nearest', 'status' => 'active',
    ]);
    $insurance->components()->create([
        'name' => 'Synthetic contribution', 'employee_rate' => '10.0000',
        'employer_rate' => '15.0000', 'calculation_basis' => 'contribution_wage', 'is_active' => true,
    ]);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $preview = app(PayrollCostAllocationService::class)->previewRun($result['run_id']);
    $amountByCenter = collect($preview['lines'])->groupBy('cost_center_id')->map(
        fn ($lines): string => $lines->reduce(fn (string $total, array $line): string => bcadd($total, $line['amount'], 4), '0.0000')
    );

    expect($result['gross'])->toBe('10300.0000')
        ->and($preview['errors'])->toBe([])
        ->and($result['deductions'])->toBe('1000.0000')
        ->and($amountByCenter->get($firstCostCenterId))->toBe('5950.0000')
        ->and($amountByCenter->get($nextCostCenter->getKey()))->toBe('5850.0000');

    app(PayrollLifecycleService::class)->submitForReview($result['run_id'], $fixture['company']->getKey());
    $approval = app(PayrollLifecycleService::class)->approve($result['run_id'], $fixture['company']->getKey());
    $journalAmounts = DB::table('journal_entry_lines')->where('journal_entry_id', $approval['journal_entry_id'])
        ->where('debit_amount', '>', 0)->get(['cost_center_id', 'debit_amount'])
        ->groupBy('cost_center_id')->map(fn ($lines): string => $lines->reduce(
            fn (string $total, object $line): string => bcadd($total, (string) $line->debit_amount, 4), '0.0000'
        ));
    expect($journalAmounts->get($firstCostCenterId))->toBe('5950.0000')
        ->and($journalAmounts->get($nextCostCenter->getKey()))->toBe('5850.0000')
        ->and(DB::table('hr_payroll_cost_allocations')->where('cost_center_id', $nextCostCenter->getKey())
            ->pluck('department_id')->unique()->all())->toBe([$nextDepartment->getKey()])
        ->and((float) DB::table('journal_entry_lines')->where('journal_entry_id', $approval['journal_entry_id'])
            ->where('account_id', $insurancePayable->getKey())->sum('credit_amount'))->toBe(2500.0);
    $insuranceByCenter = DB::table('journal_entry_lines')->where('journal_entry_id', $approval['journal_entry_id'])
        ->where('account_id', $insuranceExpense->getKey())->get(['cost_center_id', 'debit_amount'])
        ->groupBy('cost_center_id')->map(fn ($lines): string => $lines->reduce(
            fn (string $total, object $line): string => bcadd($total, (string) $line->debit_amount, 4), '0.0000'
        ));
    expect($insuranceByCenter->get($firstCostCenterId))->toBe('750.0000')
        ->and($insuranceByCenter->get($nextCostCenter->getKey()))->toBe('750.0000');
});

test('a company payroll transfer posts branch liabilities and settles each branch without duplicating advances', function (): void {
    $fixture = payrollFinancialFixture();
    $actor = payrollFinancialActor([
        'hr.payroll_preparation.view', 'hr.payroll_preparation.calculate',
        'hr.payroll_approval.review', 'hr.payroll_approval.approve',
        'hr.payroll_payment.create', 'cash_payment_vouchers.create',
        'cash_payment_vouchers.approve', 'hr.payroll_reconciliation.view',
        'hr.payroll_reports.view', 'hr.payroll_reports.export',
    ]);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture));
    $destination = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 7002,
        'doc_num' => 'PAY-BR-07002', 'name' => 'Destination payroll branch',
        'type' => Branch::TypeFactory, 'status' => 'active',
    ]);
    $fixture['employee']->update([
        'branch_id' => $destination->getKey(), 'basic_salary' => '9000.00',
        'insurance_status' => 'subject', 'insurance_start_date' => '2026-01-01',
        'insurance_contribution_wage' => '10000.00',
        'tax_status' => 'subject', 'tax_start_date' => '2026-01-01',
    ]);
    DB::table('hr_employee_salary_assignments')->where('employee_id', $fixture['employee']->getKey())
        ->update(['basic_salary' => '9000.00']);
    DB::table('hr_employee_organization_assignments')->insert([
        [
            'company_id' => $fixture['company']->getKey(), 'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $fixture['branch']->getKey(), 'department_id' => $fixture['employee']->department_id,
            'effective_from' => '2026-01-01', 'effective_to' => '2026-09-10',
            'source_type' => 'initial_verified', 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'company_id' => $fixture['company']->getKey(), 'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $destination->getKey(), 'department_id' => $fixture['employee']->department_id,
            'effective_from' => '2026-09-11', 'effective_to' => null,
            'source_type' => 'transfer', 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
    foreach ([$fixture['branch'], $destination] as $branch) {
        HrPayrollAttendancePolicy::query()->create([
            'company_id' => $fixture['company']->getKey(), 'branch_id' => $branch->getKey(),
            'branch_scope_key' => 'branch:'.$branch->getKey(),
            'effective_from' => '2026-01-01',
            'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
            'salary_day_divisor' => 30, 'standard_day_minutes' => 480, 'status' => 'active',
        ]);
    }
    $destinationCashAccount = payrollFinancialAccount($fixture['company'], 'cash_in_transit', 'PAY-1112', 1112);
    $destinationCashbox = Cashbox::query()->create([
        'doc_number' => 7002, 'doc_num' => 'PAY-CASH-07002',
        'company_id' => $fixture['company']->getKey(), 'branch_id' => $destination->getKey(),
        'account_id' => $destinationCashAccount->getKey(),
        'name' => 'Destination payroll cashbox', 'status' => 'active',
    ]);
    CashboxCurrency::query()->create([
        'cashbox_id' => $destinationCashbox->getKey(),
        'currency_id' => $fixture['currency']->getKey(), 'status' => 'active',
    ]);
    $insurancePayable = payrollFinancialAccount($fixture['company'], 'social_insurance_payable', 'PAY-2122', 2122);
    payrollFinancialAccount($fixture['company'], 'insurance_expense', 'PAY-5122', 5122);
    foreach ([['SOCIAL-INSURANCE', 'deduction', 'social_insurance_payable'], ['EMPLOYER-INSURANCE', 'employer', 'insurance_expense']] as [$code, $kind, $classification]) {
        DB::table('hr_payroll_items')->insert([
            'code' => $code, 'name' => $code, 'item_kind' => $kind,
            'account_classification_id' => AccountClassification::query()->where('code', $classification)->value('id'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $insurance = HrSocialInsurancePolicy::query()->create([
        'doc_number' => 8201, 'doc_num' => 'TEST-INS-8201',
        'company_id' => $fixture['company']->getKey(), 'name' => 'Synthetic transfer insurance',
        'effective_from' => '2026-01-01', 'employee_contribution_rate' => '10.0000',
        'employer_contribution_rate' => '15.0000', 'rounding_rule' => 'nearest', 'status' => 'active',
    ]);
    $insurance->components()->create([
        'name' => 'Synthetic contribution', 'employee_rate' => '10.0000',
        'employer_rate' => '15.0000', 'calculation_basis' => 'contribution_wage', 'is_active' => true,
    ]);
    $tax = HrEmploymentTaxPolicy::query()->create([
        'doc_number' => 8201, 'doc_num' => 'TEST-TAX-8201',
        'company_id' => $fixture['company']->getKey(), 'name' => 'Synthetic transfer tax',
        'tax_year' => 2026, 'effective_from' => '2026-01-01',
        'annual_exemption_amount' => '0.00', 'taxable_basis' => 'gross_after_employee_insurance',
        'annualization_method' => 'twelve_equal_periods', 'rounding_rule' => 'nearest', 'status' => 'active',
    ]);
    $tax->brackets()->create(['from_amount' => '0.00', 'to_amount' => null, 'rate' => '10.0000', 'sort_order' => 0]);

    $calculated = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'adjustments' => [[
            'employee_doc_num' => $fixture['employee']->doc_num,
            'advance_applications' => [[
                'salary_advance_id' => $fixture['advance_id'],
                'payroll_item_code' => 'SALARY-ADVANCE', 'amount' => '300.0000',
            ]],
        ]],
    ]);
    $slips = DB::table('hr_payslips')->where('payroll_run_id', $calculated['run_id'])->orderBy('branch_id')->get();
    $advanceApplications = DB::table('hr_payroll_advance_applications')->where('payroll_run_id', $calculated['run_id'])->get();
    expect($calculated)->toMatchArray(['employee_count' => 1, 'gross' => '9200.0000', 'deductions' => '2120.0000', 'payable' => '7080.0000'])
        ->and($slips)->toHaveCount(2)
        ->and($advanceApplications)->toHaveCount(2)
        ->and(bcadd((string) $advanceApplications[0]->amount, (string) $advanceApplications[1]->amount, 4))->toBe('300.0000')
        ->and((float) DB::table('hr_payslip_items as line')->join('hr_payroll_items as item', 'item.id', '=', 'line.payroll_item_id')
            ->where('item.code', 'SOCIAL-INSURANCE')->sum('line.amount'))->toBe(1000.0)
        ->and((float) DB::table('hr_payslip_items as line')->join('hr_payroll_items as item', 'item.id', '=', 'line.payroll_item_id')
            ->where('item.code', 'PAYROLL-TAX')->sum('line.amount'))->toBe(820.0)
        ->and((float) DB::table('hr_salary_advances')->where('id', $fixture['advance_id'])->value('balance'))->toBe(500.0);
    $taxByBranch = DB::table('hr_payslip_items as line')
        ->join('hr_payslips as slip', 'slip.id', '=', 'line.payslip_id')
        ->join('hr_payroll_items as item', 'item.id', '=', 'line.payroll_item_id')
        ->where('slip.payroll_run_id', $calculated['run_id'])
        ->where('item.code', 'PAYROLL-TAX')
        ->orderBy('slip.branch_id')
        ->pluck('line.amount')->map(fn (mixed $amount): string => bcadd((string) $amount, '0', 4))->all();
    expect($taxByBranch)->toBe(['286.6667', '533.3333']);

    $report = app(PayrollReportService::class)->payroll($fixture['company']->getKey(), $actor, ['run_id' => $calculated['run_id']]);
    expect($report['rows'])->toHaveCount(2)
        ->and($report['totals'][0]['gross'])->toBe('9200.0000')
        ->and($report['totals'][0]['deductions'])->toBe('2120.0000')
        ->and($report['totals'][0]['net'])->toBe('7080.0000');
    $firstBranchReport = app(PayrollReportService::class)->payroll($fixture['company']->getKey(), $actor, [
        'run_id' => $calculated['run_id'], 'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    expect($firstBranchReport['rows'])->toHaveCount(1)
        ->and($firstBranchReport['totals'][0]['net'])->toBe(bcadd((string) $slips[0]->net_amount, '0', 4));
    $reportUrl = route('admin.hr.reports.payroll', ['run_id' => $calculated['run_id']]);
    $this->withSession(payrollFinancialContext($fixture))->get($reportUrl)
        ->assertOk()
        ->assertSee(app(NumericFormatService::class)->format('7080.0000'));
    $csv = $this->withSession(payrollFinancialContext($fixture))->get(route('admin.hr.reports.payroll.export', [
        'run_id' => $calculated['run_id'], 'format' => 'csv',
    ]))->assertOk()->assertDownload('payroll-report.csv');
    $csvContents = file_get_contents($csv->baseResponse->getFile()->getPathname());
    expect($csvContents)->toContain('9200.0000', '2120.0000', '7080.0000');
    foreach ($slips as $slip) {
        expect($csvContents)->toContain($slip->employee_doc_num, (string) $slip->net_amount);
    }
    $xlsx = $this->withSession(payrollFinancialContext($fixture))->get(route('admin.hr.reports.payroll.export', [
        'run_id' => $calculated['run_id'], 'format' => 'xlsx',
    ]))->assertOk()->assertDownload('payroll-report.xlsx');
    $xlsxValues = collect(IOFactory::load($xlsx->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray())->flatten()->all();
    expect($xlsxValues)->toContain('9200.0000', '2120.0000', '7080.0000');
    foreach ($slips as $slip) {
        expect($xlsxValues)->toContain($slip->employee_doc_num, (string) $slip->net_amount);
    }
    $pdf = $this->withSession(payrollFinancialContext($fixture))->get(route('admin.hr.reports.payroll.export', [
        'run_id' => $calculated['run_id'], 'format' => 'pdf',
    ]))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($pdf->baseResponse->getContent())->toStartWith('%PDF-');

    app(PayrollLifecycleService::class)->submitForReview($calculated['run_id'], $fixture['company']->getKey());
    $approved = app(PayrollLifecycleService::class)->approve($calculated['run_id'], $fixture['company']->getKey());
    expect((float) DB::table('hr_salary_advances')->where('id', $fixture['advance_id'])->value('balance'))->toBe(200.0);
    expect((float) DB::table('journal_entry_lines')->where('journal_entry_id', $approved['journal_entry_id'])
        ->where('account_id', $insurancePayable->getKey())->sum('credit_amount'))->toBe(2500.0);
    foreach ($slips as $slip) {
        $postedPayable = DB::table('journal_entry_lines')->where('journal_entry_id', $approved['journal_entry_id'])
            ->where('account_id', $fixture['payable_account']->getKey())
            ->where('branch_id', $slip->branch_id)->sum('credit_amount');
        expect(bcadd((string) $postedPayable, '0', 4))->toBe(bcadd((string) $slip->net_amount, '0', 4));
        $branchPayslip = app(PayrollReportService::class)->payslipForEmployee((int) $slip->id, (int) $fixture['employee']->getKey());
        expect((int) $branchPayslip['attendance']['recorded_overtime_minutes'])
            ->toBe((int) $slip->branch_id === (int) $fixture['branch']->getKey() ? 120 : 0);
        expect($branchPayslip['attendance'])->toHaveKeys([
            'record_ids', 'effect_record_ids', 'finalized_records', 'policy_snapshots', 'summary', 'salary_segments',
        ]);
        expect($branchPayslip['attendance']['policy_snapshots'])->toHaveCount(1)
            ->and($branchPayslip['attendance']['salary_segments'][0]['organization_assignment_id'])->not->toBeNull();
    }

    foreach ($slips as $slip) {
        $cashbox = (int) $slip->branch_id === (int) $fixture['branch']->getKey() ? $fixture['cashbox'] : $destinationCashbox;
        $otherCashbox = $cashbox->getKey() === $fixture['cashbox']->getKey() ? $destinationCashbox : $fixture['cashbox'];
        expect(fn () => app(PayrollPaymentService::class)->createCashPayment($calculated['run_id'], $fixture['company']->getKey(), [
            'payslip_id' => $slip->id, 'cashbox_doc_num' => $otherCashbox->doc_num,
            'amount' => '1.0000', 'payment_date' => '2026-09-17',
            'idempotency_key' => (string) Str::uuid(),
        ]))->toThrow(DomainException::class, __('hr_payroll.messages.payment_branch_mismatch'));
        $draft = app(PayrollPaymentService::class)->createCashPayment($calculated['run_id'], $fixture['company']->getKey(), [
            'payslip_id' => $slip->id, 'cashbox_doc_num' => $cashbox->doc_num,
            'amount' => (string) $slip->net_amount, 'payment_date' => '2026-09-17',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $draft['voucher'], $fixture['company']->getKey());
        $scope = app(PayrollReconciliationService::class)->forScope(
            $fixture['company']->getKey(), (int) $slip->branch_id, '2026-08-31', '2026-09-30',
        );
        expect($scope['payable_ending'])->toBe('0.0000')
            ->and($scope['gl_ending'])->toBe('0.0000');
    }
    $reconciled = app(PayrollReconciliationService::class)->forRun($calculated['run_id'], $fixture['company']->getKey(), '2026-09-30');
    expect($reconciled['status'])->toBe('matched')
        ->and($reconciled['summary']['paid'])->toBe('7080.0000')
        ->and($reconciled['summary']['remaining'])->toBe('0.0000');

    $frozenSource = DB::table('hr_payslip_items')->whereIn('payslip_id', $slips->pluck('id'))
        ->orderBy('id')->pluck('source_snapshot', 'id')->all();
    $fixture['employee']->update([
        'branch_id' => $fixture['branch']->getKey(),
        'basic_salary' => '15000.00',
        'full_name' => 'Later employee card name',
    ]);
    expect(DB::table('hr_payslip_items')->whereIn('payslip_id', $slips->pluck('id'))
        ->orderBy('id')->pluck('source_snapshot', 'id')->all())->toBe($frozenSource);
    foreach ($slips as $slip) {
        $frozenPayslip = app(PayrollReportService::class)->payslipForEmployee((int) $slip->id, (int) $fixture['employee']->getKey());
        expect($frozenPayslip['payslip']->employee_name)->toBe($slip->employee_name)
            ->and(bcadd((string) $frozenPayslip['payslip']->net_amount, '0', 4))->toBe(bcadd((string) $slip->net_amount, '0', 4));
    }
});

test('scheduled weekly wages follow dated branch calendars through posting and payment', function (): void {
    $fixture = payrollFinancialFixture();
    HrEmployeeServiceRequest::query()->where('employee_id', $fixture['employee']->getKey())->delete();
    $actor = payrollFinancialActor([
        'hr.payroll_preparation.view', 'hr.payroll_preparation.calculate',
        'hr.payroll_approval.review', 'hr.payroll_approval.approve',
        'hr.payroll_payment.create', 'cash_payment_vouchers.create',
        'cash_payment_vouchers.approve', 'hr.payroll_reconciliation.view',
        'hr.payroll_reports.view', 'hr.payroll_reports.export',
    ]);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture));
    $destination = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 7003,
        'doc_num' => 'PAY-BR-07003', 'name' => 'Scheduled destination branch',
        'type' => Branch::TypeFactory, 'status' => 'active',
    ]);
    $destinationCashAccount = payrollFinancialAccount($fixture['company'], 'cash_in_transit', 'PAY-1113', 1113);
    $destinationCashbox = Cashbox::query()->create([
        'doc_number' => 7003, 'doc_num' => 'PAY-CASH-07003',
        'company_id' => $fixture['company']->getKey(), 'branch_id' => $destination->getKey(),
        'account_id' => $destinationCashAccount->getKey(),
        'name' => 'Scheduled destination cashbox', 'status' => 'active',
    ]);
    CashboxCurrency::query()->create([
        'cashbox_id' => $destinationCashbox->getKey(),
        'currency_id' => $fixture['currency']->getKey(), 'status' => 'active',
    ]);
    $fixture['employee']->update([
        'branch_id' => $destination->getKey(), 'pay_basis' => 'weekly_wage',
        'weekly_wage' => '700.0000', 'overtime_enabled' => false,
    ]);
    DB::table('hr_employee_organization_assignments')->insert([
        [
            'company_id' => $fixture['company']->getKey(), 'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $fixture['branch']->getKey(), 'department_id' => $fixture['employee']->department_id,
            'effective_from' => '2026-01-01', 'effective_to' => '2026-09-10',
            'source_type' => 'initial_verified', 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'company_id' => $fixture['company']->getKey(), 'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $destination->getKey(), 'department_id' => $fixture['employee']->department_id,
            'effective_from' => '2026-09-11', 'effective_to' => null,
            'source_type' => 'transfer', 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
    foreach ([
        [$fixture['branch'], '2026-09-01', '2026-09-10'],
        [$destination, '2026-09-11', '2026-09-30'],
    ] as [$branch, $from, $to]) {
        HrPayrollAttendancePolicy::query()->create([
            'company_id' => $fixture['company']->getKey(), 'branch_id' => $branch->getKey(),
            'branch_scope_key' => 'branch:'.$branch->getKey(), 'effective_from' => '2026-01-01',
            'weekly_accrual_method' => HrPayrollAttendancePolicy::WeeklyScheduledWorkAndPaidHoliday,
            'weekly_work_days' => 5, 'salary_day_divisor' => 30,
            'standard_day_minutes' => 480, 'status' => 'active',
        ]);
        $calendarId = DB::table('hr_work_calendars')->insertGetId([
            'company_id' => $fixture['company']->getKey(), 'branch_id' => $branch->getKey(),
            'code' => 'CAL-'.$branch->doc_num, 'name' => 'Synthetic scheduled payroll',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_work_calendar_assignments')->insert([
            'employee_id' => $fixture['employee']->getKey(), 'calendar_id' => $calendarId,
            'effective_from' => $from, 'effective_to' => $to,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (Carbon::parse($from)->daysUntil(Carbon::parse($to)) as $date) {
            DB::table('hr_work_calendar_days')->insert([
                'calendar_id' => $calendarId, 'work_date' => $date->toDateString(),
                'day_type' => $date->toDateString() === '2026-09-12' ? 'holiday_paid' : 'working',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    $calculated = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
    ]);
    $slips = DB::table('hr_payslips')->where('payroll_run_id', $calculated['run_id'])
        ->orderBy('branch_id')->get();
    expect($calculated)->toMatchArray([
        'employee_count' => 1, 'gross' => '4200.0000',
        'deductions' => '0.0000', 'payable' => '4200.0000',
    ])->and($slips)->toHaveCount(2)
        ->and(bcadd((string) $slips[0]->gross_amount, '0', 4))->toBe('1400.0000')
        ->and(bcadd((string) $slips[1]->gross_amount, '0', 4))->toBe('2800.0000');
    $basicSources = DB::table('hr_payslip_items')
        ->whereIn('payslip_id', $slips->pluck('id')->all())
        ->where('payroll_item_id', DB::table('hr_payroll_items')->where('code', 'BASIC')->value('id'))
        ->pluck('source_snapshot')->implode(' ');
    expect($basicSources)->toContain('holiday_paid', '2026-09-12');

    $screen = $this->get(route('admin.hr.reports.payroll', ['run_id' => $calculated['run_id']]))->assertOk();
    $screen->assertSee(app(NumericFormatService::class)->format('4200.0000'));

    $csv = $this->get(route('admin.hr.reports.payroll.export', [
        'run_id' => $calculated['run_id'], 'format' => 'csv',
    ]))->assertOk();
    $csvContents = file_get_contents($csv->baseResponse->getFile()->getPathname());
    expect($csvContents)->toContain('4200.0000', '"1400"', '"2800"');

    $xlsx = $this->get(route('admin.hr.reports.payroll.export', [
        'run_id' => $calculated['run_id'], 'format' => 'xlsx',
    ]))->assertOk();
    $xlsxValues = collect(IOFactory::load($xlsx->baseResponse->getFile()->getPathname())
        ->getActiveSheet()->toArray())->flatten()->map(fn (mixed $value): string => (string) $value)->all();
    expect($xlsxValues)->toContain('4200.0000', '1400', '2800');

    $pdf = $this->get(route('admin.hr.reports.payroll.export', [
        'run_id' => $calculated['run_id'], 'format' => 'pdf',
    ]))->assertOk()->assertHeader('content-type', 'application/pdf');
    $pdfText = new Process(['pdftotext', '-layout', '-', '-']);
    $pdfText->setInput($pdf->getContent());
    $pdfText->mustRun();
    expect($pdfText->getOutput())->toContain(
        app(NumericFormatService::class)->format('4200.0000'),
        app(NumericFormatService::class)->format('1400.0000'),
        app(NumericFormatService::class)->format('2800.0000'),
    );

    app(PayrollLifecycleService::class)->submitForReview($calculated['run_id'], $fixture['company']->getKey());
    $approved = app(PayrollLifecycleService::class)->approve($calculated['run_id'], $fixture['company']->getKey());
    foreach ($slips as $slip) {
        $credit = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $approved['journal_entry_id'])
            ->where('account_id', $fixture['payable_account']->getKey())
            ->where('branch_id', $slip->branch_id)->sum('credit_amount');
        expect(bcadd((string) $credit, '0', 4))->toBe(bcadd((string) $slip->net_amount, '0', 4));
        $cashbox = (int) $slip->branch_id === (int) $fixture['branch']->getKey()
            ? $fixture['cashbox'] : $destinationCashbox;
        $draft = app(PayrollPaymentService::class)->createCashPayment(
            $calculated['run_id'], $fixture['company']->getKey(), [
                'payslip_id' => $slip->id,
                'cashbox_doc_num' => $cashbox->doc_num,
                'amount' => (string) $slip->net_amount,
                'payment_date' => '2026-09-17',
                'idempotency_key' => (string) Str::uuid(),
            ],
        );
        app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $draft['voucher'], $fixture['company']->getKey());
    }
    $reconciled = app(PayrollReconciliationService::class)->forRun(
        $calculated['run_id'], $fixture['company']->getKey(), '2026-09-30',
    );
    expect($reconciled['status'])->toBe('matched')
        ->and($reconciled['summary']['paid'])->toBe('4200.0000')
        ->and($reconciled['summary']['remaining'])->toBe('0.0000');
});

test('configured nonmonthly wage evidence posts and pays without changing its source amount', function (string $basis, string $rateColumn, string $rate, string $expected): void {
    $fixture = payrollFinancialFixture();
    $actor = payrollFinancialActor([
        'hr.payroll_preparation.view', 'hr.payroll_preparation.calculate',
        'hr.payroll_approval.review', 'hr.payroll_approval.approve',
        'hr.payroll_payment.create', 'cash_payment_vouchers.create',
        'cash_payment_vouchers.approve', 'hr.payroll_reconciliation.view',
    ]);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture));
    HrEmployeeServiceRequest::query()->where('employee_id', $fixture['employee']->getKey())->delete();
    DB::table('hr_salary_advances')->where('employee_id', $fixture['employee']->getKey())->delete();

    $shift = HrShift::query()->create([
        'doc_number' => 7701, 'doc_num' => 'PAY-ALT-SHIFT-07701',
        'name' => 'Synthetic wage evidence shift', 'start_time' => '08:00:00',
        'end_time' => '16:00:00', 'break_minutes' => 0,
        'crosses_midnight' => false, 'status' => 'active',
    ]);
    DB::table('hr_attendance_daily_records')
        ->where('employee_id', $fixture['employee']->getKey())
        ->update(['shift_id' => $shift->getKey(), 'worked_minutes' => 480, 'overtime_minutes' => 0,
            'check_out_at' => '2026-09-10 16:00:00']);
    $fixture['employee']->update([
        'pay_basis' => $basis, $rateColumn => $rate,
        'basic_salary' => '0.0000', 'overtime_enabled' => false,
    ]);
    DB::table('hr_employee_salary_assignments')
        ->where('employee_id', $fixture['employee']->getKey())
        ->update([
            'pay_basis' => $basis, $rateColumn => $rate,
            'basic_salary' => '0.0000',
            'components' => json_encode(['items' => []], JSON_THROW_ON_ERROR),
        ]);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'weekly_accrual_method' => HrPayrollAttendancePolicy::WeeklyFinalizedAttendance,
        'weekly_work_days' => 5,
        'daily_accrual_method' => HrPayrollAttendancePolicy::DailyFinalizedAttendance,
        'hourly_accrual_method' => HrPayrollAttendancePolicy::HourlyFinalizedMinutes,
        'shift_accrual_method' => HrPayrollAttendancePolicy::ShiftFinalizedAttendance,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);

    $calculated = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-10', 'period_end' => '2026-09-10',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $slip = DB::table('hr_payslips')->where('payroll_run_id', $calculated['run_id'])->sole();
    expect($calculated['gross'])->toBe($expected)
        ->and(bcadd((string) $slip->net_amount, '0', 4))->toBe($expected);

    app(PayrollLifecycleService::class)->submitForReview($calculated['run_id'], $fixture['company']->getKey());
    $approved = app(PayrollLifecycleService::class)->approve($calculated['run_id'], $fixture['company']->getKey());
    $credit = DB::table('journal_entry_lines')
        ->where('journal_entry_id', $approved['journal_entry_id'])
        ->where('account_id', $fixture['payable_account']->getKey())
        ->sum('credit_amount');
    expect(bcadd((string) $credit, '0', 4))->toBe($expected);

    $payment = app(PayrollPaymentService::class)->createCashPayment(
        $calculated['run_id'], $fixture['company']->getKey(), [
            'payslip_id' => $slip->id,
            'cashbox_doc_num' => $fixture['cashbox']->doc_num,
            'amount' => $expected,
            'payment_date' => '2026-09-17',
            'idempotency_key' => (string) Str::uuid(),
        ],
    );
    app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $payment['voucher'], $fixture['company']->getKey());
    $reconciled = app(PayrollReconciliationService::class)->forRun(
        $calculated['run_id'], $fixture['company']->getKey(), '2026-09-30',
    );
    expect($reconciled['status'])->toBe('matched')
        ->and($reconciled['summary']['paid'])->toBe($expected)
        ->and($reconciled['summary']['remaining'])->toBe('0.0000');
})->with([
    'weekly' => ['weekly_wage', 'weekly_wage', '700.0000', '140.0000'],
    'daily' => ['daily_wage', 'daily_wage', '300.0000', '300.0000'],
    'hourly' => ['hourly_wage', 'hourly_wage', '50.0000', '400.0000'],
    'shift' => ['shift_wage', 'shift_wage', '600.0000', '600.0000'],
]);

test('payroll calculation endpoint snapshots a selected manual deduction without posting it', function (): void {
    $fixture = payrollFinancialFixture();
    $actor = payrollFinancialActor([
        'hr.payroll_preparation.view',
        'hr.payroll_preparation.calculate',
    ]);
    $session = payrollFinancialContext($fixture);

    DB::table('hr_payroll_items')->insert([
        [
            'code' => 'INACTIVE-MANUAL-DEDUCTION',
            'name' => 'Inactive Manual Deduction',
            'item_kind' => 'deduction',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'code' => 'ACTIVE-EARNING-ONLY',
            'name' => 'Active Earning Only',
            'item_kind' => 'earning',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $oldAdjustments = [[
        'employee_doc_num' => $fixture['employee']->doc_num,
        'deductions' => [[
            'payroll_item_code' => 'PAYROLL-TAX',
            'amount' => '275.1250',
            'reference' => 'Manual tax correction',
        ]],
    ]];
    $this->actingAs($actor)->withSession([...$session, '_old_input' => ['adjustments' => $oldAdjustments]])
        ->get(route('admin.hr.payroll-preparation.index'))
        ->assertOk()
        ->assertSee('value="PAYROLL-TAX"', false)
        ->assertDontSee('INACTIVE-MANUAL-DEDUCTION')
        ->assertDontSee('ACTIVE-EARNING-ONLY')
        ->assertSee('data-manual-deduction-row', false)
        ->assertSee('"employee_doc_num":"'.$fixture['employee']->doc_num.'"', false)
        ->assertSee('"payroll_item_code":"PAYROLL-TAX"', false)
        ->assertSee('"amount":"275.1250"', false);

    $response = $this->withSession($session)
        ->postJson(route('admin.hr.payroll-runs.calculate'), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'branch_doc_num' => $fixture['branch']->doc_num,
            'adjustments' => [[
                'employee_doc_num' => $fixture['employee']->doc_num,
                'deductions' => [[
                    'payroll_item_code' => 'PAYROLL-TAX',
                    'amount' => '275.1250',
                    'reference' => 'Manual tax correction',
                ], [
                    'payroll_item_code' => 'SALARY-ADVANCE',
                    'amount' => '24.8750',
                    'reference' => 'Manual advance correction',
                ]],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.deductions', '300.0000');

    $runId = (int) $response->json('data.run_id');
    $payslipItem = DB::table('hr_payslip_items as payslip_item')
        ->join('hr_payroll_items as payroll_item', 'payroll_item.id', '=', 'payslip_item.payroll_item_id')
        ->where('source_type', 'manual_deduction')
        ->where('payroll_item.code', 'PAYROLL-TAX')
        ->select('payslip_item.*')
        ->sole();
    $inputSnapshot = json_decode(
        (string) DB::table('hr_payroll_inputs')->where('payroll_run_id', $runId)->value('payload'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect(bccomp((string) $payslipItem->amount, '275.1250', 4))->toBe(0)
        ->and(json_decode($payslipItem->source_snapshot, true, 512, JSON_THROW_ON_ERROR))->toBe([
            'reference' => 'Manual tax correction',
        ])
        ->and(data_get($inputSnapshot, 'manual_adjustments.employee_doc_num'))->toBe($fixture['employee']->doc_num)
        ->and(data_get($inputSnapshot, 'manual_adjustments.deductions.0'))->toMatchArray([
            'payroll_item_code' => 'PAYROLL-TAX',
            'amount' => '275.1250',
            'reference' => 'Manual tax correction',
        ])
        ->and(data_get($inputSnapshot, 'manual_adjustments.deductions.1'))->toMatchArray([
            'payroll_item_code' => 'SALARY-ADVANCE',
            'amount' => '24.8750',
            'reference' => 'Manual advance correction',
        ])
        ->and(DB::table('hr_payslip_items')->where('source_type', 'manual_deduction')->count())->toBe(2)
        ->and(DB::table('journal_entries')->where('source_type', 'hr_payroll_run')->where('source_id', $runId)->exists())->toBeFalse();
});

test('payroll calculators can select only active employees in their allowed company and branch scope', function (): void {
    $fixture = payrollFinancialFixture();
    $otherBranch = Branch::query()->create([
        'doc_number' => 7002,
        'doc_num' => 'PAY-BR-07002',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Other Payroll Branch',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $outsideEmployee = $fixture['employee']->replicate();
    $outsideEmployee->forceFill([
        'doc_number' => 7002,
        'doc_num' => 'PAY-EMP-07002',
        'employee_code' => 'PAY-E002',
        'full_name' => 'Outside Payroll Employee',
        'name' => 'Outside Payroll Employee',
        'branch_id' => $otherBranch->getKey(),
        'public_uuid' => (string) Str::uuid(),
    ])->save();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.payroll_preparation.calculate', 'web');
    Permission::findOrCreate('hr.payroll_preparation.view', 'web');
    $calculator = User::factory()->create();
    $calculatorRole = Role::query()->create([
        'name' => 'Scoped Payroll Calculator '.Str::random(8),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => false,
    ]);
    $calculatorRole->givePermissionTo('hr.payroll_preparation.calculate');
    $calculatorRole->companyAccessCompanies()->sync([$fixture['company']->getKey()]);
    $calculatorRole->branchAccessBranches()->sync([$fixture['branch']->getKey()]);
    $calculator->assignRole($calculatorRole);
    $session = payrollFinancialContext($fixture);

    $response = $this->actingAs($calculator)->withSession($session)
        ->getJson(route('admin.hr.select2.employees', [
            'identity' => 'doc_num',
            'q' => 'PAY-EMP',
            'per_page' => 50,
        ]))
        ->assertOk();

    expect(collect($response->json('results'))->pluck('id')->all())
        ->toContain($fixture['employee']->doc_num)
        ->not->toContain($outsideEmployee->doc_num);

    $viewer = payrollFinancialActor(['hr.payroll_preparation.view']);
    $this->actingAs($viewer)->withSession($session)
        ->getJson(route('admin.hr.select2.employees', ['identity' => 'doc_num']))
        ->assertForbidden();
});

test('a restricted calculator can adjust an employee assigned to its branch during the pay period after a later transfer', function (): void {
    $fixture = payrollFinancialFixture();
    $destination = Branch::query()->create([
        'doc_number' => 7002, 'doc_num' => 'PAY-BR-07002',
        'company_id' => $fixture['company']->getKey(), 'name' => 'Later payroll branch',
        'type' => Branch::TypeFactory, 'status' => 'active',
    ]);
    $fixture['employee']->update(['branch_id' => $destination->getKey()]);
    DB::table('hr_employee_organization_assignments')->insert([
        [
            'company_id' => $fixture['company']->getKey(), 'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $fixture['branch']->getKey(), 'department_id' => $fixture['employee']->department_id,
            'effective_from' => '2026-01-01', 'effective_to' => '2026-09-30',
            'source_type' => 'initial_verified', 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'company_id' => $fixture['company']->getKey(), 'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $destination->getKey(), 'department_id' => $fixture['employee']->department_id,
            'effective_from' => '2026-10-01', 'effective_to' => null,
            'source_type' => 'transfer', 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['hr.payroll_preparation.calculate', 'hr.payroll_preparation.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $role = Role::query()->create([
        'name' => 'Dated Payroll Branch '.Str::random(8), 'guard_name' => 'web',
        'company_access_restricted' => true, 'branch_access_restricted' => true,
        'financial_period_access_restricted' => false,
    ]);
    $role->givePermissionTo(['hr.payroll_preparation.calculate', 'hr.payroll_preparation.view']);
    $role->companyAccessCompanies()->sync([$fixture['company']->getKey()]);
    $role->branchAccessBranches()->sync([$fixture['branch']->getKey()]);
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $session = payrollFinancialContext($fixture);

    $this->actingAs($actor)->withSession($session)
        ->getJson(route('admin.hr.select2.employees', [
            'identity' => 'doc_num', 'purpose' => 'payroll',
            'payroll_period_start' => '2026-09-01', 'payroll_period_end' => '2026-09-30',
            'payroll_branch_doc_num' => $fixture['branch']->doc_num, 'q' => $fixture['employee']->doc_num,
        ]))->assertOk()->assertJsonFragment(['id' => $fixture['employee']->doc_num]);
    $this->withSession($session)
        ->getJson(route('admin.hr.select2.employees', [
            'identity' => 'doc_num', 'purpose' => 'payroll',
            'payroll_period_start' => '2026-10-01', 'payroll_period_end' => '2026-10-31',
            'payroll_branch_doc_num' => $fixture['branch']->doc_num, 'q' => $fixture['employee']->doc_num,
        ]))->assertOk()->assertJsonMissing(['id' => $fixture['employee']->doc_num]);
    $this->withSession($session)->postJson(route('admin.hr.payroll-runs.calculate'), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
        'adjustments' => [[
            'employee_doc_num' => $fixture['employee']->doc_num,
            'deductions' => [[
                'payroll_item_code' => 'PAYROLL-TAX', 'amount' => '100.0000',
            ]],
        ]],
    ])->assertOk()->assertJsonPath('data.deductions', '100.0000');

    $fixture['employee']->update(['status' => 'left', 'termination_date' => '2026-09-30']);
    $this->withSession($session)
        ->getJson(route('admin.hr.select2.employees', [
            'identity' => 'doc_num', 'purpose' => 'payroll',
            'payroll_period_start' => '2026-09-01', 'payroll_period_end' => '2026-09-30',
            'payroll_branch_doc_num' => $fixture['branch']->doc_num, 'q' => $fixture['employee']->doc_num,
        ]))->assertOk()->assertJsonFragment(['id' => $fixture['employee']->doc_num]);
});

test('payroll rejects deductions for employees outside the selected pay period instead of omitting them', function (): void {
    $fixture = payrollFinancialFixture();
    $futureEmployee = $fixture['employee']->replicate();
    $futureEmployee->forceFill([
        'doc_number' => 7003,
        'doc_num' => 'PAY-EMP-07003',
        'employee_code' => 'PAY-E003',
        'full_name' => 'Future Payroll Employee',
        'name' => 'Future Payroll Employee',
        'hire_date' => '2026-10-01',
        'contract_start_date' => '2026-10-01',
        'public_uuid' => (string) Str::uuid(),
    ])->save();
    $actor = payrollFinancialActor(['hr.payroll_preparation.calculate']);
    $session = payrollFinancialContext($fixture);
    $this->actingAs($actor)->withSession($session)
        ->getJson(route('admin.hr.select2.employees', [
            'identity' => 'doc_num',
            'purpose' => 'payroll',
            'payroll_period_start' => '2026-09-01',
            'payroll_period_end' => '2026-09-30',
            'payroll_branch_doc_num' => $fixture['branch']->doc_num,
            'q' => 'PAY-EMP',
        ]))
        ->assertOk()
        ->assertJsonMissing(['id' => $futureEmployee->doc_num]);

    $payload = [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
        'adjustments' => [[
            'employee_doc_num' => $futureEmployee->doc_num,
            'deductions' => [['payroll_item_code' => 'PAYROLL-TAX', 'amount' => '10.0000']],
        ]],
    ];
    $this->withSession($session)
        ->postJson(route('admin.hr.payroll-runs.calculate'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('adjustments');
    expect(fn (): array => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), $payload))
        ->toThrow(DomainException::class);
    expect(DB::table('hr_payslips')->count())->toBe(0);
});

test('payroll deduction amounts above four decimal places fail request validation', function (): void {
    $fixture = payrollFinancialFixture();
    $actor = payrollFinancialActor(['hr.payroll_preparation.calculate']);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture))
        ->postJson(route('admin.hr.payroll-runs.calculate'), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'branch_doc_num' => $fixture['branch']->doc_num,
            'adjustments' => [[
                'employee_doc_num' => $fixture['employee']->doc_num,
                'deductions' => [['payroll_item_code' => 'PAYROLL-TAX', 'amount' => '1.00001']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('adjustments.0.deductions.0.amount');
    expect(DB::table('hr_payslips')->count())->toBe(0);
});

test('payroll reports exports and payslips use persisted snapshots with branch and employee isolation', function (): void {
    $fixture = payrollFinancialFixture();
    $workflowActor = User::factory()->create();
    $this->actingAs($workflowActor)->withSession(payrollFinancialContext($fixture));
    $employeeUser = User::factory()->create();
    $fixture['employee']->update(['user_id' => $employeeUser->getKey()]);
    $calculated = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    app(PayrollLifecycleService::class)->submitForReview($calculated['run_id'], $fixture['company']->getKey());
    app(PayrollLifecycleService::class)->approve($calculated['run_id'], $fixture['company']->getKey());
    $payment = app(PayrollPaymentService::class)->createCashPayment($calculated['run_id'], $fixture['company']->getKey(), [
        'cashbox_doc_num' => $fixture['cashbox']->doc_num,
        'amount' => '1000.0000',
        'payment_date' => '2026-09-17',
        'idempotency_key' => (string) Str::uuid(),
        'reference' => 'Presentation report payment',
    ]);
    app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $payment['voucher'], $fixture['company']->getKey());
    $payslip = DB::table('hr_payslips')->where('payroll_run_id', $calculated['run_id'])->first();
    $foreignCurrency = Currency::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 7002, 'doc_num' => 'PAY-CUR-07002',
        'name' => 'US Dollar', 'code' => 'USD', 'minor_unit_name' => 'Cent', 'minor_unit_factor' => 100,
        'is_main' => false, 'status' => 'active',
    ]);
    $foreignCurrencyEmployee = HrEmployee::query()->create([
        'doc_number' => 7003, 'doc_num' => 'PAY-EMP-07003', 'employee_code' => 'PAY-E003',
        'full_name' => 'Foreign Currency Employee', 'name' => 'Foreign Currency Employee', 'person_type' => 'fixed_employee',
        'status' => 'active', 'company_id' => $fixture['company']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'hire_date' => '2026-01-01', 'pay_basis' => 'monthly_salary', 'payroll_currency_id' => $foreignCurrency->getKey(),
    ]);
    DB::table('hr_payslips')->insert([
        'payroll_run_id' => $calculated['run_id'], 'employee_id' => $foreignCurrencyEmployee->getKey(),
        'company_id' => $fixture['company']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'currency_id' => $foreignCurrency->getKey(), 'employee_doc_num' => $foreignCurrencyEmployee->doc_num,
        'employee_name' => $foreignCurrencyEmployee->full_name, 'gross_amount' => 100, 'deduction_amount' => 10,
        'net_amount' => 90, 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $fixture['period']->update(['is_closed' => true]);

    $otherBranch = Branch::query()->create([
        'doc_number' => 7002, 'doc_num' => 'PAY-BR-07002', 'company_id' => $fixture['company']->getKey(),
        'name' => 'Other Payroll Branch', 'type' => Branch::TypeFactory, 'status' => 'active',
    ]);
    $otherUser = User::factory()->create();
    $otherEmployee = HrEmployee::query()->create([
        'doc_number' => 7002, 'doc_num' => 'PAY-EMP-07002', 'employee_code' => 'PAY-E002',
        'full_name' => 'Other Payroll Employee', 'name' => 'Other Payroll Employee', 'person_type' => 'fixed_employee',
        'status' => 'active', 'company_id' => $fixture['company']->getKey(), 'branch_id' => $otherBranch->getKey(),
        'user_id' => $otherUser->getKey(), 'hire_date' => '2026-01-01', 'pay_basis' => 'monthly_salary',
    ]);
    $otherPeriod = DB::table('hr_payroll_periods')->insertGetId([
        'company_id' => $fixture['company']->getKey(), 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        'status' => 'closed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherRun = DB::table('hr_payroll_runs')->insertGetId([
        'payroll_period_id' => $otherPeriod, 'branch_id' => $otherBranch->getKey(), 'status' => 'posted',
        'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherPayslip = DB::table('hr_payslips')->insertGetId([
        'payroll_run_id' => $otherRun, 'employee_id' => $otherEmployee->getKey(), 'company_id' => $fixture['company']->getKey(),
        'branch_id' => $otherBranch->getKey(), 'currency_id' => $fixture['currency']->getKey(),
        'employee_doc_num' => $otherEmployee->doc_num, 'employee_name' => $otherEmployee->full_name,
        'gross_amount' => 5000, 'deduction_amount' => 500, 'net_amount' => 4500, 'status' => 'posted',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignCompany = Company::query()->create([
        'doc_number' => 7099, 'doc_num' => 'PAY-COMP-07099', 'name' => 'Foreign Payroll Company',
        'legal_name' => 'Foreign Payroll Company', 'status' => 'active', 'is_main' => false, 'country' => 'Egypt',
    ]);
    $foreignBranch = Branch::query()->create([
        'doc_number' => 7099, 'doc_num' => 'PAY-BR-07099', 'company_id' => $foreignCompany->getKey(),
        'name' => 'Foreign Payroll Branch', 'type' => Branch::TypeFactory, 'status' => 'active',
    ]);
    $foreignEmployee = HrEmployee::query()->create([
        'doc_number' => 7099, 'doc_num' => 'PAY-EMP-07099', 'employee_code' => 'PAY-E099',
        'full_name' => 'Foreign Payroll Employee', 'name' => 'Foreign Payroll Employee', 'person_type' => 'fixed_employee',
        'status' => 'active', 'company_id' => $foreignCompany->getKey(), 'branch_id' => $foreignBranch->getKey(),
        'hire_date' => '2026-01-01', 'pay_basis' => 'monthly_salary',
    ]);
    $foreignPeriod = DB::table('hr_payroll_periods')->insertGetId([
        'company_id' => $foreignCompany->getKey(), 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'status' => 'closed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignRun = DB::table('hr_payroll_runs')->insertGetId([
        'payroll_period_id' => $foreignPeriod, 'branch_id' => $foreignBranch->getKey(), 'status' => 'posted',
        'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignPayslip = DB::table('hr_payslips')->insertGetId([
        'payroll_run_id' => $foreignRun, 'employee_id' => $foreignEmployee->getKey(), 'company_id' => $foreignCompany->getKey(),
        'branch_id' => $foreignBranch->getKey(), 'currency_id' => null,
        'employee_doc_num' => $foreignEmployee->doc_num, 'employee_name' => $foreignEmployee->full_name,
        'gross_amount' => 8000, 'deduction_amount' => 1000, 'net_amount' => 7000, 'status' => 'posted',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permissionNames = [
        'hr.payslips.view', 'hr.payroll_reports.view', 'hr.payroll_reports.export',
        'hr.payroll_payment_reports.view', 'hr.payroll_payment_reports.export',
        'hr.payroll_reconciliation.view', 'hr.payroll_preparation.view',
        'cash_payment_vouchers.view', 'cash_payment_vouchers.edit',
        'cash_payment_vouchers.approve', 'cash_payment_vouchers.cancel',
    ];
    foreach ($permissionNames as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $reviewer = User::factory()->create();
    $role = Role::query()->create([
        'name' => 'Payroll Reports '.Str::random(8), 'guard_name' => 'web',
        'company_access_restricted' => true, 'branch_access_restricted' => true, 'financial_period_access_restricted' => false,
    ]);
    $role->givePermissionTo($permissionNames);
    $role->companyAccessCompanies()->sync([$fixture['company']->getKey()]);
    $role->branchAccessBranches()->sync([$fixture['branch']->getKey()]);
    $reviewer->assignRole($role);
    $session = payrollFinancialContext($fixture);
    $unauthorized = User::factory()->create();
    $this->actingAs($unauthorized)->withSession($session)
        ->get(route('admin.hr.reports.payroll'))->assertForbidden();
    $this->get(route('admin.hr.reports.payroll.export', ['format' => 'csv']))->assertForbidden();
    $this->get(route('admin.hr.payslips.show', $payslip->id))->assertForbidden();

    $this->actingAs($reviewer)->withSession($session)
        ->get(route('admin.hr.reports.payroll'))
        ->assertOk()->assertSee($fixture['employee']->full_name)->assertDontSee($otherEmployee->full_name)
        ->assertDontSee($foreignEmployee->full_name)
        ->assertSee($foreignCurrencyEmployee->full_name)
        ->assertSee('EGP')->assertSee('USD')
        ->assertSee(app(NumericFormatService::class)->format($payslip->net_amount))
        ->assertDontSee(number_format((float) $payslip->net_amount, 2));
    $this->withSession($session)->get(route('admin.hr.reports.payments'))
        ->assertOk()->assertSee($payment['voucher']->doc_num)->assertSee('EGP');
    $this->withSession($session)->get(route('admin.hr.payslips.show', $payslip->id))
        ->assertOk()->assertSee($fixture['employee']->full_name)->assertSee(__('hr_payroll_reports.payslip.items'))
        ->assertDontSee('Policy Snapshots')->assertDontSee('Record Ids')->assertDontSee('employee_master')
        ->assertDontSee('BASIC')
        ->assertSee(app(NumericFormatService::class)->format($payslip->net_amount))
        ->assertDontSee(number_format((float) $payslip->net_amount, 2));
    $this->withSession($session)->get(route('admin.hr.payslips.print', $payslip->id))
        ->assertOk()->assertSee('window.print()', false)->assertDontSee('Policy Snapshots')->assertDontSee('employee_master');
    $this->withSession($session)->get(route('admin.hr.payslips.show', $otherPayslip))->assertNotFound();
    $this->withSession($session)->get(route('admin.hr.payslips.show', $foreignPayslip))->assertNotFound();

    $originalPayslipAmounts = [
        'gross_amount' => (string) $payslip->gross_amount,
        'deduction_amount' => (string) $payslip->deduction_amount,
        'net_amount' => (string) $payslip->net_amount,
    ];
    $largeAmount = DB::getDriverName() === 'sqlite' ? '123456789.1234' : '99999999999999.9999';
    $largeNetAmount = DB::getDriverName() === 'sqlite' ? '123456789.1233' : '99999999999999.9998';
    $largeFormattedAmount = app(NumericFormatService::class)->format($largeAmount);
    $largeFormattedNetAmount = app(NumericFormatService::class)->format($largeNetAmount);
    DB::table('hr_payslips')->where('id', $payslip->id)->update([
        'gross_amount' => $largeAmount,
        'deduction_amount' => '0.0001',
        'net_amount' => $largeNetAmount,
    ]);
    DB::table('hr_payroll_payments')->where('id', $payment['payment']->id)->update([
        'amount' => $largeAmount,
    ]);

    $this->withSession($session)->get(route('admin.hr.payroll-preparation.index', ['run' => $calculated['run_id']]))
        ->assertOk()
        ->assertSee($largeFormattedAmount);
    $this->withSession($session)->get(route('admin.hr.reports.payroll'))
        ->assertOk()
        ->assertSee($largeFormattedAmount)
        ->assertSee($largeFormattedNetAmount);
    $this->withSession($session)->get(route('admin.hr.reports.payments'))
        ->assertOk()
        ->assertSee($largeFormattedAmount);
    $this->withSession($session)->get(route('admin.hr.payslips.show', $payslip->id))
        ->assertOk()
        ->assertSee($largeFormattedAmount)
        ->assertSee($largeFormattedNetAmount);

    $csv = $this->withSession($session)->get(route('admin.hr.reports.payroll.export', ['format' => 'csv']));
    $csv->assertOk();
    expect($csv->headers->get('content-disposition'))->toContain('payroll-report.csv');
    $csvContents = file_get_contents($csv->baseResponse->getFile()->getPathname());
    $periodLabel = app(DateFormatService::class)->formatDate('2026-09-01').' — '.app(DateFormatService::class)->formatDate('2026-09-30');
    expect($csvContents)->toContain('EGP')->toContain('USD')->toContain($largeAmount)->toContain($largeNetAmount)
        ->toContain($periodLabel)->not->toContain('2026-09-01 00:00:00');
    $payrollXlsx = $this->withSession($session)->get(route('admin.hr.reports.payroll.export', ['format' => 'xlsx']));
    $payrollXlsx->assertOk()->assertDownload('payroll-report.xlsx');
    $payrollSheet = IOFactory::load($payrollXlsx->baseResponse->getFile()->getPathname())->getActiveSheet();
    $largeAmountCell = collect($payrollSheet->getCellCollection()->getCoordinates())
        ->map(fn (string $coordinate) => $payrollSheet->getCell($coordinate))
        ->first(fn ($cell): bool => $cell->getValue() === $largeAmount);
    expect($largeAmountCell)->not->toBeNull()
        ->and($largeAmountCell->getDataType())->toBe(DataType::TYPE_STRING)
        ->and(collect($payrollSheet->toArray())->flatten()->all())->toContain($largeNetAmount, $periodLabel);

    $paymentCsv = $this->withSession($session)->get(route('admin.hr.reports.payments.export', ['format' => 'csv']));
    $paymentCsv->assertOk()->assertDownload('payroll-payment-report.csv');
    expect(file_get_contents($paymentCsv->baseResponse->getFile()->getPathname()))
        ->toContain($largeAmount, $periodLabel, app(DateFormatService::class)->formatDate('2026-09-17'))
        ->not->toContain('2026-09-17 00:00:00');
    $paymentXlsx = $this->withSession($session)->get(route('admin.hr.reports.payments.export', ['format' => 'xlsx']));
    $paymentXlsx->assertOk()->assertDownload('payroll-payment-report.xlsx');
    $paymentSheet = IOFactory::load($paymentXlsx->baseResponse->getFile()->getPathname())->getActiveSheet();
    $largePaymentCell = collect($paymentSheet->getCellCollection()->getCoordinates())
        ->map(fn (string $coordinate) => $paymentSheet->getCell($coordinate))
        ->first(fn ($cell): bool => $cell->getValue() === $largeAmount);
    expect($largePaymentCell)->not->toBeNull()
        ->and($largePaymentCell->getDataType())->toBe(DataType::TYPE_STRING);

    foreach (['en', 'ar'] as $locale) {
        foreach (['payroll', 'payments'] as $reportType) {
            $reviewer->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $response = $this->withSession([...$session, 'locale' => $locale])
                ->get(route('admin.hr.reports.'.$reportType.'.export', ['format' => 'pdf']));
            $response->assertOk()->assertHeader('content-type', 'application/pdf');
            $pdfContent = $response->getContent();
            expect(str_starts_with($pdfContent, '%PDF-'))->toBeTrue();
            if ($directory = getenv('MGYPACK_REPORT_PRINT_SAMPLES')) {
                file_put_contents($directory.'/hr-'.$reportType.'-'.$locale.'.pdf', $pdfContent);
            }
            $extract = new Process(['pdftotext', '-layout', '-', '-']);
            $extract->setInput($pdfContent);
            $extract->run();
            expect($extract->isSuccessful())->toBeTrue()
                ->and($extract->getOutput())->toContain($largeFormattedAmount)
                ->toContain(app(DateFormatService::class)->formatDate('2026-09-01'))
                ->toContain(app(DateFormatService::class)->formatDate('2026-09-30'))
                ->not->toContain('2026-09-01 00:00:00');
        }
    }

    $reportPdf = Mockery::mock(ReportPdfService::class);
    $reportPdf->shouldReceive('stream')->twice()->withArgs(function (string $view, array $data, string $filename, string $orientation) use ($largeAmount, $largeNetAmount): bool {
        $values = collect($data['rows'])->flatten();

        return $view === 'reports.hr.payroll'
            && $orientation === 'L'
            && ($filename === 'payroll-report.pdf'
                ? $values->contains($largeAmount) && $values->contains($largeNetAmount)
                : $filename === 'payroll-payment-report.pdf' && $values->contains($largeAmount));
    })->andReturn(response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']));
    $this->app->instance(ReportPdfService::class, $reportPdf);
    $this->withSession($session)->get(route('admin.hr.reports.payroll.export', ['format' => 'pdf']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->withSession($session)->get(route('admin.hr.reports.payments.export', ['format' => 'pdf']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->withSession($session)->get(route('admin.hr.reports.payroll.export'))->assertUnprocessable();

    $currencyTotals = collect(app(PayrollReportService::class)->payroll($fixture['company']->getKey(), $reviewer, [])['totals'])
        ->keyBy('currency_code');
    expect($currencyTotals)->toHaveCount(2)
        ->and($currencyTotals['EGP']['gross'])->toBe($largeAmount)
        ->and($currencyTotals['EGP']['deductions'])->toBe('0.0001')
        ->and($currencyTotals['EGP']['net'])->toBe($largeNetAmount)
        ->and($currencyTotals['USD']['gross'])->toBe('100.0000')
        ->and($currencyTotals['USD']['deductions'])->toBe('10.0000')
        ->and($currencyTotals['USD']['net'])->toBe('90.0000');
    expect(app(PayrollReportService::class)->payments($fixture['company']->getKey(), $reviewer, [])['totals'])
        ->toMatchArray([
            'amount' => $largeAmount,
            'approved' => $largeAmount,
            'cancelled' => '0.0000',
        ]);

    DB::table('hr_payslips')->where('id', $payslip->id)->update($originalPayslipAmounts);
    DB::table('hr_payroll_payments')->where('id', $payment['payment']->id)->update(['amount' => '1000.0000']);

    $outsidePaymentPeriod = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 7002,
        'doc_num' => 'PAY-FP-07002',
        'name' => '2027',
        'from_date' => '2027-01-01',
        'to_date' => '2027-12-31',
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);
    DB::table('hr_payroll_payments')->where('id', $payment['payment']->id)->update([
        'financial_period_id' => $outsidePaymentPeriod->getKey(),
    ]);

    $periodRestrictedRole = Role::query()->create([
        'name' => 'Period Scoped Payroll Reports '.Str::random(8), 'guard_name' => 'web',
        'company_access_restricted' => true, 'branch_access_restricted' => true, 'financial_period_access_restricted' => true,
    ]);
    $periodRestrictedRole->givePermissionTo($permissionNames);
    $periodRestrictedRole->companyAccessCompanies()->sync([$fixture['company']->getKey()]);
    $periodRestrictedRole->branchAccessBranches()->sync([$fixture['branch']->getKey()]);
    $periodRestrictedReviewer = User::factory()->create();
    $periodRestrictedReviewer->assignRole($periodRestrictedRole);
    $this->actingAs($periodRestrictedReviewer)->withSession($session)
        ->get(route('admin.hr.reports.payments'))
        ->assertOk()->assertDontSee($payment['voucher']->doc_num);
    $this->withSession($session)->get(route('admin.hr.reports.payroll'))
        ->assertOk()->assertDontSee($fixture['employee']->full_name);
    expect(app(PayrollReportService::class)->paymentRows($fixture['company']->getKey(), $periodRestrictedReviewer, []))->toBeEmpty();
    $this->withSession($session)->get(route('admin.hr.payslips.show', $payslip->id))
        ->assertNotFound();
    $restrictedPaymentCsv = $this->withSession($session)->get(route('admin.hr.reports.payments.export', ['format' => 'csv']));
    $restrictedPaymentCsv->assertOk();
    expect(file_get_contents($restrictedPaymentCsv->baseResponse->getFile()->getPathname()))
        ->not->toContain($payment['voucher']->doc_num);

    $periodRestrictedRole->financialPeriodAccessPeriods()->sync([$outsidePaymentPeriod->getKey()]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->withSession($session)->get(route('admin.hr.reports.payments'))
        ->assertOk()->assertDontSee($payment['voucher']->doc_num);

    $periodRestrictedRole->financialPeriodAccessPeriods()->sync([$fixture['period']->getKey()]);
    $this->withSession($session)->get(route('admin.hr.reports.payments'))
        ->assertOk()->assertDontSee($payment['voucher']->doc_num);
    $this->withSession($session)->get(route('admin.hr.payslips.show', $payslip->id))
        ->assertOk()->assertDontSee($payment['voucher']->doc_num);
    $this->withSession($session)->get(route('admin.finance.cash-payment-vouchers.show', $payment['voucher']->doc_num))->assertNotFound();
    $this->withSession($session)->get(route('admin.finance.cash-payment-vouchers.edit', $payment['voucher']->doc_num))->assertNotFound();
    $this->withSession($session)->postJson(route('admin.finance.cash-payment-vouchers.approve', $payment['voucher']->doc_num))->assertNotFound();
    $this->withSession($session)->postJson(route('admin.finance.cash-payment-vouchers.cancel', $payment['voucher']->doc_num), [
        'cancel_reason' => 'Forbidden payroll payment period',
    ])->assertNotFound();
    $restrictedReconciliation = $this->withSession($session)
        ->getJson(route('admin.hr.payroll-runs.reconciliation', $calculated['run_id']))
        ->assertOk()
        ->assertJsonCount(0, 'data.payments');
    expect($restrictedReconciliation->json('data.summary.paid'))->toBe('0.0000');

    $periodRestrictedRole->financialPeriodAccessPeriods()->sync([$fixture['period']->getKey(), $outsidePaymentPeriod->getKey()]);
    $this->withSession($session)->get(route('admin.hr.reports.payments'))
        ->assertOk()->assertSee($payment['voucher']->doc_num);
    $this->withSession($session)->get(route('admin.hr.payslips.show', $payslip->id))
        ->assertOk()->assertSee($payment['voucher']->doc_num);
    $this->withSession($session)->get(route('admin.finance.cash-payment-vouchers.show', $payment['voucher']->doc_num))->assertOk();
    $this->withSession($session)->getJson(route('admin.hr.payroll-runs.reconciliation', $calculated['run_id']))
        ->assertOk()
        ->assertJsonPath('data.summary.paid', '1000.0000')
        ->assertJsonCount(1, 'data.payments');

    $fixture['period']->update(['is_closed' => false]);
    app(CashVoucherService::class)->cancel(
        CashVoucher::TypePayment,
        $payment['voucher']->refresh(),
        'Cross-period reversal visibility regression',
    );
    $fixture['period']->update(['is_closed' => true]);
    $reversalPeriod = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 7003,
        'doc_num' => 'PAY-FP-07003',
        'name' => '2028',
        'from_date' => '2028-01-01',
        'to_date' => '2028-12-31',
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);
    $cancelledPayment = DB::table('hr_payroll_payments')->where('id', $payment['payment']->id)->firstOrFail();
    $reversalJournalDocNum = JournalEntry::query()->whereKey($cancelledPayment->reversal_journal_entry_id)->value('doc_num');
    JournalEntry::query()->whereKey($cancelledPayment->reversal_journal_entry_id)->update([
        'financial_period_id' => $reversalPeriod->getKey(),
    ]);

    $maskedPayment = app(PayrollReportService::class)
        ->paymentRows($fixture['company']->getKey(), $periodRestrictedReviewer, [])
        ->sole();
    expect($maskedPayment->status)->toBe('approved')
        ->and($maskedPayment->cancelled_at)->toBeNull()
        ->and($maskedPayment->reversal_journal_doc_num)->toBeNull();
    expect(app(PayrollReportService::class)->payments($fixture['company']->getKey(), $periodRestrictedReviewer, [])['totals'])
        ->toMatchArray(['amount' => '1000.0000', 'approved' => '1000.0000', 'cancelled' => '0.0000']);
    $this->withSession($session)->get(route('admin.hr.reports.payments'))
        ->assertOk()
        ->assertDontSee($reversalJournalDocNum);
    $maskedReconciliation = $this->withSession($session)
        ->getJson(route('admin.hr.payroll-runs.reconciliation', $calculated['run_id']))
        ->assertOk()
        ->assertJsonPath('data.summary.paid', '1000.0000')
        ->assertJsonPath('data.summary.cash_bank_effect', '1000.0000')
        ->assertJsonPath('data.payments.0.status', 'approved')
        ->assertJsonPath('data.payments.0.cancelled_at', null)
        ->assertJsonPath('data.payments.0.reversal_journal_doc_num', null);

    $reversalVisibleRole = Role::query()->create([
        'name' => 'Reversal Period Payroll Reports '.Str::random(8),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
    ]);
    $reversalVisibleRole->givePermissionTo($permissionNames);
    $reversalVisibleRole->companyAccessCompanies()->sync([$fixture['company']->getKey()]);
    $reversalVisibleRole->branchAccessBranches()->sync([$fixture['branch']->getKey()]);
    $reversalVisibleRole->financialPeriodAccessPeriods()->sync([
        $fixture['period']->getKey(),
        $outsidePaymentPeriod->getKey(),
        $reversalPeriod->getKey(),
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $reversalVisibleReviewer = User::factory()->create();
    $reversalVisibleReviewer->assignRole($reversalVisibleRole);
    $visiblePayment = app(PayrollReportService::class)
        ->paymentRows($fixture['company']->getKey(), $reversalVisibleReviewer, [])
        ->sole();
    expect($visiblePayment->status)->toBe('cancelled')
        ->and($visiblePayment->cancelled_at)->not->toBeNull()
        ->and($visiblePayment->reversal_journal_doc_num)->toBe($reversalJournalDocNum);
    expect(app(PayrollReportService::class)->payments($fixture['company']->getKey(), $reversalVisibleReviewer, [])['totals'])
        ->toMatchArray(['amount' => '1000.0000', 'approved' => '0.0000', 'cancelled' => '1000.0000']);
    $this->actingAs($reversalVisibleReviewer)->withSession($session)
        ->getJson(route('admin.hr.payroll-runs.reconciliation', $calculated['run_id']))
        ->assertOk()
        ->assertJsonPath('data.summary.paid', '0.0000')
        ->assertJsonPath('data.summary.cash_bank_effect', '0.0000')
        ->assertJsonPath('data.payments.0.status', 'cancelled')
        ->assertJsonPath('data.payments.0.reversal_journal_doc_num', $reversalJournalDocNum);

    $this->actingAs($employeeUser)->get(route('employee.hr.payslips.show', $payslip->id))
        ->assertOk()->assertSee($fixture['employee']->full_name)
        ->assertDontSee($payment['voucher']->doc_num)
        ->assertDontSee(__('hr_payroll_reports.payslip.payment_references'));
    $this->get(route('employee.hr.payslips.show', $otherPayslip))->assertNotFound();

    $pdf = Mockery::mock(ReportPdfService::class);
    $pdf->shouldReceive('stream')->once()->withArgs(function (string $view, array $data, string $filename) use ($fixture, $payment): bool {
        expect($view)->toBe('reports.hr.payslip')
            ->and($filename)->toStartWith('payslip-')
            ->and($data['companyPrintIdentity']['company_id'])->toBe($fixture['company']->getKey())
            ->and($data['companyPrintIdentity']['name'])->toBe($fixture['company']->name)
            ->and($data['showRunPayments'])->toBeFalse()
            ->and($data['payments'])->toBeEmpty()
            ->and(collect($data['payments'])->pluck('voucher_doc_num'))->not->toContain($payment['voucher']->doc_num);

        return true;
    })->andReturn(response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']));
    $this->app->instance(ReportPdfService::class, $pdf);
    $this->flushSession();
    $this->actingAs($employeeUser)->get(route('employee.hr.payslips.pdf', $payslip->id))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

test('payroll endpoints enforce permissions company isolation and localized HR navigation', function (): void {
    $fixture = payrollFinancialFixture();
    $unauthorized = payrollFinancialActor([]);

    $this->actingAs($unauthorized)
        ->withSession(payrollFinancialContext($fixture))
        ->postJson(route('admin.hr.payroll-runs.calculate'), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'branch_doc_num' => $fixture['branch']->doc_num,
        ])
        ->assertForbidden();
    expect(Activity::query()->where('action', 'hr.payroll.calculated')->exists())->toBeFalse();

    $actor = payrollFinancialActor([
        'hr.payroll_preparation.view',
        'hr.payroll_preparation.calculate',
        'hr.payroll_approval.review',
        'hr.payroll_approval.approve',
        'hr.payroll_payment.create',
        'hr.payroll_reconciliation.view',
        'cash_payment_vouchers.create',
    ]);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture));
    $calculated = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);

    $otherCompany = Company::query()->create([
        'doc_number' => 7002,
        'doc_num' => 'PAY-COMP-07002',
        'name' => 'Other Payroll Company',
        'legal_name' => 'Other Payroll Company',
        'status' => 'active',
        'is_main' => false,
        'country' => 'Egypt',
    ]);
    $otherBranch = Branch::query()->create([
        'doc_number' => 7002,
        'doc_num' => 'PAY-BR-07002',
        'company_id' => $otherCompany->getKey(),
        'name' => 'Other Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $otherPeriod = FinancialPeriod::query()->create([
        'company_id' => $otherCompany->getKey(),
        'doc_number' => 7002,
        'doc_num' => 'PAY-FP-07002',
        'name' => '2026 Other',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);

    $this->actingAs($actor)
        ->withSession(payrollFinancialContext(['company' => $otherCompany, 'branch' => $otherBranch, 'period' => $otherPeriod]))
        ->postJson(route('admin.hr.payroll-runs.review', $calculated['run_id']))
        ->assertNotFound();

    $this->actingAs($actor)
        ->withSession(payrollFinancialContext($fixture))
        ->get(route('admin.hr.payroll-preparation.index', ['run' => $calculated['run_id']]))
        ->assertOk()
        ->assertSee('Payroll Preparation');

    $actor->forceFill(['locale' => 'ar'])->save();
    $this->actingAs($actor)
        ->withSession([...payrollFinancialContext($fixture), 'locale' => 'ar'])
        ->get(route('admin.hr.payroll-preparation.index', ['run' => $calculated['run_id']]))
        ->assertOk()
        ->assertSee('إعداد الرواتب')
        ->assertSee('إجمالي المستحقات');
});

test('payroll lifecycle audit is company scoped safe and idempotent on retried transitions', function (): void {
    $fixture = payrollFinancialFixture();
    $actor = payrollFinancialActor([
        'hr.payroll_preparation.view',
        'hr.payroll_preparation.calculate',
        'hr.payroll_approval.review',
        'hr.payroll_approval.approve',
        'hr.payroll_payment.create',
        'cash_payment_vouchers.create',
    ]);
    $session = payrollFinancialContext($fixture);
    $calculate = $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.payroll-runs.calculate'), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'branch_doc_num' => $fixture['branch']->doc_num,
        ])
        ->assertOk();
    $runId = (int) $calculate->json('data.run_id');

    $this->withSession($session)->postJson(route('admin.hr.payroll-runs.review', $runId))->assertOk();
    $this->withSession($session)->postJson(route('admin.hr.payroll-runs.review', $runId))->assertOk();
    $this->withSession($session)->postJson(route('admin.hr.payroll-runs.approve', $runId))->assertOk();
    $this->withSession($session)->postJson(route('admin.hr.payroll-runs.approve', $runId))->assertOk();

    $paymentKey = (string) Str::uuid();
    $paymentPayload = [
        'cashbox_doc_num' => $fixture['cashbox']->doc_num,
        'amount' => '1,000.0000',
        'payment_date' => '2026-09-17',
        'idempotency_key' => $paymentKey,
        'reference' => 'Sensitive payroll reference',
    ];
    foreach (['0', '1,2,3', '1000.00001'] as $invalidAmount) {
        $this->withSession($session)->postJson(route('admin.hr.payroll-runs.payments.store', $runId), [
            ...$paymentPayload,
            'amount' => $invalidAmount,
            'idempotency_key' => (string) Str::uuid(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }
    $this->withSession($session)->postJson(route('admin.hr.payroll-runs.payments.store', $runId), [
        ...$paymentPayload,
        'amount' => '99,999,999,999,999.9999',
        'idempotency_key' => (string) Str::uuid(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payroll')
        ->assertJsonMissingValidationErrors('amount');
    $this->withSession($session)->postJson(route('admin.hr.payroll-runs.payments.store', $runId), $paymentPayload)->assertOk();
    $this->withSession($session)->postJson(route('admin.hr.payroll-runs.payments.store', $runId), $paymentPayload)->assertOk();
    $this->withSession($session)->postJson(route('admin.hr.payroll-runs.payments.store', $runId), [
        ...$paymentPayload,
        'amount' => '1001.0000',
    ])->assertConflict();

    foreach (['hr.payroll.reviewed', 'hr.payroll.approved', 'hr.payroll.posted', 'hr.payroll.payment_initiated'] as $action) {
        expect(Activity::query()->where('action', $action)->count())->toBe(1);
    }

    $calculated = Activity::query()->where('action', 'hr.payroll.calculated')->sole();
    $payment = Activity::query()->where('action', 'hr.payroll.payment_initiated')->sole();
    expect($calculated->company_id)->toBe($fixture['company']->getKey())
        ->and($calculated->causer_id)->toBe($actor->getKey())
        ->and($calculated->properties->get('payroll_run_id'))->toBe($runId)
        ->and($calculated->properties->toArray())->not->toHaveKeys(['gross', 'deductions', 'payable', 'net', 'amount'])
        ->and($payment->company_id)->toBe($fixture['company']->getKey())
        ->and($payment->causer_id)->toBe($actor->getKey())
        ->and($payment->properties->toArray())->not->toHaveKeys(['amount', 'reference']);
});

test('payroll reconciliation preserves large fractional decimal values without float formatting', function (): void {
    $fixture = payrollFinancialFixture();
    $calculated = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    DB::table('hr_payslips')->where('payroll_run_id', $calculated['run_id'])->update([
        'gross_amount' => '999999999999.99',
        'deduction_amount' => '0.00',
        'net_amount' => '999999999999.99',
    ]);

    $reconciliation = app(PayrollReconciliationService::class)->forRun(
        $calculated['run_id'],
        $fixture['company']->getKey(),
        '2026-09-30',
    );

    expect($reconciliation['summary']['payable'])->toBe('999999999999.9900')
        ->and($reconciliation['summary']['remaining'])->toBe('999999999999.9900');
});

test('payroll direct routes reject same company branches and financial periods outside role scope', function (): void {
    $fixture = payrollFinancialFixture();
    $otherBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 7099,
        'doc_num' => 'PAY-BR-07099',
        'name' => 'Other Scoped Payroll Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $otherPeriod = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 7099,
        'doc_num' => 'PAY-FP-07099',
        'name' => '2027',
        'from_date' => '2027-01-01',
        'to_date' => '2027-12-31',
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);
    $permissions = [
        'hr.payroll_preparation.view',
        'hr.payroll_preparation.calculate',
        'hr.payroll_approval.review',
        'hr.payroll_approval.approve',
        'hr.payroll_payment.create',
        'hr.payroll_reconciliation.view',
        'cash_payment_vouchers.create',
        'hr.payslips.view',
        'hr.payroll_reports.view',
        'hr.payroll_reports.export',
    ];
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $scopedActor = User::factory()->create();
    $scopedRole = Role::query()->create([
        'name' => 'Scoped Payroll Good '.Str::random(8),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
    ]);
    $scopedRole->givePermissionTo($permissions);
    $scopedRole->companyAccessCompanies()->sync([$fixture['company']->getKey()]);
    $scopedRole->branchAccessBranches()->sync([$fixture['branch']->getKey()]);
    $scopedRole->financialPeriodAccessPeriods()->sync([$fixture['period']->getKey()]);
    $scopedActor->assignRole($scopedRole);
    $session = payrollFinancialContext($fixture);

    $this->actingAs($scopedActor)->withSession($session)
        ->postJson(route('admin.hr.payroll-runs.calculate'), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'branch_doc_num' => $otherBranch->doc_num,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_doc_num');
    $this->withSession($session)
        ->postJson(route('admin.hr.payroll-runs.calculate'), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'branch_doc_num' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_doc_num');
    $calculation = $this->withSession($session)
        ->postJson(route('admin.hr.payroll-runs.calculate'), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'branch_doc_num' => $fixture['branch']->doc_num,
        ])->assertOk();
    $runId = (int) $calculation->json('data.run_id');
    $payslipId = (int) DB::table('hr_payslips')->where('payroll_run_id', $runId)->value('id');
    $this->withSession($session)->get(route('admin.hr.payslips.show', $payslipId))->assertOk();
    $this->withSession($session)->get(route('admin.hr.payroll-runs.cost-preview', $runId))->assertOk();

    $wrongBranchActor = User::factory()->create();
    $wrongBranchRole = Role::query()->create([
        'name' => 'Scoped Payroll Wrong Branch '.Str::random(8),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
    ]);
    $wrongBranchRole->givePermissionTo($permissions);
    $wrongBranchRole->companyAccessCompanies()->sync([$fixture['company']->getKey()]);
    $wrongBranchRole->branchAccessBranches()->sync([$otherBranch->getKey()]);
    $wrongBranchRole->financialPeriodAccessPeriods()->sync([$fixture['period']->getKey()]);
    $wrongBranchActor->assignRole($wrongBranchRole);
    $wrongBranchSession = [
        ...$session,
        OperatingContextService::BranchIdKey => $otherBranch->getKey(),
        OperatingContextService::BranchDocNumKey => $otherBranch->doc_num,
    ];
    $this->actingAs($wrongBranchActor)->withSession($wrongBranchSession)
        ->postJson(route('admin.hr.payroll-runs.review', $runId))->assertNotFound();
    $this->withSession($wrongBranchSession)->get(route('admin.hr.payslips.show', $payslipId))->assertNotFound();
    $this->withSession($wrongBranchSession)->get(route('admin.hr.payroll-runs.cost-preview', $runId))->assertNotFound();
    $this->withSession($wrongBranchSession)->get(route('admin.hr.reports.payroll'))
        ->assertOk()->assertDontSee($fixture['employee']->full_name);

    $wrongPeriodActor = User::factory()->create();
    $wrongPeriodRole = Role::query()->create([
        'name' => 'Scoped Payroll Wrong Period '.Str::random(8),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
    ]);
    $wrongPeriodRole->givePermissionTo($permissions);
    $wrongPeriodRole->companyAccessCompanies()->sync([$fixture['company']->getKey()]);
    $wrongPeriodRole->branchAccessBranches()->sync([$fixture['branch']->getKey()]);
    $wrongPeriodRole->financialPeriodAccessPeriods()->sync([$otherPeriod->getKey()]);
    $wrongPeriodActor->assignRole($wrongPeriodRole);
    $wrongPeriodSession = [
        ...$session,
        OperatingContextService::FinancialPeriodIdKey => $otherPeriod->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $otherPeriod->doc_num,
    ];
    $this->actingAs($wrongPeriodActor)->withSession($wrongPeriodSession)
        ->postJson(route('admin.hr.payroll-runs.review', $runId))->assertNotFound();
    $this->withSession($wrongPeriodSession)->get(route('admin.hr.payslips.show', $payslipId))->assertNotFound();
    $this->withSession($wrongPeriodSession)->get(route('admin.hr.payroll-runs.cost-preview', $runId))->assertNotFound();
    $this->withSession($wrongPeriodSession)->get(route('admin.hr.reports.payroll'))
        ->assertOk()->assertDontSee($fixture['employee']->full_name);

    $unrestricted = payrollFinancialActor($permissions);
    $this->actingAs($unrestricted)->withSession($session)
        ->postJson(route('admin.hr.payroll-runs.review', $runId))->assertOk();
    $this->withSession($session)->postJson(route('admin.hr.payroll-runs.approve', $runId))->assertOk();

    $this->actingAs($wrongPeriodActor)->withSession($wrongPeriodSession)
        ->postJson(route('admin.hr.payroll-runs.payments.store', $runId), [
            'cashbox_doc_num' => $fixture['cashbox']->doc_num,
            'amount' => '100.0000',
            'payment_date' => '2026-09-17',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();
});

test('payroll posting provenance rollback preserves period only evidence and columns owned before migration', function (): void {
    $fixture = payrollFinancialFixture();
    $actor = payrollFinancialActor(['hr.payroll_approval.correct']);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture));
    Carbon::setTestNow('2026-10-01 12:00:00');
    $runId = payrollCorrectionPostedRun($fixture);
    $migration = require database_path('migrations/2026_10_03_062157_add_posting_date_to_payroll_correction_runs.php');
    DB::table('hr_payroll_runs')->where('id', $runId)->update(['posting_date' => null, 'posting_financial_period_id' => $fixture['period']->getKey()]);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(DB::table('hr_payroll_runs')->where('id', $runId)->value('posting_financial_period_id'))->toBe($fixture['period']->getKey());
    DB::table('hr_payroll_runs')->where('id', $runId)->update(['posting_financial_period_id' => null]);
    $migration->down();
    expect(Schema::hasColumn('hr_payroll_runs', 'posting_date'))->toBeFalse();
    Schema::table('hr_payroll_runs', function (Blueprint $table): void {
        $table->date('posting_date')->nullable();
        $table->foreignId('posting_financial_period_id')->nullable()->constrained('financial_periods')->restrictOnDelete();
    });
    $migration->up();
    $migration->down();
    expect(Schema::hasColumn('hr_payroll_runs', 'posting_date'))->toBeTrue()
        ->and(Schema::hasColumn('hr_payroll_runs', 'posting_financial_period_id'))->toBeTrue();
    $migration->up();
});

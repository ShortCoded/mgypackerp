<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\AccountClassificationRegistry;
use Modules\Accounting\Services\ReconciliationCenterService;
use Modules\Accounting\Services\ReconciliationComparisonService;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
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
use Modules\HR\Services\PayrollCalculationService;
use Modules\HR\Services\PayrollCostAllocationService;
use Modules\HR\Services\PayrollLifecycleService;
use Modules\HR\Services\PayrollPaymentService;
use Modules\HR\Services\PayrollReconciliationService;
use Modules\HR\Services\PayrollReportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function payrollFinancialActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $actor = User::factory()->create(['locale' => 'en']);
    $actor->givePermissionTo($permissions);

    return $actor;
}

function payrollFinancialAccount(Company $company, string $classificationCode, string $accountCode, int $number): Account
{
    $classification = AccountClassification::query()->where('code', $classificationCode)->firstOrFail();

    return Account::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $number,
        'doc_num' => 'PAY-ACC-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'account_code' => $accountCode,
        'name' => $classification->name,
        'name_en' => $classification->name_en,
        'account_classification_id' => $classification->getKey(),
        'account_type' => $classification->account_type,
        'statement_type' => $classification->statement_type,
        'normal_balance' => $classification->normal_balance,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
}

/** @return array<string, mixed> */
function payrollFinancialFixture(): array
{
    Carbon::setTestNow('2026-09-17 12:00:00');
    app(AccountClassificationRegistry::class)->synchronize();

    $company = Company::query()->create([
        'doc_number' => 7001,
        'doc_num' => 'PAY-COMP-07001',
        'name' => 'Payroll Integration Company',
        'legal_name' => 'Payroll Integration Company',
        'status' => 'active',
        'is_main' => true,
        'country' => 'Egypt',
    ]);
    $branch = Branch::query()->create([
        'doc_number' => 7001,
        'doc_num' => 'PAY-BR-07001',
        'company_id' => $company->getKey(),
        'name' => 'Payroll Branch',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 7001,
        'doc_num' => 'PAY-FP-07001',
        'name' => '2026',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);
    $currency = Currency::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 7001,
        'doc_num' => 'PAY-CUR-07001',
        'name' => 'Egyptian Pound',
        'code' => 'EGP',
        'minor_unit_name' => 'Piastre',
        'minor_unit_factor' => 100,
        'is_main' => true,
        'status' => 'active',
    ]);

    $directLaborAccount = payrollFinancialAccount($company, 'direct_labor_cost', 'PAY-5111', 5111);
    $payableAccount = payrollFinancialAccount($company, 'payroll_payable', 'PAY-2111', 2111);
    $advanceAccount = payrollFinancialAccount($company, 'employee_advances', 'PAY-1131', 1131);
    $deductionAccount = payrollFinancialAccount($company, 'payroll_tax_payable', 'PAY-2121', 2121);
    $cashAccount = payrollFinancialAccount($company, 'cash_in_transit', 'PAY-1111', 1111);

    $department = HrDepartment::query()->create([
        'doc_number' => 7001,
        'doc_num' => 'PAY-DEPT-07001',
        'name' => 'Payroll Production',
        'status' => 'active',
    ]);
    $costCenter = CostCenter::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 7001,
        'doc_num' => 'PAY-CC-07001',
        'cost_center_code' => '11',
        'name' => 'Payroll Production',
        'name_en' => 'Payroll Production',
        'is_group' => false,
        'status' => 'active',
    ]);
    HrDepartmentCostCenterDefault::query()->create([
        'company_id' => $company->getKey(),
        'department_id' => $department->getKey(),
        'cost_center_id' => $costCenter->getKey(),
    ]);

    $employee = HrEmployee::query()->create([
        'doc_number' => 7001,
        'doc_num' => 'PAY-EMP-07001',
        'employee_code' => 'PAY-E001',
        'full_name' => 'Payroll Fixture Employee',
        'name' => 'Payroll Fixture Employee',
        'person_type' => 'fixed_employee',
        'status' => 'active',
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'department_id' => $department->getKey(),
        'hire_date' => '2026-01-01',
        'contract_start_date' => '2026-01-01',
        'pay_basis' => 'monthly_salary',
        'payroll_currency_id' => $currency->getKey(),
        'exchange_rate' => 1,
        'basic_salary' => '10000.00',
        'hourly_wage' => '100.0000',
        'overtime_enabled' => true,
    ]);
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $employee->getKey(),
        'effective_from' => '2026-01-01',
        'basic_salary' => '10000.00',
        'components' => json_encode(['items' => [], 'overtime_hourly_rate' => '100.0000'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([
        ['code' => 'BASIC', 'name' => 'Basic Salary', 'kind' => 'earning', 'classification' => 'salary_expense'],
        ['code' => 'OVERTIME', 'name' => 'Overtime', 'kind' => 'earning', 'classification' => 'salary_expense'],
        ['code' => 'PAYROLL-TAX', 'name' => 'Payroll Tax', 'kind' => 'deduction', 'classification' => 'payroll_tax_payable'],
        ['code' => 'SALARY-ADVANCE', 'name' => 'Salary Advance', 'kind' => 'deduction', 'classification' => 'employee_advances'],
    ] as $item) {
        DB::table('hr_payroll_items')->insert([
            'code' => $item['code'],
            'name' => $item['name'],
            'item_kind' => $item['kind'],
            'account_classification_id' => AccountClassification::query()->where('code', $item['classification'])->value('id'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('hr_attendance_daily_records')->insert([
        'employee_id' => $employee->getKey(),
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'work_date' => '2026-09-10',
        'check_in_at' => '2026-09-10 08:00:00',
        'check_out_at' => '2026-09-10 18:00:00',
        'worked_minutes' => 600,
        'overtime_minutes' => 120,
        'status' => 'present',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    HrEmployeeServiceRequest::query()->create([
        'employee_id' => $employee->getKey(),
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'request_type' => 'overtime',
        'subject' => 'September overtime',
        'details' => 'Approved fixture overtime',
        'requested_from' => '2026-09-10',
        'requested_to' => '2026-09-10',
        'requested_minutes' => 120,
        'status' => HrEmployeeServiceRequest::StatusApproved,
        'submitted_at' => '2026-09-10 18:00:00',
        'resolved_at' => '2026-09-11 09:00:00',
    ]);
    $advanceId = DB::table('hr_salary_advances')->insertGetId([
        'employee_id' => $employee->getKey(),
        'principal' => '500.00',
        'balance' => '500.00',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cashbox = Cashbox::query()->create([
        'doc_number' => 7001,
        'doc_num' => 'PAY-CASH-07001',
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'account_id' => $cashAccount->getKey(),
        'name' => 'Payroll Cashbox',
        'status' => 'active',
    ]);
    CashboxCurrency::query()->create([
        'cashbox_id' => $cashbox->getKey(),
        'currency_id' => $currency->getKey(),
        'status' => 'active',
    ]);

    return [
        'company' => $company,
        'branch' => $branch,
        'period' => $period,
        'currency' => $currency,
        'employee' => $employee,
        'advance_id' => $advanceId,
        'cashbox' => $cashbox,
        'cash_account' => $cashAccount,
        'payable_account' => $payableAccount,
        'advance_account' => $advanceAccount,
        'deduction_account' => $deductionAccount,
        'direct_labor_account' => $directLaborAccount,
    ];
}

/** @param array<string, mixed> $fixture */
function payrollFinancialContext(array $fixture): array
{
    return [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
}

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

    expect($duplicateDraft['voucher']->getKey())->toBe($draft['voucher']->getKey())
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
        ->assertOk()->assertSee($fixture['employee']->full_name)->assertSee('BASIC');
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
    expect($csvContents)->toContain('EGP')->toContain('USD')->toContain($largeAmount)->toContain($largeNetAmount);
    $payrollXlsx = $this->withSession($session)->get(route('admin.hr.reports.payroll.export', ['format' => 'xlsx']));
    $payrollXlsx->assertOk()->assertDownload('payroll-report.xlsx');
    $payrollSheet = IOFactory::load($payrollXlsx->baseResponse->getFile()->getPathname())->getActiveSheet();
    $largeAmountCell = collect($payrollSheet->getCellCollection()->getCoordinates())
        ->map(fn (string $coordinate) => $payrollSheet->getCell($coordinate))
        ->first(fn ($cell): bool => $cell->getValue() === $largeAmount);
    expect($largeAmountCell)->not->toBeNull()
        ->and($largeAmountCell->getDataType())->toBe(DataType::TYPE_STRING)
        ->and(collect($payrollSheet->toArray())->flatten()->all())->toContain($largeNetAmount);

    $paymentCsv = $this->withSession($session)->get(route('admin.hr.reports.payments.export', ['format' => 'csv']));
    $paymentCsv->assertOk()->assertDownload('payroll-payment-report.csv');
    expect(file_get_contents($paymentCsv->baseResponse->getFile()->getPathname()))->toContain($largeAmount);
    $paymentXlsx = $this->withSession($session)->get(route('admin.hr.reports.payments.export', ['format' => 'xlsx']));
    $paymentXlsx->assertOk()->assertDownload('payroll-payment-report.xlsx');
    $paymentSheet = IOFactory::load($paymentXlsx->baseResponse->getFile()->getPathname())->getActiveSheet();
    $largePaymentCell = collect($paymentSheet->getCellCollection()->getCoordinates())
        ->map(fn (string $coordinate) => $paymentSheet->getCell($coordinate))
        ->first(fn ($cell): bool => $cell->getValue() === $largeAmount);
    expect($largePaymentCell)->not->toBeNull()
        ->and($largePaymentCell->getDataType())->toBe(DataType::TYPE_STRING);

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

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
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
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

    $draftReconciliation = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->getKey(), '2026-09-30');
    expect($draftReconciliation['summary']['paid'])->toBe('0.0000')
        ->and($draftReconciliation['summary']['remaining'])->toBe('9700.0000');

    app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $draft['voucher'], $fixture['company']->getKey());
    app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $draft['voucher']->refresh(), $fixture['company']->getKey());

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

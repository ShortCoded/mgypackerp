<?php

use App\Models\User;
use Database\Seeders\HrFullCycleBrowserE2eSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\CashVoucher;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Services\PayrollReconciliationService;
use Spatie\Permission\Models\Permission;

/** @return array<string, int|string> */
function hrFullCycleSession(Company $company, Branch $branch, FinancialPeriod $period): array
{
    return [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
}

test('HR full-cycle fixture is impossible to run in production', function (): void {
    $originalEnvironment = config('app.env');

    try {
        config(['app.env' => 'production']);

        expect(fn () => (new HrFullCycleBrowserE2eSeeder)->run())
            ->toThrow(LogicException::class, 'restricted to local and testing environments');
        expect(Company::query()->where('doc_num', 'Company-'.HrFullCycleBrowserE2eSeeder::Marker)->exists())->toBeFalse();
    } finally {
        config(['app.env' => $originalEnvironment]);
    }
});

test('marker-scoped employee-to-payroll cycle reconciles exactly and retries without duplicates', function (): void {
    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:');

    $unrelatedAdmin = User::factory()->create([
        'username' => 'admin',
        'email' => 'existing-admin@example.test',
        'name' => 'Existing Admin Must Remain Untouched',
        'locale' => 'en',
    ]);
    $adminSnapshot = $unrelatedAdmin->only(['name', 'username', 'email', 'password', 'locale', 'default_company_id', 'default_branch_id', 'default_financial_period_id']);
    $adminRole = Role::query()->create([
        'name' => 'admin',
        'guard_name' => 'web',
        'doc_number' => 50,
        'doc_num' => 'Role-Existing-Admin',
        'company_access_restricted' => false,
        'branch_access_restricted' => false,
        'financial_period_access_restricted' => false,
    ]);
    $sentinelPermission = Permission::findOrCreate('existing.admin.sentinel', 'web');
    $adminRole->syncPermissions([$sentinelPermission]);

    $this->seed(HrFullCycleBrowserE2eSeeder::class);

    $company = Company::query()->where('doc_num', 'Company-'.HrFullCycleBrowserE2eSeeder::Marker)->firstOrFail();
    $branch = Branch::query()->where('doc_num', 'Branch-'.HrFullCycleBrowserE2eSeeder::Marker)->firstOrFail();
    $period = FinancialPeriod::query()->where('doc_num', 'FinancialPeriod-'.HrFullCycleBrowserE2eSeeder::Marker)->firstOrFail();
    $operator = User::query()->where('doc_num', 'User-'.HrFullCycleBrowserE2eSeeder::Marker)->firstOrFail();
    $role = Role::query()->where('name', HrFullCycleBrowserE2eSeeder::Marker)->firstOrFail();
    $employee = HrEmployee::query()->where('doc_num', 'HrEmployee-'.HrFullCycleBrowserE2eSeeder::Marker)->firstOrFail();
    $run = DB::table('hr_payroll_runs as run')
        ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
        ->where('period.company_id', $company->getKey())
        ->where('run.branch_id', $branch->getKey())
        ->firstOrFail(['run.*']);
    $payslip = DB::table('hr_payslips')->where('payroll_run_id', $run->id)->where('employee_id', $employee->getKey())->firstOrFail();

    expect($unrelatedAdmin->refresh()->only(array_keys($adminSnapshot)))->toBe($adminSnapshot)
        ->and($adminRole->refresh()->permissions()->pluck('name')->all())->toBe(['existing.admin.sentinel'])
        ->and($operator->username)->toBe('hr_e2e_operator')
        ->and($operator->locale)->toBe('ar')
        ->and($operator->hasRole($role))->toBeTrue()
        ->and($role->company_access_restricted)->toBeTrue()
        ->and($role->branch_access_restricted)->toBeTrue()
        ->and($role->financial_period_access_restricted)->toBeTrue()
        ->and($role->companyAccessCompanies()->pluck('companies.id')->all())->toBe([$company->getKey()])
        ->and($role->branchAccessBranches()->pluck('branches.id')->all())->toBe([$branch->getKey()])
        ->and($role->financialPeriodAccessPeriods()->pluck('financial_periods.id')->all())->toBe([$period->getKey()]);

    expect($employee->company_id)->toBe($company->getKey())
        ->and($employee->branch_id)->toBe($branch->getKey())
        ->and($employee->department_id)->not->toBeNull()
        ->and($employee->section_id)->not->toBeNull()
        ->and($employee->job_id)->not->toBeNull()
        ->and($employee->employment_type_id)->not->toBeNull()
        ->and($employee->default_shift_id)->not->toBeNull()
        ->and((float) $employee->basic_salary)->toBe(12000.0)
        ->and(DB::table('hr_employee_shift_assignments')->where('employee_id', $employee->getKey())->count())->toBe(1)
        ->and((float) DB::table('hr_leave_balances')->where('employee_id', $employee->getKey())->value('current_balance'))->toBe(9.0)
        ->and(DB::table('hr_leave_balance_ledger')->where('notes', HrFullCycleBrowserE2eSeeder::Marker.':paid-leave')->count())->toBe(1)
        ->and(DB::table('hr_leave_requests')->where('employee_id', $employee->getKey())->where('status', 'approved')->count())->toBe(2)
        ->and(DB::table('hr_leave_requests')->where('employee_id', $employee->getKey())->whereNull('payment_status')->count())->toBe(0)
        ->and(DB::table('hr_payroll_attendance_policies')->where('company_id', $company->getKey())->value('branch_scope_key'))->toBe('branch:'.$branch->getKey())
        ->and(DB::table('hr_leave_request_days')->whereIn('leave_date', ['2026-09-07', '2026-09-08'])->count())->toBe(2);

    $attendance = DB::table('hr_attendance_daily_records')
        ->where('employee_id', $employee->getKey())
        ->orderBy('work_date')
        ->get()
        ->keyBy('work_date');
    expect($attendance)->toHaveCount(6)
        ->and($attendance['2026-09-02']->status)->toBe('present')
        ->and((int) $attendance['2026-09-03']->late_minutes)->toBe(30)
        ->and((int) $attendance['2026-09-03']->early_leave_minutes)->toBe(15)
        ->and($attendance['2026-09-04']->status)->toBe('absent')
        ->and($attendance['2026-09-07']->status)->toBe('absent')
        ->and($attendance['2026-09-08']->status)->toBe('absent')
        ->and((int) $attendance['2026-09-09']->overtime_minutes)->toBe(120);

    $attendanceDeductions = DB::table('hr_payslip_items')
        ->where('payslip_id', $payslip->id)
        ->where('source_type', 'attendance_policy')
        ->get()
        ->mapWithKeys(function (object $item): array {
            $snapshot = json_decode((string) $item->source_snapshot, true, 512, JSON_THROW_ON_ERROR);

            return [(string) $snapshot['effect_type'] => (float) $item->amount];
        });

    expect($attendanceDeductions)->toHaveCount(4)
        ->and($attendanceDeductions['absence'])->toBe(400.0)
        ->and($attendanceDeductions['late'])->toBe(25.0)
        ->and($attendanceDeductions['early_leave'])->toBe(12.5)
        ->and($attendanceDeductions['unpaid_leave'])->toBe(400.0)
        ->and((float) DB::table('hr_payslip_items')->where('payslip_id', $payslip->id)->where('source_type', 'approved_overtime')->value('amount'))->toBe(200.0)
        ->and((float) $payslip->gross_amount)->toBe(12200.0)
        ->and((float) $payslip->deduction_amount)->toBe(837.5)
        ->and((float) $payslip->net_amount)->toBe(11362.5)
        ->and($run->status)->toBe('posted');

    $attendanceInput = json_decode((string) DB::table('hr_payroll_attendance_inputs')
        ->where('payroll_run_id', $run->id)
        ->where('employee_id', $employee->getKey())
        ->value('payload'), true, 512, JSON_THROW_ON_ERROR);
    expect(data_get($attendanceInput, 'policy_snapshots.0.id'))->not->toBeNull()
        ->and(data_get($attendanceInput, 'policy_snapshots.0.salary_day_divisor'))->toBe(30)
        ->and(data_get($attendanceInput, 'policy_snapshots.0.standard_day_minutes'))->toBe(480);

    $payrollJournal = JournalEntry::query()->with('lines')
        ->where('source_type', 'hr_payroll_run')
        ->where('source_id', $run->id)
        ->firstOrFail();
    $payment = DB::table('hr_payroll_payments')->where('payroll_run_id', $run->id)->firstOrFail();
    $voucher = CashVoucher::query()->findOrFail($payment->cash_voucher_id);
    $paymentJournal = JournalEntry::query()->with('lines')
        ->where('source_type', 'hr_payroll_payment')
        ->where('source_id', $payment->id)
        ->firstOrFail();
    $directLaborAccountId = DB::table('accounts as account')
        ->join('account_classifications as classification', 'classification.id', '=', 'account.account_classification_id')
        ->where('account.company_id', $company->getKey())
        ->where('classification.code', 'direct_labor_cost')
        ->value('account.id');
    $directLaborLines = $payrollJournal->lines->where('account_id', $directLaborAccountId);

    expect((float) $payrollJournal->lines->sum('debit_amount'))->toBe(12200.0)
        ->and((float) $payrollJournal->lines->sum('credit_amount'))->toBe(12200.0)
        ->and((float) $directLaborLines->sum('debit_amount'))->toBe(12200.0)
        ->and((float) $directLaborLines->sum('credit_amount'))->toBe(837.5)
        ->and($voucher->status)->toBe(CashVoucher::StatusApproved)
        ->and((float) $payment->amount)->toBe(11362.5)
        ->and((float) $paymentJournal->lines->sum('debit_amount'))->toBe(11362.5)
        ->and((float) $paymentJournal->lines->sum('credit_amount'))->toBe(11362.5);

    $reconciliation = app(PayrollReconciliationService::class)->forRun((int) $run->id, $company->getKey(), '2026-09-30');
    expect($reconciliation['status'])->toBe('matched')
        ->and($reconciliation['summary'])->toMatchArray([
            'approved_payroll' => '11362.5000',
            'payable' => '11362.5000',
            'paid' => '11362.5000',
            'remaining' => '0.0000',
            'gl_ending' => '0.0000',
            'gl_difference' => '0.0000',
            'cash_bank_effect' => '11362.5000',
            'cash_bank_difference' => '0.0000',
        ]);

    $session = hrFullCycleSession($company, $branch, $period);
    $this->actingAs($operator)->withSession($session)
        ->get(route('admin.hr.payslips.show', $payslip->id))
        ->assertOk()
        ->assertSee($employee->full_name)
        ->assertSee('ATTENDANCE-DEDUCTION');
    foreach ([
        'admin.hr.reports.employees',
        'admin.hr.employee-attendance.index',
        'admin.hr.reports.leave-requests',
        'admin.hr.reports.payroll',
        'admin.hr.reports.payments',
    ] as $routeName) {
        $this->withSession($session)->get(route($routeName))->assertOk();
    }

    $beforeRetry = [
        'companies' => Company::query()->where('doc_num', 'Company-'.HrFullCycleBrowserE2eSeeder::Marker)->count(),
        'employees' => HrEmployee::query()->where('doc_num', 'HrEmployee-'.HrFullCycleBrowserE2eSeeder::Marker)->count(),
        'ledger' => DB::table('hr_leave_balance_ledger')->where('notes', HrFullCycleBrowserE2eSeeder::Marker.':paid-leave')->count(),
        'runs' => DB::table('hr_payroll_runs')->where('id', $run->id)->count(),
        'payments' => DB::table('hr_payroll_payments')->where('payroll_run_id', $run->id)->count(),
        'payroll_journals' => JournalEntry::query()->where('source_type', 'hr_payroll_run')->where('source_id', $run->id)->count(),
        'payment_journals' => JournalEntry::query()->where('source_type', 'hr_payroll_payment')->where('source_id', $payment->id)->count(),
    ];

    $this->seed(HrFullCycleBrowserE2eSeeder::class);

    expect([
        'companies' => Company::query()->where('doc_num', 'Company-'.HrFullCycleBrowserE2eSeeder::Marker)->count(),
        'employees' => HrEmployee::query()->where('doc_num', 'HrEmployee-'.HrFullCycleBrowserE2eSeeder::Marker)->count(),
        'ledger' => DB::table('hr_leave_balance_ledger')->where('notes', HrFullCycleBrowserE2eSeeder::Marker.':paid-leave')->count(),
        'runs' => DB::table('hr_payroll_runs')->where('id', $run->id)->count(),
        'payments' => DB::table('hr_payroll_payments')->where('payroll_run_id', $run->id)->count(),
        'payroll_journals' => JournalEntry::query()->where('source_type', 'hr_payroll_run')->where('source_id', $run->id)->count(),
        'payment_journals' => JournalEntry::query()->where('source_type', 'hr_payroll_payment')->where('source_id', $payment->id)->count(),
    ])->toBe($beforeRetry)
        ->and((float) DB::table('hr_leave_balances')->where('employee_id', $employee->getKey())->value('current_balance'))->toBe(9.0);
});

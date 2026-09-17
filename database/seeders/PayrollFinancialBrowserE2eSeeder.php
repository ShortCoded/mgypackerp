<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\AccountClassificationRegistry;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrDepartmentCostCenterDefault;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Services\PayrollCalculationService;
use Modules\HR\Services\PayrollLifecycleService;
use Modules\HR\Services\PayrollPaymentService;

class PayrollFinancialBrowserE2eSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DefaultOperatingContextSeeder::class,
            CurrencySeeder::class,
            PermissionSeeder::class,
            DefaultAdminSeeder::class,
        ]);
        app(AccountClassificationRegistry::class)->synchronize();

        $company = Company::query()->active()->firstOrFail();
        $branch = Branch::query()->where('company_id', $company->getKey())->active()->firstOrFail();
        $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
        $currency = Currency::query()->forCompany($company->getKey())->active()->where('is_main', true)->firstOrFail();
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $admin->forceFill([
            'locale' => 'en',
            'default_company_id' => $company->getKey(),
            'default_branch_id' => $branch->getKey(),
            'default_financial_period_id' => $period->getKey(),
        ])->save();
        $branch->forceFill(['type' => Branch::TypeFactory])->save();

        Auth::login($admin);

        DB::transaction(function () use ($company, $branch, $currency, $admin): void {
            $this->account($company, 'direct_labor_cost', '599701', 997001, 'E2E Direct Payroll Cost');
            $this->account($company, 'payroll_payable', '299701', 997002, 'E2E Payroll Payable');
            $this->account($company, 'employee_advances', '199701', 997003, 'E2E Employee Advances');
            $this->account($company, 'payroll_tax_payable', '299702', 997004, 'E2E Payroll Deduction Payable');
            $cashAccount = $this->account($company, 'cash', '199702', 997005, 'E2E Payroll Cash');

            $department = HrDepartment::query()->firstOrCreate(
                ['doc_num' => 'HR-DEPT-E2E-PAYROLL'],
                ['doc_number' => 997001, 'name' => 'E2E Payroll Production', 'status' => 'active'],
            );
            $costCenter = CostCenter::query()->firstOrCreate(
                ['company_id' => $company->getKey(), 'doc_num' => 'CC-E2E-PAYROLL'],
                [
                    'doc_number' => 997001,
                    'cost_center_code' => '11',
                    'name' => 'E2E Payroll Production',
                    'name_en' => 'E2E Payroll Production',
                    'is_group' => false,
                    'status' => 'active',
                ],
            );
            HrDepartmentCostCenterDefault::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'department_id' => $department->getKey()],
                ['cost_center_id' => $costCenter->getKey()],
            );

            $employee = HrEmployee::query()->firstOrCreate(
                ['company_id' => $company->getKey(), 'doc_num' => 'HR-EMP-E2E-PAYROLL'],
                [
                    'doc_number' => 997001,
                    'employee_code' => 'E2E-PAY-001',
                    'full_name' => 'E2E Payroll Employee',
                    'name' => 'E2E Payroll Employee',
                    'person_type' => 'fixed_employee',
                    'status' => 'active',
                    'branch_id' => $branch->getKey(),
                    'department_id' => $department->getKey(),
                    'hire_date' => now()->startOfYear()->toDateString(),
                    'contract_start_date' => now()->startOfYear()->toDateString(),
                    'pay_basis' => 'monthly_salary',
                    'payroll_currency_id' => $currency->getKey(),
                    'exchange_rate' => 1,
                    'basic_salary' => '10000.00',
                    'hourly_wage' => '100.0000',
                    'overtime_enabled' => true,
                    'created_by' => $admin->getKey(),
                ],
            );
            DB::table('hr_employee_salary_assignments')->updateOrInsert(
                ['employee_id' => $employee->getKey(), 'effective_from' => now()->startOfYear()->toDateString()],
                [
                    'basic_salary' => '10000.00',
                    'components' => json_encode(['items' => [], 'overtime_hourly_rate' => '100.0000'], JSON_THROW_ON_ERROR),
                    'created_by' => $admin->getKey(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $this->payrollItems();

            $workDate = now()->startOfMonth()->addDays(9)->toDateString();
            DB::table('hr_attendance_daily_records')->updateOrInsert(
                ['employee_id' => $employee->getKey(), 'work_date' => $workDate],
                [
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch->getKey(),
                    'check_in_at' => $workDate.' 08:00:00',
                    'check_out_at' => $workDate.' 18:00:00',
                    'worked_minutes' => 600,
                    'overtime_minutes' => 120,
                    'status' => 'present',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            HrEmployeeServiceRequest::query()->firstOrCreate(
                ['employee_id' => $employee->getKey(), 'request_type' => 'overtime', 'requested_from' => $workDate],
                [
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch->getKey(),
                    'subject' => 'E2E approved overtime',
                    'details' => 'Approved overtime for the payroll financial browser fixture.',
                    'requested_to' => $workDate,
                    'requested_minutes' => 120,
                    'status' => HrEmployeeServiceRequest::StatusApproved,
                    'submitted_at' => now(),
                    'resolved_at' => now(),
                    'resolved_by' => $admin->getKey(),
                    'created_by' => $admin->getKey(),
                ],
            );
            $advanceId = DB::table('hr_salary_advances')->where('employee_id', $employee->getKey())->value('id');
            if ($advanceId === null) {
                $advanceId = DB::table('hr_salary_advances')->insertGetId([
                    'employee_id' => $employee->getKey(),
                    'principal' => '500.00',
                    'balance' => '500.00',
                    'status' => 'active',
                    'created_by' => $admin->getKey(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $cashbox = Cashbox::query()->firstOrCreate(
                ['company_id' => $company->getKey(), 'doc_num' => 'CASH-E2E-PAYROLL'],
                [
                    'doc_number' => 997001,
                    'branch_id' => $branch->getKey(),
                    'account_id' => $cashAccount->getKey(),
                    'name' => 'E2E Payroll Cashbox',
                    'status' => 'active',
                    'created_by' => $admin->getKey(),
                ],
            );
            CashboxCurrency::query()->firstOrCreate(
                ['cashbox_id' => $cashbox->getKey(), 'currency_id' => $currency->getKey()],
                ['is_default' => true, 'status' => 'active'],
            );

            $runId = DB::table('hr_payroll_runs as run')
                ->join('hr_payroll_periods as payroll_period', 'payroll_period.id', '=', 'run.payroll_period_id')
                ->where('payroll_period.company_id', $company->getKey())
                ->where('run.branch_id', $branch->getKey())
                ->whereDate('payroll_period.period_start', now()->startOfMonth()->toDateString())
                ->whereDate('payroll_period.period_end', now()->endOfMonth()->toDateString())
                ->whereNull('run.deleted_at')
                ->value('run.id');
            if ($runId === null) {
                $calculation = app(PayrollCalculationService::class)->calculate($company->getKey(), [
                    'period_start' => now()->startOfMonth()->toDateString(),
                    'period_end' => now()->endOfMonth()->toDateString(),
                    'branch_doc_num' => $branch->doc_num,
                    'adjustments' => [[
                        'employee_doc_num' => $employee->doc_num,
                        'deductions' => [[
                            'payroll_item_code' => 'PAYROLL-TAX',
                            'amount' => '200.0000',
                            'reference' => 'E2E payroll deduction',
                        ]],
                        'advance_applications' => [[
                            'salary_advance_id' => (int) $advanceId,
                            'payroll_item_code' => 'SALARY-ADVANCE',
                            'amount' => '300.0000',
                        ]],
                    ]],
                ]);
                $runId = $calculation['run_id'];
                app(PayrollLifecycleService::class)->submitForReview($runId, $company->getKey());
                app(PayrollLifecycleService::class)->approve($runId, $company->getKey());
            }

            $payment = app(PayrollPaymentService::class)->createCashPayment((int) $runId, $company->getKey(), [
                'cashbox_doc_num' => $cashbox->doc_num,
                'amount' => '4000.0000',
                'payment_date' => now()->toDateString(),
                'idempotency_key' => '00000000-0000-4000-8000-000000997001',
                'reference' => 'E2E partial payroll settlement',
            ]);
            app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $payment['voucher'], $company->getKey());
        });

        Auth::logout();
    }

    private function account(Company $company, string $classificationCode, string $accountCode, int $number, string $name): Account
    {
        $classification = AccountClassification::query()->where('code', $classificationCode)->firstOrFail();

        return Account::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'account_code' => $accountCode],
            [
                'doc_number' => $number,
                'doc_num' => 'Account-E2E-PAY-'.$number,
                'name' => $name,
                'name_en' => $name,
                'account_classification_id' => $classification->getKey(),
                'account_type' => $classification->account_type,
                'statement_type' => $classification->statement_type,
                'normal_balance' => $classification->normal_balance,
                'is_group' => false,
                'is_postable' => true,
                'status' => 'active',
            ],
        );
    }

    private function payrollItems(): void
    {
        foreach ([
            ['code' => 'BASIC', 'name' => 'Basic Salary', 'kind' => 'earning', 'classification' => 'salary_expense'],
            ['code' => 'OVERTIME', 'name' => 'Overtime', 'kind' => 'earning', 'classification' => 'salary_expense'],
            ['code' => 'PAYROLL-TAX', 'name' => 'Payroll Tax', 'kind' => 'deduction', 'classification' => 'payroll_tax_payable'],
            ['code' => 'SALARY-ADVANCE', 'name' => 'Salary Advance', 'kind' => 'deduction', 'classification' => 'employee_advances'],
        ] as $item) {
            DB::table('hr_payroll_items')->updateOrInsert(
                ['code' => $item['code']],
                [
                    'name' => $item['name'],
                    'item_kind' => $item['kind'],
                    'account_classification_id' => AccountClassification::query()->where('code', $item['classification'])->value('id'),
                    'status' => 'active',
                    'deleted_at' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
}

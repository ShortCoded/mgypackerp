<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\AccountClassificationRegistry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrDepartmentCostCenterDefault;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Services\PayrollCalculationService;
use Modules\HR\Services\PayrollLifecycleService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionProgressEntry;
use Modules\Production\Models\ProductionRun;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/ClosureAcceptanceSupport.php';

function payrollFinancialActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $actor = closureSyntheticUser();
    $actor->update(['locale' => 'en']);
    $actor->givePermissionTo($permissions);

    return $actor;
}

function payrollFinancialAccount(Company $company, string $classificationCode, string $accountCode, int $number): Account
{
    $classification = AccountClassification::query()->where('code', $classificationCode)->firstOrFail();
    $number = max($number, (int) Account::withTrashed()->max('doc_number') + 1);

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
function payrollFinancialFixture(bool $isolated = false): array
{
    Carbon::setTestNow('2026-09-17 12:00:00');
    app(AccountClassificationRegistry::class)->synchronize();

    $companyNumber = max(7001, (int) Company::withTrashed()->max('doc_number') + 1);
    $fixtureNumber = max(7001, (int) collect([Branch::withTrashed()->max('doc_number'), FinancialPeriod::withTrashed()->max('doc_number'),
        Currency::withTrashed()->max('doc_number'), CostCenter::withTrashed()->max('doc_number'), HrEmployee::withTrashed()->max('doc_number'),
        Cashbox::withTrashed()->max('doc_number')])->max() + 1);
    $company = Company::query()->create([
        'doc_number' => $companyNumber,
        'doc_num' => 'PAY-COMP-'.str_pad((string) $companyNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'Payroll Integration Company',
        'legal_name' => 'Payroll Integration Company',
        'status' => 'active',
        'is_main' => ! $isolated,
        'country' => 'Egypt',
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $fixtureNumber,
        'doc_num' => 'PAY-BR-'.str_pad((string) $fixtureNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Payroll Branch',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $fixtureNumber,
        'doc_num' => 'PAY-FP-'.str_pad((string) $fixtureNumber, 5, '0', STR_PAD_LEFT),
        'name' => '2026',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);
    $currency = Currency::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $fixtureNumber,
        'doc_num' => 'PAY-CUR-'.str_pad((string) $fixtureNumber, 5, '0', STR_PAD_LEFT),
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

    $departmentNumber = max(7001, (int) HrDepartment::withTrashed()->max('doc_number') + 1);
    $department = HrDepartment::query()->create([
        'doc_number' => $departmentNumber,
        'doc_num' => 'PAY-DEPT-'.str_pad((string) $departmentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'Payroll Production',
        'status' => 'active',
    ]);
    $costCenter = CostCenter::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $fixtureNumber,
        'doc_num' => 'PAY-CC-'.str_pad((string) $fixtureNumber, 5, '0', STR_PAD_LEFT),
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
        'doc_number' => $fixtureNumber,
        'doc_num' => 'PAY-EMP-'.str_pad((string) $fixtureNumber, 5, '0', STR_PAD_LEFT),
        'employee_code' => 'PAY-E'.$fixtureNumber,
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
        $existingItem = DB::table('hr_payroll_items')->where('code', $item['code'])->first();
        if ($existingItem !== null) {
            expect($existingItem->item_kind)->toBe($item['kind'])->and($existingItem->status)->toBe('active')
                ->and((int) $existingItem->account_classification_id)->toBe((int) AccountClassification::query()->where('code', $item['classification'])->value('id'));

            continue;
        }
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
        'doc_number' => $fixtureNumber,
        'doc_num' => 'PAY-CASH-'.str_pad((string) $fixtureNumber, 5, '0', STR_PAD_LEFT),
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
        'cost_center' => $costCenter,
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

/** @param array<string, mixed> $fixture */
function payrollCorrectionPostedRun(array $fixture): int
{
    $calculated = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'branch_doc_num' => $fixture['branch']->doc_num,
        'adjustments' => [[
            'employee_doc_num' => $fixture['employee']->doc_num,
            'advance_applications' => [['salary_advance_id' => $fixture['advance_id'], 'payroll_item_code' => 'SALARY-ADVANCE', 'amount' => '300.0000']],
        ]],
    ]);
    app(PayrollLifecycleService::class)->submitForReview($calculated['run_id'], $fixture['company']->getKey());
    app(PayrollLifecycleService::class)->approve($calculated['run_id'], $fixture['company']->getKey());

    return $calculated['run_id'];
}

/** @param array<string, mixed> $fixture @param list<array{employee_id: int, actual_hours: string}> $labor */
function payrollManufacturingRun(array $fixture, Product $product, ItemUnit $unit, int $sequence, array $labor): ProductionRun
{
    $order = ProductionOrder::query()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'doc_number' => 7700 + $sequence, 'doc_num' => 'SYNTHETIC-PAY-PROD-'.$sequence, 'production_order_date' => '2026-09-01',
        'source_type' => 'make_to_stock', 'status' => ProductionOrder::StatusReleased,
    ]);
    $line = $order->lines()->create(['line_number' => 1, 'product_id' => $product->id, 'unit_id' => $unit->id,
        'description' => 'SYNTHETIC payroll-linked production', 'quantity' => '100', 'base_quantity' => '100']);
    $run = ProductionRun::query()->create([
        'run_number' => 'SYNTHETIC-PAY-RUN-'.$sequence, 'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'production_order_id' => $order->id, 'production_order_line_id' => $line->id, 'product_id' => $product->id, 'unit_id' => $unit->id,
        'cost_center_id' => $fixture['cost_center']->id, 'planned_quantity' => '100', 'planned_base_quantity' => '100',
        'good_base_quantity' => '100', 'received_base_quantity' => '0', 'planned_start_at' => '2026-09-10 08:00:00',
        'planned_end_at' => '2026-09-10 18:00:00', 'actual_start_at' => '2026-09-10 08:00:00',
        'labor_details' => $labor, 'status' => ProductionRun::StatusRunning,
    ]);
    ProductionProgressEntry::query()->create(['production_run_id' => $run->id, 'recorded_at' => '2026-09-10 18:00:00',
        'good_base_quantity' => '100', 'rejected_base_quantity' => '0', 'rework_base_quantity' => '0', 'scrap_base_quantity' => '0']);

    return $run;
}

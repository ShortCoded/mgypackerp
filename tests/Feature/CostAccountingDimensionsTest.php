<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\AccountClassificationRegistry;
use Modules\Accounting\Services\CostAccountingReportService;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrDepartmentCostCenterDefault;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Services\PayrollCostAllocationService;

function dimensionsCompany(): Company
{
    return Company::query()->create([
        'doc_number' => 9101,
        'doc_num' => 'COMP-09101',
        'name' => 'Dimensions Test Company',
        'legal_name' => 'Dimensions Test Company',
        'status' => 'active',
        'country' => 'Egypt',
    ]);
}

function dimensionsCostCenter(Company $company, string $code, string $name, int $number): CostCenter
{
    return CostCenter::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $number,
        'doc_num' => 'CC-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'cost_center_code' => $code,
        'name' => $name,
        'name_en' => $name,
        'is_group' => false,
        'status' => 'active',
    ]);
}

function dimensionsAccount(Company $company, string $code, string $classificationCode, int $number): Account
{
    $classification = AccountClassification::query()->where('code', $classificationCode)->firstOrFail();

    return Account::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $number,
        'doc_num' => 'ACC-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'account_code' => $code,
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

test('cost accounting migration adds only the missing dimensional relationships', function (): void {
    expect(Schema::hasColumn('cost_centers', 'name_en'))->toBeTrue()
        ->and(Schema::hasTable('hr_department_cost_center_defaults'))->toBeTrue()
        ->and(Schema::hasColumn('hr_employees', 'cost_center_id'))->toBeFalse()
        ->and(Schema::hasColumn('journal_entry_lines', 'department_id'))->toBeTrue()
        ->and(Schema::hasColumn('purchase_order_lines', 'cost_center_id'))->toBeTrue()
        ->and(Schema::hasColumn('purchase_invoice_lines', 'cost_center_id'))->toBeTrue()
        ->and(Schema::hasColumn('production_machines', 'cost_center_id'))->toBeTrue()
        ->and(Schema::hasColumn('production_runs', 'cost_center_id'))->toBeTrue()
        ->and(Schema::hasTable('hr_payroll_cost_allocations'))->toBeTrue();
});

test('services are purchasable without becoming stockable inventory', function (): void {
    expect(Product::purchasableItemClassifications())->toContain(Product::ClassificationService)
        ->and(Product::stockableItemClassifications())->not->toContain(Product::ClassificationService);
});

test('department default cost center remains company specific without an employee override', function (): void {
    $company = dimensionsCompany();
    $otherCompany = Company::query()->create([
        'doc_number' => 9102,
        'doc_num' => 'COMP-09102',
        'name' => 'Other Dimensions Test Company',
        'legal_name' => 'Other Dimensions Test Company',
        'status' => 'active',
        'is_main' => 2,
        'country' => 'Egypt',
    ]);
    $department = HrDepartment::query()->create([
        'doc_number' => 1,
        'doc_num' => 'HRDEP-00001',
        'name' => 'Production',
        'status' => 'active',
    ]);
    $departmentDefault = dimensionsCostCenter($company, '11', 'Injection Production', 11);
    $updatedDepartmentDefault = dimensionsCostCenter($company, '31', 'Maintenance and Engineering', 31);
    $otherCompanyDefault = dimensionsCostCenter($otherCompany, '41', 'Other Company Production', 41);
    HrDepartmentCostCenterDefault::query()->create([
        'company_id' => $company->getKey(),
        'department_id' => $department->getKey(),
        'cost_center_id' => $departmentDefault->getKey(),
    ]);
    HrDepartmentCostCenterDefault::query()->create([
        'company_id' => $otherCompany->getKey(),
        'department_id' => $department->getKey(),
        'cost_center_id' => $otherCompanyDefault->getKey(),
    ]);
    $employee = HrEmployee::query()->create([
        'doc_number' => 1,
        'doc_num' => 'HREMP-00001',
        'full_name' => 'Department Default Employee',
        'name' => 'Department Default Employee',
        'person_type' => 'fixed_employee',
        'status' => 'active',
        'company_id' => $company->getKey(),
        'department_id' => $department->getKey(),
    ]);

    expect($department->defaultCostCenterForCompany($company->getKey())?->is($departmentDefault))->toBeTrue()
        ->and($department->defaultCostCenterForCompany($otherCompany->getKey())?->is($otherCompanyDefault))->toBeTrue()
        ->and(method_exists($employee, 'costCenter'))->toBeFalse()
        ->and(method_exists($employee, 'effectiveCostCenter'))->toBeFalse()
        ->and($employee->getAttributes())->not->toHaveKey('cost_center_id');

    HrDepartmentCostCenterDefault::query()
        ->where('company_id', $company->getKey())
        ->where('department_id', $department->getKey())
        ->update(['cost_center_id' => $updatedDepartmentDefault->getKey()]);

    expect($department->defaultCostCenterForCompany($company->getKey())?->is($updatedDepartmentDefault))->toBeTrue()
        ->and($department->defaultCostCenterForCompany($otherCompany->getKey())?->is($otherCompanyDefault))->toBeTrue();
});

test('payroll preview and posting resolve department defaults before exact split allocations', function (): void {
    app(AccountClassificationRegistry::class)->synchronize();
    $company = dimensionsCompany();
    $department = HrDepartment::query()->create([
        'doc_number' => 1,
        'doc_num' => 'HRDEP-00001',
        'name' => 'Production',
        'status' => 'active',
    ]);
    $directCenter = dimensionsCostCenter($company, '11', 'Injection Production', 11);
    $supportCenter = dimensionsCostCenter($company, '31', 'Maintenance and Engineering', 31);
    dimensionsAccount($company, '5111', 'direct_labor_cost', 5111);
    dimensionsAccount($company, '5112', 'indirect_labor_cost', 5112);
    $employee = HrEmployee::query()->create([
        'doc_number' => 1,
        'doc_num' => 'HREMP-00001',
        'full_name' => 'Production Employee',
        'name' => 'Production Employee',
        'person_type' => 'fixed_employee',
        'status' => 'active',
        'company_id' => $company->getKey(),
        'department_id' => $department->getKey(),
    ]);
    $periodId = DB::table('hr_payroll_periods')->insertGetId([
        'company_id' => $company->getKey(),
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $runId = DB::table('hr_payroll_runs')->insertGetId([
        'payroll_period_id' => $periodId,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $payslipId = DB::table('hr_payslips')->insertGetId([
        'payroll_run_id' => $runId,
        'employee_id' => $employee->getKey(),
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $payrollItemId = DB::table('hr_payroll_items')->insertGetId([
        'code' => 'BASIC',
        'name' => 'Basic Salary',
        'item_kind' => 'earning',
        'account_classification_id' => AccountClassification::query()->where('code', 'salary_expense')->value('id'),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $payslipItemId = DB::table('hr_payslip_items')->insertGetId([
        'payslip_id' => $payslipId,
        'payroll_item_id' => $payrollItemId,
        'amount' => '1000.00',
        'direction' => 'earning',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $service = app(PayrollCostAllocationService::class);
    $missingDefaultPreview = $service->previewRun($runId);

    expect($missingDefaultPreview['lines'])->toBe([])
        ->and($missingDefaultPreview['errors'])->toHaveCount(1)
        ->and($missingDefaultPreview['errors'][0])->toContain('Employee department has no active posting cost center default for the payroll company.');

    HrDepartmentCostCenterDefault::query()->create([
        'company_id' => $company->getKey(),
        'department_id' => $department->getKey(),
        'cost_center_id' => $directCenter->getKey(),
    ]);
    $defaultPreview = $service->previewRun($runId);

    expect($defaultPreview['errors'])->toBe([])
        ->and($defaultPreview['lines'])->toHaveCount(1)
        ->and($defaultPreview['lines'][0])->toMatchArray([
            'classification' => 'direct_labor_cost',
            'cost_center_id' => $directCenter->getKey(),
            'department_id' => $department->getKey(),
            'percentage' => '100.0000',
            'stored' => false,
        ]);

    expect(fn () => $service->syncAllocations($payslipItemId, [
        ['cost_center_doc_num' => $directCenter->doc_num, 'percentage' => '110.0000', 'allocation_type' => 'direct'],
        ['cost_center_doc_num' => $supportCenter->doc_num, 'percentage' => '-10.0000', 'allocation_type' => 'indirect'],
    ]))->toThrow(DomainException::class, 'Each payroll allocation percentage must be greater than zero and at most 100%.');

    $service->syncAllocations($payslipItemId, [
        ['cost_center_doc_num' => $directCenter->doc_num, 'percentage' => '60.0000', 'allocation_type' => 'direct'],
        ['cost_center_doc_num' => $supportCenter->doc_num, 'percentage' => '40.0000', 'allocation_type' => 'indirect'],
    ]);
    $splitPreview = $service->previewRun($runId);

    expect($splitPreview['lines'])->toHaveCount(2)
        ->and(collect($splitPreview['lines'])->pluck('classification')->all())->toBe(['direct_labor_cost', 'indirect_labor_cost'])
        ->and(collect($splitPreview['lines'])->sum(fn (array $line): float => (float) $line['percentage']))->toBe(100.0)
        ->and(collect($splitPreview['lines'])->sum(fn (array $line): float => (float) $line['amount']))->toBe(1000.0)
        ->and(collect($splitPreview['lines'])->every(fn (array $line): bool => $line['stored']))->toBeTrue();

    dimensionsAccount($company, '2111', 'payroll_payable', 2111);
    FinancialPeriod::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 1,
        'doc_num' => 'FP-00001',
        'name' => 'August 2026',
        'from_date' => '2026-08-01',
        'to_date' => '2026-08-31',
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);
    Currency::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 1,
        'doc_num' => 'CUR-00001',
        'name' => 'Egyptian Pound',
        'code' => 'EGP',
        'is_main' => true,
        'status' => 'active',
    ]);

    $journalEntryId = $service->postRun($runId);
    $journalEntry = JournalEntry::query()->with('lines')->findOrFail($journalEntryId);
    $expenseLines = $journalEntry->lines->whereNotNull('employee_id')->keyBy('cost_center_id');
    $payableLine = $journalEntry->lines->firstWhere('employee_id', null);

    expect($service->postRun($runId))->toBe($journalEntryId)
        ->and(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('posted')
        ->and($expenseLines)->toHaveCount(2)
        ->and((string) $expenseLines->get($directCenter->getKey())?->debit_amount)->toBe('600.0000')
        ->and((string) $expenseLines->get($supportCenter->getKey())?->debit_amount)->toBe('400.0000')
        ->and($expenseLines->every(fn ($line): bool => (int) $line->department_id === (int) $department->getKey()))->toBeTrue()
        ->and((string) $payableLine?->credit_amount)->toBe('1000.0000')
        ->and($payableLine?->cost_center_id)->toBeNull();
});

test('all requested cost accounting report queries execute against the shared journal dimensions', function (): void {
    $company = dimensionsCompany();
    $period = FinancialPeriod::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 1,
        'doc_num' => 'FP-00001',
        'name' => '2026',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);
    $costCenter = dimensionsCostCenter($company, '11', 'Injection Production', 11);
    $reports = app(CostAccountingReportService::class);

    expect($reports->trialBalanceByCostCenter($company->getKey(), $period->getKey()))->toBeEmpty()
        ->and($reports->costCenterLedger($company->getKey(), $period->getKey(), $costCenter->getKey()))->toBeEmpty()
        ->and($reports->departmentalProfitAndLoss($company->getKey(), $period->getKey()))->toBeEmpty()
        ->and($reports->payrollCostByDepartmentAndCostCenter($company->getKey(), $period->getKey()))->toBeEmpty()
        ->and($reports->manufacturingOverheadByCostCenter($company->getKey(), $period->getKey()))->toBeEmpty()
        ->and($reports->unallocatedRequiredTransactions($company->getKey(), $period->getKey()))->toBeEmpty();
});

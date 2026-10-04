<?php

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DefaultOperatingContextSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrPayrollAttendancePolicy;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\SalesProductionDemandService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ClosureAcceptanceSupport.php';

/** @return array<string, mixed> */
function manufacturingInventoryFixture(string $suffix = '', bool $isolatedCompany = false): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(CurrencySeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    $user = closureSyntheticUser();
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);
    request()->setLaravelSession(app('session.store'));
    $company = Company::query()->where('status', 'active')->firstOrFail();
    if ($isolatedCompany) {
        $number = (int) Company::withTrashed()->max('doc_number') + 1;
        $company = Company::factory()->create(['doc_number' => $number, 'doc_num' => 'SYNTHETIC-MANUFACTURING-'.$number,
            'name' => 'SYNTHETIC isolated manufacturing'.$suffix]);
        FinancialPeriod::query()->create(['company_id' => $company->id, 'doc_number' => (int) FinancialPeriod::withTrashed()->max('doc_number') + 1,
            'doc_num' => 'SYNTHETIC-MANUFACTURING-PERIOD-'.$number, 'name' => 'SYNTHETIC manufacturing January to September',
            'from_date' => '2026-01-01', 'to_date' => '2026-09-30', 'is_closed' => false]);
        Branch::query()->create(['company_id' => $company->id, 'doc_number' => (int) Branch::withTrashed()->max('doc_number') + 1,
            'doc_num' => 'SYNTHETIC-MANUFACTURING-FACTORY-'.$number, 'name' => 'SYNTHETIC isolated factory', 'type' => Branch::TypeFactory, 'status' => 'active']);
        test()->seed(CurrencySeeder::class);
        test()->seed(DefaultChartOfAccountsSeeder::class);
    }
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    request()->session()->put(manufacturingIntegritySession(compact('company', 'branch', 'period')));
    $store = BranchStore::query()->create(['branch_id' => $branch->getKey(), 'name' => 'Manufacturing Store'.$suffix, 'position' => 1]);
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => $suffix === '' ? 9901 : max(9901, (int) ItemUnit::withTrashed()->max('doc_number') + 1), 'doc_num' => 'UNIT-MFG'.$suffix,
        'name' => 'Manufacturing Unit'.$suffix, 'status' => 'active',
    ]);
    $finished = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => $suffix === '' ? 9901 : max(9901, (int) Product::withTrashed()->max('doc_number') + 1), 'doc_num' => 'FG-MFG'.$suffix,
        'name' => 'Plastic Finished Unit'.$suffix, 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);
    $raw = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => $suffix === '' ? 9902 : max(9902, (int) Product::withTrashed()->max('doc_number') + 1), 'doc_num' => 'RM-MFG'.$suffix,
        'name' => 'Plastic Resin'.$suffix, 'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);
    ProductComponent::query()->create([
        'company_id' => $company->getKey(), 'product_id' => $finished->getKey(),
        'component_product_id' => $raw->getKey(), 'unit_id' => $unit->getKey(),
        'calculation_method' => ProductComponent::CalculationDirect, 'quantity' => '2',
        'created_by' => $user->getKey(),
    ]);
    InventoryTransaction::query()->create([
        'posting_key' => 'manufacturing-opening-resin'.$suffix, 'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(), 'branch_id' => $branch->getKey(),
        'branch_store_id' => $store->getKey(), 'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => now()->toDateString(), 'transaction_type' => 'opening_stock',
        'product_id' => $raw->getKey(), 'unit_id' => $unit->getKey(), 'quantity_in' => '1000',
        'quantity_out' => 0, 'source_type' => 'test', 'source_id' => 1,
        'source_doc_num' => 'OPEN-MFG', 'unit_cost' => '2', 'total_cost' => '2000',
        'created_by' => $user->getKey(),
    ]);
    $machine = ProductionMachine::query()->create([
        'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(),
        'code' => 'M-01'.$suffix, 'name' => 'Injection Machine'.$suffix, 'created_by' => $user->getKey(),
    ]);
    $mold = ProductionMold::query()->create([
        'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(),
        'code' => 'MD-01'.$suffix, 'name' => 'Container Mold'.$suffix, 'created_by' => $user->getKey(),
    ]);
    $machine->molds()->attach($mold);
    $mold->products()->attach($finished);

    return compact('user', 'company', 'branch', 'period', 'store', 'unit', 'finished', 'raw', 'machine', 'mold');
}

/** @param array<string, mixed> $fixture */
function manufacturingMaintenanceAssetAccount(array $fixture): Account
{
    return Account::query()
        ->where('company_id', $fixture['company']->getKey())
        ->where('status', 'active')
        ->where('is_group', false)
        ->where('is_postable', true)
        ->whereDoesntHave('fixedAsset')
        ->whereHas('classification', fn ($query) => $query
            ->where('code', AccountClassification::FixedAssets)
            ->where('status', 'active'))
        ->firstOrFail();
}

/** @param array<string, mixed> $fixture @return array<string, mixed> */
function manufacturingIntegrityRun(array $fixture, string $plannedQuantity = '1', int $offsetHours = 0, bool $asBatch = false): array
{
    $cycle = app(ProductionCycleService::class);
    $order = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
    ], [[
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => $plannedQuantity,
    ]]);
    $order = $cycle->releaseOrder($order);
    $orderLine = $order->lines->firstOrFail();
    $runData = [
        'planned_quantity' => $plannedQuantity,
        'planned_start_at' => now()->addHours($offsetHours + 1),
        'planned_end_at' => now()->addHours($offsetHours + 2),
        'production_machine_id' => $fixture['machine']->getKey(),
        'production_mold_id' => $fixture['mold']->getKey(),
        'batch_lot' => 'INTEGRITY-LOT-001',
    ];
    $batch = null;
    if ($asBatch) {
        $batch = $cycle->createRunBatch($order, [...$runData, 'lines' => [['production_order_line_id' => $orderLine->id, 'planned_quantity' => $plannedQuantity]]]);
        $run = $batch->runs->sole();
    } else {
        $run = $cycle->createRun($orderLine, $runData);
    }

    return compact('cycle', 'order', 'orderLine', 'run', 'batch');
}

/** @param array<string, mixed> $fixture @return array<string, mixed> */
function manufacturingIntegritySession(array $fixture): array
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

/** @return array<string, mixed> */
function productionCorrectionCompletedFixture(bool $withPiece = false, string $suffix = '', array $receiptParts = [], bool $forSales = false, bool $isolatedCompany = false): array
{
    $fixture = manufacturingInventoryFixture($suffix, $isolatedCompany);
    $fixture['branch']->update(['type' => Branch::TypeFactory]);
    $employee = null;
    if ($withPiece) {
        $fixture['finished']->update(['tracks_expiry' => true, 'default_shelf_life_days' => 180]);
        $employeeNumber = max(99271, (int) HrEmployee::withTrashed()->max('doc_number') + 1);
        $employee = HrEmployee::query()->create([
            'company_id' => $fixture['company']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
            'doc_number' => $employeeNumber, 'doc_num' => 'SYNTHETIC-CORRECTION-PIECE-'.$employeeNumber, 'employee_code' => 'SYNTHETIC-CORRECTION-PIECE-'.$employeeNumber,
            'full_name' => 'Synthetic correction operator', 'name' => 'Synthetic correction operator',
            'person_type' => 'regular_labor', 'status' => 'active', 'hire_date' => now()->startOfMonth()->toDateString(),
            'contract_start_date' => now()->startOfMonth()->toDateString(), 'pay_basis' => 'piece_rate', 'piece_rate' => '10',
        ]);
        HrPayrollAttendancePolicy::query()->create([
            'company_id' => $fixture['company']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
            'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(), 'effective_from' => now()->startOfMonth()->toDateString(),
            'piece_accrual_method' => HrPayrollAttendancePolicy::PieceApprovedOutput, 'salary_day_divisor' => 30, 'standard_day_minutes' => 480, 'status' => 'active',
        ]);
    }
    if ($forSales) {
        $currency = Currency::query()->where('company_id', $fixture['company']->id)->firstOrFail();
        $customer = Customer::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 99310,
            'doc_num' => 'SYNTHETIC-DATE-CUSTOMER'.$suffix, 'name' => 'Synthetic date lineage customer', 'status' => 'active']);
        $salesOrder = SalesOrder::query()->create(['company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
            'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id, 'doc_number' => 99310,
            'doc_num' => 'SYNTHETIC-DATE-SO'.$suffix, 'customer_id' => $customer->id, 'currency_id' => $currency->id,
            'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addWeek()->toDateString(), 'status' => SalesOrder::StatusApproved, 'credit_status' => 'approved',
            'subtotal_amount' => '100', 'total_amount' => '100']);
        $salesLine = $salesOrder->lines()->create(['line_number' => 1, 'product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id,
            'description' => $fixture['finished']->name, 'quantity' => '10', 'unit_price' => '10', 'line_total' => '100', 'conversion_factor' => '1', 'base_quantity' => '10',
            'product_classification_snapshot' => Product::ClassificationFinishedProduct]);
        $cycle = app(ProductionCycleService::class);
        $order = app(SalesProductionDemandService::class)->create($salesOrder, [['sales_order_line_id' => $salesLine->id, 'quantity' => '10']]);
        $orderLine = $cycle->releaseOrder($order)->lines->sole();
        $run = $cycle->createRun($orderLine, ['planned_quantity' => '10', 'planned_start_at' => now()->addHour(), 'planned_end_at' => now()->addHours(2),
            'production_machine_id' => $fixture['machine']->id, 'production_mold_id' => $fixture['mold']->id, 'batch_lot' => 'INTEGRITY-LOT-001']);
        $details = compact('cycle', 'order', 'orderLine', 'run', 'salesOrder', 'salesLine');
    } else {
        $details = manufacturingIntegrityRun($fixture, '10');
    }
    $cycle = $details['cycle'];
    $run = $details['run'];
    $cycle->reserveRun($run, $fixture['store']->getKey());
    $issue = $cycle->issueMaterials($run, $fixture['store']->getKey());
    $run = $cycle->startRun($cycle->completeSetup($cycle->startSetup($run)));
    if ($employee !== null) {
        $cycle->recordLabor($run, ['actual_labor_count' => 1, 'labor_details' => [['employee_id' => $employee->getKey(), 'actual_hours' => '1', 'piece_quantity' => '10']]]);
    }
    $cycle->recordProgress($run, ['good_base_quantity' => '10']);
    $requirement = $run->requirements()->firstOrFail();
    $costDocuments = $cycle->accountMaterials($run->fresh(), $fixture['store']->getKey(), [
        $requirement->getKey() => ['consumed_quantity' => '19', 'waste_quantity' => '1'],
    ]);
    $receipt = null;
    $receipts = [];
    $originalTime = now();
    foreach ($receiptParts ?: [['quantity' => '10', 'date' => $originalTime->toDateTimeString()]] as $part) {
        test()->travelTo(Carbon::parse($part['date']));
        if (isset($part['batch'])) {
            $run->update(['batch_lot' => $part['batch']]);
        }
        $receipt = $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), $part['quantity']);
        $receipts[] = $receipt;
    }
    test()->travelTo($originalTime);
    $run = $cycle->completeRun($run->fresh());
    foreach (['production.runs.correct', 'production.runs.correct_approve', 'production.runs.view', 'inventory.documents.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $approver = closureSyntheticUser();
    $approver->givePermissionTo(['production.runs.correct_approve', 'production.runs.view', 'inventory.documents.view']);

    return [...$fixture, ...$details, 'run' => $run, 'requirement' => $requirement, 'issue' => $issue,
        'receipt' => $receipt, 'receipts' => $receipts, 'costDocuments' => $costDocuments, 'approver' => $approver, 'employee' => $employee];
}

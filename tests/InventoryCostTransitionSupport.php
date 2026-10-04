<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Str;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ClosureAcceptanceSupport.php';

/** @return array<string, mixed> */
function costTransitionFixture(string $suffix = '', bool $isolatedCompany = false): array
{
    $suffix = $suffix === '' ? '-SYNTHETIC-'.Str::random(8) : $suffix;
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(CurrencySeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    $preparer = closureSyntheticUser();
    $approver = closureSyntheticUser();
    auth()->login($preparer);
    request()->setUserResolver(fn (): User => $preparer);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    if ($isolatedCompany) {
        $sourcePeriod = FinancialPeriod::query()->where('company_id', $company->id)->where('is_closed', false)->firstOrFail();
        $companyNumber = (int) Company::withTrashed()->max('doc_number') + 1;
        $company = Company::factory()->create(['doc_number' => $companyNumber, 'doc_num' => 'SYNTHETIC-COST-COMPANY-'.$companyNumber, 'name' => 'SYNTHETIC isolated cost transition'.$suffix]);
        FinancialPeriod::query()->create(['company_id' => $company->id, 'doc_number' => (int) FinancialPeriod::withTrashed()->max('doc_number') + 1,
            'doc_num' => 'SYNTHETIC-COST-PERIOD'.$suffix, 'name' => 'SYNTHETIC isolated cost period',
            'from_date' => $sourcePeriod->from_date, 'to_date' => $sourcePeriod->to_date, 'is_closed' => false]);
        test()->seed(CurrencySeeder::class);
        test()->seed(DefaultChartOfAccountsSeeder::class);
    }
    $branch = Branch::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => max(992001, (int) Branch::withTrashed()->max('doc_number') + 1),
        'doc_num' => 'COST-TRANSITION-BRANCH'.$suffix,
        'name' => 'Cost transition branch'.$suffix,
        'type' => Branch::TypeWarehouse,
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $store = BranchStore::query()->create(['branch_id' => $branch->getKey(), 'name' => 'Cost transition store'.$suffix]);
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => max(992001, (int) ItemUnit::withTrashed()->max('doc_number') + 1),
        'doc_num' => 'COST-TRANSITION-UNIT'.$suffix,
        'name' => 'Cost transition unit'.$suffix,
        'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => max(992001, (int) Product::withTrashed()->max('doc_number') + 1),
        'doc_num' => 'COST-TRANSITION-ITEM'.$suffix,
        'name' => 'Cost transition item'.$suffix,
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);

    foreach ([
        'inventory.cost_policies.manage',
        'inventory.cost_policies.view',
        'inventory.cost_policies.transition.prepare',
        'inventory.cost_policies.transition.approve',
        'inventory.cost_policies.transition.activate',
        'inventory.cost_policies.transition.cancel',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $preparer->givePermissionTo([
        'inventory.cost_policies.manage',
        'inventory.cost_policies.view',
        'inventory.cost_policies.transition.prepare',
        'inventory.cost_policies.transition.cancel',
    ]);
    $approver->givePermissionTo([
        'inventory.cost_policies.view',
        'inventory.cost_policies.transition.approve',
        'inventory.cost_policies.transition.activate',
        'inventory.cost_policies.transition.cancel',
    ]);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);

    return compact('preparer', 'approver', 'company', 'branch', 'period', 'store', 'unit', 'product');
}

/** @param array<string, mixed> $fixture */
function costTransitionMovement(array $fixture, string $date, string $type, string $quantity, ?string $unitCost = null, array $lineOverrides = []): InventoryDocument
{
    $store = $fixture['movement_store'] ?? $fixture['store'];

    return app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $store->getKey(),
        'source_stock_status' => InventoryTransaction::StatusAvailable,
        'document_type' => $type,
        'document_date' => $date,
    ], [[
        'product_id' => $fixture['product']->getKey(),
        'quantity' => $quantity,
        ...($unitCost === null ? [] : ['unit_cost' => $unitCost]),
        ...$lineOverrides,
    ]]);
}

/** @param array<string, mixed> $fixture @param array<string, mixed> $overrides */
function costTransitionTransaction(array $fixture, array $overrides): InventoryTransaction
{
    static $sourceId = 0;
    $sourceId++;

    return InventoryTransaction::query()->create([
        'posting_key' => 'cost-transition-'.Str::uuid(),
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => $fixture['period']->from_date->copy()->addDay()->toDateString(),
        'transaction_type' => InventoryDocument::TypeReceipt,
        'product_id' => $fixture['product']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => '0',
        'quantity_out' => '0',
        'source_type' => 'cost_transition_test',
        'source_id' => $sourceId,
        'source_doc_num' => 'COST-TRANSITION-'.$sourceId,
        'created_by' => auth()->id(),
        ...$overrides,
    ]);
}

/** @param array<string, mixed> $fixture @return array<string, int|string> */
function costTransitionSession(array $fixture): array
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

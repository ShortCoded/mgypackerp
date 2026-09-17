<?php

use App\Models\User;
use Database\Seeders\RuntimeDemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Finance\Models\FundTransfer;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetDepreciationRun;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;

function runtimeDemoCount(string $table): int
{
    return DB::table($table)
        ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
        ->count();
}

function runtimeDemoPrepareSqliteIndexes(): void
{
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('DROP INDEX IF EXISTS companies_one_active_main_unique');
    }
}

function runtimeDemoSetEnv(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        return;
    }

    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

/**
 * @return array<string, int>
 */
function runtimeDemoCounts(): array
{
    return [
        'companies' => runtimeDemoCount('companies'),
        'branches' => runtimeDemoCount('branches'),
        'financial_periods' => runtimeDemoCount('financial_periods'),
        'item_units' => runtimeDemoCount('item_units'),
        'item_sizes' => runtimeDemoCount('item_sizes'),
        'item_colors' => runtimeDemoCount('item_colors'),
        'item_models' => runtimeDemoCount('item_models'),
        'item_categories' => runtimeDemoCount('item_categories'),
        'item_groups' => runtimeDemoCount('item_groups'),
        'products' => runtimeDemoCount('products'),
        'roles' => runtimeDemoCount('roles'),
        'users' => runtimeDemoCount('users'),
    ];
}

test('runtime demo data seeder is opt in only', function () {
    expect(file_get_contents(database_path('seeders/DatabaseSeeder.php')))
        ->not->toContain('RuntimeDemoDataSeeder');
});

test('runtime demo data seeder skips production unless explicitly allowed', function () {
    runtimeDemoPrepareSqliteIndexes();

    $originalEnvironment = app()->environment();
    $originalAllowValue = env('ALLOW_RUNTIME_DEMO_DATA');

    try {
        app()->detectEnvironment(fn (): string => 'production');
        runtimeDemoSetEnv('ALLOW_RUNTIME_DEMO_DATA', 'false');

        $this->artisan('db:seed', [
            '--class' => RuntimeDemoDataSeeder::class,
            '--force' => true,
        ])->assertExitCode(0);

        expect(runtimeDemoCount('companies'))->toBe(0);

        runtimeDemoSetEnv('ALLOW_RUNTIME_DEMO_DATA', 'true');

        $this->artisan('db:seed', [
            '--class' => RuntimeDemoDataSeeder::class,
            '--force' => true,
        ])->assertExitCode(0);

        expect(runtimeDemoCount('companies'))->toBeGreaterThanOrEqual(2);
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
        runtimeDemoSetEnv(
            'ALLOW_RUNTIME_DEMO_DATA',
            $originalAllowValue === null ? null : (string) $originalAllowValue,
        );
    }
});

test('runtime demo data seeder creates useful scoped runtime master data idempotently', function () {
    runtimeDemoPrepareSqliteIndexes();

    $this->seed(RuntimeDemoDataSeeder::class);

    $firstCounts = runtimeDemoCounts();

    $this->seed(RuntimeDemoDataSeeder::class);

    expect(runtimeDemoCounts())->toBe($firstCounts);

    $companies = Company::query()
        ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
        ->orderBy('name')
        ->get();

    expect($companies->count())->toBeGreaterThanOrEqual(2)
        ->and($firstCounts['branches'])->toBeGreaterThanOrEqual($companies->count())
        ->and($firstCounts['financial_periods'])->toBeGreaterThanOrEqual($companies->count())
        ->and($firstCounts['products'])->toBeGreaterThanOrEqual($companies->count())
        ->and($firstCounts['roles'])->toBeGreaterThanOrEqual(3)
        ->and($firstCounts['users'])->toBeGreaterThanOrEqual(3);

    $validBranchTypes = Branch::types();

    $companies->each(function (Company $company) use ($validBranchTypes): void {
        $branches = DB::table('branches')
            ->where('company_id', $company->getKey())
            ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
            ->get();

        $periods = DB::table('financial_periods')
            ->where('company_id', $company->getKey())
            ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
            ->get();

        expect($branches->count())->toBeGreaterThanOrEqual(1)
            ->and($periods->count())->toBeGreaterThanOrEqual(1)
            ->and($branches->pluck('type')->diff($validBranchTypes)->values()->all())->toBe([]);

        foreach (['item_units', 'item_sizes', 'item_colors', 'item_models', 'item_categories', 'item_groups'] as $table) {
            expect(DB::table($table)
                ->where('company_id', $company->getKey())
                ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
                ->count())->toBeGreaterThan(0);
        }

        expect(Product::query()
            ->where('company_id', $company->getKey())
            ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
            ->count())->toBeGreaterThan(0);
    });

    $fullRole = Role::query()
        ->where('name', 'runtime-demo-full-admin')
        ->with(['companyAccessCompanies', 'branchAccessBranches', 'financialPeriodAccessPeriods'])
        ->firstOrFail();

    expect($fullRole->company_access_restricted)->toBeFalse()
        ->and($fullRole->branch_access_restricted)->toBeFalse()
        ->and($fullRole->financial_period_access_restricted)->toBeFalse()
        ->and($fullRole->permissions()->count())->toBe(count(app(PermissionRegistryService::class)->all()))
        ->and($fullRole->hasPermissionTo('products.view'))->toBeTrue()
        ->and($fullRole->companyAccessCompanies)->toHaveCount(0)
        ->and($fullRole->branchAccessBranches)->toHaveCount(0)
        ->and($fullRole->financialPeriodAccessPeriods)->toHaveCount(0);

    $nileRole = Role::query()
        ->where('name', 'runtime-demo-nile-scope-admin')
        ->with(['companyAccessCompanies', 'branchAccessBranches', 'financialPeriodAccessPeriods'])
        ->firstOrFail();

    expect($nileRole->company_access_restricted)->toBeTrue()
        ->and($nileRole->branch_access_restricted)->toBeTrue()
        ->and($nileRole->financial_period_access_restricted)->toBeTrue()
        ->and($nileRole->companyAccessCompanies->pluck('name')->all())
        ->toBe(['Mgy Plast Manufacturing - Runtime Demo'])
        ->and($nileRole->branchAccessBranches->pluck('name')->sort()->values()->all())
        ->toBe([
            '10th of Ramadan Distribution Warehouse - Runtime Demo',
            '10th of Ramadan Plastic Factory - Runtime Demo',
        ])
        ->and($nileRole->financialPeriodAccessPeriods->pluck('name')->sort()->values()->all())
        ->toBe([
            'FY 2026 - Runtime Demo',
            'FY 2027 - Runtime Demo',
        ]);

    $deltaRole = Role::query()
        ->where('name', 'runtime-demo-delta-operator')
        ->with(['companyAccessCompanies', 'branchAccessBranches', 'financialPeriodAccessPeriods'])
        ->firstOrFail();

    expect($deltaRole->companyAccessCompanies->pluck('name')->all())
        ->toBe(['Delta Packaging Trading - Runtime Demo'])
        ->and($deltaRole->branchAccessBranches->pluck('name')->all())
        ->toBe(['Alexandria Packaging Sales Office - Runtime Demo'])
        ->and($deltaRole->financialPeriodAccessPeriods->pluck('name')->all())
        ->toBe(['FY 2026 - Runtime Demo']);

    expect(User::query()->where('email', 'demo.full@shortcoded.test')->firstOrFail()->hasRole('runtime-demo-full-admin'))->toBeTrue()
        ->and(User::query()->where('email', 'demo.nile@shortcoded.test')->firstOrFail()->hasRole('runtime-demo-nile-scope-admin'))->toBeTrue()
        ->and(User::query()->where('email', 'demo.delta@shortcoded.test')->firstOrFail()->hasRole('runtime-demo-delta-operator'))->toBeTrue();

    $products = Product::query()
        ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
        ->with(['unit', 'size', 'color', 'itemModel', 'category', 'group'])
        ->get();

    expect($products->count())->toBeGreaterThanOrEqual($companies->count())
        ->and($products->whereNotNull('item_unit_id')->count())->toBe($products->count())
        ->and($products->whereNotNull('item_category_id')->count())->toBe($products->count())
        ->and($products->whereNotNull('item_group_id')->count())->toBe($products->count());

    $products->groupBy('company_id')
        ->each(function ($companyProducts): void {
            expect($companyProducts->whereNotNull('item_color_id')->count())->toBeGreaterThan(0)
                ->and($companyProducts->whereNotNull('item_size_id')->count())->toBeGreaterThan(0)
                ->and($companyProducts->whereNotNull('item_model_id')->count())->toBeGreaterThan(0);
        });

    $products->each(function (Product $product): void {
        foreach (['unit', 'size', 'color', 'itemModel', 'category', 'group'] as $relation) {
            if ($product->{$relation} === null) {
                continue;
            }

            expect($product->{$relation}->company_id)->toBe($product->company_id);
        }
    });

    $primaryCompany = Company::query()->where('name', 'Mgy Plast Manufacturing - Runtime Demo')->firstOrFail();
    $factoryBranch = Branch::query()
        ->where('company_id', $primaryCompany->getKey())
        ->where('name', '10th of Ramadan Plastic Factory - Runtime Demo')
        ->firstOrFail();
    $finishedStore = BranchStore::query()
        ->where('branch_id', $factoryBranch->getKey())
        ->where('name', 'Finished Goods Warehouse - Runtime Demo')
        ->firstOrFail();
    expect(BranchStore::query()->where('branch_id', $factoryBranch->getKey())->count())->toBeGreaterThanOrEqual(3);

    foreach ([
        'customers' => 3,
        'suppliers' => 3,
        'cashboxes' => 1,
        'bank_accounts' => 1,
        'inventory_opening_stocks' => 2,
        'purchase_requisitions' => 2,
        'request_for_quotations' => 1,
        'supplier_quotations' => 2,
        'supplier_selections' => 1,
        'purchase_orders' => 1,
        'unpriced_inventory_receipts' => 1,
        'goods_receipt_inspections' => 1,
        'purchase_invoices' => 1,
        'supplier_payment_contexts' => 1,
        'production_orders' => 1,
        'production_runs' => 1,
        'quality_inspections' => 3,
        'quotations' => 1,
        'sales_orders' => 2,
        'customer_invoices' => 1,
        'customer_receipts' => 2,
        'sales_returns' => 1,
        'fixed_assets' => 2,
        'fixed_asset_depreciation_runs' => 1,
        'fund_transfers' => 1,
        'cheques' => 1,
    ] as $table => $expectedCount) {
        expect(DB::table($table)->where('company_id', $primaryCompany->getKey())->count())
            ->toBeGreaterThanOrEqual($expectedCount, "The integrated demo is missing records in {$table}.");
    }

    $purchaseInvoice = PurchaseInvoice::query()
        ->where('company_id', $primaryCompany->getKey())
        ->where('supplier_invoice_number', 'EPS-INV-260216-77')
        ->sole();
    expect($purchaseInvoice->status)->toBe(PurchaseInvoice::StatusApproved)
        ->and($purchaseInvoice->payment_status)->toBe(PurchaseInvoice::PaymentStatusPartiallyPaid)
        ->and((string) $purchaseInvoice->total_amount)->toBe('268128.0000')
        ->and((string) $purchaseInvoice->paid_amount)->toBe('100000.0000')
        ->and((string) $purchaseInvoice->remaining_amount)->toBe('168128.0000');

    $supplierPayment = SupplierPaymentContext::query()
        ->where('company_id', $primaryCompany->getKey())
        ->where('reason', 'First resin invoice installment')
        ->sole();
    expect($supplierPayment->status)->toBe(SupplierPaymentContext::StatusApproved)
        ->and((string) $supplierPayment->amount)->toBe('100000.0000')
        ->and((string) $supplierPayment->allocated_amount)->toBe('100000.0000');

    $customerInvoice = CustomerInvoice::query()
        ->where('company_id', $primaryCompany->getKey())
        ->where('total_amount', '18500')
        ->sole();
    expect($customerInvoice->status)->toBe(CustomerInvoice::StatusPosted)
        ->and((string) $customerInvoice->total_amount)->toBe('18500.0000')
        ->and((string) $customerInvoice->paid_amount)->toBe('10000.0000')
        ->and((string) $customerInvoice->credited_amount)->toBe('850.0000')
        ->and((string) $customerInvoice->remaining_amount)->toBe('7650.0000')
        ->and(CustomerReceipt::query()->where('company_id', $primaryCompany->getKey())->where('receipt_type', CustomerReceipt::TypeAdvance)->value('unallocated_amount'))->toBe('25000.0000');

    $productionRun = ProductionRun::query()
        ->where('company_id', $primaryCompany->getKey())
        ->where('batch_lot', 'PAIL-BLU-260403-A')
        ->with('requirements')
        ->sole();
    expect($productionRun->status)->toBe(ProductionRun::StatusCompleted)
        ->and((string) $productionRun->good_base_quantity)->toBe('490.00000000')
        ->and((string) $productionRun->scrap_base_quantity)->toBe('10.00000000')
        ->and((string) $productionRun->received_base_quantity)->toBe('490.00000000')
        ->and(ProductionQualityInspection::query()->where('production_run_id', $productionRun->getKey())->where('result', 'passed')->count())->toBe(2)
        ->and(ProductionQualityInspection::query()->where('production_run_id', $productionRun->getKey())->where('result', 'failed')->count())->toBe(1);

    $productionRun->requirements->each(function ($requirement): void {
        $issued = bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8);
        $accounted = bcadd(
            bcadd((string) $requirement->consumed_quantity, (string) $requirement->waste_quantity, 8),
            (string) $requirement->returned_quantity,
            8,
        );

        expect($accounted)->toBe($issued);
    });

    expect(FixedAsset::query()->where('company_id', $primaryCompany->getKey())->where('status', FixedAsset::StatusActive)->count())->toBeGreaterThanOrEqual(1)
        ->and(FixedAsset::query()->where('company_id', $primaryCompany->getKey())->where('status', FixedAsset::StatusDraft)->count())->toBe(1)
        ->and(FixedAssetDepreciationRun::query()->where('company_id', $primaryCompany->getKey())->where('status', FixedAssetDepreciationRun::StatusPosted)->count())->toBeGreaterThanOrEqual(10)
        ->and(FundTransfer::query()->where('company_id', $primaryCompany->getKey())->where('status', FundTransfer::StatusApproved)->value('source_amount'))->toBe('50000.0000')
        ->and(InventoryReservation::query()->where('company_id', $primaryCompany->getKey())->where('status', InventoryReservation::StatusActive)->count())->toBe(0);

    $pail = Product::query()->where('company_id', $primaryCompany->getKey())->where('barcode', 'MGY-FG-PAIL-20L-BLU')->firstOrFail();
    $availablePails = InventoryTransaction::query()
        ->where('company_id', $primaryCompany->getKey())
        ->where('branch_store_id', $finishedStore->getKey())
        ->where('product_id', $pail->getKey())
        ->where('stock_status', InventoryTransaction::StatusAvailable)
        ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as quantity')
        ->value('quantity');
    $negativePositions = InventoryTransaction::query()
        ->where('company_id', $primaryCompany->getKey())
        ->groupBy('branch_store_id', 'product_id', 'stock_status')
        ->selectRaw('branch_store_id, product_id, stock_status, sum(quantity_in - quantity_out) as quantity')
        ->havingRaw('sum(quantity_in - quantity_out) < -0.00000001')
        ->get();
    expect((float) $availablePails)->toBeGreaterThan(1000)
        ->and($negativePositions->all())->toBe([], 'Negative inventory positions: '.$negativePositions->toJson());

    $postedJournals = JournalEntry::query()
        ->where('company_id', $primaryCompany->getKey())
        ->where('is_posted', true)
        ->with('lines')
        ->get();
    expect($postedJournals->count())->toBeGreaterThanOrEqual(10);
    $postedJournals->each(function (JournalEntry $journal): void {
        expect($journal->lines)->not->toBeEmpty();

        $debits = $journal->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->debit_amount, 4), '0.0000');
        $credits = $journal->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->credit_amount, 4), '0.0000');

        expect($debits)->toBe($credits, "Journal {$journal->doc_num} is not balanced.");
    });
});

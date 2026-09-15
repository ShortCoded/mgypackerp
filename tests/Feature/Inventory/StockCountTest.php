<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\WarehouseLocation;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** @return array<string, mixed> */
function stockCountFixture(array $permissions = []): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $store = BranchStore::query()->create(['branch_id' => $branch->getKey(), 'name' => 'Count Store', 'position' => 1]);
    $location = WarehouseLocation::query()->create(['branch_store_id' => $store->getKey(), 'code' => 'A-01', 'name' => 'Aisle 1', 'is_active' => true]);
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 801, 'doc_num' => 'UNIT-COUNT',
        'name' => 'Piece', 'status' => 'active',
    ]);
    $firstProduct = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 901, 'doc_num' => 'COUNT-ITEM-1',
        'name' => 'Count Item One', 'barcode' => 'COUNT001',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);
    $secondProduct = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 902, 'doc_num' => 'COUNT-ITEM-2',
        'name' => 'Count Item Two', 'barcode' => 'COUNT002',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    $session = [
        'locale' => 'en',
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    return compact('company', 'branch', 'period', 'store', 'location', 'unit', 'firstProduct', 'secondProduct', 'user', 'session');
}

/** @param array<string, mixed> $fixture */
function stockCountTransaction(array $fixture, Product $product, string $quantity, string $status = InventoryTransaction::StatusAvailable, ?string $batch = null): InventoryTransaction
{
    return InventoryTransaction::query()->create([
        'posting_key' => 'stock-count-test-'.str()->uuid(),
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'warehouse_location_id' => $fixture['location']->getKey(),
        'stock_status' => $status,
        'batch_lot' => $batch,
        'transaction_date' => now()->toDateString(),
        'transaction_type' => 'opening_stock',
        'product_id' => $product->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => $quantity,
        'quantity_out' => 0,
        'source_type' => 'test',
        'source_id' => 1,
        'source_doc_num' => 'COUNT-OPENING',
        'unit_cost' => 1,
        'total_cost' => $quantity,
        'created_by' => $fixture['user']->getKey(),
    ]);
}

/** @param array<string, mixed> $fixture @param list<array<string, mixed>> $lines */
function stockCountPayload(array $fixture, array $lines, array $overrides = []): array
{
    return [
        'branch_store_id' => $fixture['store']->getKey(),
        'warehouse_location_id' => $fixture['location']->getKey(),
        'count_date' => now()->toDateString(),
        'notes' => 'Full master detail stock count',
        'submit_action' => 'save_view',
        'lines' => $lines,
        ...$overrides,
    ];
}

test('stock count uses the standard master detail datatable and crud surface', function (): void {
    $permissions = [
        'inventory.stock_counts.view', 'inventory.stock_counts.create', 'inventory.stock_counts.edit',
        'inventory.stock_counts.clone', 'inventory.stock_counts.delete', 'inventory.stock_counts.view_trashed',
        'inventory.stock_counts.restore', 'inventory.stock_counts.approve', 'inventory.stock_counts.print',
        'inventory.stock_counts.export',
    ];
    $fixture = stockCountFixture($permissions);

    foreach (['index', 'data', 'create', 'store', 'show', 'edit', 'update', 'clone', 'destroy', 'restore', 'approve', 'print', 'export', 'balance'] as $route) {
        expect(Route::has("admin.inventory.stock-counts.{$route}"))->toBeTrue();
    }
    expect(Schema::hasColumn('inventory_stock_counts', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_stock_counts', 'restored_at'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_stock_count_lines', 'deleted_at'))->toBeTrue();

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.inventory.stock-counts.index'))
        ->assertOk()
        ->assertSee('js-stock-counts-table', false)
        ->assertSee(route('admin.inventory.stock-counts.data'), false)
        ->assertSee(route('admin.inventory.stock-counts.create'), false);

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.inventory.stock-counts.create'))
        ->assertOk()
        ->assertSee('js-stock-count-form', false)
        ->assertSee('stock-count-line-template', false)
        ->assertSee('lines[0][product_doc_num]', false)
        ->assertSee('data-submit-action="save_view"', false)
        ->assertSee('data-submit-action="save_edit"', false)
        ->assertSee('data-submit-action="save_back"', false)
        ->assertDontSee('product_ids[]', false);
});

test('one stock count save calculates shortage surplus and supports report approval and locking', function (): void {
    $permissions = [
        'inventory.stock_counts.view', 'inventory.stock_counts.create', 'inventory.stock_counts.edit',
        'inventory.stock_counts.delete', 'inventory.stock_counts.approve', 'inventory.stock_counts.print',
        'inventory.stock_counts.export',
    ];
    $fixture = stockCountFixture($permissions);
    test()->seed(CurrencySeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    stockCountTransaction($fixture, $fixture['firstProduct'], '10');
    stockCountTransaction($fixture, $fixture['secondProduct'], '2', InventoryTransaction::StatusDamaged, 'LOT-2');
    $payload = stockCountPayload($fixture, [
        ['product_doc_num' => $fixture['firstProduct']->doc_num, 'stock_status' => InventoryTransaction::StatusAvailable, 'physical_quantity' => '8', 'variance_reason' => 'Two units missing'],
        ['product_doc_num' => $fixture['secondProduct']->doc_num, 'stock_status' => InventoryTransaction::StatusDamaged, 'batch_lot' => 'LOT-2', 'physical_quantity' => '5', 'variance_reason' => 'Three units found'],
    ]);

    $response = $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->postJson(route('admin.inventory.stock-counts.store'), $payload)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('submit_action', 'save_view');
    $record = StockCount::query()->with('lines')->sole();
    expect($record->status)->toBe(StockCount::StatusCounted)
        ->and($record->lines)->toHaveCount(2)
        ->and($record->lines[0]->system_quantity)->toBe('10.00000000')
        ->and($record->lines[0]->variance_quantity)->toBe('-2.00000000')
        ->and($record->lines[1]->system_quantity)->toBe('2.00000000')
        ->and($record->lines[1]->variance_quantity)->toBe('3.00000000');
    expect($response->json('redirect'))->toBe(route('admin.inventory.stock-counts.show', $record));

    $dataResponse = $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->getJson(route('admin.inventory.stock-counts.data'))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 1)
        ->assertJsonPath('data.0.lines_count', '2');
    expect($dataResponse->json('data.0.doc_num'))->toContain($record->doc_num);

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->getJson(route('admin.inventory.stock-counts.balance', [
            'branch_store_id' => $fixture['store']->getKey(),
            'warehouse_location_id' => $fixture['location']->getKey(),
            'product_doc_num' => $fixture['firstProduct']->doc_num,
            'stock_status' => InventoryTransaction::StatusAvailable,
        ]))
        ->assertOk()
        ->assertJsonPath('data.system_quantity', '10.00000000');

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.inventory.stock-counts.show', $record))
        ->assertOk()
        ->assertSee('Shortage')
        ->assertSee('Surplus')
        ->assertSee('Approve Variances')
        ->assertSee(route('admin.inventory.stock-counts.export', $record), false);

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.inventory.stock-counts.print', $record))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.inventory.stock-counts.export', $record))
        ->assertOk()
        ->assertHeader('content-disposition');

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->postJson(route('admin.inventory.stock-counts.approve', $record))
        ->assertOk()
        ->assertJsonPath('success', true);
    expect($record->fresh()->status)->toBe(StockCount::StatusApproved);

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.inventory.stock-counts.edit', $record))
        ->assertForbidden();
    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->deleteJson(route('admin.inventory.stock-counts.destroy', $record))
        ->assertUnprocessable();
});

test('stock count update and soft delete restore master and details', function (): void {
    $permissions = ['inventory.stock_counts.view', 'inventory.stock_counts.create', 'inventory.stock_counts.edit', 'inventory.stock_counts.delete', 'inventory.stock_counts.view_trashed', 'inventory.stock_counts.restore'];
    $fixture = stockCountFixture($permissions);
    $line = fn (Product $product): array => ['product_doc_num' => $product->doc_num, 'stock_status' => InventoryTransaction::StatusAvailable, 'physical_quantity' => '0'];

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->postJson(route('admin.inventory.stock-counts.store'), stockCountPayload($fixture, [$line($fixture['firstProduct']), $line($fixture['secondProduct'])]))
        ->assertOk();
    $record = StockCount::query()->with('lines')->sole();
    $firstLine = $record->lines->first();

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->putJson(route('admin.inventory.stock-counts.update', $record), stockCountPayload($fixture, [['line_id' => $firstLine->getKey(), ...$line($fixture['firstProduct'])]], ['submit_action' => 'save_edit']))
        ->assertOk()
        ->assertJsonPath('success', true);
    expect($record->fresh()->lines)->toHaveCount(1)
        ->and($record->lines()->onlyTrashed()->count())->toBe(1);

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->deleteJson(route('admin.inventory.stock-counts.destroy', $record))
        ->assertOk();
    $deleted = StockCount::withTrashed()->findOrFail($record->getKey());
    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->lines()->withTrashed()->whereNotNull('deleted_at')->count())->toBe(2);
    $trashedDataResponse = $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->getJson(route('admin.inventory.stock-counts.data', ['trash_filter' => 'trashed']))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 1);
    expect($trashedDataResponse->json('data.0.doc_num'))->toContain($record->doc_num);

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->patchJson(route('admin.inventory.stock-counts.restore', $deleted))
        ->assertOk();
    expect($deleted->fresh()->trashed())->toBeFalse()
        ->and($deleted->lines()->count())->toBe(1)
        ->and($deleted->lines()->onlyTrashed()->count())->toBe(1);
});

test('stock count rejects duplicate details and another documents line identity', function (): void {
    $fixture = stockCountFixture(['inventory.stock_counts.create', 'inventory.stock_counts.edit']);
    $duplicate = ['product_doc_num' => $fixture['firstProduct']->doc_num, 'stock_status' => InventoryTransaction::StatusAvailable, 'batch_lot' => 'LOT-X', 'physical_quantity' => '0'];

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->postJson(route('admin.inventory.stock-counts.store'), stockCountPayload($fixture, [$duplicate, $duplicate]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines.1.product_doc_num');

    $line = fn (Product $product): array => ['product_doc_num' => $product->doc_num, 'stock_status' => InventoryTransaction::StatusAvailable, 'physical_quantity' => '0'];
    foreach ([$fixture['firstProduct'], $fixture['secondProduct']] as $product) {
        $this->actingAs($fixture['user'])->withSession($fixture['session'])
            ->postJson(route('admin.inventory.stock-counts.store'), stockCountPayload($fixture, [$line($product)]))
            ->assertOk();
    }
    $records = StockCount::query()->with('lines')->orderBy('id')->get();

    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->putJson(route('admin.inventory.stock-counts.update', $records[0]), stockCountPayload($fixture, [['line_id' => $records[1]->lines->first()->getKey(), ...$line($fixture['firstProduct'])]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0.line_id');
});

test('stock count javascript includes datatable repeater balance and shortcut handlers', function (): void {
    $script = file_get_contents(public_path('assets/js/modules/Inventory/stock-counts.js'));

    expect($script)->toContain('serverSide: true')
        ->toContain('stock-count-line-template')
        ->toContain('refreshBalance')
        ->toContain("isAlt(event, 'n')")
        ->toContain("isAlt(event, 'd')")
        ->toContain("event.key === 'Delete'")
        ->toContain('js-finance-submit-action');
});

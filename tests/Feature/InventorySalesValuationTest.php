<?php

use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Inventory\Exports\InventorySalesValuationExport;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Sales\Models\PriceList;
use Modules\Sales\Services\PriceListPricingService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

/** @param array<string, mixed> $fixture */
function salesValuationTransaction(array $fixture, int $productId, int $storeId, string $quantityIn, string $quantityOut = '0', array $overrides = []): void
{
    InventoryTransaction::query()->create([
        'posting_key' => 'sales-valuation-'.Str::uuid(),
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $storeId,
        'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => now()->toDateString(),
        'transaction_type' => 'sales_valuation_test',
        'product_id' => $productId,
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => $quantityIn,
        'quantity_out' => $quantityOut,
        'source_type' => 'sales_valuation_test',
        'source_id' => random_int(10000, 99999),
        'source_doc_num' => 'SV-'.Str::random(8),
        ...$overrides,
    ]);
}

test('sales valuation uses explicit stored price lines and one price-list currency across stock positions', function (): void {
    $fixture = salesCycleFixture();
    $otherStore = BranchStore::query()->create(['branch_id' => $fixture['branch']->getKey(), 'name' => 'Sales valuation second store']);
    salesValuationTransaction($fixture, $fixture['finished']->getKey(), $otherStore->getKey(), '5');
    salesValuationTransaction($fixture, $fixture['raw']->getKey(), $fixture['store']->getKey(), '3');
    salesValuationTransaction($fixture, $fixture['service']->getKey(), $fixture['store']->getKey(), '4', '4');
    $priceList = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '12.5']]);
    $priceList->update(['is_print_only' => true]);

    $valuation = app(InventoryReportService::class)->salesValuation(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        ['as_of' => now()->toDateString(), 'price_list_id' => $priceList->getKey()],
    );

    expect($valuation['priceList']->is($priceList))->toBeTrue()
        ->and($valuation['priceList']->is_print_only)->toBeTrue()
        ->and($valuation['priceListCurrencyCode'])->toBe($fixture['currency']->code)
        ->and($valuation['totals']['position_count'])->toBe(3)
        ->and($valuation['totals']['product_count'])->toBe(2)
        ->and($valuation['totals']['unpriced_product_count'])->toBe(1)
        ->and($valuation['totals']['quantity'])->toBe('108.00000000')
        ->and($valuation['totals']['sales_value'])->toBe('1312.50000000')
        ->and($valuation['totals']['unpriced_quantity'])->toBe('3.00000000')
        ->and($valuation['rows']->where('product_id', $fixture['finished']->getKey()))->toHaveCount(2)
        ->and($valuation['rows']->firstWhere('product_id', $fixture['raw']->getKey())->price_status)->toBe('unpriced')
        ->and($valuation['rows']->contains(fn (object $row): bool => $row->product_id === $fixture['service']->getKey()))->toBeFalse();

    $storeOnly = app(InventoryReportService::class)->salesValuation(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        ['price_list_id' => $priceList->getKey(), 'branch_store_id' => $otherStore->getKey()],
    );
    expect($storeOnly['totals']['position_count'])->toBe(1)
        ->and($storeOnly['totals']['quantity'])->toBe('5.00000000')
        ->and($storeOnly['totals']['sales_value'])->toBe('62.50000000');

    $export = new InventorySalesValuationExport($valuation);
    expect($export->headings())->toHaveCount(10)
        ->and($export->array()[0][7])->toBe('12.5000')
        ->and($export->array()[0][8])->toBe('1250.00000000');

    expect(PriceList::query()->operationalPricingEligible()->whereKey($priceList)->exists())->toBeFalse();
    expect(fn () => app(PriceListPricingService::class)->resolve(
        $fixture['company']->getKey(), $fixture['customer']->getKey(), $fixture['currency']->getKey(),
        $fixture['finished'], $fixture['unit']->getKey(), 1, now()->toDateString(),
    ))->toThrow(DomainException::class);

    $operationalList = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '12.5']]);
    $operational = app(InventoryReportService::class)->salesValuation(
        $fixture['company']->getKey(), [$fixture['branch']->getKey()], ['price_list_id' => $operationalList->getKey()],
    );
    expect($operational['totals'])->toBe($valuation['totals'])
        ->and(app(PriceListPricingService::class)->resolve(
            $fixture['company']->getKey(), $fixture['customer']->getKey(), $fixture['currency']->getKey(),
            $fixture['finished'], $fixture['unit']->getKey(), 1, now()->toDateString(),
        )['unit_price'])->toBe('12.5000');
});

test('sales valuation applies meaningful as-of hall and location filters and rejects invalid hierarchy combinations', function (): void {
    $fixture = salesCycleFixture();
    $fixture['branch']->update(['type' => Branch::TypeWarehouse]);
    $hall = BranchHall::query()->create(['branch_id' => $fixture['branch']->getKey(), 'name' => 'Valuation Hall']);
    $location = WarehouseLocation::query()->create(['branch_store_id' => $fixture['store']->getKey(), 'code' => 'SV-L1', 'name' => 'Valuation Location']);
    salesValuationTransaction($fixture, $fixture['raw']->getKey(), $fixture['store']->getKey(), '9', '0', [
        'branch_hall_id' => $hall->getKey(), 'warehouse_location_id' => $location->getKey(),
        'transaction_date' => now()->addDay()->toDateString(),
    ]);
    $priceList = createSalesPriceList($fixture, null, [['product' => $fixture['raw'], 'price' => '7']]);
    $beforeFuture = app(InventoryReportService::class)->salesValuation($fixture['company']->getKey(), [$fixture['branch']->getKey()], [
        'price_list_id' => $priceList->getKey(), 'as_of' => now()->toDateString(), 'branch_hall_id' => $hall->getKey(), 'warehouse_location_id' => $location->getKey(),
    ]);
    $afterFuture = app(InventoryReportService::class)->salesValuation($fixture['company']->getKey(), [$fixture['branch']->getKey()], [
        'price_list_id' => $priceList->getKey(), 'as_of' => now()->addDay()->toDateString(), 'branch_hall_id' => $hall->getKey(), 'warehouse_location_id' => $location->getKey(),
    ]);
    expect($beforeFuture['rows'])->toBeEmpty()
        ->and($afterFuture['totals']['position_count'])->toBe(1)
        ->and($afterFuture['totals']['sales_value'])->toBe('63.00000000');

    Permission::findOrCreate('inventory.reports.operational', 'web');
    $fixture['user']->givePermissionTo('inventory.reports.operational');
    $otherBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 991197,
        'doc_num' => 'SV-OTHER-BRANCH',
        'name' => 'Other valuation branch',
        'type' => Branch::TypeWarehouse,
        'status' => 'active',
    ]);
    $otherHall = BranchHall::query()->create(['branch_id' => $otherBranch->getKey(), 'name' => 'Other Hall']);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.inventory.sales-valuation', [
            'price_list_id' => $priceList->getKey(), 'branch_doc_num' => $fixture['branch']->doc_num,
            'branch_hall_uuid' => $otherHall->public_uuid,
        ]))->assertSessionHasErrors('branch_hall_uuid');
    $this->get(route('admin.inventory.sales-valuation', [
        'price_list_id' => $priceList->getKey(), 'branch_store_uuid' => $fixture['store']->public_uuid,
        'branch_hall_uuid' => $otherHall->public_uuid,
    ]))->assertSessionHasErrors('branch_hall_uuid');
    $this->get(route('admin.inventory.sales-valuation', [
        'price_list_id' => $priceList->getKey(), 'branch_hall_uuid' => $otherHall->public_uuid,
        'warehouse_location_uuid' => $location->public_id,
    ]))->assertSessionHasErrors('warehouse_location_uuid');
});

test('sales valuation screen export and pdf use canonical routes and permissions', function (): void {
    $fixture = salesCycleFixture();
    $fixture['branch']->update(['type' => Branch::TypeWarehouse]);
    $priceList = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '12.5']]);
    $session = salesCycleSession($fixture);
    $this->actingAs($fixture['user'])->withSession($session);

    $this->get(route('admin.inventory.sales-valuation'))->assertForbidden();
    Permission::findOrCreate('inventory.reports.operational', 'web');
    $fixture['user']->givePermissionTo('inventory.reports.operational');
    $this->get(route('admin.inventory.sales-valuation'))
        ->assertOk()
        ->assertSee(__('inventory_accounting.sales_valuation.title'));
    $this->get(route('admin.inventory.sales-valuation', ['price_list_id' => $priceList->getKey()]))
        ->assertOk()
        ->assertSee($fixture['finished']->name)
        ->assertSee($fixture['currency']->code);

    $query = ['price_list_id' => $priceList->getKey(), 'as_of' => now()->toDateString()];
    $this->get(route('admin.inventory.sales-valuation.export', ['format' => 'xlsx', ...$query]))->assertForbidden();
    $this->get(route('admin.inventory.sales-valuation.print', $query))->assertForbidden();
    Permission::findOrCreate('inventory.reports.export', 'web');
    $fixture['user']->givePermissionTo('inventory.reports.export');

    $this->get(route('admin.inventory.sales-valuation.export', ['format' => 'xlsx', ...$query]))->assertOk()->assertDownload();
    $this->get(route('admin.inventory.sales-valuation.export', ['format' => 'csv', ...$query]))->assertOk()->assertDownload();
    $pdf = $this->get(route('admin.inventory.sales-valuation.print', $query))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
});

test('sales valuation rejects deleted and cross company price lists', function (): void {
    $fixture = salesCycleFixture();
    foreach (['inventory.reports.operational', 'inventory.reports.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    $priceList = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '12.5']]);
    $priceList->delete();

    $this->get(route('admin.inventory.sales-valuation', ['price_list_id' => $priceList->getKey()]))
        ->assertSessionHasErrors('price_list_id');

    $otherCompany = Company::factory()->create();
    $priceList->restore();
    $priceList->forceFill(['company_id' => $otherCompany->getKey()])->save();
    $this->getJson(route('admin.inventory.sales-valuation.export', ['format' => 'csv', 'price_list_id' => $priceList->getKey()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('price_list_id');
});

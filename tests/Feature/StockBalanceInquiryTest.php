<?php

use Illuminate\Support\Collection;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\ItemCategory;
use Modules\Core\Models\ItemColor;
use Modules\Core\Models\ItemModel;
use Modules\Core\Models\ItemOriginCountry;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Exports\StockBalanceInquiryExport;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

test('stock balance inquiry keeps quantities separate by product base unit in screen and export totals', function (): void {
    $fixture = procurementFixture();
    $piece = ItemUnit::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 9204, 'doc_num' => 'STOCK-PIECE',
        'name' => 'Piece', 'status' => 'active',
    ]);
    $fixture['finished']->forceFill(['item_unit_id' => $piece->getKey()])->save();

    foreach ([[$fixture['raw'], $fixture['unit'], '10'], [$fixture['finished'], $piece, '3']] as $index => [$product, $unit, $quantity]) {
        InventoryTransaction::query()->create([
            'posting_key' => 'stock-inquiry-mixed-'.$index,
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'branch_store_id' => $fixture['store']->getKey(),
            'transaction_date' => now()->toDateString(),
            'transaction_type' => 'purchase_receipt',
            'product_id' => $product->getKey(),
            'unit_id' => $unit->getKey(),
            'quantity_in' => $quantity,
            'quantity_out' => '0',
            'unit_cost' => '1',
            'total_cost' => $quantity,
            'source_type' => 'test',
            'source_id' => $index + 1,
            'source_doc_num' => 'TEST-MIXED-'.$index,
            'stock_status' => InventoryTransaction::StatusAvailable,
        ]);
    }

    $report = app(InventoryReportService::class)->stockBalanceInquiry(
        $fixture['company']->getKey(), [$fixture['branch']->getKey()], ['as_of' => now()->toDateString()],
    );
    $export = new StockBalanceInquiryExport($report['rows'], $report['totals']);
    $pdfRows = view('reports.inventory.stock-balance-inquiry', [
        'rows' => $report['rows'], 'totals' => $report['totals'],
        'filterSummary' => ['As of' => now()->toDateString()],
        'canViewFinancial' => true, 'reservationsAreHallScoped' => true,
    ])->render();

    expect($report['totals']['mixed_units'])->toBeTrue()
        ->and($report['totals']['inventory_value'])->toBe('13.00000000')
        ->and(collect($report['totals']['quantity_by_unit'])->pluck('on_hand', 'unit_name')->all())
        ->toBe([$fixture['unit']->name => '10.00000000', 'Piece' => '3.00000000'])
        ->and($export->array()[2][14])->toBeNull()
        ->and($export->array()[3][14])->toBe(10.0)
        ->and($export->array()[4][14])->toBe(3.0)
        ->and($pdfRows)->toContain(__('inventory_accounting.book_valuation.mixed_units_warning'));

    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo('inventory.reports.stock_balances.view');
    $this->actingAs($fixture['user'])
        ->get(route('admin.inventory.stock-balances.index', ['run' => 1, 'as_of' => now()->toDateString()]))
        ->assertOk()
        ->assertSee(__('inventory_accounting.book_valuation.mixed_units_warning'));
});

test('posted purchase receipts feed the stock balance inquiry and item attribute filters', function (): void {
    $this->withoutExceptionHandling();
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());

    $category = ItemCategory::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9201,
        'doc_num' => 'CAT-STOCK-INQUIRY',
        'name' => 'Polymers',
        'status' => 'active',
    ]);
    $color = ItemColor::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9201,
        'doc_num' => 'COLOR-STOCK-INQUIRY',
        'name' => 'Natural',
        'status' => 'active',
    ]);
    $fixture['raw']->forceFill([
        'item_category_id' => $category->getKey(),
        'item_color_id' => $color->getKey(),
        'reorder_point' => 5000,
    ])->save();

    $sourcing = app(ProcurementSourcingService::class);
    $requisition = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 10000)));
    $order = app(PurchaseOrderService::class)->approve(app(PurchaseOrderService::class)->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => now()->toDateString(),
        'exchange_rate' => 1,
        'lines' => [[
            'purchase_requisition_line_id' => $requisition->lines->first()->getKey(),
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 10000,
            'unit_price' => 2,
        ]],
    ])['record']);
    $orderLine = $order->lines->first();
    $receiving = app(ProcurementReceivingService::class);
    $receipt = $receiving->createReceipt($order, [
        'document_date' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $orderLine->public_id,
            'delivered_quantity' => 4000,
        ]],
    ]);
    $receiving->inspect($receipt, [
        'lines' => [[
            'receipt_line_public_id' => $receipt->lines->first()->public_id,
            'accepted_quantity' => 4000,
            'rejected_quantity' => 0,
        ]],
    ]);
    $receipt = $receiving->postReceipt($receipt->fresh());

    $report = app(InventoryReportService::class)->stockBalanceInquiry(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        [
            'as_of' => now()->toDateString(),
            'branch_id' => $fixture['branch']->getKey(),
            'branch_store_id' => $fixture['store']->getKey(),
            'product_doc_num' => $fixture['raw']->doc_num,
            'item_category_doc_num' => $category->doc_num,
            'item_color_doc_num' => $color->doc_num,
            'quantity_state' => 'below_reorder',
        ],
    );

    expect($report['rows'])->toHaveCount(1)
        ->and((float) $report['rows']->first()->on_hand)->toBe(4000.0)
        ->and((float) $report['totals']['available'])->toBe(4000.0)
        ->and($report['totals']['products'])->toBe(1)
        ->and(InventoryTransaction::query()->where('transaction_type', 'purchase_receipt')->count())->toBe(1);

    $this->get(route('admin.inventory.stock-balances.index', [
        'run' => 1,
        'as_of' => now()->toDateString(),
        'branch_doc_num' => $fixture['branch']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'product_doc_num' => $fixture['raw']->doc_num,
        'item_category_doc_num' => $category->doc_num,
    ]))->assertOk()
        ->assertSee(__('stock_balance_inquiry.title'))
        ->assertSee('Polymer Resin')
        ->assertSee('4,000')
        ->assertSee(route('admin.raw-materials.show', $fixture['raw']), false)
        ->assertDontSee(route('admin.products.show', $fixture['raw']), false);

    $this->get(route('admin.raw-materials.show', $fixture['raw']))->assertOk();
});

test('stock balance item links open the matching raw packaging and product screens', function (): void {
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());

    $packaging = Product::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9202,
        'doc_num' => 'PACKAGING-STOCK-INQUIRY',
        'name' => 'Packaging Film',
        'item_classification' => Product::ClassificationPackaging,
        'item_unit_id' => $fixture['unit']->getKey(),
        'status' => 'active',
    ]);
    $supply = Product::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9203,
        'doc_num' => 'SUPPLY-STOCK-INQUIRY',
        'name' => 'Production supply',
        'item_classification' => Product::ClassificationOther,
        'item_unit_id' => $fixture['unit']->getKey(),
        'status' => 'active',
    ]);

    foreach ([$fixture['raw'], $packaging, $supply, $fixture['finished']] as $index => $product) {
        InventoryTransaction::query()->create([
            'posting_key' => 'stock-inquiry-link-'.$index,
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'branch_store_id' => $fixture['store']->getKey(),
            'transaction_date' => now()->toDateString(),
            'transaction_type' => 'purchase_receipt',
            'product_id' => $product->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'quantity_in' => 1,
            'quantity_out' => 0,
            'source_type' => 'test',
            'source_id' => $index + 1,
            'source_doc_num' => 'TEST-STOCK-LINK-'.$index,
            'stock_status' => InventoryTransaction::StatusAvailable,
        ]);
    }

    foreach ([
        [$fixture['raw'], 'admin.raw-materials.show'],
        [$packaging, 'admin.packaging-materials.show'],
        [$supply, 'admin.products.show'],
        [$fixture['finished'], 'admin.products.show'],
    ] as [$product, $showRoute]) {
        $showUrl = route($showRoute, $product);
        $this->get(route('admin.inventory.stock-balances.index', [
            'run' => 1,
            'as_of' => now()->toDateString(),
            'branch_doc_num' => $fixture['branch']->doc_num,
            'branch_store_uuid' => $fixture['store']->public_uuid,
            'product_doc_num' => $product->doc_num,
        ]))->assertOk()->assertSee($showUrl, false);
        $this->get($showUrl)->assertOk();
    }

    $fixture['user']->revokePermissionTo('raw_materials.view');
    $this->get(route('admin.inventory.stock-balances.index', [
        'run' => 1,
        'as_of' => now()->toDateString(),
        'product_doc_num' => $fixture['raw']->doc_num,
    ]))->assertOk()->assertDontSee(route('admin.raw-materials.show', $fixture['raw']), false);
});

test('balance aggregates discard sub-precision sqlite cancellation residue', function (): void {
    $fixture = procurementFixture();

    foreach ([['0.10000000', '0'], ['0.20000000', '0'], ['0', '0.30000000']] as $index => [$incoming, $outgoing]) {
        InventoryTransaction::query()->create([
            'posting_key' => 'stock-inquiry-decimal-cancellation-'.$index,
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'branch_store_id' => $fixture['store']->getKey(),
            'transaction_date' => now()->toDateString(),
            'transaction_type' => 'adjustment',
            'product_id' => $fixture['raw']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'quantity_in' => $incoming,
            'quantity_out' => $outgoing,
            'source_type' => 'test',
            'source_id' => $index + 1,
            'source_doc_num' => 'TEST-DECIMAL-CANCELLATION-'.$index,
            'stock_status' => InventoryTransaction::StatusProductionStaging,
        ]);
    }

    $balances = app(InventoryReportService::class)->balances($fixture['company']->getKey(), [
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'as_of' => now()->toDateString(),
    ]);

    expect($balances)->toBeEmpty();
    $availability = app(InventoryAvailabilityService::class);
    expect($availability->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['raw']->getKey(),
        stockStatus: InventoryTransaction::StatusProductionStaging,
    ))->toMatchArray([
        'on_hand' => '0.00000000',
        'reserved' => '0.00000000',
        'available' => '0.00000000',
        'physical_on_hand' => '0.00000000',
    ]);
    expect($availability->statusPosition(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['raw']->getKey(),
    )[InventoryTransaction::StatusProductionStaging] ?? null)->toBe('0.00000000');
    expect(app(InventoryReportService::class)->lowStockRows(
        $fixture['company']->getKey(),
        $fixture['branch']->getKey(),
        $balances,
        new Illuminate\Database\Eloquent\Collection,
        [],
    ))->toBeInstanceOf(Collection::class);

    $stockInquiry = app(InventoryReportService::class)->stockBalanceInquiry(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        ['as_of' => now()->toDateString(), 'product_doc_num' => $fixture['raw']->doc_num],
    );

    expect($stockInquiry['rows'])->toBeEmpty()
        ->and($stockInquiry['totals']['on_hand'])->toBe('0.00000000');

    $bookValuation = app(InventoryReportService::class)->bookValuation(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        ['as_of' => now()->toDateString(), 'product_doc_num' => $fixture['raw']->doc_num],
    );

    expect($bookValuation['rows'])->toBeEmpty()
        ->and($bookValuation['totals']['quantity'])->toBe('0.00000000');

    InventoryTransaction::query()->create([
        'posting_key' => 'stock-inquiry-smallest-quantity',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'transaction_date' => now()->toDateString(),
        'transaction_type' => 'adjustment',
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => '0.00000001',
        'quantity_out' => '0',
        'source_type' => 'test',
        'source_id' => 4,
        'source_doc_num' => 'TEST-SMALLEST-QUANTITY',
        'stock_status' => InventoryTransaction::StatusAvailable,
    ]);

    expect($availability->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['raw']->getKey(),
    ))->toMatchArray([
        'on_hand' => '0.00000001',
        'available' => '0.00000001',
        'physical_on_hand' => '0.00000001',
    ]);
    $report = app(InventoryReportService::class);
    $smallFilters = ['as_of' => now()->toDateString(), 'product_doc_num' => $fixture['raw']->doc_num];
    expect($report->report(
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
        $fixture['branch']->getKey(),
    )['reportTotals']['on_hand'])->toBe('0.00000001')
        ->and($report->stockBalanceInquiry(
            $fixture['company']->getKey(),
            [$fixture['branch']->getKey()],
            $smallFilters,
        )['totals']['on_hand'])->toBe('0.00000001')
        ->and($report->bookValuation(
            $fixture['company']->getKey(),
            [$fixture['branch']->getKey()],
            $smallFilters,
        )['totals']['quantity'])->toBe('0.00000001');
});

test('excluding a sales line still counts active reservations without a sales source', function (): void {
    $fixture = procurementFixture();
    InventoryTransaction::query()->create([
        'posting_key' => 'stock-inquiry-other-source-reservation',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'transaction_date' => now()->toDateString(),
        'transaction_type' => 'adjustment',
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => '10',
        'quantity_out' => '0',
        'source_type' => 'test',
        'source_id' => 1,
        'source_doc_num' => 'TEST-OTHER-SOURCE-RESERVATION',
        'stock_status' => InventoryTransaction::StatusAvailable,
    ]);
    InventoryReservation::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => '4',
        'stock_status' => InventoryTransaction::StatusAvailable,
        'status' => InventoryReservation::StatusActive,
    ]);

    expect(app(InventoryAvailabilityService::class)->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['raw']->getKey(),
        exceptOrderLineId: 999,
    ))->toMatchArray([
        'on_hand' => '10.00000000',
        'reserved' => '4.00000000',
        'available' => '6.00000000',
    ]);
});

test('stock balance rows reconcile reservations when stock movements have hall history', function (): void {
    $fixture = procurementFixture();
    $hall = BranchHall::query()->create([
        'branch_id' => $fixture['branch']->getKey(),
        'name' => 'Historical hall',
        'position' => 1,
    ]);
    InventoryTransaction::query()->create([
        'posting_key' => 'stock-inquiry-hall-reservation',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'branch_hall_id' => $hall->getKey(),
        'transaction_date' => now()->toDateString(),
        'transaction_type' => 'purchase_receipt',
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => 10,
        'quantity_out' => 0,
        'source_type' => 'test',
        'source_id' => 1,
        'source_doc_num' => 'TEST-HALL-RESERVATION',
        'stock_status' => InventoryTransaction::StatusAvailable,
    ]);
    InventoryReservation::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => 4,
        'stock_status' => InventoryTransaction::StatusAvailable,
        'status' => InventoryReservation::StatusActive,
    ]);

    $report = app(InventoryReportService::class)->stockBalanceInquiry(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        ['as_of' => now()->toDateString(), 'product_doc_num' => $fixture['raw']->doc_num],
    );

    expect($report['rows'])->toHaveCount(1)
        ->and($report['rows']->sole()->reserved)->toBe('4.00000000')
        ->and($report['rows']->sole()->available)->toBe('6.00000000')
        ->and($report['totals']['reserved'])->toBe('4.00000000')
        ->and($report['totals']['available'])->toBe('6.00000000');
});

test('stock balance inquiry exposes a zero quantity position with residual value and reservation', function (): void {
    $fixture = procurementFixture();
    foreach ([
        ['quantity_in' => 10, 'quantity_out' => 0, 'unit_cost' => 5, 'total_cost' => 50],
        ['quantity_in' => 0, 'quantity_out' => 10, 'unit_cost' => 10, 'total_cost' => 100],
    ] as $index => $movement) {
        InventoryTransaction::query()->create([
            'posting_key' => 'stock-inquiry-residual-'.$index,
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'branch_store_id' => $fixture['store']->getKey(),
            'transaction_date' => now()->toDateString(),
            'transaction_type' => 'purchase_receipt',
            'product_id' => $fixture['raw']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            ...$movement,
            'source_type' => 'test',
            'source_id' => $index + 1,
            'source_doc_num' => 'TEST-RESIDUAL-'.$index,
            'stock_status' => InventoryTransaction::StatusAvailable,
        ]);
    }
    InventoryReservation::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => 4,
        'stock_status' => InventoryTransaction::StatusAvailable,
        'status' => InventoryReservation::StatusActive,
    ]);

    $report = app(InventoryReportService::class)->stockBalanceInquiry(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        ['as_of' => now()->toDateString(), 'product_doc_num' => $fixture['raw']->doc_num],
    );

    expect($report['rows'])->toHaveCount(1)
        ->and($report['rows']->sole()->on_hand)->toBe('0.00000000')
        ->and($report['rows']->sole()->inventory_value)->toBe('-50.00000000')
        ->and($report['rows']->sole()->reserved)->toBe('4.00000000')
        ->and($report['totals']['reserved'])->toBe('4.00000000')
        ->and($report['totals']['inventory_value'])->toBe('-50.00000000');
});

test('stock balance available total matches visible warehouse rows when another warehouse is negative', function (): void {
    $fixture = procurementFixture();
    $negativeStore = BranchStore::query()->create([
        'branch_id' => $fixture['branch']->getKey(), 'name' => 'Negative stock warehouse', 'position' => 2,
    ]);
    foreach ([[$fixture['store'], 10, 0], [$negativeStore, 0, 4]] as $index => [$store, $incoming, $outgoing]) {
        InventoryTransaction::query()->create([
            'posting_key' => 'stock-inquiry-negative-total-'.$index,
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'branch_store_id' => $store->getKey(),
            'transaction_date' => now()->toDateString(),
            'transaction_type' => 'adjustment',
            'product_id' => $fixture['raw']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'quantity_in' => $incoming,
            'quantity_out' => $outgoing,
            'source_type' => 'test',
            'source_id' => $index + 1,
            'source_doc_num' => 'TEST-NEGATIVE-STOCK-'.$index,
            'stock_status' => InventoryTransaction::StatusAvailable,
        ]);
    }

    $report = app(InventoryReportService::class)->stockBalanceInquiry(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        ['as_of' => now()->toDateString(), 'product_doc_num' => $fixture['raw']->doc_num],
    );

    expect($report['rows'])->toHaveCount(2)
        ->and($report['totals']['on_hand'])->toBe('6.00000000')
        ->and($report['totals']['available'])->toBe('10.00000000')
        ->and($report['totals']['available'])->toBe(
            $report['rows']->reduce(fn (string $sum, InventoryTransaction $row): string => bcadd($sum, (string) $row->available, 8), '0.00000000'),
        );
});

test('hall filters include historical position balances while warehouse locations are absent from reports', function (): void {
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    $hall = BranchHall::query()->create([
        'branch_id' => $fixture['branch']->getKey(),
        'name' => 'Injection Hall',
        'position' => 1,
    ]);
    $location = WarehouseLocation::query()->create([
        'branch_store_id' => $fixture['store']->getKey(),
        'code' => 'R-A-01',
        'name' => 'Rack A / Bin 01',
        'is_active' => true,
    ]);
    $model = ItemModel::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9301,
        'doc_num' => 'MODEL-STOCK-INQUIRY',
        'name' => 'Container 2026',
        'status' => 'active',
    ]);
    $origin = ItemOriginCountry::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9301,
        'doc_num' => 'ORIGIN-STOCK-INQUIRY',
        'name' => 'Egypt',
        'status' => 'active',
    ]);
    $fixture['finished']->forceFill([
        'item_model_id' => $model->getKey(),
        'item_origin_country_id' => $origin->getKey(),
    ])->save();
    InventoryTransaction::query()->create([
        'posting_key' => 'stock-inquiry-position-test',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'branch_hall_id' => $hall->getKey(),
        'warehouse_location_id' => $location->getKey(),
        'transaction_date' => now()->toDateString(),
        'transaction_type' => 'production_receipt',
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => 25,
        'quantity_out' => 0,
        'source_type' => 'test',
        'source_id' => 1,
        'source_doc_num' => 'TEST-STOCK-POSITION',
        'stock_status' => InventoryTransaction::StatusAvailable,
    ]);

    $report = app(InventoryReportService::class)->stockBalanceInquiry(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        [
            'as_of' => now()->toDateString(),
            'branch_hall_id' => $hall->getKey(),
            'warehouse_location_id' => $location->getKey(),
            'item_model_doc_num' => $model->doc_num,
            'item_origin_country_doc_num' => $origin->doc_num,
        ],
    );

    expect($report['rows'])->toHaveCount(1)
        ->and($report['rows']->first()->branchHall->is($hall))->toBeTrue()
        ->and($report['rows']->first()->warehouseLocation->is($location))->toBeTrue()
        ->and((float) $report['totals']['on_hand'])->toBe(25.0)
        ->and($report['reservations_are_hall_scoped'])->toBeFalse();

    $query = [
        'run' => 1,
        'as_of' => now()->toDateString(),
        'branch_doc_num' => $fixture['branch']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'branch_hall_uuid' => $hall->public_uuid,
        'item_model_doc_num' => $model->doc_num,
    ];
    $this->get(route('admin.inventory.stock-balances.index', $query))
        ->assertOk()
        ->assertDontSee('name="warehouse_location_uuid"', false);
    $this->get(route('admin.inventory.stock-balances.export', $query))
        ->assertOk()
        ->assertHeader('content-disposition');
    $this->get(route('admin.inventory.stock-balances.print', $query))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->getJson(route('admin.inventory.stock-balances.index', [
        ...$query,
        'warehouse_location_uuid' => $location->public_id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('warehouse_location_uuid');
});

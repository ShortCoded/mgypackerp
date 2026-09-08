<?php

use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\ItemCategory;
use Modules\Core\Models\ItemColor;
use Modules\Core\Models\ItemModel;
use Modules\Core\Models\ItemOriginCountry;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

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
        ->assertSee('4,000');
});

test('hall and warehouse location filters use posted position dimensions and exports stay available', function (): void {
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
        'warehouse_location_uuid' => $location->public_id,
        'item_model_doc_num' => $model->doc_num,
    ];
    $this->get(route('admin.inventory.stock-balances.export', $query))
        ->assertOk()
        ->assertHeader('content-disposition');
    $this->get(route('admin.inventory.stock-balances.print', $query))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

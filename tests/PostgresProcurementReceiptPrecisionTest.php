<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\SupplyOrderService;
use Tests\TestCase;

require_once __DIR__.'/ProcurementSupport.php';

uses(TestCase::class, DatabaseTransactions::class);

test('isolated PostgreSQL source inspection retains a large eight decimal receipt quantity', function (): void {
    if (DB::getDriverName() !== 'pgsql'
        || ! in_array(DB::selectOne('select current_database() as name')->name, ['mgypack_acceptance_receipt_20261001', 'mgypack_acceptance_closure_20261003'], true)) {
        $this->markTestSkipped('Run only against the dedicated disposable PostgreSQL acceptance database.');
    }

    $fixture = procurementFixture(true);
    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $quantity = '1000000000.00000001';
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'document_date' => now()->toDateString(),
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Synthetic large quantity precision regression',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => $quantity,
            'unit_price' => '0.00000001',
        ]],
    ])['record']);
    $orderLine = $order->lines->sole();
    expect($orderLine->ordered_quantity)->toBe($quantity);

    $inspection = $receiving->inspectPurchaseSource($order, ['lines' => [[
        'purchase_order_line_public_id' => $orderLine->public_id,
        'delivered_quantity' => $quantity,
        'accepted_quantity' => $quantity,
        'rejected_quantity' => '0',
    ]]]);
    $inspectionLine = $inspection->lines->sole();
    expect($inspectionLine->accepted_quantity)->toBe($quantity);

    $receiptData = [
        'document_date' => now()->toDateString(),
        'lines' => [[
            'inspection_line_public_id' => $inspectionLine->public_id,
            'delivered_quantity' => '1000000000.00000002',
        ]],
    ];
    expect(fn () => $receiving->createReceiptFromInspection($inspection, $receiptData))
        ->toThrow(DomainException::class, __('procurement.messages.receipt_quantity_exceeds_inspection_remaining'));

    $receiptData['lines'][0]['delivered_quantity'] = $quantity;
    $receipt = $receiving->createReceiptFromInspection($inspection->fresh(), $receiptData);
    expect($receipt->lines->sole()->delivered_quantity)->toBe($quantity)
        ->and($receipt->lines->sole()->accepted_quantity)->toBe($quantity);

    $supplyPurchaseOrder = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'document_date' => now()->toDateString(),
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Synthetic supply inspection precision regression',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => $quantity,
            'unit_price' => '0.00000001',
        ]],
    ])['record']);
    $supplyOrders = app(SupplyOrderService::class);
    $supplyData = [
        'source_type' => SupplyOrder::SourcePurchaseOrder,
        'source_doc_num' => $supplyPurchaseOrder->doc_num,
        'issue_date' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $supplyPurchaseOrder->lines->sole()->public_id,
            'ordered_quantity' => '1000000000.00000002',
        ]],
    ];
    expect(fn () => $supplyOrders->create($supplyData))
        ->toThrow(DomainException::class, __('Supply order quantity exceeds the remaining source quantity.'));

    $supplyData['lines'][0]['ordered_quantity'] = $quantity;
    $supplyOrder = $supplyOrders->create($supplyData);
    expect($supplyOrder->lines->sole()->ordered_quantity)->toBe($quantity)
        ->and($supplyOrder->total_ordered_quantity)->toBe($quantity);

    $supplyOrder = $supplyOrders->issue($supplyOrder);
    $supplyInspection = $receiving->inspectPurchaseSource($supplyOrder, ['lines' => [[
        'supply_order_line_public_id' => $supplyOrder->lines->sole()->public_id,
        'delivered_quantity' => $quantity,
        'accepted_quantity' => $quantity,
        'rejected_quantity' => '0',
    ]]]);
    expect($supplyInspection->lines->sole()->accepted_quantity)->toBe($quantity);

    $supplyReceipt = $receiving->createReceiptFromInspection($supplyInspection, [
        'document_date' => now()->toDateString(),
        'lines' => [['inspection_line_public_id' => $supplyInspection->lines->sole()->public_id]],
    ]);
    expect($supplyReceipt->lines->sole()->delivered_quantity)->toBe($quantity);
});

<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Purchases\Services\ProcurementAttachmentService;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Modules\Purchases\Services\SupplyOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

test('supply order drives partial warehouse receipts without duplicate inventory posting', function (): void {
    Storage::fake('public');
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());

    $sourcing = app(ProcurementSourcingService::class);
    $purchaseOrders = app(PurchaseOrderService::class);
    $supplyOrders = app(SupplyOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $attachment = procurementDocumentAttachment($fixture['company']);

    $requisition = $sourcing->approveRequisition(
        $sourcing->submitRequisition(procurementManualRequisition($fixture, 10000)),
    );
    $purchaseOrder = $purchaseOrders->create([
        'document_date' => now()->toDateString(),
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 10000,
            'unit_price' => 2,
            'purchase_requisition_line_id' => $requisition->lines->sole()->id,
        ]],
    ])['record'];
    $purchaseOrder = $purchaseOrders->approve($purchaseOrders->submit($purchaseOrder));

    $invoiceSource = PurchaseInvoice::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'PINV-SUPPLY-SOURCE',
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'supplier_id' => $fixture['firstSupplier']->id,
        'purchase_order_id' => $purchaseOrder->id,
        'invoice_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->id,
        'status' => PurchaseInvoice::StatusApproved,
    ]);
    $invoiceSource->lines()->create([
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'line_number' => 1,
        'purchase_order_line_id' => $purchaseOrder->lines->sole()->id,
        'product_id' => $fixture['raw']->id,
        'unit_id' => $fixture['unit']->id,
        'quantity' => 4000,
        'unit_price' => 2,
    ]);
    $invoiceSource->load(['purchaseOrder', 'lines']);
    expect((float) $supplyOrders->sourceLines($invoiceSource)->sole()->getAttribute('supply_available_quantity'))->toBe(4000.0)
        ->and(fn () => $supplyOrders->create([
            'source_type' => SupplyOrder::SourcePurchaseInvoice,
            'source_doc_num' => $invoiceSource->doc_num,
            'issue_date' => now()->toDateString(),
            'lines' => [[
                'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
                'ordered_quantity' => 4001,
            ]],
        ]))->toThrow(DomainException::class);
    $invoiceSupply = $supplyOrders->create([
        'source_type' => SupplyOrder::SourcePurchaseInvoice,
        'source_doc_num' => $invoiceSource->doc_num,
        'issue_date' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
            'ordered_quantity' => 4000,
        ]],
    ]);
    $supplyOrders->cancel($invoiceSupply, 'Use the purchase order supply path instead.');

    $supplyOrder = $supplyOrders->create([
        'source_type' => SupplyOrder::SourcePurchaseOrder,
        'source_doc_num' => $purchaseOrder->doc_num,
        'issue_date' => now()->toDateString(),
        'expected_delivery_date' => now()->addDay()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
            'ordered_quantity' => 10000,
            'attachment_file_doc_nums' => [$attachment->doc_num],
        ]],
    ]);
    $supplyOrder = $supplyOrders->issue($supplyOrder);

    expect(app(ProcurementAttachmentService::class)->documents(
        $supplyOrder->lines->sole(),
        ProcurementAttachmentService::LineCollection,
        $fixture['company']->id,
    ))->toHaveCount(1);

    expect(InventoryTransaction::query()->count())->toBe(0)
        ->and(DB::table('journal_entries')->count())->toBe(0);

    $firstInspection = $receiving->inspectPurchaseSource($supplyOrder, [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'supply_order_line_public_id' => $supplyOrder->lines->sole()->public_id,
            'delivered_quantity' => 4000,
            'accepted_quantity' => 4000,
            'rejected_quantity' => 0,
        ]],
    ]);
    $pendingInspectionRows = app(ProcurementCycleReport::class)->rows(
        ProcurementCycleReport::IncomingQcPending,
        ['branch_id' => $fixture['branch']->id],
        $fixture['company']->id,
        $fixture['period']->id,
    );
    expect($pendingInspectionRows->pluck('document')->all())->toContain($firstInspection->doc_num);
    $firstReceipt = $receiving->createReceiptFromInspection($firstInspection, [
        'document_date' => now()->toDateString(),
        'supplier_delivery_note' => 'DN-4000',
        'lines' => [['inspection_line_public_id' => $firstInspection->lines->sole()->public_id]],
    ]);
    expect(app(ProcurementCycleReport::class)->rows(
        ProcurementCycleReport::IncomingQcPending,
        ['branch_id' => $fixture['branch']->id],
        $fixture['company']->id,
        $fixture['period']->id,
    )->pluck('document')->all())->not->toContain($firstInspection->doc_num);
    $firstReceipt = $receiving->postReceipt($firstReceipt->fresh());

    expect($supplyOrder->fresh()->status)->toBe(SupplyOrder::StatusPartiallyReceived)
        ->and($supplyOrder->fresh()->lines->sole()->remainingQuantity())->toBe(6000.0)
        ->and($purchaseOrder->fresh()->fulfillmentStatus())->toBe('partially_received')
        ->and((float) InventoryTransaction::query()->where('source_doc_num', $firstReceipt->doc_num)->sum('quantity_in'))->toBe(4000.0);

    $secondInspection = $receiving->inspectPurchaseSource($supplyOrder->fresh(), [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'supply_order_line_public_id' => $supplyOrder->lines->sole()->public_id,
            'delivered_quantity' => 6000,
            'accepted_quantity' => 6000,
            'rejected_quantity' => 0,
        ]],
    ]);
    $secondReceipt = $receiving->createReceiptFromInspection($secondInspection, [
        'document_date' => now()->toDateString(),
        'supplier_delivery_note' => 'DN-6000',
        'lines' => [['inspection_line_public_id' => $secondInspection->lines->sole()->public_id]],
    ]);
    $secondReceipt = $receiving->postReceipt($secondReceipt->fresh());

    expect($supplyOrder->fresh()->status)->toBe(SupplyOrder::StatusFullyReceived)
        ->and($supplyOrder->fresh()->lines->sole()->remainingQuantity())->toBe(0.0)
        ->and($purchaseOrder->fresh()->fulfillmentStatus())->toBe('fully_received')
        ->and((float) InventoryTransaction::query()->where('product_id', $fixture['raw']->id)->sum('quantity_in'))->toBe(10000.0)
        ->and(InventoryTransaction::query()->where('posting_key', "purchase-receipt:{$firstReceipt->lines->sole()->id}")->count())->toBe(1)
        ->and(InventoryTransaction::query()->where('posting_key', "purchase-receipt:{$secondReceipt->lines->sole()->id}")->count())->toBe(1);

    $chain = app(ProcurementCycleReport::class)->documentChain($purchaseOrder)->pluck('doc_num');
    expect($chain)->toContain(
        $requisition->doc_num,
        $purchaseOrder->doc_num,
        $supplyOrder->doc_num,
        $firstInspection->doc_num,
        $firstReceipt->doc_num,
        $secondInspection->doc_num,
        $secondReceipt->doc_num,
    );

    $supplyRows = app(ProcurementCycleReport::class)->rows(
        ProcurementCycleReport::SupplyOrders,
        ['branch_id' => $fixture['branch']->id],
        $fixture['company']->id,
        $fixture['period']->id,
    );
    $completedSupplyRow = $supplyRows->firstWhere('document', $supplyOrder->doc_num);
    expect($completedSupplyRow)->not->toBeNull()
        ->and((float) $completedSupplyRow['outstanding'])->toBe(0.0);

    $this->withoutExceptionHandling();
    $this->get(route('admin.purchases.supply-orders.show', $supplyOrder))->assertOk()->assertSee($purchaseOrder->doc_num);
    $this->get(route('admin.purchases.goods-receipt-notes.show', $firstReceipt))->assertOk()->assertSee($supplyOrder->doc_num);
    $this->get(route('admin.purchases.procurement.print', ['supply-order', $supplyOrder->doc_num]))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

test('purchase item selector excludes services and finished products across the cycle', function (): void {
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());

    $results = collect($this->getJson(route('admin.purchases.select2.products'))->assertOk()->json('results'));

    expect($results->pluck('id')->all())
        ->toContain($fixture['raw']->doc_num)
        ->not->toContain($fixture['service']->doc_num, $fixture['finished']->doc_num);
});

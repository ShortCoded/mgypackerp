<?php

use App\Services\DocumentOwnerEffectProofService;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\SupplyOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

/** @return array<string, mixed> */
function purchaseParentProofFixture(bool $postReceipts = true): array
{
    $f = procurementFixture();
    foreach (['purchase_orders.cancel', 'purchases.purchase_requisitions.cancel', 'purchases.supply_orders.cancel'] as $ability) {
        $f['user']->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    test()->actingAs($f['user']);
    request()->setLaravelSession(app('session.store'));
    $sourcing = app(ProcurementSourcingService::class);
    $f['requisition'] = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($f)));
    $f['admin'] = procurementAdministrativeBranch($f);
    procurementUseBranch($f, $f['admin']);
    $orders = app(PurchaseOrderService::class);
    $f['order'] = $orders->approve($orders->create(['supplier_doc_num' => $f['firstSupplier']->doc_num,
        'currency_doc_num' => $f['currency']->doc_num, 'branch_store_uuid' => $f['store']->public_uuid,
        'document_date' => now()->toDateString(), 'exchange_rate' => '1',
        'lines' => [['product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
            'ordered_quantity' => '10', 'unit_price' => '2', 'purchase_requisition_line_id' => $f['requisition']->lines->sole()->id]]])['record']);
    $supplies = app(SupplyOrderService::class);
    $f['supply'] = $supplies->issue($supplies->create(['source_type' => SupplyOrder::SourcePurchaseOrder,
        'source_doc_num' => $f['order']->doc_num, 'issue_date' => now()->toDateString(),
        'lines' => [['purchase_order_line_public_id' => $f['order']->lines->sole()->public_id, 'ordered_quantity' => '10']]]));
    procurementUseBranch($f, $f['branch']);
    $receiving = app(ProcurementReceivingService::class);
    $f['receipts'] = collect();
    foreach (['4', '6'] as $quantity) {
        $receipt = $receiving->createReceiptFromSupplyOrder($f['supply'], ['document_date' => now()->toDateString(),
            'lines' => [['supply_order_line_public_id' => $f['supply']->lines->sole()->public_id, 'delivered_quantity' => $quantity]]]);
        if ($postReceipts) {
            if ($receipt->qc_status === 'pending_inspection') {
                $receiving->inspect($receipt, ['lines' => [['receipt_line_public_id' => $receipt->lines->sole()->public_id,
                    'accepted_quantity' => $quantity, 'rejected_quantity' => '0']]]);
            }
            $receipt = $receiving->postReceipt($receipt->fresh());
        }
        $f['receipts']->push($receipt->load('lines'));
    }

    return $f;
}

test('purchase parents cancel only after native receipt stock and GRNI inverses then supply cancellation', function (string $locale): void {
    $f = purchaseParentProofFixture();
    app()->setLocale($locale);
    $proof = app(DocumentOwnerEffectProofService::class);
    $original = $f['receipts']->map(fn ($receipt): array => $receipt->lines->map->getRawOriginal()->all())->all();
    $receiving = app(ProcurementReceivingService::class);
    foreach ($f['receipts'] as $receipt) {
        expect($proof->receiptIsSettled($receipt))->toBeFalse();
        $receiving->reverseReceipt($receipt, 'SYNTHETIC supplier receipt withdrawn');
        expect($proof->receiptIsSettled($receipt->fresh()))->toBeTrue();
    }
    $ledger = [JournalEntry::count(), InventoryTransaction::count()];
    procurementUseBranch($f, $f['admin']);
    expect($f['order']->fresh()->canCancelSafely())->toBeFalse()->and($f['supply']->fresh()->canCancelSafely())->toBeTrue();
    app(SupplyOrderService::class)->cancel($f['supply']->fresh(), 'SYNTHETIC supply withdrawn');
    expect($f['order']->fresh()->canCancelSafely())->toBeTrue()->and($f['order']->fresh()->hasDownstreamDocuments())->toBeTrue()
        ->and($f['order']->fresh()->canReplaceUnexecutedLines())->toBeFalse();
    $order = app(PurchaseOrderService::class)->cancel($f['order']->fresh(), 'SYNTHETIC purchase withdrawn');
    app(PurchaseOrderService::class)->cancel($order, 'SYNTHETIC retry');
    $request = app(ProcurementSourcingService::class)->finishRequisition($f['requisition']->fresh(), PurchaseRequisition::StatusCancelled, 'SYNTHETIC requirement withdrawn');
    expect($request->status)->toBe(PurchaseRequisition::StatusCancelled)->and($order->status)->toBe(PurchaseOrder::StatusCancelled)
        ->and($f['receipts']->map(fn ($receipt): array => $receipt->fresh()->lines->map->getRawOriginal()->all())->all())->toBe($original)
        ->and($f['order']->lines->sole()->fresh()->received_quantity)->toBe('0.00000000')
        ->and($f['order']->lines->sole()->fresh()->remaining_quantity)->toBe('10.00000000')
        ->and([JournalEntry::count(), InventoryTransaction::count()])->toBe($ledger)
        ->and(DB::table('activity_log')->where('event', 'purchase_order.cancelled')->where('subject_id', $order->id)->count())->toBe(1);
})->with(['ar', 'en']);

test('purchase receipt settlement proof rejects altered inverse quantity value dimensions and GRNI lines', function (): void {
    $f = purchaseParentProofFixture();
    $receipt = $f['receipts']->first();
    app(ProcurementReceivingService::class)->reverseReceipt($receipt, 'SYNTHETIC exact inverse');
    $proof = app(DocumentOwnerEffectProofService::class);
    expect($proof->receiptIsSettled($receipt->fresh()))->toBeTrue();
    $source = InventoryTransaction::query()->where('posting_key', 'purchase-receipt:'.$receipt->lines->sole()->id)->sole();
    $inverse = InventoryTransaction::query()->where('reversal_of_id', $source->id)->sole();
    foreach (['quantity_out' => '3', 'unit_cost' => '999', 'total_cost' => '999', 'branch_id' => $f['admin']->id, 'source_line_id' => null] as $field => $value) {
        $original = $inverse->getRawOriginal($field);
        $inverse->update([$field => $value]);
        expect($proof->receiptIsSettled($receipt->fresh()))->toBeFalse();
        $inverse->update([$field => $original]);
    }
    $journal = JournalEntry::query()->findOrFail($receipt->lines->sole()->grni_journal_entry_id);
    $inverseJournal = JournalEntry::query()->findOrFail($journal->reversed_entry_id);
    $line = $inverseJournal->lines->first();
    $original = $line->credit_amount;
    $line->update(['credit_amount' => '999']);
    expect($proof->receiptIsSettled($receipt->fresh()))->toBeFalse();
    $line->update(['credit_amount' => $original]);
    expect($proof->receiptIsSettled($receipt->fresh()))->toBeTrue();
});

test('purchase unused draft receipt cancellation preserves history and rejects status-only supply cancellation', function (): void {
    $f = purchaseParentProofFixture(false);
    $receiving = app(ProcurementReceivingService::class);
    foreach ($f['receipts'] as $receipt) {
        $receiving->cancelBeforeQuality($receipt, 'SYNTHETIC unused receipt');
        expect(app(DocumentOwnerEffectProofService::class)->receiptIsSettled($receipt->fresh()))->toBeTrue();
    }
    procurementUseBranch($f, $f['admin']);
    $f['supply']->update(['status' => SupplyOrder::StatusCancelled, 'cancelled_by' => $f['user']->id,
        'cancelled_at' => now(), 'cancel_reason' => 'SYNTHETIC forged status']);
    expect($f['order']->fresh()->canCancelSafely())->toBeFalse()
        ->and(fn () => app(PurchaseOrderService::class)->cancel($f['order']->fresh(), 'SYNTHETIC blocked'))->toThrow(DomainException::class);
    $f['supply']->update(['status' => SupplyOrder::StatusIssued, 'cancelled_by' => null, 'cancelled_at' => null, 'cancel_reason' => null]);
    app(SupplyOrderService::class)->cancel($f['supply']->fresh(), 'SYNTHETIC native owner');
    expect($f['order']->fresh()->canCancelSafely())->toBeTrue();
    $f['period']->update(['is_closed' => true]);
    expect(fn () => app(PurchaseOrderService::class)->cancel($f['order']->fresh(), 'SYNTHETIC closed'))->toThrow(DomainException::class)
        ->and($f['order']->fresh()->status)->toBe(PurchaseOrder::StatusApproved);
});

require_once __DIR__.'/../ProcurementCorrectionSupport.php';

test('purchase parent recognizes posted invoice bank payment and return recovery only after exact owner effects settle', function (): void {
    $f = procurementCorrectionFixture();
    request()->setLaravelSession(app('session.store'));
    $invoices = app(PurchaseInvoiceService::class);
    $settlements = app(ProcurementSettlementService::class);
    $f['invoice'] = $invoices->approve($invoices->create($f['invoice_data'])['record']);
    $payment = $settlements->approveSupplierPayment($settlements->createSupplierPayment(procurementCorrectionPaymentData($f, '5')));
    procurementUseBranch($f, $f['branch']);
    $return = $settlements->approvePurchaseReturn($settlements->createPurchaseReturn([
        'purchase_order_doc_num' => $f['order']->doc_num, 'purchase_invoice_doc_num' => $f['invoice']->doc_num,
        'return_date' => now()->toDateString(), 'reason_code' => 'SYNTHETIC source recovery',
        'lines' => [['receipt_line_public_id' => $f['receipts']->first()->lines->sole()->public_id, 'quantity' => '1', 'from_quarantine' => false]],
    ]));
    $proof = app(DocumentOwnerEffectProofService::class);
    expect($proof->purchaseInvoiceIsSettled($f['invoice']->fresh()))->toBeFalse()
        ->and($proof->purchaseReturnIsSettled($return))->toBeFalse()->and($proof->supplierPaymentIsSettled($payment))->toBeFalse();
    $settlements->reversePurchaseReturn($return, 'SYNTHETIC returned stock owner reversed');
    expect($proof->purchaseReturnIsSettled($return->fresh()))->toBeTrue();
    procurementUseBranch($f, $f['admin']);
    $settlements->cancelSupplierPayment($payment, 'SYNTHETIC actual bank recovery');
    expect($proof->supplierPaymentIsSettled($payment->fresh()))->toBeTrue();
    $invoices->reverse($f['invoice']->fresh(), 'SYNTHETIC invoice owner reversal');
    expect($proof->purchaseInvoiceIsSettled($f['invoice']->fresh()))->toBeTrue();
    procurementUseBranch($f, $f['branch']);
    foreach ($f['receipts'] as $receipt) {
        app(ProcurementReceivingService::class)->reverseReceipt($receipt, 'SYNTHETIC receipt owner reversed');
        expect($proof->receiptIsSettled($receipt->fresh()))->toBeTrue();
    }
    procurementUseBranch($f, $f['admin']);
    $ledger = [JournalEntry::count(), InventoryTransaction::count()];
    expect($f['order']->fresh()->canCancelSafely())->toBeTrue();
    app(PurchaseOrderService::class)->cancel($f['order']->fresh(), 'SYNTHETIC fully recovered purchase');
    expect($f['order']->fresh()->status)->toBe(PurchaseOrder::StatusCancelled)
        ->and([JournalEntry::count(), InventoryTransaction::count()])->toBe($ledger)
        ->and($f['invoice']->fresh()->lines()->where('grni_cleared_quantity', '<>', 0)->exists())->toBeFalse();
    $inverseLine = $payment->fresh()->journalEntry->reversedEntry->lines->first();
    $amount = $inverseLine->credit_amount;
    $inverseLine->update(['credit_amount' => '999']);
    expect($proof->supplierPaymentIsSettled($payment->fresh()))->toBeFalse()
        ->and($proof->cancelledPurchaseOrderIsSettled($f['order']->fresh()))->toBeFalse();
    $inverseLine->update(['credit_amount' => $amount]);
    expect($proof->cancelledPurchaseOrderIsSettled($f['order']->fresh()))->toBeTrue();
});

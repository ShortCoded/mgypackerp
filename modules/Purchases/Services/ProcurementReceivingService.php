<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderDeliverySchedule;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\PurchaseReturnLine;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Purchases\Models\SupplyOrderLine;

class ProcurementReceivingService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $operatingContext,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementAttachmentService $attachments,
        private readonly InventoryGrniService $grni,
        private readonly InventoryLayerService $layers,
        private readonly InventoryAvailabilityService $availability,
        private readonly JournalEntryService $journals,
    ) {}

    public function createDeliverySchedules(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $purchaseOrder): PurchaseOrder {
            $context = $this->context();
            $order = PurchaseOrder::query()->with('lines')->lockForUpdate()->findOrFail($purchaseOrder->getKey());
            $this->assertOrderContext($order, $context);

            if (! $order->isApproved()) {
                throw new DomainException(__('Only an approved purchase order may be scheduled.'));
            }

            foreach (array_values($data['schedules']) as $input) {
                $line = PurchaseOrderLine::query()->lockForUpdate()
                    ->where('purchase_order_id', $order->getKey())
                    ->where('public_id', $input['purchase_order_line_public_id'])
                    ->first();
                if (! $line instanceof PurchaseOrderLine) {
                    throw new DomainException(__('The selected purchase order line is invalid.'));
                }

                $scheduled = (float) $line->deliverySchedules()
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('scheduled_quantity');
                $quantity = (float) $input['scheduled_quantity'];
                if ($quantity <= 0 || $scheduled + $quantity > (float) $line->ordered_quantity + 0.00000001) {
                    throw new DomainException(__('Scheduled quantity exceeds the purchase order line quantity.'));
                }

                $line->deliverySchedules()->create([
                    'company_id' => $context['company_id'],
                    'financial_period_id' => $context['financial_period_id'],
                    'purchase_order_id' => $order->getKey(),
                    'sequence' => ((int) $line->deliverySchedules()->max('sequence')) + 1,
                    'scheduled_date' => $input['scheduled_date'],
                    'scheduled_quantity' => $this->quantity($quantity),
                    'received_quantity' => 0,
                    'status' => 'scheduled',
                    'notes' => $input['notes'] ?? null,
                    'created_by' => auth()->id(),
                ]);
            }

            $order = $order->refresh()->load(['lines.deliverySchedules', 'supplier', 'branchStore']);
            $this->audit->record($order, 'purchase_order.delivery_scheduled');

            return $order;
        }, 3);
    }

    public function createReceipt(PurchaseOrder $order, array $data): UnpricedInventoryReceipt
    {
        return $this->receiveDocument($order, $data, false);
    }

    public function createReceiptFromSupplyOrder(SupplyOrder $supplyOrder, array $data): UnpricedInventoryReceipt
    {
        $supplyOrder->loadMissing('purchaseOrder');
        if (! $supplyOrder->purchaseOrder instanceof PurchaseOrder) {
            throw new DomainException(__('A stock receipt requires a purchase order behind the supply order.'));
        }

        return $this->receiveDocument($supplyOrder->purchaseOrder, $data, false, null, $supplyOrder);
    }

    public function updateReceipt(UnpricedInventoryReceipt $receipt, array $data): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($receipt, $data): UnpricedInventoryReceipt {
            $locked = UnpricedInventoryReceipt::query()->lockForUpdate()->findOrFail($receipt->getKey());
            $this->assertReceiptContext($locked, $this->context());
            if ($locked->status !== UnpricedInventoryReceipt::StatusDraft || $locked->posting_status !== 'unposted') {
                throw new DomainException(__('Only a draft goods receipt can be edited or deleted.'));
            }

            return $this->receiveDocument($locked->purchaseOrder, $data, false, $locked, $locked->supplyOrder);
        }, 3);
    }

    public function deleteReceipt(UnpricedInventoryReceipt $receipt): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($receipt): UnpricedInventoryReceipt {
            $locked = UnpricedInventoryReceipt::query()->lockForUpdate()->findOrFail($receipt->getKey());
            $this->assertReceiptContext($locked, $this->context());
            if ($locked->status !== UnpricedInventoryReceipt::StatusDraft || $locked->posting_status !== 'unposted') {
                throw new DomainException(__('Only a draft goods receipt can be edited or deleted.'));
            }
            $locked->forceFill(['deleted_by' => auth()->id()])->save();
            $locked->delete();
            $this->audit->record($locked, 'goods_receipt.deleted');

            return $locked;
        }, 3);
    }

    public function receive(PurchaseOrder $order, array $data): UnpricedInventoryReceipt
    {
        return $this->receiveDocument($order, $data, true);
    }

    public function postReceipt(UnpricedInventoryReceipt $receipt): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($receipt): UnpricedInventoryReceipt {
            $locked = UnpricedInventoryReceipt::query()->with(['supplyOrder', 'lines.purchaseOrderLine', 'lines.supplyOrderLine', 'lines.deliverySchedule'])
                ->lockForUpdate()->findOrFail($receipt->getKey());
            $this->assertReceiptContext($locked, $this->context());
            if (in_array($locked->posting_status, ['posted', 'partially_posted'], true)) {
                return $locked;
            }
            if ($locked->status !== UnpricedInventoryReceipt::StatusDraft || ! $locked->purchase_order_id) {
                throw new DomainException(__('Only a draft goods receipt can be posted.'));
            }

            return $this->receiveDocument($locked->purchaseOrder, [
                'document_date' => $locked->document_date->format('Y-m-d'),
                'supplier_delivery_note' => $locked->supplier_delivery_note,
                'supplier_delivery_date' => $locked->reference_date?->format('Y-m-d'),
                'received_at' => $locked->received_at, 'notes' => $locked->notes,
                'lines' => $locked->lines->map(fn (UnpricedInventoryReceiptLine $line): array => [
                    'purchase_order_line_public_id' => $line->purchaseOrderLine->public_id,
                    'supply_order_line_public_id' => $line->supplyOrderLine?->public_id,
                    'delivery_schedule_public_id' => $line->deliverySchedule?->public_id,
                    'delivered_quantity' => $line->delivered_quantity, 'supplier_lot_number' => $line->supplier_lot_number,
                    'manufacture_date' => $line->manufacture_date, 'expiry_date' => $line->expiry_date, 'notes' => $line->notes,
                ])->all(),
            ], true, $locked, $locked->supplyOrder);
        }, 3);
    }

    private function receiveDocument(PurchaseOrder $purchaseOrder, array $data, bool $postNow, ?UnpricedInventoryReceipt $draft = null, ?SupplyOrder $supplyOrder = null): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($data, $purchaseOrder, $postNow, $draft, $supplyOrder): UnpricedInventoryReceipt {
            $context = $this->context();
            app(FinancialPeriodService::class)->resolveOpenForPostingDate($context['company_id'], $data['document_date'], $context['financial_period_id'], lockForUpdate: true);
            $order = PurchaseOrder::query()->with('lines.product')->lockForUpdate()->findOrFail($purchaseOrder->getKey());
            $this->assertOrderContext($order, $context);
            $lockedSupplyOrder = null;
            if ($supplyOrder instanceof SupplyOrder) {
                $lockedSupplyOrder = SupplyOrder::query()->with('lines.purchaseOrderLine.product')->lockForUpdate()->findOrFail($supplyOrder->getKey());
                if ((int) $lockedSupplyOrder->company_id !== $context['company_id']
                    || (int) $lockedSupplyOrder->financial_period_id !== $context['financial_period_id']
                    || (int) $lockedSupplyOrder->branch_id !== $context['branch_id']
                    || (int) $lockedSupplyOrder->purchase_order_id !== (int) $order->getKey()
                    || ! in_array($lockedSupplyOrder->status, [SupplyOrder::StatusIssued, SupplyOrder::StatusPartiallyReceived], true)) {
                    throw new DomainException(__('Only an issued open supply order may be received.'));
                }
            }

            if (! $order->isApproved()) {
                throw new DomainException(__('Only an approved open purchase order may be received.'));
            }

            if ($postNow && FinancialPeriod::query()->lockForUpdate()->findOrFail($context['financial_period_id'])->is_closed) {
                throw new DomainException(__('Inventory movements cannot be posted in a closed financial period.'));
            }
            $sourceLineKey = $lockedSupplyOrder ? 'supply_order_line_public_id' : 'purchase_order_line_public_id';
            if (empty($data['lines']) || count(array_unique(array_column($data['lines'], $sourceLineKey))) !== count($data['lines'])) {
                throw new DomainException(__('A goods receipt requires distinct purchase order lines.'));
            }
            $receipt = $draft ?? new UnpricedInventoryReceipt;
            $receipt->fill([
                ...($draft ? [] : $this->number('unpriced_inventory_receipts', UnpricedInventoryReceipt::class, $context)),
                ...$context,
                'document_date' => $data['document_date'],
                'branch_hall_id' => null,
                'branch_store_id' => $order->branch_store_id,
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->getKey(),
                'supply_order_id' => $lockedSupplyOrder?->getKey(),
                'reference_number' => $data['supplier_delivery_note'] ?? null,
                'reference_date' => $data['supplier_delivery_date'] ?? null,
                'supplier_delivery_note' => $data['supplier_delivery_note'] ?? null,
                'received_at' => $data['received_at'] ?? $receipt->received_at ?? now(),
                'notes' => $data['notes'] ?? null,
                'approved' => $postNow,
                'is_closed' => false,
                'status' => $postNow ? UnpricedInventoryReceipt::StatusApproved : UnpricedInventoryReceipt::StatusDraft,
                'pricing_status' => UnpricedInventoryReceipt::PricingStatusUnpriced,
                'qc_status' => 'not_required',
                'posting_status' => 'unposted',
                'received_by' => $receipt->received_by ?? auth()->id(),
                'approved_by' => $postNow ? auth()->id() : null,
                'approved_at' => $postNow ? now() : null,
                'created_by' => $receipt->created_by ?? auth()->id(),
            ]);
            if ($receipt->isDirty() || ! $receipt->exists) {
                if ($receipt->exists) {
                    $receipt->updated_by = auth()->id();
                }
                $receipt->save();
            }

            $attachmentsChanged = app(ProcurementAttachmentService::class)->attach($receipt, $data['attachment_file_doc_nums'] ?? [], ProcurementAttachmentService::OperationalCollection, $context['company_id']);
            $changed = $receipt->wasChanged() || $receipt->wasRecentlyCreated || $attachmentsChanged;
            $keptLineIds = [];
            $requiresInspection = false;
            foreach (array_values($data['lines']) as $index => $input) {
                $supplyLine = null;
                if ($lockedSupplyOrder) {
                    $supplyLine = SupplyOrderLine::query()->with('purchaseOrderLine.product')->lockForUpdate()
                        ->where('supply_order_id', $lockedSupplyOrder->getKey())
                        ->where('public_id', $input['supply_order_line_public_id'] ?? null)
                        ->first();
                    $line = $supplyLine?->purchaseOrderLine;
                } else {
                    $line = PurchaseOrderLine::query()->with('product')->lockForUpdate()
                        ->where('purchase_order_id', $order->getKey())
                        ->where('public_id', $input['purchase_order_line_public_id'])
                        ->first();
                }
                if (! $line instanceof PurchaseOrderLine || ! $line->product instanceof Product) {
                    throw new DomainException(__('The selected purchase order line is invalid.'));
                }
                if ($line->product->isService()) {
                    throw new DomainException(__('Service lines do not create warehouse receipts.'));
                }

                $quantity = (float) $input['delivered_quantity'];
                $receivedBefore = $line->netReceivedQuantity($draft?->getKey());
                if ($quantity <= 0 || $quantity > max(0, (float) $line->ordered_quantity - $receivedBefore) + 0.00000001) {
                    throw new DomainException(__('Delivered quantity exceeds the remaining purchase order quantity.'));
                }
                if ($supplyLine instanceof SupplyOrderLine
                    && $quantity > $supplyLine->remainingQuantity($draft?->getKey(), true) + 0.00000001) {
                    throw new DomainException(__('Delivered quantity exceeds the remaining supply order quantity.'));
                }

                $schedule = $this->schedule($line, $input['delivery_schedule_public_id'] ?? null);
                if ($schedule instanceof PurchaseOrderDeliverySchedule && $postNow) {
                    $scheduleRemaining = (float) $schedule->scheduled_quantity - (float) $schedule->received_quantity;
                    if ($quantity > $scheduleRemaining + 0.00000001) {
                        throw new DomainException(__('Delivered quantity exceeds the remaining scheduled quantity.'));
                    }
                    $newScheduledReceived = (float) $schedule->received_quantity + $quantity;
                    $schedule->forceFill([
                        'received_quantity' => $this->quantity($newScheduledReceived),
                        'status' => $newScheduledReceived >= (float) $schedule->scheduled_quantity - 0.00000001 ? 'received' : 'partially_received',
                        'updated_by' => auth()->id(),
                    ])->save();
                }

                $lineNeedsInspection = $line->product->requiresIncomingInspection();
                $requiresInspection = $requiresInspection || $lineNeedsInspection;
                $receiptLine = $draft
                    ? ($receipt->lines()->where($supplyLine ? 'supply_order_line_id' : 'purchase_order_line_id', $supplyLine?->getKey() ?? $line->getKey())->first() ?? new UnpricedInventoryReceiptLine)
                    : new UnpricedInventoryReceiptLine;
                $receiptLine->fill([
                    ...$context,
                    'receipt_id' => $receipt->getKey(),
                    'line_no' => $index + 1,
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id,
                    'purchase_order_line_id' => $line->getKey(),
                    'supply_order_line_id' => $supplyLine?->getKey(),
                    'delivery_schedule_id' => $schedule?->getKey(),
                    'product_snapshot' => $line->product_snapshot,
                    'quantity' => $this->quantity($quantity),
                    'delivered_quantity' => $this->quantity($quantity),
                    'accepted_quantity' => $postNow && ! $lineNeedsInspection ? $this->quantity($quantity) : 0,
                    'rejected_quantity' => 0,
                    'inventory_posted_quantity' => 0,
                    'supplier_lot_number' => $input['supplier_lot_number'] ?? null,
                    'manufacture_date' => $input['manufacture_date'] ?? null,
                    'expiry_date' => $input['expiry_date'] ?? null,
                    'notes' => $input['notes'] ?? null,
                    'created_by' => $receiptLine->created_by ?? auth()->id(),
                ]);
                if ($receiptLine->isDirty() || ! $receiptLine->exists) {
                    if ($receiptLine->exists) {
                        $receiptLine->updated_by = auth()->id();
                    }
                    $receiptLine->save();
                }
                $lineAttachmentChanged = $this->attachments->attachLine(
                    $receiptLine,
                    $input['attachment_file_doc_nums'] ?? [],
                    $context['company_id'],
                );

                $changed = $changed || $receiptLine->wasChanged() || $receiptLine->wasRecentlyCreated || $lineAttachmentChanged;
                $keptLineIds[] = $receiptLine->getKey();
                if ($postNow && ! $lineNeedsInspection) {
                    $this->postAcceptedMovement($receipt, $receiptLine);
                }
            }

            if (! $postNow) {
                $removed = $receipt->lines()->whereNotIn('id', $keptLineIds)->delete();
                $changed = $changed || $removed > 0;
            }
            $receipt->forceFill([
                'qc_status' => ! $postNow ? 'not_required' : ($requiresInspection ? 'pending_inspection' : 'accepted'),
                'posting_status' => ! $postNow ? 'unposted' : ($requiresInspection ? 'partially_posted' : 'posted'),
                'posted_by' => $postNow && ! $requiresInspection ? auth()->id() : null,
                'posted_at' => $postNow && ! $requiresInspection ? now() : null,
            ]);
            if ($receipt->isDirty() || $changed) {
                if ($draft && $changed) {
                    $receipt->updated_by = auth()->id();
                }
                $receipt->save();
            }
            if ($postNow) {
                $this->refreshOrderReceiptTotals($order);
                if ($lockedSupplyOrder) {
                    app(SupplyOrderService::class)->syncFulfillmentStatus($lockedSupplyOrder);
                }
            }

            $receipt = $receipt->refresh()->load(['purchaseOrder', 'supplyOrder', 'supplier', 'branchStore', 'lines.product', 'lines.unit', 'lines.supplyOrderLine']);
            if ($postNow || $changed) {
                $this->audit->record($receipt, $postNow ? 'goods_receipt.posted' : ($draft ? 'goods_receipt.updated' : 'goods_receipt.created'), ['qc_status' => $receipt->qc_status]);
            }

            return $receipt;
        }, 3);
    }

    public function reverseReceipt(UnpricedInventoryReceipt $receipt, string $reason): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($receipt, $reason): UnpricedInventoryReceipt {
            $locked = UnpricedInventoryReceipt::query()->with('lines')->lockForUpdate()->findOrFail($receipt->getKey());
            $context = $this->context();
            $this->assertReceiptContext($locked, $context);
            if ($locked->status === UnpricedInventoryReceipt::StatusReversed) {
                return $locked;
            }
            if (! in_array($locked->posting_status, ['posted', 'partially_posted'], true) || blank($reason)) {
                throw new DomainException(__('A posted receipt and reversal reason are required.'));
            }
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($locked->financial_period_id);
            if ($period->is_closed) {
                throw new DomainException(__('Inventory movements cannot be reversed in a closed financial period.'));
            }
            $lineIds = $locked->lines->modelKeys();
            $billed = PurchaseInvoiceLine::query()->whereIn('receipt_line_id', $lineIds)
                ->whereHas('purchaseInvoice', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed']))->exists();
            $returned = PurchaseReturnLine::query()->whereIn('receipt_line_id', $lineIds)
                ->whereHas('purchaseReturn', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed']))->exists();
            if ($billed || $returned) {
                throw new DomainException(__('Resolve the related invoices and returns before reversing this receipt.'));
            }
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($locked->purchase_order_id);
            BranchStore::query()->lockForUpdate()->findOrFail($locked->branch_store_id);
            foreach ($locked->lines as $line) {
                $movement = InventoryTransaction::query()->where('posting_key', "purchase-receipt:{$line->getKey()}")->lockForUpdate()->first();
                if ($movement) {
                    Product::query()->lockForUpdate()->findOrFail($line->product_id);
                    $position = $this->availability->forProduct((int) $locked->company_id, (int) $locked->branch_store_id,
                        (int) $line->product_id, null, $movement->warehouse_location_id, $movement->stock_status, $movement->batch_lot, true);
                    if (bccomp((string) $movement->quantity_in, $position['available'], 8) > 0) {
                        throw new DomainException(__('The received stock is consumed, reserved, or moved and cannot be reversed.'));
                    }
                    $reversal = InventoryTransaction::query()->firstOrCreate(['posting_key' => $movement->posting_key.':reversal'], [
                        ...$movement->only(['company_id', 'financial_period_id', 'branch_id', 'branch_store_id', 'warehouse_location_id',
                            'stock_status', 'batch_lot', 'manufacture_date', 'expiry_date', 'product_id', 'unit_id',
                            'source_type', 'source_id', 'source_doc_num', 'source_line_type', 'source_line_id', 'supplier_id', 'unit_cost', 'total_cost']),
                        'transaction_date' => now()->toDateString(), 'transaction_type' => 'purchase_receipt_reversal',
                        'quantity_in' => 0, 'quantity_out' => $movement->quantity_in, 'is_reversal' => true,
                        'reversal_of_id' => $movement->getKey(), 'notes' => $reason, 'created_by' => auth()->id(),
                    ]);
                    $this->layers->allocateIssue($reversal, $movement->getKey());
                }
                if ($line->grni_journal_entry_id) {
                    $this->journals->createPostedReversalFromSource(JournalEntry::query()->findOrFail($line->grni_journal_entry_id), [
                        'company_id' => (int) $locked->company_id, 'financial_period_id' => (int) $locked->financial_period_id,
                        'branch_id' => $locked->branch_id, 'currency_id' => $order->currency_id, 'exchange_rate' => $order->exchange_rate,
                        'entry_date' => now()->toDateString(), 'description' => __('Goods receipt reversal :document', ['document' => $locked->doc_num]),
                        'notes' => $reason, 'source_type' => 'grni_receipt_reversal', 'source_id' => $line->getKey(), 'source_doc_num' => $locked->doc_num,
                    ]);
                }
                if ($line->delivery_schedule_id) {
                    $schedule = PurchaseOrderDeliverySchedule::query()->lockForUpdate()->findOrFail($line->delivery_schedule_id);
                    $quantity = max(0, (float) $schedule->received_quantity - (float) $line->delivered_quantity);
                    $schedule->forceFill(['received_quantity' => $this->quantity($quantity), 'status' => $quantity > 0 ? 'partially_received' : 'scheduled', 'updated_by' => auth()->id()])->save();
                }
            }
            $locked->forceFill(['status' => UnpricedInventoryReceipt::StatusReversed, 'posting_status' => 'reversed',
                'approved' => false, 'reversed_by' => auth()->id(), 'reversed_at' => now(), 'reversal_reason' => trim($reason), 'updated_by' => auth()->id()])->save();
            $this->refreshOrderReceiptTotals($order);
            if ($locked->supply_order_id !== null) {
                app(SupplyOrderService::class)->syncFulfillmentStatus(SupplyOrder::query()->findOrFail($locked->supply_order_id));
            }
            $this->audit->record($locked, 'goods_receipt.reversed', ['reason' => trim($reason)]);

            return $locked->refresh();
        }, 3);
    }

    public function inspect(UnpricedInventoryReceipt $receipt, array $data): GoodsReceiptInspection
    {
        return DB::transaction(function () use ($data, $receipt): GoodsReceiptInspection {
            $context = $this->context();
            $locked = UnpricedInventoryReceipt::query()->with('lines.product')->lockForUpdate()->findOrFail($receipt->getKey());
            $this->assertReceiptContext($locked, $context);

            if (! $locked->approved || in_array($locked->status, ['cancelled', 'reversed'], true) || $locked->qc_status !== 'pending_inspection' || $locked->inspection()->exists()) {
                throw new DomainException(__('This goods receipt is not awaiting an incoming inspection.'));
            }

            $inspection = GoodsReceiptInspection::query()->create([
                ...$this->number('goods_receipt_inspections', GoodsReceiptInspection::class, $context),
                ...$context,
                'receipt_id' => $locked->getKey(),
                'inspection_at' => $data['inspection_at'] ?? now(),
                'result' => 'pending',
                'status' => 'draft',
                'observations' => $data['observations'] ?? null,
                'inspected_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]);

            $acceptedTotal = 0.0;
            $rejectedTotal = 0.0;
            $inspectedLineIds = [];
            foreach ($data['lines'] as $input) {
                $line = UnpricedInventoryReceiptLine::query()->with('product')->lockForUpdate()
                    ->where('receipt_id', $locked->getKey())
                    ->where('public_id', $input['receipt_line_public_id'])
                    ->first();
                if (! $line instanceof UnpricedInventoryReceiptLine || ! $line->product?->requiresIncomingInspection()) {
                    throw new DomainException(__('The selected receipt line is not eligible for incoming inspection.'));
                }

                $accepted = (float) $input['accepted_quantity'];
                $rejected = (float) $input['rejected_quantity'];
                if ($accepted < 0 || $rejected < 0 || abs(($accepted + $rejected) - (float) $line->delivered_quantity) > 0.00000001) {
                    throw new DomainException(__('Accepted plus rejected quantity must equal the delivered quantity.'));
                }
                if ($rejected > 0 && blank($input['reason'] ?? null)) {
                    throw new DomainException(__('A rejection reason is required for rejected material.'));
                }

                $result = match (true) {
                    $accepted <= 0 => 'rejected',
                    $rejected <= 0 => 'accepted',
                    default => 'partially_accepted',
                };
                $inspectionLine = $inspection->lines()->create([
                    'receipt_line_id' => $line->getKey(),
                    'product_id' => $line->product_id,
                    'inspected_quantity' => $line->delivered_quantity,
                    'accepted_quantity' => $this->quantity($accepted),
                    'rejected_quantity' => $this->quantity($rejected),
                    'result' => $result,
                    'disposition' => $rejected > 0 ? ($input['disposition'] ?? 'quarantine') : null,
                    'reason' => $input['reason'] ?? null,
                    'measurements' => $input['measurements'] ?? null,
                ]);
                $this->attachments->attachLine(
                    $inspectionLine,
                    $input['attachment_file_doc_nums'] ?? [],
                    $context['company_id'],
                );
                $line->forceFill([
                    'accepted_quantity' => $this->quantity($accepted),
                    'rejected_quantity' => $this->quantity($rejected),
                    'updated_by' => auth()->id(),
                ])->save();
                $this->postAcceptedMovement($locked, $line->refresh());

                $acceptedTotal += $accepted;
                $rejectedTotal += $rejected;
                $inspectedLineIds[] = $line->getKey();
            }

            $requiredIds = $locked->lines->filter(fn (UnpricedInventoryReceiptLine $line): bool => $line->product?->requiresIncomingInspection() === true)->modelKeys();
            if (array_diff($requiredIds, $inspectedLineIds) !== []) {
                throw new DomainException(__('Every inspection-controlled receipt line must be dispositioned together.'));
            }

            $result = match (true) {
                $acceptedTotal <= 0 => 'rejected',
                $rejectedTotal <= 0 => 'accepted',
                default => 'partially_accepted',
            };
            $inspection->forceFill([
                'result' => $result, 'status' => 'finalized', 'finalized_by' => auth()->id(), 'finalized_at' => now(), 'updated_by' => auth()->id(),
            ])->save();
            $this->attachments->attach(
                $inspection,
                $data['attachment_file_doc_nums'] ?? [],
                GoodsReceiptInspection::AttachmentCollection,
                $context['company_id'],
            );
            $locked->forceFill([
                'qc_status' => $result,
                'posting_status' => 'posted',
                'posted_by' => auth()->id(),
                'posted_at' => now(),
                'updated_by' => auth()->id(),
            ])->save();

            $inspection = $inspection->refresh()->load(['receipt.purchaseOrder', 'lines.product', 'lines.receiptLine']);
            $this->audit->record($inspection, 'goods_receipt_inspection.finalized', ['result' => $inspection->result]);

            return $inspection;
        }, 3);
    }

    public function cancelBeforeQuality(UnpricedInventoryReceipt $receipt, string $reason): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($receipt, $reason): UnpricedInventoryReceipt {
            $context = $this->context();
            $locked = UnpricedInventoryReceipt::query()->with('lines')->lockForUpdate()->findOrFail($receipt->getKey());
            $this->assertReceiptContext($locked, $context);

            if ($locked->isCancelled()) {
                return $locked;
            }

            $lineIds = $locked->lines->modelKeys();
            $hasDownstreamEffects = $locked->qc_status !== 'pending_inspection'
                || $locked->inspection()->exists()
                || InventoryTransaction::query()->whereIn('source_line_id', $lineIds)->where('source_line_type', UnpricedInventoryReceiptLine::class)->exists()
                || DB::table('purchase_invoice_lines')->whereIn('receipt_line_id', $lineIds)->whereNull('deleted_at')->exists()
                || DB::table('purchase_return_lines')->whereIn('receipt_line_id', $lineIds)->exists();

            if ($hasDownstreamEffects) {
                throw new DomainException(__('This GRN has Quality, Inventory, Invoice, or Return effects and must be corrected through the downstream Return/reversal flow.'));
            }

            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($locked->purchase_order_id);
            foreach ($locked->lines as $receiptLine) {
                $orderLine = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($receiptLine->purchase_order_line_id);
                $received = max(0, (float) $orderLine->received_quantity - (float) $receiptLine->delivered_quantity);
                $orderLine->forceFill([
                    'received_quantity' => $this->quantity($received),
                    'remaining_quantity' => $this->quantity(max(0, (float) $orderLine->ordered_quantity - $received)),
                    'updated_by' => auth()->id(),
                ])->save();

                if ($receiptLine->delivery_schedule_id !== null) {
                    $schedule = PurchaseOrderDeliverySchedule::query()->lockForUpdate()->findOrFail($receiptLine->delivery_schedule_id);
                    $scheduleReceived = max(0, (float) $schedule->received_quantity - (float) $receiptLine->delivered_quantity);
                    $schedule->forceFill([
                        'received_quantity' => $this->quantity($scheduleReceived),
                        'status' => match (true) {
                            $scheduleReceived <= 0 => 'scheduled',
                            $scheduleReceived >= (float) $schedule->scheduled_quantity - 0.00000001 => 'received',
                            default => 'partially_received',
                        },
                        'updated_by' => auth()->id(),
                    ])->save();
                }
            }

            $locked->forceFill([
                'approved' => false,
                'status' => UnpricedInventoryReceipt::StatusCancelled,
                'qc_status' => 'cancelled',
                'posting_status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'updated_by' => auth()->id(),
            ])->save();
            $this->refreshOrderReceiptTotals($order);
            if ($locked->supply_order_id !== null) {
                app(SupplyOrderService::class)->syncFulfillmentStatus(SupplyOrder::query()->findOrFail($locked->supply_order_id));
            }
            $this->audit->record($locked, 'goods_receipt.cancelled_before_quality', ['reason' => $reason]);

            return $locked->refresh()->load(['purchaseOrder', 'supplier', 'branchStore', 'lines.product', 'lines.unit']);
        }, 3);
    }

    private function postAcceptedMovement(UnpricedInventoryReceipt $receipt, UnpricedInventoryReceiptLine $line): void
    {
        $quantity = (float) $line->accepted_quantity;
        if ($quantity <= 0) {
            return;
        }

        $line->loadMissing('product');
        if (! $line->product?->cost_as_inventory) {
            return;
        }

        BranchStore::query()->lockForUpdate()->findOrFail($receipt->branch_store_id);
        Product::query()->lockForUpdate()->findOrFail($line->product_id);

        if ($line->product?->tracks_expiry && ($line->expiry_date === null || $line->expiry_date->isBefore($receipt->document_date))) {
            throw new DomainException(__('Expiry-tracked stock requires a non-expired receipt-layer expiry date.'));
        }

        $movement = InventoryTransaction::query()->firstOrCreate([
            'posting_key' => "purchase-receipt:{$line->getKey()}",
        ], [
            'company_id' => $receipt->company_id,
            'financial_period_id' => $receipt->financial_period_id,
            'branch_id' => $receipt->branch_id,
            'branch_store_id' => $receipt->branch_store_id,
            'product_id' => $line->product_id,
            'unit_id' => $line->purchaseOrderLine->product_snapshot['stock_unit_id'] ?? $line->product->item_unit_id,
            'transaction_date' => $receipt->document_date,
            'transaction_type' => 'purchase_receipt',
            'quantity_in' => bcmul($this->quantity($quantity), $line->purchaseOrderLine->stockConversionFactor(), 8),
            'quantity_out' => 0,
            'source_type' => UnpricedInventoryReceipt::class,
            'source_id' => $receipt->getKey(),
            'source_doc_num' => $receipt->doc_num,
            'source_line_type' => UnpricedInventoryReceiptLine::class,
            'source_line_id' => $line->getKey(),
            'supplier_id' => $receipt->supplier_id,
            'stock_status' => InventoryTransaction::StatusAvailable,
            'batch_lot' => $line->supplier_lot_number,
            'manufacture_date' => $line->manufacture_date,
            'expiry_date' => $line->expiry_date,
            'created_by' => auth()->id(),
        ]);
        $this->grni->postAcceptedLine($receipt, $line, $movement);
        $this->layers->recordInbound($movement);
        $line->forceFill(['inventory_posted_quantity' => $this->quantity($quantity), 'updated_by' => auth()->id()])->save();
    }

    public function refreshOrderReceiptTotals(PurchaseOrder $order): void
    {
        $received = 0.0;
        $remaining = 0.0;
        foreach ($order->lines()->orderBy('id')->lockForUpdate()->get() as $line) {
            $quantity = $line->netReceivedQuantity();
            $outstanding = max(0, (float) $line->ordered_quantity - $quantity);
            PurchaseOrderLine::withoutTimestamps(fn () => $line->forceFill(['received_quantity' => $this->quantity($quantity), 'remaining_quantity' => $this->quantity($outstanding)])->save());
            foreach ($line->deliverySchedules()->orderBy('id')->lockForUpdate()->get() as $schedule) {
                $scheduleReceipts = UnpricedInventoryReceiptLine::query()->where('delivery_schedule_id', $schedule->getKey())
                    ->whereHas('receipt', fn ($query) => $query->where('approved', true)->whereNotIn('status', ['cancelled', 'reversed']))->get();
                $scheduleReturns = (float) PurchaseReturnLine::query()->whereIn('receipt_line_id', $scheduleReceipts->modelKeys())
                    ->whereHas('purchaseReturn', fn ($query) => $query->where('status', 'posted'))->sum('quantity');
                $netScheduled = max(0, (float) $scheduleReceipts->sum('delivered_quantity') - $scheduleReturns);
                PurchaseOrderDeliverySchedule::withoutTimestamps(fn () => $schedule->forceFill([
                    'received_quantity' => $this->quantity($netScheduled),
                    'status' => $netScheduled <= 0 ? 'scheduled' : ($netScheduled >= (float) $schedule->scheduled_quantity - 0.00000001 ? 'received' : 'partially_received'),
                ])->save());
            }
            $received += $quantity;
            $remaining += $outstanding;
        }
        PurchaseOrder::withoutTimestamps(fn () => $order->forceFill([
            'total_received_quantity' => $this->quantity($received),
            'total_remaining_quantity' => $this->quantity($remaining),
        ])->save());
    }

    private function schedule(PurchaseOrderLine $line, ?string $publicId): ?PurchaseOrderDeliverySchedule
    {
        if (blank($publicId)) {
            return null;
        }
        $schedule = PurchaseOrderDeliverySchedule::query()->lockForUpdate()
            ->where('purchase_order_line_id', $line->getKey())
            ->where('public_id', $publicId)
            ->whereNotIn('status', ['cancelled'])
            ->first();

        if (! $schedule instanceof PurchaseOrderDeliverySchedule) {
            throw new DomainException(__('The selected delivery schedule is unavailable.'));
        }

        return $schedule;
    }

    private function assertOrderContext(PurchaseOrder $order, array $context): void
    {
        if ((int) $order->company_id !== $context['company_id']
            || (int) $order->branch_id !== $context['branch_id']) {
            throw new DomainException(__('The purchase order is outside the active operating context.'));
        }
    }

    private function assertReceiptContext(UnpricedInventoryReceipt $receipt, array $context): void
    {
        if (FinancialPeriod::query()->whereKey($receipt->financial_period_id)->value('is_closed')) {
            throw new DomainException(__('journal_entries.messages.period_closed'));
        }
        if ((int) $receipt->company_id !== $context['company_id']
            || (int) $receipt->financial_period_id !== $context['financial_period_id']
            || (int) $receipt->branch_id !== $context['branch_id']) {
            throw new DomainException(__('The goods receipt is outside the active operating context.'));
        }
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function context(): array
    {
        $snapshot = $this->operatingContext->snapshot(request());
        if (! $snapshot['company_id'] || ! $snapshot['financial_period_id'] || ! $snapshot['branch_id']) {
            throw new DomainException(__('An operating company, branch, and financial period are required.'));
        }

        return [
            'company_id' => (int) $snapshot['company_id'],
            'financial_period_id' => (int) $snapshot['financial_period_id'],
            'branch_id' => (int) $snapshot['branch_id'],
        ];
    }

    /** @param class-string<Model> $model */
    private function number(string $key, string $model, array $context): array
    {
        return $this->documents->nextForCompany(
            $key,
            $model,
            $context['company_id'],
        );
    }

    private function quantity(mixed $value): string
    {
        return number_format((float) $value, 8, '.', '');
    }
}

<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Inventory\Services\InventorySerialService;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\GoodsReceiptInspectionLine;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderDeliverySchedule;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\PurchaseReturn;
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
        private readonly InventoryCostPolicyService $costPolicies,
        private readonly InventoryAvailabilityService $availability,
        private readonly JournalEntryService $journals,
    ) {}

    /** @return array{can_reverse: bool, blockers: list<string>, dependent_documents: array<string, list<string>>, lines: list<array<string, string|null>>} */
    public function receiptReversalPlan(UnpricedInventoryReceipt $receipt): array
    {
        $receipt = UnpricedInventoryReceipt::query()->withTrashed()->with('lines.product', 'lines.purchaseOrderLine')->findOrFail($receipt->getKey());
        $blockers = [];
        if ($receipt->trashed() || $receipt->purchase_order_id === null || ! in_array($receipt->posting_status, ['posted', 'partially_posted'], true)
            || $receipt->status === UnpricedInventoryReceipt::StatusReversed) {
            $blockers[] = __('open_documents.corrections.receipt_not_posted');
        }

        try {
            $this->receiptReversalPostingPeriod($receipt);
            $this->costPolicies->assertPostingDateAllowed((int) $receipt->company_id, (int) $receipt->branch_store_id, now()->toDateString());
        } catch (DomainException $exception) {
            $blockers[] = $exception->getMessage();
        }
        if (! $this->originalReceiptLineageComplete($receipt, $receipt->lines)) {
            $blockers[] = __('open_documents.corrections.receipt_lineage_incomplete');
        }

        $lineIds = $receipt->lines->modelKeys();
        $invoices = PurchaseInvoiceLine::query()->with('purchaseInvoice')->whereIn('receipt_line_id', $lineIds)
            ->whereHas('purchaseInvoice', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed']))
            ->get()->pluck('purchaseInvoice.doc_num')->filter()->unique()->values()->all();
        $returns = PurchaseReturnLine::query()->with('purchaseReturn')->whereIn('receipt_line_id', $lineIds)
            ->whereHas('purchaseReturn', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed']))
            ->get()->pluck('purchaseReturn.doc_num')->filter()->unique()->values()->all();
        if ($invoices !== [] || $returns !== []) {
            $blockers[] = __('Resolve the related invoices and returns before reversing this receipt.');
        }

        $lines = [];
        $projectedPositions = [];
        foreach ($receipt->lines as $line) {
            $movement = InventoryTransaction::query()->where('posting_key', "purchase-receipt:{$line->getKey()}")->first();
            if (! $movement instanceof InventoryTransaction) {
                continue;
            }
            $positionKey = json_encode([
                $movement->company_id, $movement->branch_store_id, $movement->product_id,
                $movement->warehouse_location_id, $movement->stock_status, $movement->batch_lot,
            ], JSON_THROW_ON_ERROR);
            if (! isset($projectedPositions[$positionKey])) {
                $position = $this->availability->forProduct(
                    (int) $movement->company_id, (int) $movement->branch_store_id,
                    (int) $movement->product_id, null, $movement->warehouse_location_id,
                    (string) $movement->stock_status, $movement->batch_lot, true,
                );
                $projectedPositions[$positionKey] = [
                    'on_hand' => bcadd((string) $position['on_hand'], '0', 8),
                    'available' => bcadd((string) $position['available'], '0', 8),
                ];
            }
            $position = $projectedPositions[$positionKey];
            $quantity = bcadd((string) $movement->quantity_in, '0', 8);
            $lineageIds = $this->layers->receiptLineageTransactionIds((int) $movement->getKey());
            $layerAvailable = bcadd((string) InventoryReceiptLayer::query()
                ->whereIn('receipt_transaction_id', $lineageIds)
                ->where('company_id', $movement->company_id)
                ->where('branch_store_id', $movement->branch_store_id)
                ->where('product_id', $movement->product_id)
                ->where('stock_status', $movement->stock_status)
                ->where('warehouse_location_id', $movement->warehouse_location_id)
                ->where('batch_lot', $movement->batch_lot)
                ->sum('remaining_quantity'), '0', 8);
            if (bccomp($quantity, $position['available'], 8) > 0 || bccomp($quantity, $layerAvailable, 8) > 0) {
                $blockers[] = __('open_documents.corrections.receipt_stock_unavailable', [
                    'product' => $line->product?->doc_num ?? (string) $line->product_id,
                ]);
            }
            $lines[] = [
                'product' => $line->product?->doc_num,
                'quantity' => $quantity,
                'before_quantity' => $position['on_hand'],
                'after_quantity' => bcsub($position['on_hand'], $quantity, 8),
                'layer_available' => $layerAvailable,
                'value_delta' => $movement->total_cost === null ? null : bcsub('0', (string) $movement->total_cost, 8),
            ];
            $projectedPositions[$positionKey] = [
                'on_hand' => bcsub($position['on_hand'], $quantity, 8),
                'available' => bcsub($position['available'], $quantity, 8),
            ];
        }

        return [
            'can_reverse' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'dependent_documents' => array_filter([
                __('open_documents.dependents.purchaseInvoices') => $invoices,
                __('open_documents.dependents.returns') => $returns,
            ]),
            'lines' => $lines,
            'correction_steps' => app(ProcurementCorrectionPlanService::class)->forReceipt($receipt, request()),
        ];
    }

    /** @param Collection<int, UnpricedInventoryReceiptLine> $lines */
    private function originalReceiptLineageComplete(UnpricedInventoryReceipt $receipt, Collection $lines): bool
    {
        if ($receipt->lines()->withTrashed()->count() !== $lines->count()) {
            return false;
        }

        $movements = InventoryTransaction::query()
            ->where('source_type', UnpricedInventoryReceipt::class)
            ->where('source_id', $receipt->getKey())
            ->where('is_reversal', false)
            ->get()
            ->keyBy('posting_key');
        $expectedCount = 0;

        foreach ($lines as $line) {
            $key = "purchase-receipt:{$line->getKey()}";
            $movement = $movements->get($key);
            if (! $line->product instanceof Product || ! $line->purchaseOrderLine instanceof PurchaseOrderLine) {
                return false;
            }
            $requiresMovement = $line->product->cost_as_inventory
                && bccomp((string) $line->accepted_quantity, '0', 8) > 0;
            if (! $requiresMovement) {
                if ($movement !== null || $line->grni_journal_entry_id !== null) {
                    return false;
                }

                continue;
            }

            $expectedCount++;
            $expectedQuantity = bcmul(
                (string) $line->accepted_quantity,
                (string) $line->purchaseOrderLine->stockConversionFactor(),
                8,
            );
            try {
                $journal = $this->receiptSourceJournal($receipt, $line);
            } catch (DomainException) {
                return false;
            }
            if (! $movement instanceof InventoryTransaction
                || ! $journal instanceof JournalEntry
                || ! $journal->is_posted
                || $journal->reversed_entry_id !== null
                || (string) $journal->source_type !== 'grni_receipt'
                || (int) $journal->source_id !== (int) $line->getKey()
                || (int) $movement->company_id !== (int) $receipt->company_id
                || (int) $movement->financial_period_id !== (int) $receipt->financial_period_id
                || (int) $movement->branch_store_id !== (int) $receipt->branch_store_id
                || (int) $movement->product_id !== (int) $line->product_id
                || (string) $movement->source_line_type !== UnpricedInventoryReceiptLine::class
                || (int) $movement->source_line_id !== (int) $line->getKey()
                || (string) $movement->transaction_type !== 'purchase_receipt'
                || bccomp((string) $movement->quantity_in, $expectedQuantity, 8) !== 0
                || bccomp((string) $movement->quantity_out, '0', 8) !== 0) {
                return false;
            }
        }

        return $expectedCount === $movements->count();
    }

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

                $scheduled = $this->aggregateQuantity($line->deliverySchedules()
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('scheduled_quantity'));
                $quantity = app(NumericFormatService::class)->normalizeToScale($input['scheduled_quantity'], 8);
                if ($quantity === null
                    || bccomp($quantity, '0', 8) <= 0
                    || bccomp(bcadd($scheduled, $quantity, 8), (string) $line->ordered_quantity, 8) > 0) {
                    throw new DomainException(__('Scheduled quantity exceeds the purchase order line quantity.'));
                }

                $line->deliverySchedules()->create([
                    'company_id' => $context['company_id'],
                    'financial_period_id' => $context['financial_period_id'],
                    'purchase_order_id' => $order->getKey(),
                    'sequence' => ((int) $line->deliverySchedules()->max('sequence')) + 1,
                    'scheduled_date' => $input['scheduled_date'],
                    'scheduled_quantity' => $quantity,
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
        return $this->receiveDocument($order, $data);
    }

    public function createReceiptFromSupplyOrder(SupplyOrder $supplyOrder, array $data): UnpricedInventoryReceipt
    {
        $supplyOrder->loadMissing('purchaseOrder');
        if (! $supplyOrder->purchaseOrder instanceof PurchaseOrder) {
            throw new DomainException(__('A stock receipt requires a purchase order behind the supply order.'));
        }

        return $this->receiveDocument($supplyOrder->purchaseOrder, $data, null, $supplyOrder);
    }

    public function inspectPurchaseSource(PurchaseOrder|SupplyOrder $source, array $data): GoodsReceiptInspection
    {
        return DB::transaction(function () use ($source, $data): GoodsReceiptInspection {
            $context = $this->context();
            $supplyOrder = $source instanceof SupplyOrder
                ? SupplyOrder::query()->with('purchaseOrder')->lockForUpdate()->findOrFail($source->getKey())
                : null;
            $order = PurchaseOrder::query()->with('lines.product')->lockForUpdate()
                ->findOrFail($supplyOrder?->purchase_order_id ?? $source->getKey());
            $this->assertReceivingOrderContext($order, $context);
            if (! $order->isApproved()) {
                throw new DomainException(__('Only an approved open purchase order may be inspected.'));
            }
            if ($supplyOrder instanceof SupplyOrder
                && ((int) $supplyOrder->company_id !== $context['company_id']
                    || (int) $supplyOrder->branch_store_id !== (int) $order->branch_store_id
                    || ! in_array($supplyOrder->status, [SupplyOrder::StatusIssued, SupplyOrder::StatusPartiallyReceived], true))) {
                throw new DomainException(__('Only an issued open supply order may be inspected.'));
            }

            $sourceLineKey = $supplyOrder ? 'supply_order_line_public_id' : 'purchase_order_line_public_id';
            $sourceLineIds = array_map(
                static fn (array $line): mixed => $line[$sourceLineKey] ?? null,
                $data['lines'] ?? [],
            );
            if ($sourceLineIds === [] || in_array(null, $sourceLineIds, true) || count(array_unique($sourceLineIds)) !== count($sourceLineIds)) {
                throw new DomainException(__('A purchase inspection requires distinct source lines.'));
            }

            $inspection = GoodsReceiptInspection::query()->create([
                ...$this->number('goods_receipt_inspections', GoodsReceiptInspection::class, $context),
                ...$context,
                'receipt_id' => null,
                'purchase_order_id' => $order->getKey(),
                'supply_order_id' => $supplyOrder?->getKey(),
                'source_type' => $supplyOrder ? 'supply_order' : 'purchase_order',
                'source_id' => $supplyOrder?->getKey() ?? $order->getKey(),
                'source_doc_num' => $supplyOrder?->doc_num ?? $order->doc_num,
                'inspection_at' => $data['inspection_at'] ?? now(),
                'result' => 'pending',
                'status' => 'draft',
                'observations' => $data['observations'] ?? null,
                'inspected_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]);

            $acceptedTotal = '0.00000000';
            $rejectedTotal = '0.00000000';
            foreach (array_values($data['lines']) as $input) {
                $supplyLine = null;
                if ($supplyOrder instanceof SupplyOrder) {
                    $supplyLine = SupplyOrderLine::query()->with('purchaseOrderLine.product')->lockForUpdate()
                        ->where('supply_order_id', $supplyOrder->getKey())
                        ->where('public_id', $input['supply_order_line_public_id'] ?? null)
                        ->first();
                    $orderLine = $supplyLine?->purchaseOrderLine;
                } else {
                    $orderLine = PurchaseOrderLine::query()->with('product')->lockForUpdate()
                        ->where('purchase_order_id', $order->getKey())
                        ->where('public_id', $input['purchase_order_line_public_id'] ?? null)
                        ->first();
                }
                if (! $orderLine instanceof PurchaseOrderLine || ! $orderLine->product instanceof Product) {
                    throw new DomainException(__('The selected purchase order line is invalid.'));
                }
                if ($orderLine->product->isService()) {
                    throw new DomainException(__('Service lines do not create purchase inspections or warehouse receipts.'));
                }

                $delivered = app(NumericFormatService::class)->normalizeToScale($input['delivered_quantity'], 8) ?? '0.00000000';
                $accepted = app(NumericFormatService::class)->normalizeToScale($input['accepted_quantity'], 8) ?? '0.00000000';
                $rejected = app(NumericFormatService::class)->normalizeToScale($input['rejected_quantity'], 8) ?? '0.00000000';
                if (bccomp($delivered, '0', 8) <= 0
                    || bccomp($accepted, '0', 8) < 0
                    || bccomp($rejected, '0', 8) < 0
                    || bccomp(bcadd($accepted, $rejected, 8), $delivered, 8) !== 0) {
                    throw new DomainException(__('Accepted plus rejected quantity must equal the delivered quantity.'));
                }
                if (bccomp($rejected, '0', 8) > 0 && blank($input['reason'] ?? null)) {
                    throw new DomainException(__('A rejection reason is required for rejected material.'));
                }

                $pendingReceiptQuantity = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
                    ->where('purchase_order_line_id', $orderLine->getKey())
                    ->whereHas('receipt', fn ($query) => $query->where('posting_status', 'unposted')->where('status', UnpricedInventoryReceipt::StatusDraft))
                    ->sum('delivered_quantity'));
                $receivedBefore = bcsub(
                    $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
                        ->where('purchase_order_line_id', $orderLine->getKey())
                        ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
                        ->sum('accepted_quantity')),
                    $this->aggregateQuantity(PurchaseReturnLine::query()
                        ->where('purchase_order_line_id', $orderLine->getKey())
                        ->where('from_quarantine', false)
                        ->whereHas('purchaseReturn', fn ($query) => $query->where('status', PurchaseReturn::StatusPosted))
                        ->sum('quantity')),
                    8,
                );
                if (bccomp($receivedBefore, '0', 8) < 0) {
                    $receivedBefore = '0.00000000';
                }
                $remaining = bcsub(
                    bcsub(bcsub((string) $orderLine->ordered_quantity, $receivedBefore, 8), $pendingReceiptQuantity, 8),
                    $this->pendingInspectionAcceptedQuantity($orderLine),
                    8,
                );
                if (bccomp($delivered, $remaining, 8) > 0) {
                    throw new DomainException(__('Inspected quantity exceeds the remaining purchase order quantity.'));
                }
                if ($supplyLine instanceof SupplyOrderLine) {
                    $supplyRemaining = bcsub($this->remainingSupplyQuantity($supplyLine, null, true), $this->pendingSupplyInspectionAcceptedQuantity($supplyLine), 8);
                    if (bccomp($delivered, $supplyRemaining, 8) > 0) {
                        throw new DomainException(__('Inspected quantity exceeds the remaining supply order quantity.'));
                    }
                }

                $schedule = $this->schedule($orderLine, $input['delivery_schedule_public_id'] ?? null);
                if ($schedule instanceof PurchaseOrderDeliverySchedule) {
                    $pendingScheduledReceipts = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
                        ->where('delivery_schedule_id', $schedule->getKey())
                        ->whereHas('receipt', fn ($query) => $query->where('posting_status', 'unposted')->where('status', UnpricedInventoryReceipt::StatusDraft))
                        ->sum('delivered_quantity'));
                    $pendingScheduledInspections = $this->pendingScheduledInspectionAcceptedQuantity($schedule);
                    $scheduleRemaining = bcsub(
                        bcsub(bcsub((string) $schedule->scheduled_quantity, (string) $schedule->received_quantity, 8), $pendingScheduledReceipts, 8),
                        $pendingScheduledInspections,
                        8,
                    );
                    if (bccomp($delivered, $scheduleRemaining, 8) > 0) {
                        throw new DomainException(__('Inspected quantity exceeds the remaining scheduled quantity.'));
                    }
                }

                $result = match (true) {
                    bccomp($accepted, '0', 8) <= 0 => 'rejected',
                    bccomp($rejected, '0', 8) <= 0 => 'accepted',
                    default => 'partially_accepted',
                };
                $inspectionLine = $inspection->lines()->create([
                    'purchase_order_line_id' => $orderLine->getKey(),
                    'supply_order_line_id' => $supplyLine?->getKey(),
                    'delivery_schedule_id' => $schedule?->getKey(),
                    'product_id' => $orderLine->product_id,
                    'unit_id' => $orderLine->unit_id,
                    'supplier_lot_number' => $input['supplier_lot_number'] ?? null,
                    'serial_numbers' => app(InventorySerialService::class)->receiptNumbers($orderLine->product,
                        bcmul($accepted, $orderLine->stockConversionFactor(), 8), $input['serial_numbers'] ?? null),
                    'manufacture_date' => $input['manufacture_date'] ?? null,
                    'expiry_date' => $input['expiry_date'] ?? null,
                    'notes' => $input['notes'] ?? null,
                    'inspected_quantity' => $delivered,
                    'accepted_quantity' => $accepted,
                    'rejected_quantity' => $rejected,
                    'result' => $result,
                    'disposition' => bccomp($rejected, '0', 8) > 0 ? ($input['disposition'] ?? 'quarantine') : null,
                    'reason' => $input['reason'] ?? null,
                    'measurements' => $input['measurements'] ?? null,
                ]);
                $this->attachments->attachLine($inspectionLine, $input['attachment_file_doc_nums'] ?? [], $context['company_id']);
                $acceptedTotal = bcadd($acceptedTotal, $accepted, 8);
                $rejectedTotal = bcadd($rejectedTotal, $rejected, 8);
            }

            $result = match (true) {
                bccomp($acceptedTotal, '0', 8) <= 0 => 'rejected',
                bccomp($rejectedTotal, '0', 8) <= 0 => 'accepted',
                default => 'partially_accepted',
            };
            $inspection->forceFill([
                'result' => $result,
                'status' => 'finalized',
                'finalized_by' => auth()->id(),
                'finalized_at' => now(),
                'updated_by' => auth()->id(),
            ])->save();
            $this->attachments->attach(
                $inspection,
                $data['attachment_file_doc_nums'] ?? [],
                GoodsReceiptInspection::AttachmentCollection,
                $context['company_id'],
            );
            $inspection = $inspection->refresh()->load(['purchaseOrder.supplier', 'supplyOrder.supplier', 'lines.product', 'lines.unit']);
            $this->audit->record($inspection, 'purchase_inspection.finalized', ['result' => $inspection->result]);

            return $inspection;
        }, 3);
    }

    public function createReceiptFromInspection(GoodsReceiptInspection $inspection, array $data): UnpricedInventoryReceipt
    {
        return $this->saveReceiptFromInspection($inspection, $data);
    }

    private function saveReceiptFromInspection(
        GoodsReceiptInspection $inspection,
        array $data,
        ?UnpricedInventoryReceipt $draft = null,
    ): UnpricedInventoryReceipt {
        return DB::transaction(function () use ($inspection, $data, $draft): UnpricedInventoryReceipt {
            $context = $this->context();
            $locked = GoodsReceiptInspection::query()->with([
                'purchaseOrder', 'supplyOrder.purchaseOrder',
            ])->lockForUpdate()->findOrFail($inspection->getKey());
            $inspectionLines = GoodsReceiptInspectionLine::query()
                ->with([
                    'purchaseOrderLine', 'supplyOrderLine', 'deliverySchedule',
                    'receiptLines.receipt',
                ])
                ->where('goods_receipt_inspection_id', $locked->getKey())
                ->lockForUpdate()
                ->get();
            $locked->setRelation('lines', $inspectionLines);
            if ((int) $locked->company_id !== $context['company_id']
                || (int) $locked->financial_period_id !== $context['financial_period_id']
                || (int) $locked->branch_id !== $context['branch_id']) {
                throw new DomainException(__('The purchase inspection is outside the active operating context.'));
            }
            if ($locked->status !== 'finalized'
                || ! in_array($locked->result, ['accepted', 'partially_accepted'], true)) {
                throw new DomainException(__('procurement.messages.inspection_status_not_receiptable'));
            }
            if (! $locked->purchaseOrder instanceof PurchaseOrder) {
                throw new DomainException(__('The purchase inspection has no valid purchase order.'));
            }

            $inputLines = collect($data['lines'] ?? [])->values();
            $inputLineIds = $inputLines->pluck('inspection_line_public_id');
            if ($inputLines->isEmpty()
                || $inputLineIds->contains(fn (mixed $id): bool => blank($id))
                || $inputLineIds->unique()->count() !== $inputLines->count()) {
                throw new DomainException(__('procurement.messages.receipt_requires_distinct_inspection_lines'));
            }

            $receiptLines = $inputLines->map(function (array $input) use ($locked, $draft): array {
                $line = $locked->lines->firstWhere('public_id', $input['inspection_line_public_id']);
                if (! $line instanceof GoodsReceiptInspectionLine || bccomp((string) $line->accepted_quantity, '0', 8) <= 0) {
                    throw new DomainException(__('procurement.messages.inspection_line_unavailable'));
                }

                $remaining = $this->remainingSourceInspectionQuantity($line, $draft?->getKey());
                $quantity = app(NumericFormatService::class)->normalizeToScale($input['delivered_quantity'] ?? $remaining, 8);
                if ($quantity === null || bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $remaining, 8) > 0) {
                    throw new DomainException(__('procurement.messages.receipt_quantity_exceeds_inspection_remaining'));
                }

                $sourceLine = $locked->supply_order_id === null ? $line->purchaseOrderLine : $line->supplyOrderLine;
                if ($sourceLine === null) {
                    throw new DomainException(__('The purchase inspection line has no valid source line.'));
                }
                $serialService = app(InventorySerialService::class);
                $usedSerials = UnpricedInventoryReceiptLine::query()->where('goods_receipt_inspection_line_id', $line->id)
                    ->when($draft !== null, fn ($query) => $query->where('receipt_id', '<>', $draft->id))
                    ->whereHas('receipt', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed']))
                    ->pluck('serial_numbers')->flatMap(fn ($numbers) => $numbers ?? [])->map(fn (string $serial): string => mb_strtolower(trim($serial)))->all();
                $availableSerials = array_values(array_filter($line->serial_numbers ?? [], fn (string $serial): bool => ! in_array(mb_strtolower(trim($serial)), $usedSerials, true)));
                $serialNumbers = $serialService->receiptNumbers($line->product,
                    bcmul($quantity, $line->purchaseOrderLine->stockConversionFactor(), 8), $input['serial_numbers'] ?? $availableSerials, $availableSerials);

                return [
                    'inspection_line_public_id' => $line->public_id,
                    $locked->supply_order_id === null ? 'purchase_order_line_public_id' : 'supply_order_line_public_id' => $sourceLine->public_id,
                    'delivery_schedule_public_id' => $line->deliverySchedule?->public_id,
                    'delivered_quantity' => $quantity,
                    'supplier_lot_number' => $line->supplier_lot_number,
                    'serial_numbers' => $serialNumbers,
                    'manufacture_date' => $line->manufacture_date?->toDateString(),
                    'expiry_date' => $line->expiry_date?->toDateString(),
                    'notes' => $input['notes'] ?? $line->notes,
                    'attachment_file_doc_nums' => $input['attachment_file_doc_nums'] ?? [],
                ];
            })->values()->all();

            if ($receiptLines === []) {
                throw new DomainException(__('The purchase inspection has no accepted quantity to receive.'));
            }

            return $this->receiveDocument(
                $locked->purchaseOrder,
                [...$data, 'lines' => $receiptLines],
                $draft,
                $locked->supplyOrder,
                $locked,
            );
        }, 3);
    }

    public function updateReceipt(UnpricedInventoryReceipt $receipt, array $data): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($receipt, $data): UnpricedInventoryReceipt {
            $locked = UnpricedInventoryReceipt::query()->with('sourceInspection')->lockForUpdate()->findOrFail($receipt->getKey());
            $this->assertReceiptContext($locked, $this->context());
            $this->assertMutableDraftReceipt($locked);

            if ($locked->sourceInspection instanceof GoodsReceiptInspection) {
                return $this->saveReceiptFromInspection($locked->sourceInspection, $data, $locked);
            }

            return $this->receiveDocument($locked->purchaseOrder, $data, $locked, $locked->supplyOrder);
        }, 3);
    }

    public function deleteReceipt(UnpricedInventoryReceipt $receipt): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($receipt): UnpricedInventoryReceipt {
            $locked = UnpricedInventoryReceipt::query()->lockForUpdate()->findOrFail($receipt->getKey());
            $this->assertReceiptContext($locked, $this->context());
            $this->assertMutableDraftReceipt($locked);
            $locked->forceFill(['deleted_by' => auth()->id()])->save();
            $locked->delete();
            $this->audit->record($locked, 'goods_receipt.deleted');

            return $locked;
        }, 3);
    }

    private function assertMutableDraftReceipt(UnpricedInventoryReceipt $receipt): void
    {
        $lineIds = $receipt->lines()->withTrashed()->pluck('id');
        if ($receipt->status !== UnpricedInventoryReceipt::StatusDraft
            || $receipt->isLockedForEditing()
            || $receipt->posting_status !== 'unposted'
            || $receipt->posted_at !== null || $receipt->reversed_at !== null
            || $receipt->grni_journal_entry_id !== null
            || $receipt->hasBlockingInspection()
            || InventoryTransaction::query()->whereIn('source_line_id', $lineIds)
                ->where('source_line_type', UnpricedInventoryReceiptLine::class)->exists()
            || DB::table('purchase_invoice_lines')->whereIn('receipt_line_id', $lineIds)->exists()
            || DB::table('purchase_return_lines')->whereIn('receipt_line_id', $lineIds)->exists()) {
            throw new DomainException(__('Only a draft goods receipt can be edited or deleted.'));
        }
    }

    public function receive(PurchaseOrder $order, array $data): UnpricedInventoryReceipt
    {
        $receipt = $this->createReceipt($order, $data);

        return $receipt->qc_status === 'pending_inspection'
            ? $receipt
            : $this->postReceipt($receipt);
    }

    public function postReceipt(UnpricedInventoryReceipt $receipt): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($receipt): UnpricedInventoryReceipt {
            Company::query()->whereKey($this->operatingContext->snapshot(request())['company_id'])->lockForUpdate()->firstOrFail();
            $locked = UnpricedInventoryReceipt::query()->with([
                'inspection', 'sourceInspection', 'purchaseOrder', 'supplyOrder', 'lines.product', 'lines.purchaseOrderLine',
                'lines.supplyOrderLine', 'lines.deliverySchedule',
            ])
                ->lockForUpdate()->findOrFail($receipt->getKey());
            $context = $this->context();
            $this->assertReceiptContext($locked, $context);
            if ($locked->posting_status === 'posted') {
                return $locked;
            }
            if ($locked->status !== UnpricedInventoryReceipt::StatusDraft || $locked->isLockedForEditing() || ! $locked->purchase_order_id) {
                throw new DomainException(__('Only a draft goods receipt can be posted.'));
            }
            if (FinancialPeriod::query()->lockForUpdate()->findOrFail($context['financial_period_id'])->is_closed) {
                throw new DomainException(__('Inventory movements cannot be posted in a closed financial period.'));
            }

            $requiresInspection = $locked->lines->contains(
                fn (UnpricedInventoryReceiptLine $line): bool => $line->product?->requiresIncomingInspection() === true,
            );
            $qualityInspection = $locked->sourceInspection ?? $locked->inspection;
            if ($requiresInspection && (! $qualityInspection instanceof GoodsReceiptInspection
                || $qualityInspection->status !== 'finalized'
                || $locked->qc_status === 'pending_inspection')) {
                throw new DomainException(__('procurement.messages.quality_before_receipt_posting'));
            }

            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($locked->purchase_order_id);
            $this->assertReceivingOrderContext($order, $context);
            foreach ($locked->lines as $receiptLine) {
                $orderLine = PurchaseOrderLine::query()->with('product')->lockForUpdate()->findOrFail($receiptLine->purchase_order_line_id);
                $acceptedQuantity = $receiptLine->product?->requiresIncomingInspection()
                    ? bcadd((string) $receiptLine->accepted_quantity, '0', 8)
                    : bcadd((string) $receiptLine->delivered_quantity, '0', 8);
                $postedToOrder = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
                    ->where('purchase_order_line_id', $orderLine->getKey())
                    ->where('receipt_id', '<>', $locked->getKey())
                    ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
                    ->sum('accepted_quantity'));
                $returnedToOrder = $this->aggregateQuantity(PurchaseReturnLine::query()
                    ->where('purchase_order_line_id', $orderLine->getKey())
                    ->where('from_quarantine', false)
                    ->whereHas('purchaseReturn', fn ($query) => $query->where('status', PurchaseReturn::StatusPosted))
                    ->sum('quantity'));
                $receivedBefore = bcsub($postedToOrder, $returnedToOrder, 8);
                if (bccomp($receivedBefore, '0', 8) < 0) {
                    $receivedBefore = '0.00000000';
                }
                if (bccomp($acceptedQuantity, bcsub((string) $orderLine->ordered_quantity, $receivedBefore, 8), 8) > 0) {
                    throw new DomainException(__('procurement.messages.accepted_exceeds_po_remaining'));
                }

                if ($receiptLine->supply_order_line_id !== null) {
                    $supplyLine = SupplyOrderLine::query()->lockForUpdate()->findOrFail($receiptLine->supply_order_line_id);
                    $postedToSupply = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
                        ->where('supply_order_line_id', $supplyLine->getKey())
                        ->where('receipt_id', '<>', $locked->getKey())
                        ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
                        ->sum('accepted_quantity'));
                    if (bccomp($acceptedQuantity, bcsub((string) $supplyLine->ordered_quantity, $postedToSupply, 8), 8) > 0) {
                        throw new DomainException(__('procurement.messages.accepted_exceeds_supply_remaining'));
                    }
                }

                if ($receiptLine->delivery_schedule_id !== null) {
                    $schedule = PurchaseOrderDeliverySchedule::query()->lockForUpdate()->findOrFail($receiptLine->delivery_schedule_id);
                    $postedToSchedule = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
                        ->where('delivery_schedule_id', $schedule->getKey())
                        ->where('receipt_id', '<>', $locked->getKey())
                        ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
                        ->sum('accepted_quantity'));
                    $returnedToSchedule = $this->aggregateQuantity(PurchaseReturnLine::query()
                        ->where('from_quarantine', false)
                        ->whereHas('receiptLine', fn ($query) => $query->where('delivery_schedule_id', $schedule->getKey())
                            ->whereHas('receipt', fn ($receipts) => $receipts->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed'])))
                        ->whereHas('purchaseReturn', fn ($query) => $query->where('status', PurchaseReturn::StatusPosted))
                        ->sum('quantity'));
                    $netScheduled = bcsub($postedToSchedule, $returnedToSchedule, 8);
                    if (bccomp($netScheduled, '0', 8) < 0) {
                        $netScheduled = '0.00000000';
                    }
                    if (bccomp($acceptedQuantity, bcsub((string) $schedule->scheduled_quantity, $netScheduled, 8), 8) > 0) {
                        throw new DomainException(__('procurement.messages.accepted_exceeds_schedule_remaining'));
                    }
                }

                $receiptLine->forceFill([
                    'accepted_quantity' => $acceptedQuantity,
                    'rejected_quantity' => $receiptLine->product?->requiresIncomingInspection() ? $receiptLine->rejected_quantity : 0,
                    'updated_by' => auth()->id(),
                ])->save();
                $this->postAcceptedMovement($locked, $receiptLine->refresh());
            }

            $locked->forceFill([
                'approved' => true,
                'status' => UnpricedInventoryReceipt::StatusApproved,
                'posting_status' => 'posted',
                'posted_by' => auth()->id(),
                'posted_at' => now(),
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ])->save();

            $this->refreshOrderReceiptTotals($order);
            if ($locked->supply_order_id !== null) {
                app(SupplyOrderService::class)->syncFulfillmentStatus(SupplyOrder::query()->findOrFail($locked->supply_order_id));
            }
            $this->audit->record($locked, 'goods_receipt.posted', ['qc_status' => $locked->qc_status]);

            return $locked->refresh()->load(['purchaseOrder', 'supplyOrder', 'supplier', 'branchStore', 'lines.product', 'lines.unit', 'lines.supplyOrderLine']);
        }, 3);
    }

    private function receiveDocument(
        PurchaseOrder $purchaseOrder,
        array $data,
        ?UnpricedInventoryReceipt $draft = null,
        ?SupplyOrder $supplyOrder = null,
        ?GoodsReceiptInspection $sourceInspection = null,
    ): UnpricedInventoryReceipt {
        return DB::transaction(function () use ($data, $purchaseOrder, $draft, $supplyOrder, $sourceInspection): UnpricedInventoryReceipt {
            $context = $this->context();
            app(FinancialPeriodService::class)->resolveOpenForPostingDate($context['company_id'], $data['document_date'], $context['financial_period_id'], lockForUpdate: true);
            $order = PurchaseOrder::query()->with('lines.product')->lockForUpdate()->findOrFail($purchaseOrder->getKey());
            $this->assertReceivingOrderContext($order, $context);
            $lockedSupplyOrder = null;
            if ($supplyOrder instanceof SupplyOrder) {
                $lockedSupplyOrder = SupplyOrder::query()->with('lines.purchaseOrderLine.product')->lockForUpdate()->findOrFail($supplyOrder->getKey());
                if ((int) $lockedSupplyOrder->company_id !== $context['company_id']
                    || (int) $lockedSupplyOrder->branch_store_id !== (int) $order->branch_store_id
                    || (int) $lockedSupplyOrder->purchase_order_id !== (int) $order->getKey()
                    || ! in_array($lockedSupplyOrder->status, [SupplyOrder::StatusIssued, SupplyOrder::StatusPartiallyReceived], true)) {
                    throw new DomainException(__('Only an issued open supply order may be received.'));
                }
            }

            if (! $order->isApproved()) {
                throw new DomainException(__('Only an approved open purchase order may be received.'));
            }

            $sourceLineKey = $lockedSupplyOrder ? 'supply_order_line_public_id' : 'purchase_order_line_public_id';
            if (empty($data['lines']) || count(array_unique(array_column($data['lines'], $sourceLineKey))) !== count($data['lines'])) {
                throw new DomainException(__('A goods receipt requires distinct purchase order lines.'));
            }
            $sourceInspectionLines = $sourceInspection?->lines?->keyBy('public_id') ?? collect();
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
                'goods_receipt_inspection_id' => $sourceInspection?->getKey(),
                'reference_number' => $data['supplier_delivery_note'] ?? null,
                'reference_date' => $data['supplier_delivery_date'] ?? null,
                'supplier_delivery_note' => $data['supplier_delivery_note'] ?? null,
                'received_at' => $data['received_at'] ?? $receipt->received_at ?? now(),
                'notes' => $data['notes'] ?? null,
                'approved' => false,
                'is_closed' => false,
                'status' => UnpricedInventoryReceipt::StatusDraft,
                'pricing_status' => UnpricedInventoryReceipt::PricingStatusUnpriced,
                'qc_status' => $draft?->qc_status ?? 'not_required',
                'posting_status' => 'unposted',
                'received_by' => $receipt->received_by ?? auth()->id(),
                'approved_by' => null,
                'approved_at' => null,
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
                $sourceInspectionLine = null;
                if ($sourceInspection instanceof GoodsReceiptInspection) {
                    $sourceInspectionLine = $sourceInspectionLines->get($input['inspection_line_public_id'] ?? null);
                    if (! $sourceInspectionLine instanceof GoodsReceiptInspectionLine) {
                        throw new DomainException(__('procurement.messages.inspection_line_unavailable'));
                    }
                }
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
                if ($sourceInspectionLine instanceof GoodsReceiptInspectionLine
                    && ((int) $sourceInspectionLine->purchase_order_line_id !== (int) $line->getKey()
                        || (int) ($sourceInspectionLine->supply_order_line_id ?? 0) !== (int) ($supplyLine?->getKey() ?? 0))) {
                    throw new DomainException(__('procurement.messages.inspection_line_source_mismatch'));
                }

                $quantity = app(NumericFormatService::class)->normalizeToScale($input['delivered_quantity'], 8)
                    ?? throw new DomainException(__('Delivered quantity exceeds the remaining purchase order quantity.'));
                if ($sourceInspectionLine instanceof GoodsReceiptInspectionLine
                    && bccomp($quantity, $this->remainingSourceInspectionQuantity($sourceInspectionLine, $draft?->getKey()), 8) > 0) {
                    throw new DomainException(__('procurement.messages.receipt_quantity_exceeds_inspection_remaining'));
                }
                $receivedBefore = bcsub(
                    $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
                        ->where('purchase_order_line_id', $line->getKey())
                        ->when($draft?->getKey() !== null, fn ($query) => $query->where('receipt_id', '<>', $draft->getKey()))
                        ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
                        ->sum('accepted_quantity')),
                    $this->aggregateQuantity(PurchaseReturnLine::query()
                        ->where('purchase_order_line_id', $line->getKey())
                        ->where('from_quarantine', false)
                        ->whereHas('purchaseReturn', fn ($query) => $query->where('status', PurchaseReturn::StatusPosted))
                        ->sum('quantity')),
                    8,
                );
                if (bccomp($receivedBefore, '0', 8) < 0) {
                    $receivedBefore = '0.00000000';
                }
                $pendingArrivalQuantity = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
                    ->where('purchase_order_line_id', $line->getKey())
                    ->when($draft?->getKey() !== null, fn ($query) => $query->where('receipt_id', '<>', $draft->getKey()))
                    ->whereHas('receipt', fn ($query) => $query
                        ->where('posting_status', 'unposted')
                        ->where('status', UnpricedInventoryReceipt::StatusDraft))
                    ->sum('delivered_quantity'));
                $pendingInspectionQuantity = $this->pendingInspectionAcceptedQuantity($line, $sourceInspection?->getKey());
                $remainingOrderQuantity = bcsub(
                    bcsub(bcsub((string) $line->ordered_quantity, $receivedBefore, 8), $pendingArrivalQuantity, 8),
                    $pendingInspectionQuantity,
                    8,
                );
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $remainingOrderQuantity, 8) > 0) {
                    throw new DomainException(__('Delivered quantity exceeds the remaining purchase order quantity.'));
                }
                if ($supplyLine instanceof SupplyOrderLine) {
                    $remainingSupply = bcsub(
                        $this->remainingSupplyQuantity($supplyLine, $draft?->getKey(), true),
                        $this->pendingSupplyInspectionAcceptedQuantity($supplyLine, $sourceInspection?->getKey()),
                        8,
                    );
                    if (bccomp($quantity, $remainingSupply, 8) > 0) {
                        throw new DomainException(__('Delivered quantity exceeds the remaining supply order quantity.'));
                    }
                }

                $schedule = $this->schedule($line, $input['delivery_schedule_public_id'] ?? null);
                if ($schedule instanceof PurchaseOrderDeliverySchedule) {
                    $pendingScheduledQuantity = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
                        ->where('delivery_schedule_id', $schedule->getKey())
                        ->when($draft?->getKey() !== null, fn ($query) => $query->where('receipt_id', '<>', $draft->getKey()))
                        ->whereHas('receipt', fn ($query) => $query
                            ->where('posting_status', 'unposted')
                            ->where('status', UnpricedInventoryReceipt::StatusDraft))
                        ->sum('delivered_quantity'));
                    $pendingScheduledInspections = $this->pendingScheduledInspectionAcceptedQuantity($schedule, $sourceInspection?->getKey());
                    $scheduleRemaining = bcsub(
                        bcsub(bcsub((string) $schedule->scheduled_quantity, (string) $schedule->received_quantity, 8), $pendingScheduledQuantity, 8),
                        $pendingScheduledInspections,
                        8,
                    );
                    if (bccomp($quantity, $scheduleRemaining, 8) > 0) {
                        throw new DomainException(__('Delivered quantity exceeds the remaining scheduled quantity.'));
                    }
                }

                $lineNeedsInspection = $sourceInspection === null && $line->product->requiresIncomingInspection();
                $requiresInspection = $requiresInspection || $lineNeedsInspection;
                $receiptLine = $draft
                    ? ($receipt->lines()
                        ->where(
                            $sourceInspectionLine ? 'goods_receipt_inspection_line_id' : ($supplyLine ? 'supply_order_line_id' : 'purchase_order_line_id'),
                            $sourceInspectionLine?->getKey() ?? $supplyLine?->getKey() ?? $line->getKey(),
                        )
                        ->first() ?? new UnpricedInventoryReceiptLine)
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
                    'goods_receipt_inspection_line_id' => $sourceInspectionLine?->getKey(),
                    'product_snapshot' => $line->product_snapshot,
                    'quantity' => $quantity,
                    'delivered_quantity' => $quantity,
                    'accepted_quantity' => ! $lineNeedsInspection ? $quantity : 0,
                    'rejected_quantity' => 0,
                    'inventory_posted_quantity' => 0,
                    'supplier_lot_number' => $input['supplier_lot_number'] ?? null,
                    'serial_numbers' => app(InventorySerialService::class)->receiptNumbers($line->product,
                        bcmul($quantity, $line->stockConversionFactor(), 8), $input['serial_numbers'] ?? $sourceInspectionLine?->serial_numbers,
                        $sourceInspectionLine?->serial_numbers),
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
            }

            $removed = $receipt->lines()->whereNotIn('id', $keptLineIds)->delete();
            $changed = $changed || $removed > 0;
            $receipt->forceFill([
                'qc_status' => $sourceInspection?->result ?? ($requiresInspection ? 'pending_inspection' : 'not_required'),
                'posting_status' => 'unposted',
                'posted_by' => null,
                'posted_at' => null,
            ]);
            if ($receipt->isDirty() || $changed) {
                if ($draft && $changed) {
                    $receipt->updated_by = auth()->id();
                }
                $receipt->save();
            }
            $receipt = $receipt->refresh()->load(['sourceInspection', 'purchaseOrder', 'supplyOrder', 'supplier', 'branchStore', 'lines.product', 'lines.unit', 'lines.supplyOrderLine']);
            if ($changed) {
                $this->audit->record($receipt, $draft ? 'goods_receipt.updated' : 'goods_receipt.created', ['qc_status' => $receipt->qc_status]);
            }

            return $receipt;
        }, 3);
    }

    public function reverseReceipt(UnpricedInventoryReceipt $receipt, string $reason): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($receipt, $reason): UnpricedInventoryReceipt {
            Company::query()->whereKey($this->operatingContext->snapshot(request())['company_id'])->lockForUpdate()->firstOrFail();
            $locked = UnpricedInventoryReceipt::query()->with('lines')->lockForUpdate()->findOrFail($receipt->getKey());
            $context = $this->context();
            $this->assertReceiptReversalOwnership($locked, $context);
            if ($locked->status === UnpricedInventoryReceipt::StatusReversed) {
                return $locked;
            }
            if (! in_array($locked->posting_status, ['posted', 'partially_posted'], true) || blank($reason)) {
                throw new DomainException(__('A posted receipt and reversal reason are required.'));
            }
            $postingPeriod = $this->receiptReversalPostingPeriod($locked, true);
            $locked->setRelation('lines', $locked->lines()->with('product', 'purchaseOrderLine')->orderBy('id')->lockForUpdate()->get());
            if (! $this->originalReceiptLineageComplete($locked, $locked->lines)) {
                throw new DomainException(__('open_documents.corrections.receipt_lineage_incomplete'));
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
            $this->costPolicies->assertPostingDateAllowed((int) $locked->company_id, (int) $locked->branch_store_id, now()->toDateString());
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
                            'source_type', 'source_id', 'source_doc_num', 'source_line_type', 'source_line_id', 'supplier_id', 'unit_cost', 'total_cost',
                            'cost_method', 'cost_policy_id', 'inventory_serial_identity_id', 'serial_numbers']),
                        'transaction_date' => now()->toDateString(), 'transaction_type' => 'purchase_receipt_reversal',
                        'financial_period_id' => (int) $postingPeriod->id,
                        'quantity_in' => 0, 'quantity_out' => $movement->quantity_in, 'is_reversal' => true,
                        'reversal_of_id' => $movement->getKey(), 'notes' => $reason, 'cost_basis' => 'reversal', 'created_by' => auth()->id(),
                    ]);
                    $this->layers->allocateIssue($reversal, $movement->getKey());
                }
                if ($line->grni_journal_entry_id) {
                    $this->journals->createPostedReversalFromSource($this->receiptSourceJournal($locked, $line, true), [
                        'company_id' => (int) $locked->company_id, 'financial_period_id' => (int) $postingPeriod->id,
                        'branch_id' => $locked->branch_id, 'currency_id' => $order->currency_id, 'exchange_rate' => $order->exchange_rate,
                        'entry_date' => now()->toDateString(), 'description' => __('Goods receipt reversal :document', ['document' => $locked->doc_num]),
                        'notes' => $reason, 'source_type' => 'grni_receipt_reversal', 'source_id' => $line->getKey(), 'source_doc_num' => $locked->doc_num,
                    ]);
                }
                if ($line->delivery_schedule_id) {
                    $schedule = PurchaseOrderDeliverySchedule::query()->lockForUpdate()->findOrFail($line->delivery_schedule_id);
                    $quantity = max(0, (float) $schedule->received_quantity - (float) $line->accepted_quantity);
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
            $locked = UnpricedInventoryReceipt::query()->with(['purchaseOrder', 'supplyOrder', 'lines.product'])->lockForUpdate()->findOrFail($receipt->getKey());
            $this->assertReceiptContext($locked, $context);

            if ($locked->status !== UnpricedInventoryReceipt::StatusDraft
                || $locked->isLockedForEditing()
                || $locked->posting_status !== 'unposted'
                || in_array($locked->status, ['cancelled', 'reversed'], true)
                || $locked->qc_status !== 'pending_inspection'
                || $locked->inspection()->withTrashed()->exists()) {
                throw new DomainException(__('This goods receipt is not awaiting an incoming inspection.'));
            }

            $inspection = GoodsReceiptInspection::query()->create([
                ...$this->number('goods_receipt_inspections', GoodsReceiptInspection::class, $context),
                ...$context,
                'receipt_id' => $locked->getKey(),
                'purchase_order_id' => $locked->purchase_order_id,
                'supply_order_id' => $locked->supply_order_id,
                'source_type' => 'goods_receipt',
                'source_id' => $locked->getKey(),
                'source_doc_num' => $locked->doc_num,
                'inspection_at' => $data['inspection_at'] ?? now(),
                'result' => 'pending',
                'status' => 'draft',
                'observations' => $data['observations'] ?? null,
                'inspected_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]);

            $acceptedTotal = '0.00000000';
            $rejectedTotal = '0.00000000';
            $inspectedLineIds = [];
            foreach ($data['lines'] as $input) {
                $line = UnpricedInventoryReceiptLine::query()->with('product')->lockForUpdate()
                    ->where('receipt_id', $locked->getKey())
                    ->where('public_id', $input['receipt_line_public_id'])
                    ->first();
                if (! $line instanceof UnpricedInventoryReceiptLine || ! $line->product?->requiresIncomingInspection()) {
                    throw new DomainException(__('The selected receipt line is not eligible for incoming inspection.'));
                }

                $accepted = app(NumericFormatService::class)->normalizeToScale($input['accepted_quantity'], 8) ?? '0.00000000';
                $rejected = app(NumericFormatService::class)->normalizeToScale($input['rejected_quantity'], 8) ?? '0.00000000';
                if (bccomp($accepted, '0', 8) < 0
                    || bccomp($rejected, '0', 8) < 0
                    || bccomp(bcadd($accepted, $rejected, 8), (string) $line->delivered_quantity, 8) !== 0) {
                    throw new DomainException(__('Accepted plus rejected quantity must equal the delivered quantity.'));
                }
                if (bccomp($rejected, '0', 8) > 0 && blank($input['reason'] ?? null)) {
                    throw new DomainException(__('A rejection reason is required for rejected material.'));
                }

                $result = match (true) {
                    bccomp($accepted, '0', 8) <= 0 => 'rejected',
                    bccomp($rejected, '0', 8) <= 0 => 'accepted',
                    default => 'partially_accepted',
                };
                $inspectionLine = $inspection->lines()->create([
                    'receipt_line_id' => $line->getKey(),
                    'purchase_order_line_id' => $line->purchase_order_line_id,
                    'supply_order_line_id' => $line->supply_order_line_id,
                    'delivery_schedule_id' => $line->delivery_schedule_id,
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id,
                    'supplier_lot_number' => $line->supplier_lot_number,
                    'serial_numbers' => app(InventorySerialService::class)->receiptNumbers($line->product,
                        bcmul($accepted, $line->purchaseOrderLine->stockConversionFactor(), 8), $input['serial_numbers'] ?? $line->serial_numbers, $line->serial_numbers),
                    'manufacture_date' => $line->manufacture_date,
                    'expiry_date' => $line->expiry_date,
                    'notes' => $line->notes,
                    'inspected_quantity' => $line->delivered_quantity,
                    'accepted_quantity' => $accepted,
                    'rejected_quantity' => $rejected,
                    'result' => $result,
                    'disposition' => bccomp($rejected, '0', 8) > 0 ? ($input['disposition'] ?? 'quarantine') : null,
                    'reason' => $input['reason'] ?? null,
                    'measurements' => $input['measurements'] ?? null,
                ]);
                $this->attachments->attachLine(
                    $inspectionLine,
                    $input['attachment_file_doc_nums'] ?? [],
                    $context['company_id'],
                );
                $line->forceFill([
                    'accepted_quantity' => $accepted,
                    'rejected_quantity' => $rejected,
                    'updated_by' => auth()->id(),
                ])->save();
                $acceptedTotal = bcadd($acceptedTotal, $accepted, 8);
                $rejectedTotal = bcadd($rejectedTotal, $rejected, 8);
                $inspectedLineIds[] = $line->getKey();
            }

            $requiredIds = $locked->lines->filter(fn (UnpricedInventoryReceiptLine $line): bool => $line->product?->requiresIncomingInspection() === true)->modelKeys();
            if (array_diff($requiredIds, $inspectedLineIds) !== []) {
                throw new DomainException(__('Every inspection-controlled receipt line must be dispositioned together.'));
            }

            $result = match (true) {
                bccomp($acceptedTotal, '0', 8) <= 0 => 'rejected',
                bccomp($rejectedTotal, '0', 8) <= 0 => 'accepted',
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

            if ($locked->status !== UnpricedInventoryReceipt::StatusDraft
                || $locked->is_closed || $locked->closed_at !== null
                || $locked->approved || $locked->approved_at !== null
                || $locked->posting_status !== 'unposted'
                || $locked->posted_at !== null || $locked->reversed_at !== null
                || $locked->grni_journal_entry_id !== null || blank($reason)) {
                throw new DomainException(__('Only an open, unposted draft receipt can be cancelled before quality inspection.'));
            }

            $lineIds = $locked->lines()->withTrashed()->pluck('id');
            $hasDownstreamEffects = $locked->qc_status !== 'pending_inspection'
                || $locked->inspection()->withTrashed()->exists()
                || InventoryTransaction::query()->whereIn('source_line_id', $lineIds)->where('source_line_type', UnpricedInventoryReceiptLine::class)->exists()
                || DB::table('purchase_invoice_lines')->whereIn('receipt_line_id', $lineIds)->exists()
                || DB::table('purchase_return_lines')->whereIn('receipt_line_id', $lineIds)->exists();

            if ($hasDownstreamEffects) {
                throw new DomainException(__('This GRN has Quality, Inventory, Invoice, or Return effects and must be corrected through the downstream Return/reversal flow.'));
            }

            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($locked->purchase_order_id);
            foreach ($locked->lines as $receiptLine) {
                $orderLine = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($receiptLine->purchase_order_line_id);
                $received = max(0, (float) $orderLine->received_quantity - (float) $receiptLine->accepted_quantity);
                $orderLine->forceFill([
                    'received_quantity' => $this->quantity($received),
                    'remaining_quantity' => $this->quantity(max(0, (float) $orderLine->ordered_quantity - $received)),
                    'updated_by' => auth()->id(),
                ])->save();

                if ($receiptLine->delivery_schedule_id !== null) {
                    $schedule = PurchaseOrderDeliverySchedule::query()->lockForUpdate()->findOrFail($receiptLine->delivery_schedule_id);
                    $scheduleReceived = max(0, (float) $schedule->received_quantity - (float) $receiptLine->accepted_quantity);
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
        $quantity = bcadd((string) $line->accepted_quantity, '0', 8);
        if (bccomp($quantity, '0', 8) <= 0) {
            return;
        }

        $line->loadMissing('product');
        if (! $line->product?->cost_as_inventory) {
            return;
        }

        BranchStore::query()->lockForUpdate()->findOrFail($receipt->branch_store_id);
        $this->costPolicies->assertPostingDateAllowed(
            (int) $receipt->company_id,
            (int) $receipt->branch_store_id,
            $receipt->document_date->toDateString(),
        );
        Product::query()->lockForUpdate()->findOrFail($line->product_id);

        if ($line->product?->tracks_expiry && ($line->expiry_date === null || $line->expiry_date->isBefore($receipt->document_date))) {
            throw new DomainException(__('Expiry-tracked stock requires a non-expired receipt-layer expiry date.'));
        }

        $costPolicy = $this->costPolicies->resolve((int) $receipt->company_id, (int) $receipt->branch_store_id, $receipt->document_date->toDateString());

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
            'quantity_in' => bcmul($quantity, $line->purchaseOrderLine->stockConversionFactor(), 8),
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
            'cost_method' => $costPolicy['method'],
            'cost_policy_id' => $costPolicy['policy_id'],
            'cost_basis' => 'supplier_receipt',
            'serial_numbers' => $receipt->sourceInspection === null && $receipt->inspection !== null
                ? $receipt->inspection->lines()->where('receipt_line_id', $line->id)->first()?->serial_numbers
                : $line->serial_numbers,
            'created_by' => auth()->id(),
        ]);
        $this->grni->postAcceptedLine($receipt, $line, $movement);
        $this->layers->recordInbound($movement);
        $line->forceFill(['inventory_posted_quantity' => $quantity, 'updated_by' => auth()->id()])->save();
    }

    public function refreshOrderReceiptTotals(PurchaseOrder $order): void
    {
        $received = '0.00000000';
        $remaining = '0.00000000';
        foreach ($order->lines()->orderBy('id')->lockForUpdate()->get() as $line) {
            $quantity = bcsub(
                $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
                    ->where('purchase_order_line_id', $line->getKey())
                    ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
                    ->sum('accepted_quantity')),
                $this->aggregateQuantity(PurchaseReturnLine::query()
                    ->where('purchase_order_line_id', $line->getKey())
                    ->where('from_quarantine', false)
                    ->whereHas('purchaseReturn', fn ($query) => $query->where('status', PurchaseReturn::StatusPosted))
                    ->sum('quantity')),
                8,
            );
            if (bccomp($quantity, '0', 8) < 0) {
                $quantity = '0.00000000';
            }
            $outstanding = bcsub((string) $line->ordered_quantity, $quantity, 8);
            if (bccomp($outstanding, '0', 8) < 0) {
                $outstanding = '0.00000000';
            }
            PurchaseOrderLine::withoutTimestamps(fn () => $line->forceFill(['received_quantity' => $quantity, 'remaining_quantity' => $outstanding])->save());
            foreach ($line->deliverySchedules()->orderBy('id')->lockForUpdate()->get() as $schedule) {
                $scheduleReceived = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
                    ->where('delivery_schedule_id', $schedule->getKey())
                    ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
                    ->sum('accepted_quantity'));
                $scheduleReturns = $this->aggregateQuantity(PurchaseReturnLine::query()
                    ->whereHas('receiptLine', fn ($query) => $query->where('delivery_schedule_id', $schedule->getKey())
                        ->whereHas('receipt', fn ($receipts) => $receipts->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed'])))
                    ->where('from_quarantine', false)
                    ->whereHas('purchaseReturn', fn ($query) => $query->where('status', PurchaseReturn::StatusPosted))->sum('quantity'));
                $netScheduled = bcsub($scheduleReceived, $scheduleReturns, 8);
                if (bccomp($netScheduled, '0', 8) < 0) {
                    $netScheduled = '0.00000000';
                }
                PurchaseOrderDeliverySchedule::withoutTimestamps(fn () => $schedule->forceFill([
                    'received_quantity' => $netScheduled,
                    'status' => bccomp($netScheduled, '0', 8) <= 0 ? 'scheduled' : (bccomp($netScheduled, (string) $schedule->scheduled_quantity, 8) >= 0 ? 'received' : 'partially_received'),
                ])->save());
            }
            $received = bcadd($received, $quantity, 8);
            $remaining = bcadd($remaining, $outstanding, 8);
        }
        PurchaseOrder::withoutTimestamps(fn () => $order->forceFill([
            'total_received_quantity' => $received,
            'total_remaining_quantity' => $remaining,
        ])->save());
    }

    private function remainingSourceInspectionQuantity(GoodsReceiptInspectionLine $line, ?int $exceptReceiptId = null): string
    {
        $received = $this->aggregateQuantity($line->receiptLines()
            ->when($exceptReceiptId !== null, fn ($query) => $query->where('receipt_id', '<>', $exceptReceiptId))
            ->whereHas('receipt', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed']))
            ->sum('delivered_quantity'));
        $remaining = bcsub((string) $line->accepted_quantity, $received, 8);

        return bccomp($remaining, '0', 8) > 0 ? $remaining : '0.00000000';
    }

    private function remainingSupplyQuantity(SupplyOrderLine $line, ?int $exceptReceiptId = null, bool $includeDrafts = false): string
    {
        $received = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
            ->where('supply_order_line_id', $line->getKey())
            ->when($exceptReceiptId !== null, fn ($query) => $query->where('receipt_id', '<>', $exceptReceiptId))
            ->whereHas('receipt', fn ($query) => $query
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->when(! $includeDrafts, fn ($receipts) => $receipts->where('approved', true)->where('posting_status', 'posted')))
            ->sum($includeDrafts ? 'delivered_quantity' : 'accepted_quantity'));
        $remaining = bcsub((string) $line->ordered_quantity, $received, 8);

        return bccomp($remaining, '0', 8) > 0 ? $remaining : '0.00000000';
    }

    private function pendingInspectionAcceptedQuantity(PurchaseOrderLine $line, ?int $exceptInspectionId = null): string
    {
        $accepted = $this->aggregateQuantity(GoodsReceiptInspectionLine::query()
            ->where('purchase_order_line_id', $line->getKey())
            ->when($exceptInspectionId !== null, fn ($query) => $query->where('goods_receipt_inspection_id', '<>', $exceptInspectionId))
            ->whereHas('inspection', fn ($query) => $query
                ->whereIn('source_type', ['purchase_order', 'supply_order'])
                ->where('status', 'finalized'))
            ->sum('accepted_quantity'));

        $received = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
            ->where('purchase_order_line_id', $line->getKey())
            ->whereNotNull('goods_receipt_inspection_line_id')
            ->when($exceptInspectionId !== null, fn ($query) => $query->whereHas(
                'sourceInspectionLine',
                fn ($inspectionLines) => $inspectionLines->where('goods_receipt_inspection_id', '<>', $exceptInspectionId),
            ))
            ->whereHas('receipt', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed']))
            ->sum('delivered_quantity'));

        $pending = bcsub($accepted, $received, 8);

        return bccomp($pending, '0', 8) > 0 ? $pending : '0.00000000';
    }

    private function pendingSupplyInspectionAcceptedQuantity(SupplyOrderLine $line, ?int $exceptInspectionId = null): string
    {
        $accepted = $this->aggregateQuantity(GoodsReceiptInspectionLine::query()
            ->where('supply_order_line_id', $line->getKey())
            ->when($exceptInspectionId !== null, fn ($query) => $query->where('goods_receipt_inspection_id', '<>', $exceptInspectionId))
            ->whereHas('inspection', fn ($query) => $query
                ->whereIn('source_type', ['purchase_order', 'supply_order'])
                ->where('status', 'finalized'))
            ->sum('accepted_quantity'));

        $received = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
            ->where('supply_order_line_id', $line->getKey())
            ->whereNotNull('goods_receipt_inspection_line_id')
            ->when($exceptInspectionId !== null, fn ($query) => $query->whereHas(
                'sourceInspectionLine',
                fn ($inspectionLines) => $inspectionLines->where('goods_receipt_inspection_id', '<>', $exceptInspectionId),
            ))
            ->whereHas('receipt', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed']))
            ->sum('delivered_quantity'));

        $pending = bcsub($accepted, $received, 8);

        return bccomp($pending, '0', 8) > 0 ? $pending : '0.00000000';
    }

    private function pendingScheduledInspectionAcceptedQuantity(
        PurchaseOrderDeliverySchedule $schedule,
        ?int $exceptInspectionId = null,
    ): string {
        $accepted = $this->aggregateQuantity(GoodsReceiptInspectionLine::query()
            ->where('delivery_schedule_id', $schedule->getKey())
            ->when($exceptInspectionId !== null, fn ($query) => $query->where('goods_receipt_inspection_id', '<>', $exceptInspectionId))
            ->whereHas('inspection', fn ($query) => $query
                ->whereIn('source_type', ['purchase_order', 'supply_order'])
                ->where('status', 'finalized'))
            ->sum('accepted_quantity'));

        $received = $this->aggregateQuantity(UnpricedInventoryReceiptLine::query()
            ->where('delivery_schedule_id', $schedule->getKey())
            ->whereNotNull('goods_receipt_inspection_line_id')
            ->when($exceptInspectionId !== null, fn ($query) => $query->whereHas(
                'sourceInspectionLine',
                fn ($inspectionLines) => $inspectionLines->where('goods_receipt_inspection_id', '<>', $exceptInspectionId),
            ))
            ->whereHas('receipt', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed']))
            ->sum('delivered_quantity'));

        $pending = bcsub($accepted, $received, 8);

        return bccomp($pending, '0', 8) > 0 ? $pending : '0.00000000';
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
            || (int) $order->financial_period_id !== $context['financial_period_id']
            || (int) $order->branch_id !== $context['branch_id']) {
            throw new DomainException(__('The purchase order is outside the active operating context.'));
        }
    }

    private function assertReceivingOrderContext(PurchaseOrder $order, array $context): void
    {
        $receivingBranchId = BranchStore::query()->whereKey($order->branch_store_id)->value('branch_id');
        if ((int) $order->company_id !== $context['company_id']
            || (int) $receivingBranchId !== $context['branch_id']) {
            throw new DomainException(__('procurement.messages.purchase_order_outside_receiving_context'));
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

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function assertReceiptReversalOwnership(UnpricedInventoryReceipt $receipt, array $context): void
    {
        if ((int) $receipt->company_id !== $context['company_id'] || (int) $receipt->branch_id !== $context['branch_id']) {
            throw new DomainException(__('The goods receipt is outside the active operating context.'));
        }
    }

    private function receiptSourceJournal(UnpricedInventoryReceipt $receipt, UnpricedInventoryReceiptLine $line, bool $lock = false): JournalEntry
    {
        $order = $receipt->purchaseOrder;
        if (! $order) {
            throw new DomainException(__('open_documents.validation.purchase_journal_invalid'));
        }

        return app(ProcurementSourceJournalService::class)->requireSource($line->grni_journal_entry_id, [
            'company_id' => (int) $receipt->company_id, 'branch_id' => $receipt->branch_id,
            'financial_period_id' => (int) $receipt->financial_period_id, 'currency_id' => $order->currency_id,
            'exchange_rate' => (string) $order->exchange_rate, 'source_type' => 'grni_receipt',
            'source_id' => (int) $line->id, 'entry_date' => $receipt->document_date->toDateString(),
        ], $lock);
    }

    private function receiptReversalPostingPeriod(UnpricedInventoryReceipt $receipt, bool $lock = false): FinancialPeriod
    {
        $context = $this->context();
        $this->assertReceiptReversalOwnership($receipt, $context);
        $sourcePeriod = FinancialPeriod::query()->where('company_id', $receipt->company_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($receipt->financial_period_id);
        if ((int) $sourcePeriod->id !== $context['financial_period_id']) {
            abort_unless(auth()->user()?->can('purchases.goods_receipt_notes.reverse'), 403);
        }

        return app(FinancialPeriodService::class)->resolveOpenForPostingDate(
            (int) $receipt->company_id, now()->toDateString(), $context['financial_period_id'], lockForUpdate: $lock,
        );
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

    private function aggregateQuantity(mixed $value): string
    {
        return app(NumericFormatService::class)->normalizeScientificNotation((string) ($value ?? 0)) ?? '0';
    }
}

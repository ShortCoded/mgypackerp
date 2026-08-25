<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderDeliverySchedule;
use Modules\Purchases\Models\PurchaseOrderLine;

class ProcurementReceivingService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $operatingContext,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementAttachmentService $attachments,
        private readonly InventoryGrniService $grni,
        private readonly InventoryLayerService $layers,
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

    public function receive(PurchaseOrder $purchaseOrder, array $data): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($data, $purchaseOrder): UnpricedInventoryReceipt {
            $context = $this->context();
            $order = PurchaseOrder::query()->with('lines.product')->lockForUpdate()->findOrFail($purchaseOrder->getKey());
            $this->assertOrderContext($order, $context);

            if (! $order->isApproved()) {
                throw new DomainException(__('Only an approved open purchase order may be received.'));
            }

            $receipt = UnpricedInventoryReceipt::query()->create([
                ...$this->number('unpriced_inventory_receipts', UnpricedInventoryReceipt::class, $context),
                ...$context,
                'document_date' => $data['document_date'],
                'branch_hall_id' => null,
                'branch_store_id' => $order->branch_store_id,
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->getKey(),
                'reference_number' => $data['supplier_delivery_note'] ?? null,
                'reference_date' => $data['supplier_delivery_date'] ?? null,
                'supplier_delivery_note' => $data['supplier_delivery_note'] ?? null,
                'received_at' => $data['received_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'approved' => true,
                'is_closed' => false,
                'status' => UnpricedInventoryReceipt::StatusApproved,
                'pricing_status' => UnpricedInventoryReceipt::PricingStatusUnpriced,
                'qc_status' => 'not_required',
                'posting_status' => 'unposted',
                'received_by' => auth()->id(),
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'created_by' => auth()->id(),
            ]);

            $requiresInspection = false;
            foreach (array_values($data['lines']) as $index => $input) {
                $line = PurchaseOrderLine::query()->with('product')->lockForUpdate()
                    ->where('purchase_order_id', $order->getKey())
                    ->where('public_id', $input['purchase_order_line_public_id'])
                    ->first();
                if (! $line instanceof PurchaseOrderLine || ! $line->product instanceof Product) {
                    throw new DomainException(__('The selected purchase order line is invalid.'));
                }
                if ($line->product->isService()) {
                    throw new DomainException(__('Service lines do not create warehouse receipts.'));
                }

                $quantity = (float) $input['delivered_quantity'];
                if ($quantity <= 0 || $quantity > (float) $line->remaining_quantity + 0.00000001) {
                    throw new DomainException(__('Delivered quantity exceeds the remaining purchase order quantity.'));
                }

                $schedule = $this->schedule($line, $input['delivery_schedule_public_id'] ?? null);
                if ($schedule instanceof PurchaseOrderDeliverySchedule) {
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
                $receiptLine = $receipt->lines()->create([
                    ...$context,
                    'receipt_id' => $receipt->getKey(),
                    'line_no' => $index + 1,
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id,
                    'purchase_order_line_id' => $line->getKey(),
                    'delivery_schedule_id' => $schedule?->getKey(),
                    'product_snapshot' => $line->product_snapshot,
                    'quantity' => $this->quantity($quantity),
                    'delivered_quantity' => $this->quantity($quantity),
                    'accepted_quantity' => $lineNeedsInspection ? 0 : $this->quantity($quantity),
                    'rejected_quantity' => 0,
                    'inventory_posted_quantity' => 0,
                    'supplier_lot_number' => $input['supplier_lot_number'] ?? null,
                    'manufacture_date' => $input['manufacture_date'] ?? null,
                    'expiry_date' => $input['expiry_date'] ?? null,
                    'notes' => $input['notes'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                $received = (float) $line->received_quantity + $quantity;
                $line->forceFill([
                    'received_quantity' => $this->quantity($received),
                    'remaining_quantity' => $this->quantity(max(0, (float) $line->ordered_quantity - $received)),
                    'updated_by' => auth()->id(),
                ])->save();

                if (! $lineNeedsInspection) {
                    $this->postAcceptedMovement($receipt, $receiptLine);
                }
            }

            $this->refreshOrderReceiptTotals($order);
            $receipt->forceFill([
                'qc_status' => $requiresInspection ? 'pending_inspection' : 'accepted',
                'posting_status' => $requiresInspection ? 'partially_posted' : 'posted',
                'posted_by' => $requiresInspection ? null : auth()->id(),
                'posted_at' => $requiresInspection ? null : now(),
            ])->save();

            $receipt = $receipt->refresh()->load(['purchaseOrder', 'supplier', 'branchStore', 'lines.product', 'lines.unit']);
            $this->audit->record($receipt, 'goods_receipt.posted', ['qc_status' => $receipt->qc_status]);

            return $receipt;
        }, 3);
    }

    public function inspect(UnpricedInventoryReceipt $receipt, array $data): GoodsReceiptInspection
    {
        return DB::transaction(function () use ($data, $receipt): GoodsReceiptInspection {
            $context = $this->context();
            $locked = UnpricedInventoryReceipt::query()->with('lines.product')->lockForUpdate()->findOrFail($receipt->getKey());
            $this->assertReceiptContext($locked, $context);

            if ($locked->qc_status !== 'pending_inspection' || $locked->inspection()->exists()) {
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
                $inspection->lines()->create([
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

            $this->refreshOrderReceiptTotals($order);
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
            'unit_id' => $line->unit_id,
            'transaction_date' => $receipt->document_date,
            'transaction_type' => 'purchase_receipt',
            'quantity_in' => $this->quantity($quantity),
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

    private function refreshOrderReceiptTotals(PurchaseOrder $order): void
    {
        $received = (float) $order->lines()->sum('received_quantity');
        $remaining = (float) $order->lines()->sum('remaining_quantity');
        $order->forceFill([
            'total_received_quantity' => $this->quantity($received),
            'total_remaining_quantity' => $this->quantity($remaining),
            'updated_by' => auth()->id(),
        ])->save();
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

    private function assertReceiptContext(UnpricedInventoryReceipt $receipt, array $context): void
    {
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
            fn ($query) => $query->where('financial_period_id', $context['financial_period_id']),
        );
    }

    private function quantity(mixed $value): string
    {
        return number_format((float) $value, 8, '.', '');
    }
}

<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

class SalesFulfillmentService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly SalesAmountService $amounts,
        private readonly InventoryAvailabilityService $availability,
        private readonly InventoryDocumentPostingService $posting,
        private readonly SalesAccountingService $accounting,
    ) {}

    public function reserve(SalesOrderLine $line, string $quantity): InventoryReservation
    {
        return DB::transaction(function () use ($line, $quantity): InventoryReservation {
            $locked = SalesOrderLine::query()->with('order')->lockForUpdate()->findOrFail($line->getKey());
            if (! $locked->order->isApprovedForFulfillment() || $locked->isService()) {
                throw new DomainException('This line is not eligible for stock reservation.');
            }
            $this->amounts->assertPositive($quantity, 'Reservation quantity must be greater than zero.');
            $remaining = $this->amounts->subtract($locked->quantity, $locked->reserved_quantity, 8);
            $this->amounts->assertNotGreaterThan($quantity, $remaining, 'Reservation exceeds the remaining order quantity.');
            BranchStore::query()->lockForUpdate()->findOrFail($locked->order->branch_store_id);
            $product = Product::query()->lockForUpdate()->findOrFail($locked->product_id);
            $baseQuantity = bcmul($quantity, (string) $locked->conversion_factor, 8);
            $available = $this->availability->forProduct((int) $locked->order->company_id, (int) $locked->order->branch_store_id, (int) $locked->product_id)['available'];
            $this->amounts->assertNotGreaterThan($baseQuantity, $available, 'Reservation exceeds currently available stock.');

            $reservation = InventoryReservation::query()->create([
                'company_id' => $locked->order->company_id, 'financial_period_id' => $locked->order->financial_period_id,
                'branch_id' => $locked->order->branch_id, 'branch_store_id' => $locked->order->branch_store_id,
                'sales_order_id' => $locked->sales_order_id, 'sales_order_line_id' => $locked->getKey(),
                'product_id' => $locked->product_id, 'unit_id' => $product->item_unit_id,
                'transaction_unit_id' => $locked->unit_id, 'conversion_factor' => $locked->conversion_factor,
                'transaction_quantity' => $quantity, 'quantity' => $baseQuantity,
                'status' => InventoryReservation::StatusActive, 'created_by' => auth()->id(),
            ]);
            $locked->increment('reserved_quantity', $quantity);
            $locked->increment('reserved_base_quantity', $baseQuantity);

            return $reservation->refresh();
        });
    }

    public function releaseReservation(InventoryReservation $reservation, string $reason): InventoryReservation
    {
        return DB::transaction(function () use ($reservation, $reason): InventoryReservation {
            $locked = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());
            if ($locked->status !== InventoryReservation::StatusActive) {
                throw new DomainException('Only an active reservation can be released.');
            }
            if (trim($reason) === '') {
                throw new DomainException('A reservation release reason is required.');
            }

            $line = SalesOrderLine::query()->lockForUpdate()->findOrFail($locked->sales_order_line_id);
            $remainingBaseQuantity = (string) $locked->remaining_quantity;
            $remainingTransactionQuantity = bcdiv($remainingBaseQuantity, (string) $locked->conversion_factor, 8);
            $locked->update([
                'released_quantity' => bcadd((string) $locked->released_quantity, $remainingBaseQuantity, 8),
                'status' => InventoryReservation::StatusReleased,
                'released_by' => auth()->id(),
                'released_at' => now(),
                'release_reason' => trim($reason),
            ]);
            $line->decrement('reserved_quantity', $remainingTransactionQuantity);
            $line->decrement('reserved_base_quantity', $remainingBaseQuantity);

            return $locked->refresh();
        });
    }

    /** @param list<array{sales_order_line_id: int, quantity: string|int|float}> $lines @param array<string, mixed> $logistics */
    public function deliver(SalesOrder $order, array $lines, array $logistics = []): InventoryDocument
    {
        return DB::transaction(function () use ($order, $lines, $logistics): InventoryDocument {
            $lockedOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            if (! $lockedOrder->isApprovedForFulfillment()) {
                throw new DomainException('Only an approved sales order can be delivered.');
            }
            if (! $lockedOrder->branch_store_id) {
                throw new DomainException('A finished-goods store is required for delivery.');
            }

            BranchStore::query()->lockForUpdate()->findOrFail($lockedOrder->branch_store_id);
            $numbers = $this->documents->nextForCompany('inventory_documents', InventoryDocument::class, (int) $lockedOrder->company_id, fn ($query) => $query->where('financial_period_id', $lockedOrder->financial_period_id));
            $document = InventoryDocument::query()->create([
                ...$numbers, 'company_id' => $lockedOrder->company_id, 'financial_period_id' => $lockedOrder->financial_period_id,
                'branch_id' => $lockedOrder->branch_id, 'branch_store_id' => $lockedOrder->branch_store_id,
                'document_type' => InventoryDocument::TypeSalesDelivery, 'document_date' => $logistics['document_date'] ?? now()->toDateString(),
                'purpose' => 'Sales delivery', 'source_document_type' => SalesOrder::class,
                'source_document_id' => $lockedOrder->getKey(), 'source_doc_num' => $lockedOrder->doc_num,
                'customer_id' => $lockedOrder->customer_id, 'status' => InventoryDocument::StatusDraft,
                'recipient_name' => $logistics['recipient_name'] ?? null, 'recipient_phone' => $logistics['recipient_phone'] ?? null,
                'vehicle_number' => $logistics['vehicle_number'] ?? null, 'driver_name' => $logistics['driver_name'] ?? null,
                'notes' => $logistics['notes'] ?? null, 'created_by' => auth()->id(),
            ]);

            foreach ($lines as $index => $input) {
                $line = SalesOrderLine::query()->lockForUpdate()->where('sales_order_id', $lockedOrder->getKey())->findOrFail($input['sales_order_line_id']);
                if ($line->isService()) {
                    throw new DomainException('Services do not generate warehouse deliveries.');
                }
                $quantity = (string) $input['quantity'];
                $baseQuantity = bcmul($quantity, (string) $line->conversion_factor, 8);
                $this->amounts->assertPositive($quantity, 'Delivery quantity must be greater than zero.');
                $this->amounts->assertNotGreaterThan($quantity, $line->remainingDeliveryQuantity(), 'Delivery exceeds the remaining approved quantity.');
                $product = Product::query()->lockForUpdate()->findOrFail($line->product_id);
                $stock = $this->availability->forProduct((int) $lockedOrder->company_id, (int) $lockedOrder->branch_store_id, (int) $line->product_id, (int) $line->getKey());
                $ownReserved = $this->amounts->subtract($line->reserved_base_quantity, $line->delivered_base_quantity, 8);
                $usable = $this->amounts->add($stock['available'], $ownReserved, 8);
                $this->amounts->assertNotGreaterThan($baseQuantity, $usable, 'Delivery exceeds available or reserved stock.');

                $document->lines()->create([
                    'company_id' => $lockedOrder->company_id, 'financial_period_id' => $lockedOrder->financial_period_id,
                    'line_number' => $index + 1, 'product_id' => $line->product_id, 'unit_id' => $product->item_unit_id,
                    'transaction_unit_id' => $line->unit_id, 'conversion_factor' => $line->conversion_factor,
                    'transaction_quantity' => $quantity, 'base_quantity' => $baseQuantity,
                    'source_line_type' => SalesOrderLine::class, 'source_line_id' => $line->getKey(),
                    'source_line_public_id' => $line->public_id, 'reference_quantity' => $line->base_quantity,
                    'previous_quantity' => $line->delivered_base_quantity, 'quantity' => $baseQuantity,
                    'product_snapshot' => ['classification' => $line->product_classification_snapshot, 'description' => $line->description],
                    'notes' => $line->warehouse_notes, 'created_by' => auth()->id(),
                ]);
            }

            $posted = $this->posting->post($document);
            $journal = $this->accounting->postDeliveryCost($posted);
            $posted->update(['journal_entry_id' => $journal->getKey()]);
            foreach ($posted->lines as $documentLine) {
                $line = SalesOrderLine::query()->lockForUpdate()->findOrFail($documentLine->source_line_id);
                $line->increment('delivered_quantity', $documentLine->transaction_quantity);
                $line->increment('delivered_base_quantity', $documentLine->quantity);
                $this->consumeReservations($line, (string) $documentLine->quantity);
            }
            $this->refreshOrderStatus($lockedOrder);

            return $posted->refresh()->load('lines');
        });
    }

    private function consumeReservations(SalesOrderLine $line, string $quantity): void
    {
        $remaining = $quantity;
        foreach (InventoryReservation::query()->where('sales_order_line_id', $line->getKey())->where('status', InventoryReservation::StatusActive)->lockForUpdate()->oldest()->get() as $reservation) {
            if ($this->amounts->compare($remaining, '0', 8) <= 0) {
                break;
            }
            $consume = $this->amounts->compare($remaining, $reservation->remaining_quantity, 8) > 0 ? $reservation->remaining_quantity : $remaining;
            $reservation->increment('consumed_quantity', $consume);
            if ($this->amounts->compare($reservation->fresh()->remaining_quantity, '0', 8) <= 0) {
                $reservation->update(['status' => InventoryReservation::StatusConsumed]);
            }
            $remaining = $this->amounts->subtract($remaining, $consume, 8);
        }
    }

    private function refreshOrderStatus(SalesOrder $order): void
    {
        $lines = $order->lines()->get();
        $physical = $lines->reject->isService();
        if ($physical->isEmpty()) {
            return;
        }
        $hasDelivery = $physical->contains(fn (SalesOrderLine $line): bool => $this->amounts->compare($line->delivered_quantity, '0', 8) > 0);
        $complete = $physical->every(fn (SalesOrderLine $line): bool => $this->amounts->compare($line->delivered_quantity, $line->quantity, 8) >= 0);
        $order->update(['status' => $complete ? SalesOrder::StatusFulfilled : ($hasDelivery ? SalesOrder::StatusPartiallyFulfilled : SalesOrder::StatusApproved)]);
    }
}

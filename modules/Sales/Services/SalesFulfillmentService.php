<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
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
        private readonly FinancialPeriodService $periods,
    ) {}

    public function reserve(SalesOrderLine $line, string $quantity): InventoryReservation
    {
        return DB::transaction(function () use ($line, $quantity): InventoryReservation {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($line->sales_order_id);
            $locked = SalesOrderLine::query()->lockForUpdate()->findOrFail($line->getKey());
            $locked->setRelation('order', $order);
            if (! $locked->order->isApprovedForFulfillment() || $locked->isService()) {
                throw new DomainException(__('This line is not eligible for stock reservation.'));
            }
            $this->amounts->assertPositive($quantity, __('Reservation quantity must be greater than zero.'));
            $remaining = $this->amounts->subtract($locked->remainingDeliveryQuantity(), $locked->activeReservedQuantity(), 8);
            $this->amounts->assertNotGreaterThan($quantity, $remaining, __('Reservation exceeds the remaining order quantity.'));
            BranchStore::query()->lockForUpdate()->findOrFail($locked->order->branch_store_id);
            $product = Product::query()->lockForUpdate()->findOrFail($locked->product_id);
            $baseQuantity = bcmul($quantity, (string) $locked->conversion_factor, 8);
            $available = $this->availability->forProduct((int) $locked->order->company_id, (int) $locked->order->branch_store_id, (int) $locked->product_id)['available'];
            $this->amounts->assertNotGreaterThan($baseQuantity, $available, __('Reservation exceeds currently available stock.'));

            $reservations = collect($this->allocateStockPositions(
                (int) $locked->order->company_id,
                (int) $locked->order->branch_store_id,
                (int) $locked->product_id,
                $baseQuantity,
            ))->map(fn (array $allocation): InventoryReservation => InventoryReservation::query()->create([
                'company_id' => $locked->order->company_id, 'financial_period_id' => $locked->order->financial_period_id,
                'branch_id' => $locked->order->branch_id, 'branch_store_id' => $locked->order->branch_store_id,
                'warehouse_location_id' => $allocation['warehouse_location_id'], 'batch_lot' => $allocation['batch_lot'],
                'sales_order_id' => $locked->sales_order_id, 'sales_order_line_id' => $locked->getKey(),
                'product_id' => $locked->product_id, 'unit_id' => $product->item_unit_id,
                'transaction_unit_id' => $locked->unit_id, 'conversion_factor' => $locked->conversion_factor,
                'transaction_quantity' => bcdiv($allocation['quantity'], (string) $locked->conversion_factor, 8),
                'quantity' => $allocation['quantity'], 'stock_status' => InventoryTransaction::StatusAvailable,
                'status' => InventoryReservation::StatusActive, 'created_by' => auth()->id(),
            ]));
            $locked->increment('reserved_quantity', $quantity);
            $locked->increment('reserved_base_quantity', $baseQuantity);

            return $reservations->firstOrFail()->refresh();
        });
    }

    public function releaseReservation(InventoryReservation $reservation, string $reason): InventoryReservation
    {
        return DB::transaction(function () use ($reservation, $reason): InventoryReservation {
            SalesOrder::query()->lockForUpdate()->findOrFail($reservation->sales_order_id);
            $locked = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());
            if ($locked->status !== InventoryReservation::StatusActive) {
                throw new DomainException(__('Only an active reservation can be released.'));
            }
            if (trim($reason) === '') {
                throw new DomainException(__('A reservation release reason is required.'));
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
            if ($lines === [] || count(array_unique(array_column($lines, 'sales_order_line_id'))) !== count($lines)) {
                throw new DomainException(__('Select each delivery order line once and enter its total quantity.'));
            }
            $lockedOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            if (! $lockedOrder->isApprovedForFulfillment()) {
                throw new DomainException(__('Only an approved sales order can be delivered.'));
            }
            $branchStoreId = isset($logistics['branch_store_id']) ? (int) $logistics['branch_store_id'] : (int) $lockedOrder->branch_store_id;
            if ($branchStoreId <= 0) {
                throw new DomainException(__('A finished-goods store is required for delivery.'));
            }

            $documentDate = $logistics['document_date'] ?? now()->toDateString();
            $period = $this->periods->resolveOpenForPostingDate((int) $lockedOrder->company_id, $documentDate, lockForUpdate: true);
            BranchStore::query()->where('branch_id', $lockedOrder->branch_id)->lockForUpdate()->findOrFail($branchStoreId);
            $numbers = $this->documents->nextForCompany('inventory_documents', InventoryDocument::class, (int) $lockedOrder->company_id);
            $document = InventoryDocument::query()->create([
                ...$numbers, 'company_id' => $lockedOrder->company_id, 'financial_period_id' => $period->getKey(),
                'branch_id' => $lockedOrder->branch_id, 'branch_store_id' => $branchStoreId,
                'document_type' => InventoryDocument::TypeSalesDelivery, 'document_date' => $documentDate,
                'purpose' => 'Sales delivery', 'source_document_type' => SalesOrder::class,
                'source_document_id' => $lockedOrder->getKey(), 'source_doc_num' => $lockedOrder->doc_num,
                'customer_id' => $lockedOrder->customer_id, 'status' => InventoryDocument::StatusDraft,
                'recipient_name' => $logistics['recipient_name'] ?? null, 'recipient_phone' => $logistics['recipient_phone'] ?? null,
                'vehicle_number' => $logistics['vehicle_number'] ?? null, 'driver_name' => $logistics['driver_name'] ?? null,
                'notes' => $logistics['notes'] ?? null, 'created_by' => auth()->id(),
            ]);

            $documentLineNumber = 0;
            foreach ($lines as $input) {
                $line = SalesOrderLine::query()->lockForUpdate()->where('sales_order_id', $lockedOrder->getKey())->findOrFail($input['sales_order_line_id']);
                if ($line->isService()) {
                    throw new DomainException(__('Services do not generate warehouse deliveries.'));
                }
                $quantity = (string) $input['quantity'];
                $baseQuantity = bcmul($quantity, (string) $line->conversion_factor, 8);
                $this->amounts->assertPositive($quantity, __('Delivery quantity must be greater than zero.'));
                $this->amounts->assertNotGreaterThan($quantity, $line->remainingDeliveryQuantity(), __('Delivery exceeds the remaining approved quantity.'));
                $product = Product::query()->lockForUpdate()->findOrFail($line->product_id);
                $stock = $this->availability->forProduct((int) $lockedOrder->company_id, $branchStoreId, (int) $line->product_id, (int) $line->getKey());
                $this->amounts->assertNotGreaterThan($baseQuantity, $stock['available'], __('Delivery exceeds available or reserved stock.'));

                foreach ($this->deliveryStockAllocations($line, $baseQuantity, $branchStoreId) as $allocation) {
                    $document->lines()->create([
                        'company_id' => $lockedOrder->company_id, 'financial_period_id' => $period->getKey(),
                        'line_number' => ++$documentLineNumber, 'product_id' => $line->product_id, 'unit_id' => $product->item_unit_id,
                        'transaction_unit_id' => $line->unit_id, 'conversion_factor' => $line->conversion_factor,
                        'transaction_quantity' => bcdiv($allocation['quantity'], (string) $line->conversion_factor, 8),
                        'base_quantity' => $allocation['quantity'],
                        'warehouse_location_id' => $allocation['warehouse_location_id'],
                        'batch_lot' => $allocation['batch_lot'],
                        'inventory_reservation_id' => $allocation['inventory_reservation_id'],
                        'source_line_type' => SalesOrderLine::class, 'source_line_id' => $line->getKey(),
                        'source_line_public_id' => $line->public_id, 'reference_quantity' => $line->base_quantity,
                        'previous_quantity' => $line->delivered_base_quantity, 'quantity' => $allocation['quantity'],
                        'product_snapshot' => ['classification' => $line->product_classification_snapshot, 'description' => $line->description],
                        'notes' => $line->warehouse_notes, 'created_by' => auth()->id(),
                    ]);
                }
            }

            $posted = $this->posting->post($document);
            $journal = $this->accounting->postDeliveryCost($posted);
            $posted->update(['journal_entry_id' => $journal->getKey()]);
            foreach ($posted->lines as $documentLine) {
                $line = SalesOrderLine::query()->lockForUpdate()->findOrFail($documentLine->source_line_id);
                $line->increment('delivered_quantity', $documentLine->transaction_quantity);
                $line->increment('delivered_base_quantity', $documentLine->quantity);
                $this->consumeReservations($line, (string) $documentLine->quantity, $branchStoreId);
            }
            $this->refreshOrderStatus($lockedOrder);

            return $posted->refresh()->load('lines');
        });
    }

    /**
     * @param  list<array{customer_invoice_line_id: int, quantity: string|int|float}>  $lines
     * @param  array<string, mixed>  $logistics
     */
    public function deliverInvoice(CustomerInvoice $invoice, array $lines, array $logistics): InventoryDocument
    {
        return DB::transaction(function () use ($invoice, $lines, $logistics): InventoryDocument {
            if ($lines === [] || count(array_unique(array_column($lines, 'customer_invoice_line_id'))) !== count($lines)) {
                throw new DomainException(__('Select each invoice line once and enter its delivery quantity.'));
            }

            $lockedInvoice = CustomerInvoice::query()->with(['order', 'lines.orderLine', 'lines.product', 'deliveries.lines'])
                ->lockForUpdate()->findOrFail($invoice->getKey());
            if ($lockedInvoice->document_type !== CustomerInvoice::TypeInvoice || $lockedInvoice->posting_status !== CustomerInvoice::StatusPosted) {
                throw new DomainException(__('Only a posted sales invoice can be delivered.'));
            }
            if (! $lockedInvoice->order) {
                return $this->deliverDirectInvoice($lockedInvoice, $lines, $logistics);
            }

            $branchStore = BranchStore::query()
                ->where('branch_id', $lockedInvoice->branch_id)
                ->where('public_uuid', $logistics['branch_store_uuid'] ?? '')
                ->lockForUpdate()
                ->firstOrFail();
            $deliveryLines = [];
            foreach ($lines as $input) {
                $invoiceLine = CustomerInvoiceLine::query()->with('orderLine')->where('customer_invoice_id', $lockedInvoice->getKey())
                    ->lockForUpdate()->findOrFail($input['customer_invoice_line_id']);
                if ($invoiceLine->is_service || ! $invoiceLine->orderLine) {
                    throw new DomainException(__('Service invoice lines do not generate warehouse deliveries.'));
                }

                $deliveredForInvoice = (string) $lockedInvoice->deliveries
                    ->flatMap->lines
                    ->where('source_line_type', SalesOrderLine::class)
                    ->where('source_line_id', $invoiceLine->sales_order_line_id)
                    ->sum('transaction_quantity');
                $remaining = $this->amounts->subtract((string) $invoiceLine->quantity, $deliveredForInvoice, 8);
                $quantity = (string) $input['quantity'];
                $this->amounts->assertPositive($quantity, __('Delivery quantity must be greater than zero.'));
                $this->amounts->assertNotGreaterThan($quantity, $remaining, __('Delivery quantity exceeds the invoiced quantity remaining for delivery.'));
                $deliveryLines[] = ['sales_order_line_id' => $invoiceLine->sales_order_line_id, 'quantity' => $quantity];
            }

            $document = $this->deliver($lockedInvoice->order, $deliveryLines, [
                ...$logistics,
                'branch_store_id' => $branchStore->getKey(),
            ]);
            $document->update(['source_doc_num' => $lockedInvoice->doc_num]);
            $lockedInvoice->deliveries()->syncWithoutDetaching([$document->getKey()]);
            if (! $lockedInvoice->delivery_document_id) {
                $lockedInvoice->update(['delivery_document_id' => $document->getKey()]);
            }

            return $document->refresh()->load(['lines', 'branchStore']);
        });
    }

    /**
     * @param  list<array{customer_invoice_line_id: int, quantity: string|int|float}>  $lines
     * @param  array<string, mixed>  $logistics
     */
    private function deliverDirectInvoice(CustomerInvoice $invoice, array $lines, array $logistics): InventoryDocument
    {
        $branchStore = BranchStore::query()
            ->where('branch_id', $invoice->branch_id)
            ->where('public_uuid', $logistics['branch_store_uuid'] ?? '')
            ->lockForUpdate()
            ->firstOrFail();
        $documentDate = $logistics['document_date'] ?? now()->toDateString();
        $period = $this->periods->resolveOpenForPostingDate((int) $invoice->company_id, $documentDate, lockForUpdate: true);
        $numbers = $this->documents->nextForCompany('inventory_documents', InventoryDocument::class, (int) $invoice->company_id);
        $document = InventoryDocument::query()->create([
            ...$numbers,
            'company_id' => $invoice->company_id, 'financial_period_id' => $period->getKey(), 'branch_id' => $invoice->branch_id,
            'branch_store_id' => $branchStore->getKey(), 'document_type' => InventoryDocument::TypeSalesDelivery,
            'document_date' => $documentDate, 'purpose' => 'Sales delivery',
            'source_document_type' => CustomerInvoice::class, 'source_document_id' => $invoice->getKey(), 'source_doc_num' => $invoice->doc_num,
            'customer_id' => $invoice->customer_id, 'status' => InventoryDocument::StatusDraft,
            'recipient_name' => $logistics['recipient_name'] ?? null, 'recipient_phone' => $logistics['recipient_phone'] ?? null,
            'vehicle_number' => $logistics['vehicle_number'] ?? null, 'driver_name' => $logistics['driver_name'] ?? null,
            'notes' => $logistics['notes'] ?? null, 'created_by' => auth()->id(),
        ]);

        $lineNumber = 0;
        foreach ($lines as $input) {
            $invoiceLine = CustomerInvoiceLine::query()->with('product')->where('customer_invoice_id', $invoice->getKey())
                ->lockForUpdate()->findOrFail($input['customer_invoice_line_id']);
            if ($invoiceLine->is_service || ! $invoiceLine->product_id) {
                throw new DomainException(__('Services do not generate warehouse deliveries.'));
            }
            $delivered = (string) $invoice->deliveries->flatMap->lines
                ->where('source_line_type', CustomerInvoiceLine::class)
                ->where('source_line_id', $invoiceLine->getKey())
                ->sum('transaction_quantity');
            $remaining = $this->amounts->subtract((string) $invoiceLine->quantity, $delivered, 8);
            $quantity = (string) $input['quantity'];
            $this->amounts->assertPositive($quantity, __('Delivery quantity must be greater than zero.'));
            $this->amounts->assertNotGreaterThan($quantity, $remaining, __('Delivery quantity exceeds the invoiced quantity remaining for delivery.'));
            $baseQuantity = bcmul($quantity, (string) $invoiceLine->conversion_factor, 8);

            foreach ($this->allocateStockPositions((int) $invoice->company_id, (int) $branchStore->getKey(), (int) $invoiceLine->product_id, $baseQuantity) as $allocation) {
                $document->lines()->create([
                    'company_id' => $invoice->company_id, 'financial_period_id' => $period->getKey(), 'line_number' => ++$lineNumber,
                    'product_id' => $invoiceLine->product_id, 'unit_id' => $invoiceLine->product->item_unit_id,
                    'transaction_unit_id' => $invoiceLine->unit_id, 'conversion_factor' => $invoiceLine->conversion_factor,
                    'transaction_quantity' => bcdiv($allocation['quantity'], (string) $invoiceLine->conversion_factor, 8),
                    'base_quantity' => $allocation['quantity'], 'quantity' => $allocation['quantity'],
                    'warehouse_location_id' => $allocation['warehouse_location_id'], 'batch_lot' => $allocation['batch_lot'],
                    'source_line_type' => CustomerInvoiceLine::class, 'source_line_id' => $invoiceLine->getKey(),
                    'source_line_public_id' => $invoiceLine->public_id, 'reference_quantity' => $invoiceLine->base_quantity,
                    'previous_quantity' => bcmul($delivered, (string) $invoiceLine->conversion_factor, 8),
                    'product_snapshot' => ['classification' => $invoiceLine->product->item_classification, 'description' => $invoiceLine->description],
                    'created_by' => auth()->id(),
                ]);
            }
        }

        $posted = $this->posting->post($document);
        $journal = $this->accounting->postDeliveryCost($posted);
        $posted->update(['journal_entry_id' => $journal->getKey()]);
        $invoice->deliveries()->syncWithoutDetaching([$posted->getKey()]);
        if (! $invoice->delivery_document_id) {
            $invoice->update(['delivery_document_id' => $posted->getKey()]);
        }

        return $posted->refresh()->load(['lines', 'branchStore']);
    }

    private function consumeReservations(SalesOrderLine $line, string $quantity, int $branchStoreId): void
    {
        $remaining = $quantity;
        foreach (InventoryReservation::query()->where('sales_order_line_id', $line->getKey())->where('branch_store_id', $branchStoreId)->where('status', InventoryReservation::StatusActive)->lockForUpdate()->oldest()->get() as $reservation) {
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

    /**
     * @return list<array{warehouse_location_id: int|null, batch_lot: string|null, quantity: string}>
     */
    private function allocateStockPositions(
        int $companyId,
        int $branchStoreId,
        int $productId,
        string $quantity,
        ?int $exceptOrderLineId = null,
    ): array {
        $remaining = $quantity;
        $allocations = [];
        $positions = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->where('stock_status', InventoryTransaction::StatusAvailable)
            ->groupBy(['warehouse_location_id', 'batch_lot'])
            ->havingRaw('sum(quantity_in - quantity_out) > 0')
            ->orderByRaw('min(transaction_date), min(id)')
            ->get(['warehouse_location_id', 'batch_lot']);

        foreach ($positions as $position) {
            if (bccomp($remaining, '0', 8) <= 0) {
                break;
            }

            $available = $this->availability->forProduct(
                $companyId,
                $branchStoreId,
                $productId,
                $exceptOrderLineId,
                $position->warehouse_location_id,
                InventoryTransaction::StatusAvailable,
                $position->batch_lot,
                true,
            )['available'];
            if (bccomp($available, '0', 8) <= 0) {
                continue;
            }

            $allocated = bccomp($remaining, $available, 8) > 0 ? $available : $remaining;
            $allocations[] = [
                'warehouse_location_id' => $position->warehouse_location_id === null ? null : (int) $position->warehouse_location_id,
                'batch_lot' => $position->batch_lot,
                'quantity' => $allocated,
            ];
            $remaining = bcsub($remaining, $allocated, 8);
        }

        if (bccomp($remaining, '0', 8) > 0) {
            throw new DomainException(__('The requested stock cannot be allocated across available warehouse locations and batches.'));
        }

        return $allocations;
    }

    /**
     * @return list<array{warehouse_location_id: int|null, batch_lot: string|null, quantity: string, inventory_reservation_id: int|null}>
     */
    private function deliveryStockAllocations(SalesOrderLine $line, string $quantity, int $branchStoreId): array
    {
        $remaining = $quantity;
        $allocations = [];
        $allocatedByPosition = [];
        $positionKey = fn (mixed $locationId, mixed $batchLot): string => ($locationId ?? 'null').'|'.($batchLot ?? 'null');

        $reservations = InventoryReservation::query()
            ->where('sales_order_line_id', $line->getKey())
            ->where('branch_store_id', $branchStoreId)
            ->where('status', InventoryReservation::StatusActive)
            ->oldest()
            ->lockForUpdate()
            ->get();
        foreach ($reservations as $reservation) {
            if (bccomp($remaining, '0', 8) <= 0) {
                break;
            }

            $available = $this->availability->forProduct(
                (int) $line->order->company_id,
                $branchStoreId,
                (int) $line->product_id,
                (int) $line->getKey(),
                $reservation->warehouse_location_id,
                InventoryTransaction::StatusAvailable,
                $reservation->batch_lot,
                true,
            )['available'];
            $reservable = bccomp((string) $reservation->remaining_quantity, $available, 8) > 0
                ? $available
                : (string) $reservation->remaining_quantity;
            if (bccomp($reservable, '0', 8) <= 0) {
                continue;
            }

            $allocated = bccomp($remaining, $reservable, 8) > 0 ? $reservable : $remaining;
            $allocations[] = [
                'warehouse_location_id' => $reservation->warehouse_location_id === null ? null : (int) $reservation->warehouse_location_id,
                'batch_lot' => $reservation->batch_lot,
                'quantity' => $allocated,
                'inventory_reservation_id' => $reservation->getKey(),
            ];
            $key = $positionKey($reservation->warehouse_location_id, $reservation->batch_lot);
            $allocatedByPosition[$key] = bcadd($allocatedByPosition[$key] ?? '0', $allocated, 8);
            $remaining = bcsub($remaining, $allocated, 8);
        }

        if (bccomp($remaining, '0', 8) > 0) {
            foreach ($this->allocateStockPositions(
                (int) $line->order->company_id,
                $branchStoreId,
                (int) $line->product_id,
                bcadd($remaining, array_reduce($allocatedByPosition, fn (string $carry, string $value): string => bcadd($carry, $value, 8), '0'), 8),
                (int) $line->getKey(),
            ) as $position) {
                $key = $positionKey($position['warehouse_location_id'], $position['batch_lot']);
                $available = bcsub($position['quantity'], $allocatedByPosition[$key] ?? '0', 8);
                if (bccomp($available, '0', 8) <= 0 || bccomp($remaining, '0', 8) <= 0) {
                    continue;
                }
                $allocated = bccomp($remaining, $available, 8) > 0 ? $available : $remaining;
                $allocations[] = [
                    ...$position,
                    'quantity' => $allocated,
                    'inventory_reservation_id' => null,
                ];
                $remaining = bcsub($remaining, $allocated, 8);
            }
        }

        if (bccomp($remaining, '0', 8) > 0) {
            throw new DomainException(__('The delivery cannot be allocated across the reserved and available stock positions.'));
        }

        return collect($allocations)
            ->groupBy(fn (array $allocation): string => $positionKey($allocation['warehouse_location_id'], $allocation['batch_lot']))
            ->map(function ($positionAllocations): array {
                $first = $positionAllocations->first();

                return [
                    ...$first,
                    'quantity' => $positionAllocations->reduce(
                        fn (string $carry, array $allocation): string => bcadd($carry, $allocation['quantity'], 8),
                        '0',
                    ),
                ];
            })
            ->values()
            ->all();
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

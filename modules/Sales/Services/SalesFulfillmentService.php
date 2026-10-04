<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
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
        private readonly SalesCycleAuditService $audit,
    ) {}

    public function reserve(SalesOrderLine $line, string $quantity): InventoryReservation
    {
        return DB::transaction(function () use ($line, $quantity): InventoryReservation {
            Company::query()->whereKey($line->order->company_id)->lockForUpdate()->firstOrFail();
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

            $allocations = $this->allocateStockPositions(
                (int) $locked->order->company_id,
                (int) $locked->order->branch_store_id,
                (int) $locked->product_id,
                $baseQuantity,
            );
            $transactionQuantities = $this->amounts->splitQuantityByWeights($quantity, array_column($allocations, 'quantity'));
            $reservations = collect($allocations)->map(fn (array $allocation, int $index): InventoryReservation => InventoryReservation::query()->create([
                'company_id' => $locked->order->company_id, 'financial_period_id' => $locked->order->financial_period_id,
                'branch_id' => $locked->order->branch_id, 'branch_store_id' => $locked->order->branch_store_id,
                'warehouse_location_id' => $allocation['warehouse_location_id'], 'batch_lot' => $allocation['batch_lot'],
                'sales_order_id' => $locked->sales_order_id, 'sales_order_line_id' => $locked->getKey(),
                'product_id' => $locked->product_id, 'unit_id' => $product->item_unit_id,
                'transaction_unit_id' => $locked->unit_id, 'conversion_factor' => $locked->conversion_factor,
                'transaction_quantity' => $transactionQuantities[$index],
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
            Company::query()->whereKey($reservation->company_id)->lockForUpdate()->firstOrFail();
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
    public function deliver(SalesOrder $order, array $lines, array $logistics = [], bool $allowCompanyWarehouse = false): InventoryDocument
    {
        return DB::transaction(function () use ($order, $lines, $logistics, $allowCompanyWarehouse): InventoryDocument {
            Company::query()->whereKey($order->company_id)->lockForUpdate()->firstOrFail();
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
            $branchStore = BranchStore::query()->with('branch')->lockForUpdate()->findOrFail($branchStoreId);
            if ((int) $branchStore->branch?->company_id !== (int) $lockedOrder->company_id
                || (! $allowCompanyWarehouse && (int) $branchStore->branch_id !== (int) $lockedOrder->branch_id)) {
                throw new DomainException(__('sales_issue.messages.store_not_eligible'));
            }
            $numbers = $this->documents->nextForCompany('inventory_documents', InventoryDocument::class, (int) $lockedOrder->company_id);
            $document = InventoryDocument::query()->create([
                ...$numbers, 'company_id' => $lockedOrder->company_id, 'financial_period_id' => $period->getKey(),
                'branch_id' => $branchStore->branch_id, 'branch_store_id' => $branchStoreId,
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

                $allocations = $this->deliveryStockAllocations($line, $baseQuantity, $branchStoreId, $input['receipt_layers'] ?? [], $documentDate);
                $transactionQuantities = $this->amounts->splitQuantityByWeights($quantity, array_column($allocations, 'quantity'));
                foreach ($allocations as $index => $allocation) {
                    $document->lines()->create([
                        'company_id' => $lockedOrder->company_id, 'financial_period_id' => $period->getKey(),
                        'line_number' => ++$documentLineNumber, 'product_id' => $line->product_id, 'unit_id' => $product->item_unit_id,
                        'transaction_unit_id' => $line->unit_id, 'conversion_factor' => $line->conversion_factor,
                        'transaction_quantity' => $transactionQuantities[$index],
                        'base_quantity' => $allocation['quantity'],
                        'warehouse_location_id' => $allocation['warehouse_location_id'],
                        'batch_lot' => $allocation['batch_lot'],
                        'selected_receipt_layer_id' => $allocation['selected_receipt_layer_id'] ?? null,
                        'manufacture_date' => $allocation['manufacture_date'] ?? null,
                        'expiry_date' => $allocation['expiry_date'] ?? null,
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
                $this->consumeReservations($line, (string) $documentLine->quantity, $branchStoreId, $documentLine->warehouse_location_id, $documentLine->batch_lot);
            }
            foreach ($posted->lines->pluck('source_line_id')->unique() as $lineId) {
                $this->releaseExcessReservations(SalesOrderLine::query()->lockForUpdate()->findOrFail($lineId), $posted);
            }
            $this->refreshOrderStatus($lockedOrder);

            return $posted->refresh()->load('lines');
        });
    }

    /**
     * @param  list<array{customer_invoice_line_id: int, quantity: string|int|float}>  $lines
     * @param  array<string, mixed>  $logistics
     */
    public function deliverInvoice(CustomerInvoice $invoice, array $lines, array $logistics, bool $allowCompanyWarehouse = false): InventoryDocument
    {
        return DB::transaction(function () use ($invoice, $lines, $logistics, $allowCompanyWarehouse): InventoryDocument {
            Company::query()->whereKey($invoice->company_id)->lockForUpdate()->firstOrFail();
            if ($lines === [] || count(array_unique(array_column($lines, 'customer_invoice_line_id'))) !== count($lines)) {
                throw new DomainException(__('Select each invoice line once and enter its delivery quantity.'));
            }

            $lockedInvoice = CustomerInvoice::query()->with(['order', 'lines.orderLine', 'lines.product', 'deliveries.lines'])
                ->lockForUpdate()->findOrFail($invoice->getKey());
            $lockedInvoice->setRelation('deliveries', $lockedInvoice->deliveries->where('status', InventoryDocument::StatusPosted));
            if ($lockedInvoice->document_type !== CustomerInvoice::TypeInvoice || $lockedInvoice->posting_status !== CustomerInvoice::StatusPosted || $lockedInvoice->hasApprovedCorrection()) {
                throw new DomainException(__('Only a posted sales invoice can be delivered.'));
            }
            if (! $lockedInvoice->order) {
                return $this->deliverDirectInvoice($lockedInvoice, $lines, $logistics, $allowCompanyWarehouse);
            }

            $branchStore = BranchStore::query()->with('branch')
                ->where('public_uuid', $logistics['branch_store_uuid'] ?? '')
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $branchStore->branch?->company_id !== (int) $lockedInvoice->company_id
                || (! $allowCompanyWarehouse && (int) $branchStore->branch_id !== (int) $lockedInvoice->branch_id)) {
                throw new DomainException(__('sales_issue.messages.store_not_eligible'));
            }
            $deliveredByOrderLine = [];
            foreach ($lockedInvoice->deliveries->flatMap->lines as $documentLine) {
                if ($documentLine->source_line_type !== SalesOrderLine::class) {
                    continue;
                }
                $sourceId = (int) $documentLine->source_line_id;
                $deliveredByOrderLine[$sourceId] = bcadd($deliveredByOrderLine[$sourceId] ?? '0', (string) $documentLine->transaction_quantity, 8);
            }
            $remainingByInvoiceLine = [];
            foreach ($lockedInvoice->lines as $invoiceLine) {
                if ($invoiceLine->is_service || $invoiceLine->sales_order_line_id === null) {
                    continue;
                }
                $sourceId = (int) $invoiceLine->sales_order_line_id;
                $delivered = $deliveredByOrderLine[$sourceId] ?? '0';
                $consumed = bccomp($delivered, (string) $invoiceLine->quantity, 8) > 0 ? (string) $invoiceLine->quantity : $delivered;
                $remainingByInvoiceLine[$invoiceLine->getKey()] = bcsub((string) $invoiceLine->quantity, $consumed, 8);
                $deliveredByOrderLine[$sourceId] = bcsub($delivered, $consumed, 8);
            }
            $deliveryQuantities = [];
            $deliveryLayers = [];
            foreach ($lines as $input) {
                $invoiceLine = CustomerInvoiceLine::query()->with('orderLine')->where('customer_invoice_id', $lockedInvoice->getKey())
                    ->lockForUpdate()->findOrFail($input['customer_invoice_line_id']);
                if ($invoiceLine->is_service || ! $invoiceLine->orderLine) {
                    throw new DomainException(__('Service invoice lines do not generate warehouse deliveries.'));
                }

                $remaining = $remainingByInvoiceLine[$invoiceLine->getKey()] ?? '0';
                $quantity = (string) $input['quantity'];
                $this->amounts->assertPositive($quantity, __('Delivery quantity must be greater than zero.'));
                $this->amounts->assertNotGreaterThan($quantity, $remaining, __('Delivery quantity exceeds the invoiced quantity remaining for delivery.'));
                $sourceId = (int) $invoiceLine->sales_order_line_id;
                $deliveryQuantities[$sourceId] = bcadd($deliveryQuantities[$sourceId] ?? '0', $quantity, 8);
                $deliveryLayers[$sourceId] = [...($deliveryLayers[$sourceId] ?? []), ...($input['receipt_layers'] ?? [])];
            }
            $deliveryLines = [];
            foreach ($deliveryQuantities as $sourceId => $quantity) {
                $deliveryLines[] = ['sales_order_line_id' => $sourceId, 'quantity' => $quantity, 'receipt_layers' => $deliveryLayers[$sourceId] ?? []];
            }

            $document = $this->deliver($lockedInvoice->order, $deliveryLines, [
                ...$logistics,
                'branch_store_id' => $branchStore->getKey(),
            ], $allowCompanyWarehouse);
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
    private function deliverDirectInvoice(CustomerInvoice $invoice, array $lines, array $logistics, bool $allowCompanyWarehouse): InventoryDocument
    {
        $branchStore = BranchStore::query()->with('branch')
            ->where('public_uuid', $logistics['branch_store_uuid'] ?? '')
            ->lockForUpdate()
            ->firstOrFail();
        if ((int) $branchStore->branch?->company_id !== (int) $invoice->company_id
            || (! $allowCompanyWarehouse && (int) $branchStore->branch_id !== (int) $invoice->branch_id)) {
            throw new DomainException(__('sales_issue.messages.store_not_eligible'));
        }
        $documentDate = $logistics['document_date'] ?? now()->toDateString();
        $period = $this->periods->resolveOpenForPostingDate((int) $invoice->company_id, $documentDate, lockForUpdate: true);
        $numbers = $this->documents->nextForCompany('inventory_documents', InventoryDocument::class, (int) $invoice->company_id);
        $document = InventoryDocument::query()->create([
            ...$numbers,
            'company_id' => $invoice->company_id, 'financial_period_id' => $period->getKey(), 'branch_id' => $branchStore->branch_id,
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
            $delivered = $this->amounts->sum($invoice->deliveries->flatMap->lines
                ->where('source_line_type', CustomerInvoiceLine::class)
                ->where('source_line_id', $invoiceLine->getKey())
                ->pluck('transaction_quantity'), 8);
            $remaining = $this->amounts->subtract((string) $invoiceLine->quantity, $delivered, 8);
            $quantity = (string) $input['quantity'];
            $this->amounts->assertPositive($quantity, __('Delivery quantity must be greater than zero.'));
            $this->amounts->assertNotGreaterThan($quantity, $remaining, __('Delivery quantity exceeds the invoiced quantity remaining for delivery.'));
            $baseQuantity = bcmul($quantity, (string) $invoiceLine->conversion_factor, 8);

            $allocations = ($input['receipt_layers'] ?? []) !== []
                ? $this->selectedDeliveryStockAllocations((int) $invoice->company_id, (int) $branchStore->getKey(), (int) $invoiceLine->product_id, $baseQuantity, $input['receipt_layers'], $documentDate)
                : $this->allocateStockPositions((int) $invoice->company_id, (int) $branchStore->getKey(), (int) $invoiceLine->product_id, $baseQuantity);
            $transactionQuantities = $this->amounts->splitQuantityByWeights($quantity, array_column($allocations, 'quantity'));
            foreach ($allocations as $index => $allocation) {
                $document->lines()->create([
                    'company_id' => $invoice->company_id, 'financial_period_id' => $period->getKey(), 'line_number' => ++$lineNumber,
                    'product_id' => $invoiceLine->product_id, 'unit_id' => $invoiceLine->product->item_unit_id,
                    'transaction_unit_id' => $invoiceLine->unit_id, 'conversion_factor' => $invoiceLine->conversion_factor,
                    'transaction_quantity' => $transactionQuantities[$index],
                    'base_quantity' => $allocation['quantity'], 'quantity' => $allocation['quantity'],
                    'warehouse_location_id' => $allocation['warehouse_location_id'], 'batch_lot' => $allocation['batch_lot'],
                    'selected_receipt_layer_id' => $allocation['selected_receipt_layer_id'] ?? null,
                    'manufacture_date' => $allocation['manufacture_date'] ?? null, 'expiry_date' => $allocation['expiry_date'] ?? null,
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

    private function consumeReservations(SalesOrderLine $line, string $quantity, int $branchStoreId, ?int $locationId, ?string $batchLot): void
    {
        $remaining = $quantity;
        foreach (InventoryReservation::query()->where('sales_order_line_id', $line->getKey())->where('branch_store_id', $branchStoreId)
            ->where('warehouse_location_id', $locationId)->where('batch_lot', $batchLot)
            ->where('status', InventoryReservation::StatusActive)->lockForUpdate()->oldest()->get() as $reservation) {
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

    private function releaseExcessReservations(SalesOrderLine $line, InventoryDocument $delivery): void
    {
        $reservations = InventoryReservation::query()->where('company_id', $delivery->company_id)
            ->where('sales_order_id', $line->sales_order_id)->where('sales_order_line_id', $line->id)
            ->where('status', InventoryReservation::StatusActive)->orderByDesc('id')->lockForUpdate()->get();
        $active = $this->amounts->sum($reservations->map(fn (InventoryReservation $reservation): string => $reservation->remaining_quantity), 8);
        $remaining = bcsub((string) $line->base_quantity, (string) $line->delivered_base_quantity, 8);
        $excess = bcsub($active, bccomp($remaining, '0', 8) > 0 ? $remaining : '0', 8);
        $released = '0';
        foreach ($reservations as $reservation) {
            if (bccomp($excess, '0', 8) <= 0) {
                break;
            }
            $before = $reservation->remaining_quantity;
            $quantity = bccomp($excess, $before, 8) > 0 ? $before : $excess;
            $reservation->update([
                'released_quantity' => bcadd((string) $reservation->released_quantity, $quantity, 8),
                'status' => bccomp($quantity, $before, 8) === 0 ? InventoryReservation::StatusReleased : InventoryReservation::StatusActive,
                'released_by' => auth()->id(), 'released_at' => now(),
                'release_reason' => __('sales_issue.messages.reservation_released_after_delivery'),
            ]);
            $this->audit->record($reservation, 'inventory_reservation.released_after_delivery', [
                'delivery' => $delivery->doc_num, 'sales_order_line_id' => $line->id,
                'released_base_quantity' => $quantity, 'before_remaining_base_quantity' => $before,
                'after_remaining_base_quantity' => $reservation->fresh()->remaining_quantity,
            ]);
            $released = bcadd($released, $quantity, 8);
            $excess = bcsub($excess, $quantity, 8);
        }
        if (bccomp($released, '0', 8) > 0) {
            $reservedBase = bcsub((string) $line->reserved_base_quantity, $released, 8);
            $reservedBase = bccomp($reservedBase, '0', 8) > 0 ? $reservedBase : '0';
            $line->update(['reserved_base_quantity' => $reservedBase, 'reserved_quantity' => bcdiv($reservedBase, (string) $line->conversion_factor, 8)]);
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
            throw new DomainException(__('The requested stock cannot be allocated across available stock batches.'));
        }

        return $allocations;
    }

    /**
     * @return list<array{warehouse_location_id: int|null, batch_lot: string|null, quantity: string, inventory_reservation_id: int|null}>
     */
    private function deliveryStockAllocations(SalesOrderLine $line, string $quantity, int $branchStoreId, array $selectedLayers = [], ?string $date = null): array
    {
        if ($selectedLayers !== []) {
            return $this->selectedDeliveryStockAllocations((int) $line->order->company_id, $branchStoreId, (int) $line->product_id, $quantity, $selectedLayers, $date ?? now()->toDateString(), (int) $line->id);
        }
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

    /** @param list<array{layer_id: int, quantity: string}> $selections
     * @return list<array<string, mixed>>
     */
    private function selectedDeliveryStockAllocations(int $companyId, int $storeId, int $productId, string $quantity, array $selections, string $date, ?int $salesLineId = null): array
    {
        $seen = [];
        $positions = [];
        $total = '0';
        $allocations = [];
        foreach ($selections as $selection) {
            $id = (int) ($selection['layer_id'] ?? 0);
            $slice = (string) ($selection['quantity'] ?? '0');
            $layer = InventoryReceiptLayer::query()->whereKey($id)->where('company_id', $companyId)
                ->where('branch_store_id', $storeId)->where('product_id', $productId)->where('stock_status', InventoryTransaction::StatusAvailable)
                ->withAuthoritativeCost()->whereDate('original_receipt_date', '<=', $date)->lockForUpdate()->first();
            if (isset($seen[$id]) || $layer === null || ! preg_match('/^\d+(?:\.\d{1,8})?$/D', $slice)
                || bccomp($slice, '0', 8) <= 0 || bccomp($slice, (string) $layer->remaining_quantity, 8) > 0) {
                throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
            }
            $key = json_encode([$layer->warehouse_location_id, $layer->batch_lot], JSON_THROW_ON_ERROR);
            $available = $this->availability->forProduct($companyId, $storeId, $productId, $salesLineId, $layer->warehouse_location_id, InventoryTransaction::StatusAvailable, $layer->batch_lot, true)['available'];
            $positions[$key] = bcadd($positions[$key] ?? '0', $slice, 8);
            if (bccomp($positions[$key], $available, 8) > 0) {
                throw new DomainException(__('Delivery exceeds available or reserved stock.'));
            }
            $seen[$id] = true;
            $total = bcadd($total, $slice, 8);
            $allocations[] = ['warehouse_location_id' => $layer->warehouse_location_id, 'batch_lot' => $layer->batch_lot,
                'quantity' => $slice, 'selected_receipt_layer_id' => $id, 'inventory_reservation_id' => null,
                'manufacture_date' => $layer->manufacture_date, 'expiry_date' => $layer->expiry_date];
        }
        if (bccomp($total, $quantity, 8) !== 0) {
            throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
        }

        return $allocations;
    }

    public function refreshOrderStatus(SalesOrder $order): void
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

<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Sales\Models\CustomerCreditAllocation;
use Modules\Sales\Models\CustomerCreditRefund;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\CustomerInvoicePaymentSchedule;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnCorrection;
use Modules\Sales\Models\SalesReturnLine;

class SalesReturnService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly SalesAmountService $amounts,
        private readonly InventoryDocumentPostingService $inventoryPosting,
        private readonly SalesAccountingService $accounting,
        private readonly SalesCycleAuditService $audit,
        private readonly SalesFulfillmentService $fulfillment,
        private readonly CustomerCreditService $credits,
    ) {}

    /** @param list<array{customer_invoice_line_id: int, quantity: string|int|float}> $lines */
    public function create(CustomerInvoice $invoice, string $reasonCode, ?string $reasonDetails, array $lines, ?int $branchStoreId = null, ?int $correctionId = null): SalesReturn
    {
        return DB::transaction(function () use ($invoice, $reasonCode, $reasonDetails, $lines, $branchStoreId, $correctionId): SalesReturn {
            Company::query()->whereKey($invoice->company_id)->lockForUpdate()->firstOrFail();
            if (count(array_unique(array_column($lines, 'customer_invoice_line_id'))) !== count($lines)) {
                throw new DomainException(__('Select each return invoice line once and enter its total quantity.'));
            }
            $source = CustomerInvoice::query()->with('deliveries')->lockForUpdate()->findOrFail($invoice->getKey());
            app(CustomerInvoiceBalanceService::class)->assertNoActiveWithholding($source);
            $proposal = $correctionId === null ? null : app(SalesReturnCorrectionService::class)->execution($correctionId, 'replacement',
                (int) SalesReturnCorrection::query()->findOrFail($correctionId)->sales_return_id);
            if ($proposal !== null && (int) data_get($proposal->source_snapshot, 'return.customer_invoice_id') !== (int) $source->id) {
                throw new DomainException(__('sales_return_plan.source_invalid'));
            }
            $returnDate = $proposal?->posting_date->toDateString() ?? now()->toDateString();
            $period = app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $source->company_id, $returnDate,
                expectedPeriodId: $proposal?->posting_financial_period_id, lockForUpdate: true);
            if ($source->document_type !== CustomerInvoice::TypeInvoice || $source->posting_status !== 'posted') {
                throw new DomainException(__('Returns require an original posted sales invoice.'));
            }
            if (! in_array($reasonCode, $this->reasonCodes(), true)) {
                throw new DomainException(__('Select a controlled sales return reason.'));
            }
            $returnStoreId = $branchStoreId;
            if (! $returnStoreId) {
                $deliveryStoreIds = $source->deliveries
                    ->where('status', InventoryDocument::StatusPosted)
                    ->pluck('branch_store_id')
                    ->filter()
                    ->unique()
                    ->values();
                $returnStoreId = $deliveryStoreIds->count() === 1 ? (int) $deliveryStoreIds->first() : null;
            }
            $prepared = [];
            $preparedByDelivery = [];
            foreach ($lines as $input) {
                $line = CustomerInvoiceLine::query()->lockForUpdate()->where('customer_invoice_id', $source->getKey())->findOrFail($input['customer_invoice_line_id']);
                $quantity = (string) $input['quantity'];
                $this->amounts->assertPositive($quantity, __('Return quantity must be greater than zero.'));
                $alreadyReturned = (string) DB::table('sales_return_lines')->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
                    ->where('sales_return_lines.customer_invoice_line_id', $line->getKey())->where('sales_returns.status', '<>', SalesReturn::StatusCancelled)->whereNull('sales_returns.deleted_at')->sum('sales_return_lines.quantity');
                $this->amounts->assertNotGreaterThan($quantity, $this->amounts->subtract($line->quantity, $alreadyReturned, 8), __('Return quantity exceeds the quantity still returnable.'));
                [$expectedTotal, $expectedTax] = $this->invoiceReturnAmounts($line, $quantity, $alreadyReturned);

                if ($line->is_service) {
                    $prepared[] = [...$this->prepareInvoiceReturnLine($line, $quantity, null), 'lineTotal' => $expectedTotal, 'tax' => $expectedTax,
                        'baseQuantity' => bcmul($quantity, (string) $line->conversion_factor, 8)];

                    continue;
                }
                if (! $returnStoreId) {
                    throw new DomainException(__('Choose the warehouse that will receive the returned goods.'));
                }

                $remaining = $quantity;
                $firstPreparedIndex = count($prepared);
                $deliverySourceType = $line->sales_order_line_id ? SalesOrderLine::class : CustomerInvoiceLine::class;
                $deliverySourceId = $line->sales_order_line_id ?: $line->getKey();
                $deliveryIds = array_map('intval', $input['delivery_line_ids'] ?? (isset($input['delivery_line_id']) ? [$input['delivery_line_id']] : []));
                if (count($deliveryIds) !== count(array_unique($deliveryIds)) || ($line->product?->tracks_serials
                    && ($deliveryIds === [] || bccomp((string) count($deliveryIds), bcmul($quantity, (string) $line->conversion_factor, 8), 8) !== 0))) {
                    throw new DomainException(__('inventory_serial.source_selection'));
                }
                $deliveryLines = InventoryDocumentLine::query()->with('document')
                    ->whereIn('inventory_document_id', $source->deliveries->modelKeys())
                    ->where('source_line_type', $deliverySourceType)
                    ->where('source_line_id', $deliverySourceId)
                    ->when($deliveryIds !== [], fn ($query) => $query->whereIn('id', $deliveryIds))
                    ->whereHas('document', fn ($query) => $query->where('status', InventoryDocument::StatusPosted))
                    ->oldest('id')
                    ->lockForUpdate()
                    ->get();
                if ($deliveryIds !== [] && $deliveryLines->count() !== count($deliveryIds)) {
                    throw new DomainException(__('inventory_serial.source_selection'));
                }
                if ($line->product?->tracks_serials && $deliveryLines->contains(fn (InventoryDocumentLine $delivery): bool => $delivery->inventory_serial_identity_id === null
                    || bccomp((string) $delivery->quantity, '1', 8) !== 0)) {
                    throw new DomainException(__('inventory_serial.source_selection'));
                }
                foreach ($deliveryLines as $deliveryLine) {
                    if (bccomp($remaining, '0', 8) <= 0) {
                        break;
                    }
                    $returnedFromDelivery = (string) SalesReturnLine::query()
                        ->where('delivery_line_id', $deliveryLine->getKey())
                        ->whereHas('salesReturn', fn ($query) => $query->where('status', '<>', SalesReturn::StatusCancelled))
                        ->sum('quantity');
                    $provisional = $preparedByDelivery[$deliveryLine->id] ?? ['quantity' => '0', 'base_quantity' => '0'];
                    $available = bcsub(bcsub((string) $deliveryLine->transaction_quantity, $returnedFromDelivery, 8), $provisional['quantity'], 8);
                    if (bccomp($available, '0', 8) <= 0) {
                        continue;
                    }
                    $allocated = bccomp($remaining, $available, 8) > 0 ? $available : $remaining;
                    $base = $this->returnBaseQuantity($deliveryLine, $allocated, $provisional);
                    $prepared[] = [...$this->prepareInvoiceReturnLine($line, $allocated, $deliveryLine), 'baseQuantity' => $base];
                    $preparedByDelivery[$deliveryLine->id] = ['quantity' => bcadd($provisional['quantity'], $allocated, 8), 'base_quantity' => bcadd($provisional['base_quantity'], $base, 8)];
                    $remaining = bcsub($remaining, $allocated, 8);
                }
                if (bccomp($remaining, '0', 8) > 0) {
                    throw new DomainException(__('Return quantity exceeds the invoiced quantity that was delivered to the customer.'));
                }
                $preparedIndices = range($firstPreparedIndex, count($prepared) - 1);
                $weights = array_map(fn (int $index): string => $prepared[$index]['quantity'], $preparedIndices);
                $totals = $this->amounts->splitQuantityByWeights($expectedTotal, $weights, 4);
                $taxes = $this->amounts->splitQuantityByWeights($expectedTax, $weights, 4);
                foreach ($preparedIndices as $offset => $index) {
                    $prepared[$index]['lineTotal'] = $totals[$offset];
                    $prepared[$index]['tax'] = $taxes[$offset];
                }
            }
            if ($prepared === []) {
                throw new DomainException(__('A sales return requires at least one original invoice line.'));
            }

            $numbers = $this->documents->nextForCompany('sales_returns', SalesReturn::class, (int) $source->company_id);
            $firstDeliveryLine = collect($prepared)->pluck('deliveryLine')->filter()->first();
            $return = SalesReturn::query()->create([
                ...$numbers, 'company_id' => $source->company_id, 'financial_period_id' => $period->getKey(),
                'branch_id' => $source->branch_id, 'branch_store_id' => $returnStoreId,
                'customer_id' => $source->customer_id, 'sales_order_id' => $source->sales_order_id,
                'customer_invoice_id' => $source->getKey(), 'delivery_document_id' => $firstDeliveryLine?->inventory_document_id,
                'return_date' => $returnDate, 'reason_code' => $reasonCode,
                'reason_details' => $reasonDetails, 'status' => SalesReturn::StatusPendingAuthorization,
                'subtotal_amount' => $this->amounts->sum(array_map(fn (array $row): string => $this->amounts->subtract($row['lineTotal'], $row['tax']), $prepared)),
                'tax_amount' => $this->amounts->sum(array_column($prepared, 'tax')),
                'total_amount' => $this->amounts->sum(array_column($prepared, 'lineTotal')),
                'created_by' => auth()->id(),
            ]);
            foreach ($prepared as $index => $row) {
                $line = $row['line'];
                $return->lines()->create([
                    'line_number' => $index + 1, 'customer_invoice_line_id' => $line->getKey(),
                    'delivery_line_id' => $row['deliveryLine']?->getKey(), 'sales_order_line_id' => $line->sales_order_line_id,
                    'product_id' => $line->product_id, 'unit_id' => $line->unit_id,
                    'conversion_factor' => $line->conversion_factor, 'quantity' => $row['quantity'],
                    'base_quantity' => $row['baseQuantity'],
                    'unit_price' => $line->unit_price, 'tax_amount' => $row['tax'], 'line_total' => $row['lineTotal'],
                    'is_service' => $line->is_service, 'original_unit_cost' => $line->unit_cost,
                    'source_snapshot' => ['invoice' => $source->doc_num, 'invoice_line_public_id' => $line->public_id, 'delivery' => $row['deliveryLine']?->document?->doc_num],
                ]);
            }
            $this->recordStatus($return, null, SalesReturn::StatusPendingAuthorization);
            $this->audit->record($return, 'sales_return.created', ['reason_code' => $reasonCode]);

            return $return->load(['lines.invoiceLine', 'invoice']);
        });
    }

    /** @return array{line: CustomerInvoiceLine, quantity: string, tax: string, lineTotal: string, deliveryLine: InventoryDocumentLine|null} */
    private function prepareInvoiceReturnLine(CustomerInvoiceLine $line, string $quantity, ?InventoryDocumentLine $deliveryLine): array
    {
        $ratio = bcdiv($quantity, (string) $line->quantity, 12);

        return [
            'line' => $line,
            'quantity' => $quantity,
            'tax' => $this->amounts->round(bcmul((string) $line->tax_amount, $ratio, 8)),
            'lineTotal' => $this->amounts->round(bcmul((string) $line->line_total, $ratio, 8)),
            'deliveryLine' => $deliveryLine,
        ];
    }

    /** @return array{string, string} */
    private function invoiceReturnAmounts(CustomerInvoiceLine $line, string $quantity, string $alreadyReturned): array
    {
        $prior = SalesReturnLine::query()->where('customer_invoice_line_id', $line->id)
            ->whereHas('salesReturn', fn ($query) => $query->where('status', '<>', SalesReturn::StatusCancelled))
            ->selectRaw('coalesce(sum(line_total), 0) as total, coalesce(sum(tax_amount), 0) as tax')->first();
        $allRemaining = bccomp($quantity, bcsub((string) $line->quantity, $alreadyReturned, 8), 8) === 0;

        return [
            $allRemaining ? bcsub((string) $line->line_total, (string) $prior->total, 4) : $this->amounts->round(bcdiv(bcmul((string) $line->line_total, $quantity, 24), (string) $line->quantity, 12)),
            $allRemaining ? bcsub((string) $line->tax_amount, (string) $prior->tax, 4) : $this->amounts->round(bcdiv(bcmul((string) $line->tax_amount, $quantity, 24), (string) $line->quantity, 12)),
        ];
    }

    /** @param array{quantity: string, base_quantity: string} $provisional */
    private function returnBaseQuantity(InventoryDocumentLine $deliveryLine, string $quantity, array $provisional = ['quantity' => '0', 'base_quantity' => '0']): string
    {
        $prior = SalesReturnLine::query()->where('delivery_line_id', $deliveryLine->id)
            ->whereHas('salesReturn', fn ($query) => $query->where('status', '<>', SalesReturn::StatusCancelled))
            ->selectRaw('coalesce(sum(quantity), 0) as quantity, coalesce(sum(base_quantity), 0) as base_quantity')->first();
        $remaining = bcsub(bcsub((string) $deliveryLine->transaction_quantity, (string) $prior->quantity, 8), $provisional['quantity'], 8);
        $remainingBase = bcsub(bcsub((string) $deliveryLine->quantity, (string) $prior->base_quantity, 8), $provisional['base_quantity'], 8);
        $base = bccomp($quantity, $remaining, 8) === 0
            ? $remainingBase
            : bcdiv(bcmul((string) $deliveryLine->quantity, $quantity, 24), (string) $deliveryLine->transaction_quantity, 8);
        if (bccomp($base, '0', 8) <= 0 || bccomp($base, $remainingBase, 8) > 0) {
            throw new DomainException(__('sales_issue.messages.quantity_allocation_mismatch'));
        }

        return $base;
    }

    /** @param list<array{delivery_line_id: int, quantity: string|int|float}> $lines */
    public function createFromDelivery(InventoryDocument $delivery, string $reasonCode, ?string $reasonDetails, array $lines, ?int $correctionId = null): SalesReturn
    {
        return DB::transaction(function () use ($delivery, $reasonCode, $reasonDetails, $lines, $correctionId): SalesReturn {
            Company::query()->whereKey($delivery->company_id)->lockForUpdate()->firstOrFail();
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($delivery->source_document_id);
            $source = InventoryDocument::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($source->document_type !== InventoryDocument::TypeSalesDelivery || $source->status !== InventoryDocument::StatusPosted || $source->source_document_type !== SalesOrder::class) {
                throw new DomainException(__('Returns require a posted sales delivery.'));
            }
            if (! in_array($reasonCode, $this->reasonCodes(), true)) {
                throw new DomainException(__('Select a controlled sales return reason.'));
            }
            if ($lines === [] || count(array_unique(array_column($lines, 'delivery_line_id'))) !== count($lines)) {
                throw new DomainException(__('Select each delivery line once.'));
            }
            $proposal = $correctionId === null ? null : app(SalesReturnCorrectionService::class)->execution($correctionId, 'replacement',
                (int) SalesReturnCorrection::query()->findOrFail($correctionId)->sales_return_id);
            if ($proposal !== null && (int) data_get($proposal->source_snapshot, 'return.delivery_document_id') !== (int) $source->id) {
                throw new DomainException(__('sales_return_plan.source_invalid'));
            }
            $returnDate = $proposal?->posting_date->toDateString() ?? now()->toDateString();
            $period = app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $source->company_id, $returnDate,
                expectedPeriodId: $proposal?->posting_financial_period_id, lockForUpdate: true);
            $prepared = [];
            foreach ($lines as $input) {
                $line = $source->lines()->lockForUpdate()->findOrFail($input['delivery_line_id']);
                $quantity = (string) $input['quantity'];
                $this->amounts->assertPositive($quantity, __('Return quantity must be positive.'));
                $invoiced = CustomerInvoiceLine::query()->where('delivery_line_id', $line->id)->whereHas('invoice', fn ($query) => $query->where('document_type', CustomerInvoice::TypeInvoice))->sum('quantity');
                $returning = SalesReturnLine::query()->where('delivery_line_id', $line->id)->whereNull('customer_invoice_line_id')->whereHas('salesReturn', fn ($query) => $query->where('status', '<>', SalesReturn::StatusCancelled))->sum('quantity');
                $available = bcsub(bcsub((string) $line->transaction_quantity, (string) $invoiced, 8), (string) $returning, 8);
                $this->amounts->assertNotGreaterThan($quantity, $available, __('Return quantity exceeds the unbilled delivery quantity.'));
                $prepared[] = [
                    'delivery_line_id' => $line->id, 'sales_order_line_id' => $line->source_line_id,
                    'product_id' => $line->product_id, 'unit_id' => $line->transaction_unit_id ?? $line->unit_id,
                    'conversion_factor' => $line->conversion_factor, 'quantity' => $quantity,
                    'base_quantity' => $this->returnBaseQuantity($line, $quantity),
                    'original_unit_cost' => $line->unit_cost, 'is_service' => false,
                    'source_snapshot' => ['delivery' => $source->doc_num, 'delivery_line_public_id' => $line->public_id],
                ];
            }
            $numbers = $this->documents->nextForCompany('sales_returns', SalesReturn::class, (int) $source->company_id);
            $return = SalesReturn::query()->create([
                ...$numbers, 'company_id' => $source->company_id, 'financial_period_id' => $period->id,
                'branch_id' => $source->branch_id, 'branch_store_id' => $source->branch_store_id,
                'customer_id' => $order->customer_id, 'sales_order_id' => $order->id, 'delivery_document_id' => $source->id,
                'return_date' => $returnDate, 'reason_code' => $reasonCode, 'reason_details' => $reasonDetails,
                'status' => SalesReturn::StatusPendingAuthorization, 'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'created_by' => auth()->id(),
            ]);
            foreach ($prepared as $index => $values) {
                $return->lines()->create([...$values, 'line_number' => $index + 1]);
            }
            $this->recordStatus($return, null, SalesReturn::StatusPendingAuthorization);
            $this->audit->record($return, 'sales_return.created_from_delivery', ['delivery' => $source->doc_num]);

            return $return->load('lines', 'delivery');
        });
    }

    public function authorize(SalesReturn $return): SalesReturn
    {
        return DB::transaction(function () use ($return): SalesReturn {
            $locked = SalesReturn::query()->lockForUpdate()->findOrFail($return->getKey());
            if ($locked->status !== SalesReturn::StatusPendingAuthorization) {
                throw new DomainException(__('Only a pending return can be authorized.'));
            }
            $locked->update(['status' => SalesReturn::StatusAuthorized, 'authorized_by' => auth()->id(), 'authorized_at' => now(), 'updated_by' => auth()->id()]);
            $this->recordStatus($locked, SalesReturn::StatusPendingAuthorization, SalesReturn::StatusAuthorized);
            $this->audit->record($locked, 'sales_return.authorized');

            return $locked->refresh();
        });
    }

    public function receive(SalesReturn $return): SalesReturn
    {
        return DB::transaction(function () use ($return): SalesReturn {
            Company::query()->whereKey($return->company_id)->lockForUpdate()->firstOrFail();
            $locked = SalesReturn::query()->with('lines')->lockForUpdate()->findOrFail($return->getKey());
            if ($locked->status !== SalesReturn::StatusAuthorized) {
                throw new DomainException(__('Only an authorized return can be received.'));
            }
            [$order, $sourceLines] = $locked->customer_invoice_id === null
                ? $this->lockUnbilledOrderLines($locked)
                : [null, collect()];
            $postingReturn = $this->postingCopy($locked);
            $physical = $locked->lines->where('is_service', false);
            if ($physical->isNotEmpty()) {
                if (! $locked->branch_store_id) {
                    throw new DomainException(__('A return store is required for a physical return.'));
                }
                $numbers = $this->documents->nextForCompany('inventory_documents', InventoryDocument::class, (int) $locked->company_id);
                $document = InventoryDocument::query()->create([
                    ...$numbers, 'company_id' => $locked->company_id, 'financial_period_id' => $postingReturn->financial_period_id,
                    'branch_id' => $locked->branch_id, 'branch_store_id' => $locked->branch_store_id,
                    'document_type' => InventoryDocument::TypeSalesReturnReceipt, 'document_date' => now()->toDateString(),
                    'destination_stock_status' => InventoryTransaction::StatusQuarantine,
                    'movement_reason' => 'sales_return_received_quarantine',
                    'purpose' => 'Sales return pending quality disposition', 'source_document_type' => SalesReturn::class,
                    'source_document_id' => $locked->getKey(), 'source_doc_num' => $locked->doc_num,
                    'customer_id' => $locked->customer_id, 'status' => InventoryDocument::StatusDraft, 'created_by' => auth()->id(),
                ]);
                foreach ($physical->values() as $index => $line) {
                    $product = Product::query()->lockForUpdate()->findOrFail($line->product_id);
                    $sourceIssue = InventoryTransaction::query()
                        ->where('posting_key', "inventory-document:{$line->deliveryLine->inventory_document_id}:line:{$line->delivery_line_id}:out")
                        ->where('source_type', InventoryDocument::class)
                        ->where('quantity_out', '>', 0)
                        ->where('is_reversal', false)
                        ->latest('id')
                        ->first();
                    $document->lines()->create([
                        'company_id' => $locked->company_id, 'financial_period_id' => $postingReturn->financial_period_id,
                        'line_number' => $index + 1, 'product_id' => $line->product_id, 'unit_id' => $product->item_unit_id,
                        'transaction_unit_id' => $line->unit_id, 'conversion_factor' => $line->conversion_factor,
                        'transaction_quantity' => $line->quantity, 'base_quantity' => $line->base_quantity,
                        'source_line_type' => SalesReturnLine::class, 'source_line_id' => $line->getKey(),
                        'source_line_public_id' => $line->public_id, 'reference_quantity' => $line->quantity,
                        'quantity' => $line->base_quantity, 'rejected_quantity' => 0, 'unit_cost' => $line->original_unit_cost,
                        'batch_lot' => $sourceIssue?->batch_lot,
                        'manufacture_date' => $sourceIssue?->manufacture_date,
                        'expiry_date' => $sourceIssue?->expiry_date,
                        'product_snapshot' => [
                            'quality_status' => 'pending',
                            'source_issue_transaction_id' => $sourceIssue?->getKey(),
                        ],
                        'created_by' => auth()->id(),
                    ]);
                }
                $document = $this->inventoryPosting->post($document);
                foreach ($physical as $line) {
                    $receiptLine = $document->lines->sole(fn ($receiptLine): bool => $receiptLine->source_line_type === SalesReturnLine::class
                        && (int) $receiptLine->source_line_id === (int) $line->id);
                    $line->forceFill(['original_unit_cost' => $receiptLine->unit_cost,
                        'source_snapshot' => [...($line->source_snapshot ?? []),
                            'receipt_total_cost' => $receiptLine->total_cost,
                            'receipt_line_public_id' => $receiptLine->public_id,
                            'source_issue_transaction_id' => $receiptLine->product_snapshot['source_issue_transaction_id'] ?? null],
                    ])->save();
                }
                $quarantineJournal = $this->accounting->postReturnedGoodsToQuarantine($postingReturn);
                $locked->update([
                    'return_inventory_document_id' => $document->getKey(),
                    'quarantine_journal_entry_id' => $quarantineJournal?->getKey(),
                ]);
            }
            if ($order !== null) {
                foreach ($locked->lines as $line) {
                    $sourceLine = $sourceLines->get($line->sales_order_line_id);
                    $sourceLine->decrement('delivered_quantity', $line->quantity);
                    $sourceLine->decrement('delivered_base_quantity', $line->base_quantity);
                    $sourceLine->increment('returned_quantity', $line->quantity);
                    $sourceLine->increment('returned_base_quantity', $line->base_quantity);
                }
                $this->fulfillment->refreshOrderStatus($order);
            }
            $locked->update(['status' => SalesReturn::StatusReceived, 'received_by' => auth()->id(), 'received_at' => now(), 'updated_by' => auth()->id()]);
            $this->recordStatus($locked, SalesReturn::StatusAuthorized, SalesReturn::StatusReceived);

            return $locked->refresh()->load('returnInventoryDocument.lines');
        });
    }

    /** @param list<array{sales_return_line_id: int, saleable_quantity?: string|int|float, quarantine_quantity?: string|int|float, rework_quantity?: string|int|float, scrap_quantity?: string|int|float, notes?: string|null}> $results */
    public function inspect(SalesReturn $return, array $results): SalesReturn
    {
        return DB::transaction(function () use ($return, $results): SalesReturn {
            Company::query()->whereKey($return->company_id)->lockForUpdate()->firstOrFail();
            $locked = SalesReturn::query()->with(['lines', 'returnInventoryDocument.lines'])->lockForUpdate()->findOrFail($return->getKey());
            if ($locked->status !== SalesReturn::StatusReceived) {
                throw new DomainException(__('Only a received return can be inspected.'));
            }
            foreach ($results as $result) {
                $line = SalesReturnLine::query()->lockForUpdate()->where('sales_return_id', $locked->getKey())->findOrFail($result['sales_return_line_id']);
                if ($line->is_service) {
                    continue;
                }
                $saleable = (string) ($result['saleable_quantity'] ?? 0);
                $quarantine = (string) ($result['quarantine_quantity'] ?? 0);
                $rework = (string) ($result['rework_quantity'] ?? 0);
                $scrap = (string) ($result['scrap_quantity'] ?? 0);
                foreach ([$saleable, $quarantine, $rework, $scrap] as $quantity) {
                    if (bccomp($quantity, '0', 8) < 0) {
                        throw new DomainException(__('Disposition quantities cannot be negative.'));
                    }
                }
                $sum = $this->amounts->sum([$saleable, $quarantine, $rework, $scrap], 8);
                if ($this->amounts->compare($sum, $line->quantity, 8) !== 0) {
                    throw new DomainException(__('Quality disposition quantities must equal the received return quantity.'));
                }
                [$saleableBase, $quarantineBase, $reworkBase, $scrapBase] = $this->amounts->splitQuantityByWeights((string) $line->base_quantity, [$saleable, $quarantine, $rework, $scrap]);
                $disposition = collect([SalesReturnLine::DispositionSaleable => $saleable, SalesReturnLine::DispositionQuarantine => $quarantine, SalesReturnLine::DispositionRework => $rework, SalesReturnLine::DispositionScrap => $scrap])->filter(fn ($quantity) => $this->amounts->compare($quantity, '0', 8) > 0)->keys()->implode(',');
                $line->update([
                    'saleable_quantity' => $saleable, 'saleable_base_quantity' => $saleableBase,
                    'quarantine_quantity' => $quarantine, 'quarantine_base_quantity' => $quarantineBase,
                    'rework_quantity' => $rework, 'rework_base_quantity' => $reworkBase,
                    'scrap_quantity' => $scrap, 'scrap_base_quantity' => $scrapBase,
                    'quality_disposition' => $disposition, 'inspection_notes' => $result['notes'] ?? null,
                ]);
            }
            $locked->load('lines');
            if ($locked->lines->where('is_service', false)->contains(fn (SalesReturnLine $line): bool => $line->quality_disposition === null)) {
                throw new DomainException(__('Every physical return line requires a quality disposition.'));
            }
            $postingReturn = $this->postingCopy($locked);
            $this->postDispositionInventory($postingReturn);
            $dispositionJournal = $this->accounting->postReturnDisposition($postingReturn);
            $locked->update([
                'status' => SalesReturn::StatusInspected,
                'disposition_journal_entry_id' => $dispositionJournal?->getKey(),
                'inspected_by' => auth()->id(),
                'inspected_at' => now(),
                'updated_by' => auth()->id(),
            ]);
            $this->recordStatus($locked, SalesReturn::StatusReceived, SalesReturn::StatusInspected);
            $this->audit->record($locked, 'sales_return.inspected');

            return $locked->refresh()->load('lines');
        });
    }

    public function close(SalesReturn $return): SalesReturn
    {
        return DB::transaction(function () use ($return): SalesReturn {
            $locked = SalesReturn::query()->with(['lines.invoiceLine', 'invoice.customer'])->lockForUpdate()->findOrFail($return->getKey());
            $fromStatus = $locked->status;
            $hasPhysical = $locked->lines->contains(fn (SalesReturnLine $line): bool => ! $line->is_service);
            if (($hasPhysical && $locked->status !== SalesReturn::StatusInspected) || (! $hasPhysical && ! in_array($locked->status, [SalesReturn::StatusAuthorized, SalesReturn::StatusReceived, SalesReturn::StatusInspected], true))) {
                throw new DomainException(__('The return has not completed its required authorization and quality stages.'));
            }
            if ($locked->credit_note_id) {
                return $locked;
            }
            $postingReturn = $this->postingCopy($locked);
            $invoice = $locked->customer_invoice_id === null ? null : CustomerInvoice::query()
                ->lockForUpdate()->findOrFail($locked->customer_invoice_id);
            if ($invoice) {
                app(CustomerInvoiceBalanceService::class)->assertNoActiveWithholding($invoice);
            }
            if (! $invoice) {
                $locked->update(['status' => SalesReturn::StatusClosed, 'closed_by' => auth()->id(), 'closed_at' => now(), 'updated_by' => auth()->id()]);
                $this->recordStatus($locked, $fromStatus, SalesReturn::StatusClosed);
                $this->audit->record($locked, 'sales_return.closed_without_credit', ['delivery' => $locked->delivery?->doc_num]);

                return $locked->refresh()->load('lines');
            }
            $numbers = $this->documents->nextForCompany('customer_credit_notes', CustomerInvoice::class, (int) $locked->company_id);
            $credit = CustomerInvoice::query()->create([
                ...$numbers, 'company_id' => $locked->company_id, 'financial_period_id' => $postingReturn->financial_period_id,
                'branch_id' => $locked->branch_id, 'customer_id' => $locked->customer_id,
                'sales_order_id' => $locked->sales_order_id, 'delivery_document_id' => $locked->delivery_document_id,
                'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
                'currency_id' => $invoice->currency_id, 'exchange_rate' => $invoice->exchange_rate,
                'subtotal_amount' => $locked->subtotal_amount, 'taxable_amount' => $locked->subtotal_amount,
                'tax_amount' => $locked->tax_amount, 'total_amount' => $locked->total_amount,
                'remaining_amount' => 0, 'document_type' => CustomerInvoice::TypeCreditNote,
                'original_invoice_id' => $invoice->getKey(), 'sales_return_id' => $locked->getKey(),
                'status' => CustomerInvoice::StatusDraft, 'posting_status' => 'unposted',
                'source_type' => SalesReturn::class, 'source_id' => $locked->getKey(), 'source_doc_num' => $locked->doc_num,
                'created_by' => auth()->id(),
            ]);
            foreach ($locked->lines as $index => $line) {
                $source = $line->invoiceLine;
                $credit->lines()->create([
                    'sales_order_line_id' => $line->sales_order_line_id, 'delivery_line_id' => $line->delivery_line_id,
                    'product_id' => $line->product_id, 'unit_id' => $line->unit_id, 'line_number' => $index + 1,
                    'conversion_factor' => $line->conversion_factor, 'base_quantity' => $line->base_quantity,
                    'description' => $source->description, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price,
                    'tax_amount' => $line->tax_amount, 'line_total' => $line->line_total, 'is_service' => $line->is_service,
                    'unit_cost' => $line->original_unit_cost, 'source_snapshot' => [
                        ...($source->source_snapshot ?? []), 'original_invoice_line_public_id' => $source->public_id,
                        'sales_return' => $locked->doc_num,
                    ],
                ]);
                if ($line->sales_order_line_id) {
                    SalesOrderLine::query()->whereKey($line->sales_order_line_id)->increment('returned_quantity', $line->quantity);
                    SalesOrderLine::query()->whereKey($line->sales_order_line_id)->increment('returned_base_quantity', $line->base_quantity);
                }
            }
            $journal = $this->accounting->postCreditNote($credit);
            $credit->update(['status' => CustomerInvoice::StatusPosted, 'posting_status' => 'posted', 'is_closed' => true, 'journal_entry_id' => $journal->getKey(), 'issued_by' => auth()->id(), 'issued_at' => now()]);
            $outstandingBeforeCredit = app(CustomerInvoiceBalanceService::class)->remaining($invoice);
            $appliedToOriginal = $this->amounts->compare($locked->total_amount, $outstandingBeforeCredit) > 0
                ? $outstandingBeforeCredit
                : (string) $locked->total_amount;
            if ($this->amounts->compare($appliedToOriginal, '0') < 0) {
                $appliedToOriginal = '0.0000';
            }
            $invoice->increment('credited_amount', $appliedToOriginal);
            $remaining = $this->amounts->subtract($outstandingBeforeCredit, $appliedToOriginal);
            $invoice->update(['remaining_amount' => $this->amounts->compare($remaining, '0') < 0 ? '0.0000' : $remaining]);
            $scheduleCredits = $this->applyCreditToSchedules($invoice, $appliedToOriginal);
            $credit->update([
                'credit_available_amount' => $this->amounts->subtract($locked->total_amount, $appliedToOriginal),
                'credit_application_snapshot' => [
                    'original_invoice_id' => $invoice->getKey(),
                    'applied_to_original' => $appliedToOriginal,
                    'schedules' => $scheduleCredits,
                ],
            ]);
            $locked->update(['credit_note_id' => $credit->getKey(), 'status' => SalesReturn::StatusClosed, 'closed_by' => auth()->id(), 'closed_at' => now(), 'updated_by' => auth()->id()]);
            $this->recordStatus($locked, $fromStatus, SalesReturn::StatusClosed);
            $this->audit->record($locked, 'sales_return.closed', ['credit_note' => $credit->doc_num]);

            return $locked->refresh()->load(['creditNote', 'lines']);
        });
    }

    public function cancel(SalesReturn $return, string $reason): SalesReturn
    {
        return DB::transaction(function () use ($return, $reason): SalesReturn {
            $locked = SalesReturn::query()->lockForUpdate()->findOrFail($return->id);
            if (trim($reason) === '' || ! in_array($locked->status, [SalesReturn::StatusPendingAuthorization, SalesReturn::StatusAuthorized], true)) {
                throw new DomainException(__('Only an unreceived return can be cancelled, with a reason.'));
            }
            $from = $locked->status;
            $locked->update(['status' => SalesReturn::StatusCancelled, 'cancelled_by' => auth()->id(), 'cancelled_at' => now(), 'cancel_reason' => trim($reason), 'updated_by' => auth()->id()]);
            $this->recordStatus($locked, $from, SalesReturn::StatusCancelled);
            $this->audit->record($locked, 'sales_return.cancelled', ['reason' => trim($reason)]);

            return $locked->refresh();
        });
    }

    public function correctReceived(SalesReturn $return, string $reason): SalesReturn
    {
        return $this->correctReceivedOrInspected($return, $reason, SalesReturn::StatusReceived);
    }

    public function correctInspected(SalesReturn $return, string $reason): SalesReturn
    {
        return $this->correctReceivedOrInspected($return, $reason, SalesReturn::StatusInspected);
    }

    public function correctClosed(SalesReturn $return, string $reason): SalesReturn
    {
        return $this->correctReceivedOrInspected($return, $reason, SalesReturn::StatusClosed);
    }

    public function correctForApprovedProposal(SalesReturn $return, int $correctionId): SalesReturn
    {
        $proposal = app(SalesReturnCorrectionService::class)->execution($correctionId, 'return', (int) $return->id);

        return $this->correctReceivedOrInspected($return, $proposal->reason, data_get($proposal->source_snapshot, 'return.status'), $correctionId);
    }

    private function correctReceivedOrInspected(SalesReturn $return, string $reason, string $fromStatus, ?int $correctionId = null): SalesReturn
    {
        return DB::transaction(function () use ($return, $reason, $fromStatus, $correctionId): SalesReturn {
            Company::query()->whereKey($return->company_id)->lockForUpdate()->firstOrFail();
            $proposal = $correctionId === null ? null : app(SalesReturnCorrectionService::class)->execution($correctionId, 'return', (int) $return->id);
            $correctionPeriodId = $proposal?->posting_financial_period_id ?? ($fromStatus === SalesReturn::StatusClosed
                ? app(FinancialPeriodService::class)->resolveOpenForPostingDate(
                    (int) $return->company_id, now()->toDateString(), lockForUpdate: true,
                )->getKey()
                : null);
            $locked = SalesReturn::query()
                ->with(['lines', 'returnInventoryDocument.lines', 'quarantineJournalEntry', 'dispositionJournalEntry'])
                ->lockForUpdate()
                ->findOrFail($return->getKey());
            $reason = trim($reason);
            $inspected = $fromStatus !== SalesReturn::StatusReceived;
            $closed = $fromStatus === SalesReturn::StatusClosed;
            if ($reason === '' || $locked->status !== $fromStatus
                || ($closed ? $locked->closed_at === null : ($locked->credit_note_id !== null || $locked->closed_at !== null))
                || ($locked->lines->contains(fn (SalesReturnLine $line): bool => ! $line->is_service)
                    && ($inspected ? $locked->inspected_at === null : ($locked->inspected_at !== null || $locked->disposition_journal_entry_id !== null)))) {
                throw new DomainException(__($closed ? 'sales_return_correction.closed_not_correctable' : ($inspected ? 'sales_return_correction.inspected_not_correctable' : 'sales_return_correction.not_correctable')));
            }
            [$order, $sourceLines] = $locked->customer_invoice_id === null
                ? $this->lockUnbilledOrderLines($locked)
                : [null, collect()];
            $closedSourceLines = $closed && $locked->customer_invoice_id !== null
                ? $this->lockCreditedOrderLines($locked)
                : collect();

            if ($closed) {
                if ($locked->customer_invoice_id !== null) {
                    $credit = $this->reverseUnusedCreditNote($locked, $reason, (int) $correctionPeriodId, $correctionId);
                    $locked->setRelation('creditNote', $credit);
                } elseif ($locked->credit_note_id !== null) {
                    throw new DomainException(__('sales_return_correction.closed_credit_mismatch'));
                }
            }

            $physicalLines = $locked->lines->where('is_service', false);
            $receipt = $locked->returnInventoryDocument;
            $journal = $locked->quarantineJournalEntry;
            if ($physicalLines->isNotEmpty()) {
                $expectedJournalCost = $this->amounts->sum($physicalLines->map(
                    fn (SalesReturnLine $line): string => isset($line->source_snapshot['receipt_total_cost'])
                        ? $this->amounts->round($line->source_snapshot['receipt_total_cost'], 4)
                        : $this->amounts->multiply($line->base_quantity, $line->original_unit_cost, 4),
                ));
                if (! $receipt instanceof InventoryDocument
                    || $receipt->status !== InventoryDocument::StatusPosted
                    || ($journal === null && $this->amounts->compare($expectedJournalCost, '0') > 0)) {
                    throw new DomainException(__('sales_return_correction.incomplete_receipt'));
                }
                if ($order !== null) {
                    $projectedDelivered = [];
                    $projectedDeliveredBase = [];
                    foreach ($physicalLines as $line) {
                        $sourceLine = $sourceLines->get($line->sales_order_line_id);
                        $lineId = (int) $sourceLine->getKey();
                        $projectedDelivered[$lineId] = $this->amounts->add($projectedDelivered[$lineId] ?? $sourceLine->delivered_quantity, $line->quantity, 8);
                        $projectedDeliveredBase[$lineId] = $this->amounts->add($projectedDeliveredBase[$lineId] ?? $sourceLine->delivered_base_quantity, $line->base_quantity, 8);
                        if ($this->amounts->compare($projectedDelivered[$lineId], $sourceLine->quantity, 8) > 0
                            || $this->amounts->compare($projectedDeliveredBase[$lineId], $sourceLine->base_quantity, 8) > 0) {
                            throw new DomainException(__('sales_return_correction.replacement_already_delivered'));
                        }
                    }
                }
                if ($inspected) {
                    $this->reverseInspectedDisposition($locked, $reason, $correctionId);
                }
                $this->inventoryPosting->reverseSalesReturnReceipt($receipt, $locked, $reason, $correctionId);
                if ($journal !== null) {
                    $this->accounting->reverseReturnedGoodsFromQuarantine($locked, $journal, $correctionId);
                }
            } elseif ($receipt !== null || $journal !== null) {
                throw new DomainException(__('sales_return_correction.incomplete_receipt'));
            } elseif ($inspected) {
                $this->reverseInspectedDisposition($locked, $reason, $correctionId);
            }

            if ($order !== null) {
                foreach ($physicalLines as $line) {
                    $sourceLine = $sourceLines->get($line->sales_order_line_id);
                    if ((int) $sourceLine->sales_order_id !== (int) $order->getKey()
                        || $this->amounts->compare($sourceLine->returned_quantity, $line->quantity, 8) < 0
                        || $this->amounts->compare($sourceLine->returned_base_quantity, $line->base_quantity, 8) < 0) {
                        throw new DomainException(__('sales_return_correction.invalid_order_quantities'));
                    }
                    $sourceLine->increment('delivered_quantity', $line->quantity);
                    $sourceLine->increment('delivered_base_quantity', $line->base_quantity);
                    $sourceLine->decrement('returned_quantity', $line->quantity);
                    $sourceLine->decrement('returned_base_quantity', $line->base_quantity);
                }
                $this->fulfillment->refreshOrderStatus($order);
            }
            if ($closed && $locked->customer_invoice_id !== null) {
                foreach ($locked->lines as $line) {
                    if ($line->sales_order_line_id === null) {
                        continue;
                    }
                    $sourceLine = $closedSourceLines->get($line->sales_order_line_id);
                    if (! $sourceLine instanceof SalesOrderLine
                        || $this->amounts->compare($sourceLine->returned_quantity, $line->quantity, 8) < 0
                        || $this->amounts->compare($sourceLine->returned_base_quantity, $line->base_quantity, 8) < 0) {
                        throw new DomainException(__('sales_return_correction.invalid_order_quantities'));
                    }
                    $sourceLine->decrement('returned_quantity', $line->quantity);
                    $sourceLine->decrement('returned_base_quantity', $line->base_quantity);
                }
            }

            $locked->update([
                'status' => SalesReturn::StatusCancelled,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'updated_by' => auth()->id(),
            ]);
            $this->recordStatus($locked, $fromStatus, SalesReturn::StatusCancelled);
            $this->audit->record($locked, $closed ? 'sales_return.closed_corrected' : ($inspected ? 'sales_return.disposition_corrected' : 'sales_return.receipt_corrected'), [
                'reason' => $reason,
                'receipt' => $receipt?->doc_num,
                'quarantine_journal_entry_id' => $journal?->getKey(),
                'quarantine_reversal_journal_entry_id' => $journal?->fresh()?->reversed_entry_id,
            ]);

            return $locked->refresh()->load(['returnInventoryDocument', 'quarantineJournalEntry']);
        });
    }

    private function reverseInspectedDisposition(SalesReturn $return, string $reason, ?int $correctionId = null): void
    {
        Company::query()->whereKey($return->company_id)->lockForUpdate()->firstOrFail();
        $sourceJournals = JournalEntry::query()
            ->withTrashed()
            ->where('source_type', 'sales_return_financial_disposition')
            ->where('source_id', $return->getKey())
            ->lockForUpdate()
            ->get();
        $releasedValue = $this->amounts->sum($return->lines->where('is_service', false)->flatMap(
            fn (SalesReturnLine $line): array => [
                $this->accounting->returnDispositionCost($line, 'saleable_base_quantity'),
                $this->accounting->returnDispositionCost($line, 'rework_base_quantity'),
                $this->accounting->returnDispositionCost($line, 'scrap_base_quantity'),
            ],
        ));
        $sourceJournal = $sourceJournals->first();
        if ($sourceJournals->count() > 1
            || ($return->disposition_journal_entry_id === null) !== ($sourceJournal === null)
            || ($this->amounts->compare($releasedValue, '0') > 0 && $sourceJournal === null)
            || ($sourceJournal !== null && ($sourceJournal->trashed()
                || (int) $sourceJournal->getKey() !== (int) $return->disposition_journal_entry_id
                || (int) $sourceJournal->company_id !== (int) $return->company_id
                || (int) $sourceJournal->branch_id !== (int) $return->branch_id
                || $sourceJournal->status !== JournalEntry::StatusPosted
                || ! $sourceJournal->is_posted || $sourceJournal->reversed_entry_id !== null))) {
            throw new DomainException(__('sales_return_correction.incomplete_disposition_journal'));
        }
        $profiles = [
            'sales_return_saleable' => ['base' => 'saleable_base_quantity', 'quantity' => 'saleable_quantity', 'status' => InventoryTransaction::StatusAvailable],
            'sales_return_rework' => ['base' => 'rework_base_quantity', 'quantity' => 'rework_quantity', 'status' => InventoryTransaction::StatusRework],
            'sales_return_scrap' => ['base' => 'scrap_base_quantity', 'quantity' => 'scrap_quantity', 'status' => InventoryTransaction::StatusScrap],
        ];
        $expected = [];
        foreach ($profiles as $reasonCode => $profile) {
            foreach ($return->lines->where('is_service', false) as $line) {
                if ($this->amounts->compare($line->{$profile['base']}, '0', 8) > 0) {
                    $expected[$reasonCode][(int) $line->getKey()] = $line;
                }
            }
        }

        $documents = InventoryDocument::query()
            ->withTrashed()
            ->with('lines')
            ->where('source_document_type', SalesReturn::class)
            ->where('source_document_id', $return->getKey())
            ->where('id', '<>', $return->return_inventory_document_id ?? 0)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();
        if ($documents->count() !== count($expected)) {
            throw new DomainException(__('sales_return_correction.incomplete_disposition'));
        }

        $seen = [];
        foreach ($documents as $document) {
            $reasonCode = $document->movement_reason;
            $profile = $profiles[$reasonCode] ?? null;
            $lines = $expected[$reasonCode] ?? null;
            if ($document->trashed() || $document->status !== InventoryDocument::StatusPosted
                || $document->document_type !== InventoryDocument::TypeTransfer
                || (int) $document->company_id !== (int) $return->company_id
                || (int) $document->branch_id !== (int) $return->branch_id
                || (int) $document->branch_store_id !== (int) $return->branch_store_id
                || (int) $document->destination_branch_store_id !== (int) $return->branch_store_id
                || $document->source_stock_status !== InventoryTransaction::StatusQuarantine
                || $document->destination_stock_status !== ($profile['status'] ?? null)
                || $lines === null || isset($seen[$reasonCode]) || $document->lines->pluck('source_line_id')->unique()->count() !== count($lines)) {
                throw new DomainException(__('sales_return_correction.incomplete_disposition'));
            }
            $seen[$reasonCode] = true;
            foreach ($document->lines->groupBy('source_line_id') as $returnLineId => $slices) {
                $returnLine = $lines[(int) $returnLineId] ?? null;
                if (! $returnLine instanceof SalesReturnLine
                    || $slices->contains(fn ($slice): bool => $slice->source_line_type !== SalesReturnLine::class
                        || (int) $slice->product_id !== (int) $returnLine->product_id
                        || $slice->source_line_public_id !== $returnLine->public_id)
                    || $this->amounts->compare($this->amounts->sum($slices->pluck('base_quantity'), 8), $returnLine->{$profile['base']}, 8) !== 0
                    || $this->amounts->compare($this->amounts->sum($slices->pluck('transaction_quantity'), 8), $returnLine->{$profile['quantity']}, 8) !== 0
                    || (data_get($returnLine->source_snapshot, 'disposition_documents.'.$profile['base']) !== null
                        && data_get($returnLine->source_snapshot, 'disposition_documents.'.$profile['base']) !== $document->doc_num)) {
                    throw new DomainException(__('sales_return_correction.incomplete_disposition'));
                }
                $frozenCost = data_get($returnLine->source_snapshot, 'disposition_costs.'.$profile['base']);
                if ($frozenCost !== null && ($slices->contains(fn ($slice): bool => $slice->total_cost === null)
                    || $this->amounts->compare($this->amounts->sum($slices->pluck('total_cost'), 8), $frozenCost, 8) !== 0)) {
                    throw new DomainException(__('sales_return_correction.incomplete_disposition'));
                }
                unset($lines[(int) $returnLine->getKey()]);
            }
            if ($lines !== []) {
                throw new DomainException(__('sales_return_correction.incomplete_disposition'));
            }
        }

        foreach ($documents as $document) {
            $this->inventoryPosting->reverseSalesReturnDisposition($document, $return, $reason, $correctionId);
        }
        if ($sourceJournal !== null) {
            $this->accounting->reverseReturnDisposition($return, $sourceJournal, $correctionId);
            $return->setRelation('dispositionJournalEntry', $sourceJournal->refresh());
        }
    }

    /** @return array{SalesOrder, Collection<int, SalesOrderLine>} */
    private function lockUnbilledOrderLines(SalesReturn $return): array
    {
        $order = SalesOrder::query()->lockForUpdate()->findOrFail($return->sales_order_id);
        $sourceLineIds = $return->lines->pluck('sales_order_line_id')->filter()->unique()->values();
        $lines = SalesOrderLine::query()
            ->where('sales_order_id', $order->getKey())
            ->whereIn('id', $sourceLineIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($sourceLineIds->isEmpty()
            || $return->lines->contains(fn (SalesReturnLine $line): bool => $line->sales_order_line_id === null)
            || (int) $order->company_id !== (int) $return->company_id
            || (int) $order->branch_id !== (int) $return->branch_id
            || $lines->count() !== $sourceLineIds->count()) {
            throw new DomainException(__('sales_return_correction.invalid_order_quantities'));
        }

        return [$order, $lines];
    }

    /** @return Collection<int, SalesOrderLine> */
    private function lockCreditedOrderLines(SalesReturn $return): Collection
    {
        $lineIds = $return->lines->pluck('sales_order_line_id')->filter()->unique()->values();
        if ($lineIds->isEmpty()) {
            return new Collection;
        }

        $order = SalesOrder::query()->lockForUpdate()->findOrFail($return->sales_order_id);
        $lines = SalesOrderLine::query()
            ->where('sales_order_id', $order->getKey())
            ->whereIn('id', $lineIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ((int) $order->company_id !== (int) $return->company_id
            || (int) $order->branch_id !== (int) $return->branch_id
            || $lines->count() !== $lineIds->count()) {
            throw new DomainException(__('sales_return_correction.invalid_order_quantities'));
        }

        return $lines;
    }

    private function reverseUnusedCreditNote(SalesReturn $return, string $reason, int $correctionPeriodId, ?int $correctionId = null): CustomerInvoice
    {
        Company::query()->whereKey($return->company_id)->lockForUpdate()->firstOrFail();
        $credit = $return->credit_note_id === null ? null : CustomerInvoice::query()
            ->with('journalEntry')
            ->lockForUpdate()
            ->find($return->credit_note_id);
        $invoice = CustomerInvoice::query()->lockForUpdate()->findOrFail($return->customer_invoice_id);
        if ($credit instanceof CustomerInvoice) {
            app(CustomerCreditApplicationEvidenceService::class)->assertApprovedApplication($credit);
        }
        $application = $credit?->credit_application_snapshot;
        $rows = is_array($application) ? ($application['schedules'] ?? null) : null;
        $applied = is_array($application) ? ($application['applied_to_original'] ?? null) : null;
        $sourceJournals = $credit instanceof CustomerInvoice
            ? JournalEntry::query()->withTrashed()->where('source_type', 'customer_credit_note')
                ->where('source_id', $credit->getKey())->lockForUpdate()->get()
            : collect();
        $allocations = $credit instanceof CustomerInvoice
            ? $credit->creditAllocations()->lockForUpdate()->get()
            : collect();
        $refunds = $credit instanceof CustomerInvoice
            ? $credit->creditRefunds()->lockForUpdate()->get()
            : collect();
        $creditNotesForReturn = CustomerInvoice::query()->withTrashed()
            ->where('sales_return_id', $return->getKey())->lockForUpdate()->get();
        $journal = $sourceJournals->first();

        if (! $credit instanceof CustomerInvoice
            || $credit->document_type !== CustomerInvoice::TypeCreditNote
            || $credit->status !== CustomerInvoice::StatusPosted
            || $credit->posting_status !== 'posted'
            || (int) $credit->sales_return_id !== (int) $return->getKey()
            || $creditNotesForReturn->count() !== 1
            || (int) $creditNotesForReturn->first()?->getKey() !== (int) $credit->getKey()
            || (int) $credit->original_invoice_id !== (int) $invoice->getKey()
            || (int) $credit->company_id !== (int) $return->company_id
            || ($correctionId === null && (int) $credit->financial_period_id !== $correctionPeriodId)
            || (int) $credit->branch_id !== (int) $return->branch_id
            || (int) $credit->customer_id !== (int) $return->customer_id
            || $credit->reversal_journal_entry_id !== null
            || $allocations->contains(fn (CustomerCreditAllocation $allocation): bool => ! $this->hasValidAllocationReversalEvidence($allocation, $credit))
            || $refunds->contains(fn (CustomerCreditRefund $refund): bool => ! $this->credits->refundReversalEvidenceValid($refund, $credit))
            || $this->amounts->compare($credit->credit_allocated_amount, '0') !== 0
            || $this->amounts->compare($credit->credit_refunded_amount, '0') !== 0
            || filled($credit->electronic_invoice_uuid)
            || ! in_array($credit->electronic_invoice_status, ['not_configured', 'draft', 'rejected'], true)
            || $credit->electronicInvoiceSubmissions()->exists()
            || $invoice->document_type !== CustomerInvoice::TypeInvoice
            || $invoice->posting_status !== 'posted'
            || (int) $invoice->company_id !== (int) $return->company_id
            || (int) $invoice->branch_id !== (int) $return->branch_id
            || (int) $invoice->customer_id !== (int) $return->customer_id
            || ! is_array($rows) || ! is_numeric($applied)
            || (int) ($application['original_invoice_id'] ?? 0) !== (int) $invoice->getKey()
            || $sourceJournals->count() !== 1
            || ! $journal instanceof JournalEntry
            || $journal->trashed() || $journal->status !== JournalEntry::StatusPosted
            || ! $journal->is_posted || $journal->reversed_entry_id !== null
            || (int) $journal->getKey() !== (int) $credit->journal_entry_id
            || (int) $journal->company_id !== (int) $credit->company_id
            || (int) $journal->branch_id !== (int) $credit->branch_id
            || (int) $journal->financial_period_id !== (int) $credit->financial_period_id
            || $journal->entry_date?->toDateString() !== $credit->invoice_date?->toDateString()
            || (int) $journal->currency_id !== (int) $credit->currency_id
            || $this->amounts->compare($journal->exchange_rate, $credit->exchange_rate, 6) !== 0) {
            throw new DomainException(__('sales_return_correction.closed_credit_mismatch'));
        }

        $applied = (string) $applied;
        $scheduleIds = [];
        $scheduleTotal = '0.0000';
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['schedule_id'] ?? null)
                || ! is_numeric($row['amount'] ?? null)
                || $this->amounts->compare((string) $row['amount'], '0') <= 0) {
                throw new DomainException(__('sales_return_correction.closed_credit_mismatch'));
            }
            $scheduleIds[] = (int) $row['schedule_id'];
            $scheduleTotal = $this->amounts->add($scheduleTotal, (string) $row['amount']);
        }
        if (count($scheduleIds) !== count(array_unique($scheduleIds))
            || $this->amounts->compare($applied, '0') < 0
            || $this->amounts->compare($applied, $credit->total_amount) > 0
            || $this->amounts->compare($scheduleTotal, $applied) !== 0
            || $this->amounts->compare($credit->credit_available_amount, $this->amounts->subtract($credit->total_amount, $applied)) !== 0
            || $this->amounts->compare($invoice->credited_amount, $applied) < 0
            || $this->amounts->compare(
                $invoice->remaining_amount,
                app(CustomerInvoiceBalanceService::class)->remaining($invoice),
            ) !== 0) {
            throw new DomainException(__('sales_return_correction.closed_credit_mismatch'));
        }

        $schedules = CustomerInvoicePaymentSchedule::query()
            ->where('customer_invoice_id', $invoice->getKey())
            ->whereIn('id', $scheduleIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($schedules->count() !== count($scheduleIds)) {
            throw new DomainException(__('sales_return_correction.closed_credit_mismatch'));
        }
        foreach ($rows as $row) {
            if ($this->amounts->compare($schedules->get((int) $row['schedule_id'])->credited_amount, (string) $row['amount']) < 0) {
                throw new DomainException(__('sales_return_correction.closed_credit_mismatch'));
            }
        }

        $reversal = $this->accounting->reverseCreditNote($credit, $journal, $reason, $correctionId);
        foreach ($rows as $row) {
            $schedules->get((int) $row['schedule_id'])->decrement('credited_amount', (string) $row['amount']);
        }
        $newCredited = $this->amounts->subtract($invoice->credited_amount, $applied);
        $invoice->forceFill([
            'credited_amount' => $newCredited,
            'remaining_amount' => app(CustomerInvoiceBalanceService::class)->remaining($invoice, credited: $newCredited),
        ])->save();
        $credit->forceFill([
            'status' => CustomerInvoice::StatusCancelled,
            'posting_status' => 'reversed',
            'is_closed' => true,
            'reversal_journal_entry_id' => $reversal->getKey(),
            'credit_available_amount' => '0.0000',
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
            'updated_by' => auth()->id(),
        ])->save();
        $this->audit->record($credit, 'customer_credit_note.corrected', [
            'sales_return' => $return->doc_num,
            'reason' => $reason,
            'reversal_journal_entry_id' => $reversal->getKey(),
        ]);

        return $credit->refresh()->load('journalEntry');
    }

    public function assertCancelledRecovery(SalesReturn $return): void
    {
        if ($return->status !== SalesReturn::StatusCancelled || $return->cancelled_at === null || $return->cancelled_by === null || blank($return->cancel_reason)) {
            throw new DomainException(__('invoice_correction.source_invalid'));
        }
        $documents = InventoryDocument::query()->withTrashed()->where('source_document_type', SalesReturn::class)->where('source_document_id', $return->id)->get();
        foreach ($documents as $document) {
            if ($document->trashed() || $document->status !== InventoryDocument::StatusReversed || (int) $document->company_id !== (int) $return->company_id) {
                throw new DomainException(__('invoice_correction.source_invalid'));
            }
            $originals = $document->transactions()->where('is_reversal', false)->get();
            if ($originals->isEmpty()) {
                throw new DomainException(__('invoice_correction.source_invalid'));
            }
            foreach ($originals as $original) {
                $inverses = InventoryTransaction::query()->where('reversal_of_id', $original->id)->where('is_reversal', true)->get();
                if ($inverses->count() !== 1) {
                    throw new DomainException(__('invoice_correction.source_invalid'));
                }
                $inverse = $inverses->sole();
                foreach (['company_id', 'branch_id', 'branch_store_id', 'product_id', 'unit_id', 'source_type', 'source_id', 'source_line_id', 'stock_status', 'batch_lot', 'warehouse_location_id'] as $field) {
                    if ($inverse->{$field} !== $original->{$field}) {
                        throw new DomainException(__('invoice_correction.source_invalid'));
                    }
                }
                if (bccomp($inverse->quantity_in, $original->quantity_out, 8) !== 0 || bccomp($inverse->quantity_out, $original->quantity_in, 8) !== 0) {
                    throw new DomainException(__('invoice_correction.source_invalid'));
                }
            }
        }
        foreach ([$return->quarantine_journal_entry_id, $return->disposition_journal_entry_id, $return->creditNote?->journal_entry_id] as $id) {
            if ($id === null) {
                continue;
            }
            $journal = JournalEntry::query()->findOrFail($id);
            $inverse = JournalEntry::query()->find($journal->reversed_entry_id);
            if ($inverse === null || (int) $journal->company_id !== (int) $return->company_id) {
                throw new DomainException(__('invoice_correction.source_invalid'));
            }
            app(SalesReturnCorrectionService::class)->assertInverse($journal, $inverse, (int) $inverse->financial_period_id, $inverse->entry_date->toDateString());
        }
        if ($return->creditNote !== null) {
            app(CustomerCreditApplicationEvidenceService::class)->assertApprovedApplication($return->creditNote);
            foreach ($return->creditNote->creditAllocations as $allocation) {
                if (! $this->hasValidAllocationReversalEvidence($allocation, $return->creditNote)) {
                    throw new DomainException(__('invoice_correction.source_invalid'));
                }
            }
            foreach ($return->creditNote->creditRefunds as $refund) {
                if (! app(CustomerCreditService::class)->refundReversalEvidenceValid($refund, $return->creditNote)) {
                    throw new DomainException(__('invoice_correction.source_invalid'));
                }
            }
        }
    }

    public function hasValidAllocationReversalEvidence(CustomerCreditAllocation $allocation, CustomerInvoice $credit): bool
    {
        $effect = $allocation->reversal_effect_snapshot;
        $later = is_array($effect) && isset($effect['sales_return_correction_id']);
        if ($later && ! app(SalesReturnCorrectionService::class)->approvedRecovery($effect, 'allocation', (int) $allocation->id)) {
            return false;
        }
        if ($allocation->status !== CustomerCreditAllocation::StatusReversed
            || $allocation->reversed_at === null
            || $allocation->reversed_by === null
            || blank($allocation->reversal_reason)
            || ! is_array($effect)
            || (int) ($effect['credit_note_id'] ?? 0) !== (int) $credit->getKey()
            || (int) ($effect['target_invoice_id'] ?? 0) !== (int) $allocation->target_invoice_id
            || (int) ($effect['target_payment_schedule_id'] ?? 0) !== (int) $allocation->target_payment_schedule_id
            || (int) ($effect['allocation_financial_period_id'] ?? 0) !== (int) $allocation->financial_period_id
            || (! $later && (int) ($effect['reversal_financial_period_id'] ?? 0) !== (int) $allocation->financial_period_id)
            || (int) ($effect['reversed_by'] ?? 0) !== (int) $allocation->reversed_by
            || ($effect['allocation_date'] ?? null) !== $allocation->allocation_date?->toDateString()
            || (! $later && ($effect['reversal_date'] ?? null) !== $allocation->reversed_at->toDateString())
            || ($effect['reversal_reason'] ?? null) !== $allocation->reversal_reason) {
            return false;
        }

        $moneyFields = [
            'amount', 'credit_available_before', 'credit_available_after',
            'credit_allocated_before', 'credit_allocated_after',
            'target_invoice_credited_before', 'target_invoice_credited_after',
            'target_invoice_remaining_before', 'target_invoice_remaining_after',
            'target_schedule_credited_before', 'target_schedule_credited_after',
        ];
        foreach ($moneyFields as $field) {
            if (! is_string($effect[$field] ?? null)
                || preg_match('/^\d+(?:\.\d{1,4})?$/D', $effect[$field]) !== 1) {
                return false;
            }
        }

        $amount = $effect['amount'];

        return $this->amounts->compare($amount, $allocation->amount) === 0
            && $this->amounts->compare($amount, '0') > 0
            && $this->amounts->compare($effect['credit_available_after'], $this->amounts->add($effect['credit_available_before'], $amount)) === 0
            && $this->amounts->compare($effect['credit_allocated_after'], $this->amounts->subtract($effect['credit_allocated_before'], $amount)) === 0
            && $this->amounts->compare($effect['target_invoice_credited_after'], $this->amounts->subtract($effect['target_invoice_credited_before'], $amount)) === 0
            && $this->amounts->compare($effect['target_invoice_remaining_after'], $this->amounts->add($effect['target_invoice_remaining_before'], $amount)) === 0
            && $this->amounts->compare($effect['target_schedule_credited_after'], $this->amounts->subtract($effect['target_schedule_credited_before'], $amount)) === 0;
    }

    /** @return list<string> */
    public function reasonCodes(): array
    {
        return [SalesReturn::ReasonExcess, SalesReturn::ReasonOrderEntry, SalesReturn::ReasonWrongItem, SalesReturn::ReasonWrongSpecification, SalesReturn::ReasonManufacturingDefect, SalesReturn::ReasonDamaged, SalesReturn::ReasonProductionDefect, SalesReturn::ReasonCustomerRejection, SalesReturn::ReasonOther];
    }

    private function postingCopy(SalesReturn $return): SalesReturn
    {
        Company::query()->whereKey($return->company_id)->lockForUpdate()->firstOrFail();
        $posting = clone $return;
        $posting->return_date = now()->toDateString();
        $posting->financial_period_id = app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $return->company_id, $posting->return_date, lockForUpdate: true)->id;

        return $posting;
    }

    /** @return list<array{schedule_id: int, amount: string}> */
    private function applyCreditToSchedules(CustomerInvoice $invoice, string $amount): array
    {
        $remaining = $amount;
        $applied = [];
        foreach ($invoice->paymentSchedules()->lockForUpdate()->orderBy('due_date')->get() as $schedule) {
            if ($this->amounts->compare($remaining, '0') <= 0) {
                break;
            }
            $credit = $this->amounts->compare($remaining, $schedule->outstanding_amount) > 0 ? $schedule->outstanding_amount : $remaining;
            $schedule->increment('credited_amount', $credit);
            $applied[] = ['schedule_id' => (int) $schedule->getKey(), 'amount' => $credit];
            $remaining = $this->amounts->subtract($remaining, $credit);
        }

        if ($this->amounts->compare($remaining, '0') !== 0) {
            throw new DomainException(__('sales_return_correction.credit_schedule_incomplete'));
        }

        return $applied;
    }

    private function postDispositionInventory(SalesReturn $return): void
    {
        $return->loadMissing(['lines.product', 'returnInventoryDocument.lines']);
        $dispositions = [
            SalesReturnLine::DispositionSaleable => ['quantity' => 'saleable_quantity', 'base' => 'saleable_base_quantity', 'status' => InventoryTransaction::StatusAvailable],
            SalesReturnLine::DispositionRework => ['quantity' => 'rework_quantity', 'base' => 'rework_base_quantity', 'status' => InventoryTransaction::StatusRework],
            SalesReturnLine::DispositionScrap => ['quantity' => 'scrap_quantity', 'base' => 'scrap_base_quantity', 'status' => InventoryTransaction::StatusScrap],
        ];

        foreach ($dispositions as $disposition => $profile) {
            $lines = $return->lines
                ->where('is_service', false)
                ->filter(fn (SalesReturnLine $line): bool => $this->amounts->compare($line->{$profile['base']}, '0', 8) > 0)
                ->values();

            if ($lines->isEmpty()) {
                continue;
            }

            $numbers = $this->documents->nextForCompany(
                'inventory_documents',
                InventoryDocument::class,
                (int) $return->company_id,
            );
            $document = InventoryDocument::query()->create([
                ...$numbers,
                'company_id' => $return->company_id,
                'financial_period_id' => $return->financial_period_id,
                'branch_id' => $return->branch_id,
                'branch_store_id' => $return->branch_store_id,
                'document_type' => InventoryDocument::TypeTransfer,
                'document_date' => $return->return_date,
                'destination_branch_store_id' => $return->branch_store_id,
                'source_stock_status' => InventoryTransaction::StatusQuarantine,
                'destination_stock_status' => $profile['status'],
                'movement_reason' => "sales_return_{$disposition}",
                'purpose' => "Sales return quality disposition: {$disposition}",
                'source_document_type' => SalesReturn::class,
                'source_document_id' => $return->getKey(),
                'source_doc_num' => $return->doc_num,
                'customer_id' => $return->customer_id,
                'status' => InventoryDocument::StatusDraft,
                'created_by' => auth()->id(),
            ]);

            $lineNumber = 0;
            $specific = app(InventoryCostPolicyService::class)->resolve((int) $return->company_id, (int) $return->branch_store_id, $return->return_date->toDateString())['method'] === InventoryCostPolicy::SpecificIdentification;
            foreach ($lines as $line) {
                $slices = [['quantity' => (string) $line->{$profile['base']}, 'layer' => null]];
                if ($specific || $line->product?->tracks_serials) {
                    $slices = [];
                    $remaining = (string) $line->{$profile['base']};
                    $layers = InventoryReceiptLayer::query()->where('company_id', $return->company_id)->where('branch_store_id', $return->branch_store_id)
                        ->where('product_id', $line->product_id)->where('stock_status', InventoryTransaction::StatusQuarantine)->where('remaining_quantity', '>', 0)
                        ->whereHas('receiptTransaction', fn ($receipt) => $receipt->where('source_type', InventoryDocument::class)
                            ->where('source_id', $return->return_inventory_document_id)->where('source_line_type', SalesReturnLine::class)->where('source_line_id', $line->id))
                        ->orderBy('id')->lockForUpdate()->get();
                    foreach ($layers as $layer) {
                        if (bccomp($remaining, '0', 8) <= 0) {
                            break;
                        }
                        $quantity = bccomp($remaining, (string) $layer->remaining_quantity, 8) > 0 ? (string) $layer->remaining_quantity : $remaining;
                        $slices[] = ['quantity' => $quantity, 'layer' => $layer];
                        $remaining = bcsub($remaining, $quantity, 8);
                    }
                    if (bccomp($remaining, '0', 8) > 0) {
                        throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
                    }
                }
                $transactionQuantities = $this->amounts->splitQuantityByWeights((string) $line->{$profile['quantity']}, array_column($slices, 'quantity'));
                foreach ($slices as $index => $slice) {
                    $layer = $slice['layer'];
                    $document->lines()->create([
                        'company_id' => $return->company_id,
                        'financial_period_id' => $return->financial_period_id,
                        'line_number' => ++$lineNumber,
                        'product_id' => $line->product_id,
                        'unit_id' => $line->product?->item_unit_id,
                        'transaction_unit_id' => $line->unit_id,
                        'conversion_factor' => $line->conversion_factor,
                        'transaction_quantity' => $transactionQuantities[$index],
                        'base_quantity' => $slice['quantity'],
                        'quantity' => $slice['quantity'],
                        'selected_receipt_layer_id' => $layer?->id,
                        'inventory_serial_identity_id' => $layer?->inventory_serial_identity_id,
                        'batch_lot' => $layer?->batch_lot, 'warehouse_location_id' => $layer?->warehouse_location_id,
                        'manufacture_date' => $layer?->manufacture_date, 'expiry_date' => $layer?->expiry_date,
                        'reference_quantity' => $line->{$profile['quantity']},
                        'source_line_type' => SalesReturnLine::class,
                        'source_line_id' => $line->getKey(),
                        'source_line_public_id' => $line->public_id,
                        'unit_cost' => $line->original_unit_cost,
                        'product_snapshot' => [
                            'quality_disposition' => $disposition,
                            'original_sales_return' => $return->doc_num,
                            'original_unit_cost' => $line->original_unit_cost,
                        ],
                        'created_by' => auth()->id(),
                    ]);
                }
            }

            $posted = $this->inventoryPosting->post($document)->load('transactions');
            foreach ($lines as $line) {
                $issues = $posted->transactions->where('source_line_type', SalesReturnLine::class)
                    ->where('source_line_id', $line->id)->filter(fn ($transaction): bool => bccomp((string) $transaction->quantity_out, '0', 8) > 0);
                if ($issues->isEmpty() || $issues->contains(fn ($transaction): bool => $transaction->total_cost === null)) {
                    throw new DomainException(__('sales_return_correction.incomplete_disposition'));
                }
                $cost = $issues->reduce(fn (string $sum, $transaction): string => bcadd($sum, (string) $transaction->total_cost, 8), '0.00000000');
                $snapshot = $line->source_snapshot ?? [];
                $snapshot['disposition_costs'][$profile['base']] = $cost;
                $snapshot['disposition_documents'][$profile['base']] = $posted->doc_num;
                $line->forceFill(['source_snapshot' => $snapshot])->save();
            }
        }
    }

    private function recordStatus(SalesReturn $return, ?string $from, string $to): void
    {
        $return->statusHistory()->create(['from_status' => $from, 'to_status' => $to, 'changed_by' => auth()->id(), 'changed_at' => now()]);
    }
}

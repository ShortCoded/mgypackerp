<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnLine;

class SalesReturnService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly SalesAmountService $amounts,
        private readonly InventoryDocumentPostingService $inventoryPosting,
        private readonly SalesAccountingService $accounting,
        private readonly SalesCycleAuditService $audit,
    ) {}

    /** @param list<array{customer_invoice_line_id: int, quantity: string|int|float}> $lines */
    public function create(CustomerInvoice $invoice, string $reasonCode, ?string $reasonDetails, array $lines): SalesReturn
    {
        return DB::transaction(function () use ($invoice, $reasonCode, $reasonDetails, $lines): SalesReturn {
            $source = CustomerInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            if ($source->document_type !== CustomerInvoice::TypeInvoice || $source->posting_status !== 'posted') {
                throw new DomainException('Returns require an original posted sales invoice.');
            }
            if (! in_array($reasonCode, $this->reasonCodes(), true)) {
                throw new DomainException('Select a controlled sales return reason.');
            }
            $prepared = [];
            foreach ($lines as $input) {
                $line = CustomerInvoiceLine::query()->lockForUpdate()->where('customer_invoice_id', $source->getKey())->findOrFail($input['customer_invoice_line_id']);
                $quantity = (string) $input['quantity'];
                $this->amounts->assertPositive($quantity, 'Return quantity must be greater than zero.');
                $alreadyReturned = (string) DB::table('sales_return_lines')->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
                    ->where('sales_return_lines.customer_invoice_line_id', $line->getKey())->where('sales_returns.status', '<>', SalesReturn::StatusCancelled)->whereNull('sales_returns.deleted_at')->sum('sales_return_lines.quantity');
                $this->amounts->assertNotGreaterThan($quantity, $this->amounts->subtract($line->quantity, $alreadyReturned, 8), 'Return quantity exceeds the quantity still returnable.');
                $ratio = bcdiv($quantity, (string) $line->quantity, 12);
                $tax = $this->amounts->round(bcmul((string) $line->tax_amount, $ratio, 8));
                $lineTotal = $this->amounts->round(bcmul((string) $line->line_total, $ratio, 8));
                $prepared[] = compact('line', 'quantity', 'tax', 'lineTotal');
            }
            if ($prepared === []) {
                throw new DomainException('A sales return requires at least one original invoice line.');
            }

            $numbers = $this->documents->nextForCompany('sales_returns', SalesReturn::class, (int) $source->company_id, fn ($query) => $query->where('financial_period_id', $source->financial_period_id));
            $return = SalesReturn::query()->create([
                ...$numbers, 'company_id' => $source->company_id, 'financial_period_id' => $source->financial_period_id,
                'branch_id' => $source->branch_id, 'branch_store_id' => $source->order?->branch_store_id,
                'customer_id' => $source->customer_id, 'sales_order_id' => $source->sales_order_id,
                'customer_invoice_id' => $source->getKey(), 'delivery_document_id' => $source->delivery_document_id,
                'return_date' => now()->toDateString(), 'reason_code' => $reasonCode,
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
                    'delivery_line_id' => $line->delivery_line_id, 'sales_order_line_id' => $line->sales_order_line_id,
                    'product_id' => $line->product_id, 'unit_id' => $line->unit_id,
                    'conversion_factor' => $line->conversion_factor, 'quantity' => $row['quantity'],
                    'base_quantity' => bcmul((string) $row['quantity'], (string) $line->conversion_factor, 8),
                    'unit_price' => $line->unit_price, 'tax_amount' => $row['tax'], 'line_total' => $row['lineTotal'],
                    'is_service' => $line->is_service, 'original_unit_cost' => $line->unit_cost,
                    'source_snapshot' => ['invoice' => $source->doc_num, 'invoice_line_public_id' => $line->public_id],
                ]);
            }
            $this->recordStatus($return, null, SalesReturn::StatusPendingAuthorization);
            $this->audit->record($return, 'sales_return.created', ['reason_code' => $reasonCode]);

            return $return->load(['lines.invoiceLine', 'invoice']);
        });
    }

    public function authorize(SalesReturn $return): SalesReturn
    {
        return DB::transaction(function () use ($return): SalesReturn {
            $locked = SalesReturn::query()->lockForUpdate()->findOrFail($return->getKey());
            if ($locked->status !== SalesReturn::StatusPendingAuthorization) {
                throw new DomainException('Only a pending return can be authorized.');
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
            $locked = SalesReturn::query()->with('lines')->lockForUpdate()->findOrFail($return->getKey());
            if ($locked->status !== SalesReturn::StatusAuthorized) {
                throw new DomainException('Only an authorized return can be received.');
            }
            $physical = $locked->lines->where('is_service', false);
            if ($physical->isNotEmpty()) {
                if (! $locked->branch_store_id) {
                    throw new DomainException('A return store is required for a physical return.');
                }
                $numbers = $this->documents->nextForCompany('inventory_documents', InventoryDocument::class, (int) $locked->company_id, fn ($query) => $query->where('financial_period_id', $locked->financial_period_id));
                $document = InventoryDocument::query()->create([
                    ...$numbers, 'company_id' => $locked->company_id, 'financial_period_id' => $locked->financial_period_id,
                    'branch_id' => $locked->branch_id, 'branch_store_id' => $locked->branch_store_id,
                    'document_type' => InventoryDocument::TypeSalesReturnReceipt, 'document_date' => now()->toDateString(),
                    'purpose' => 'Sales return pending quality disposition', 'source_document_type' => SalesReturn::class,
                    'source_document_id' => $locked->getKey(), 'source_doc_num' => $locked->doc_num,
                    'customer_id' => $locked->customer_id, 'status' => InventoryDocument::StatusDraft, 'created_by' => auth()->id(),
                ]);
                foreach ($physical->values() as $index => $line) {
                    $product = Product::query()->lockForUpdate()->findOrFail($line->product_id);
                    $document->lines()->create([
                        'company_id' => $locked->company_id, 'financial_period_id' => $locked->financial_period_id,
                        'line_number' => $index + 1, 'product_id' => $line->product_id, 'unit_id' => $product->item_unit_id,
                        'transaction_unit_id' => $line->unit_id, 'conversion_factor' => $line->conversion_factor,
                        'transaction_quantity' => $line->quantity, 'base_quantity' => $line->base_quantity,
                        'source_line_type' => SalesReturnLine::class, 'source_line_id' => $line->getKey(),
                        'source_line_public_id' => $line->public_id, 'reference_quantity' => $line->quantity,
                        'quantity' => 0, 'rejected_quantity' => 0, 'unit_cost' => $line->original_unit_cost,
                        'product_snapshot' => ['quality_status' => 'pending'], 'created_by' => auth()->id(),
                    ]);
                }
                $locked->update(['return_inventory_document_id' => $document->getKey()]);
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
            $locked = SalesReturn::query()->with(['lines', 'returnInventoryDocument.lines'])->lockForUpdate()->findOrFail($return->getKey());
            if ($locked->status !== SalesReturn::StatusReceived) {
                throw new DomainException('Only a received return can be inspected.');
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
                $saleableBase = bcmul($saleable, (string) $line->conversion_factor, 8);
                $quarantineBase = bcmul($quarantine, (string) $line->conversion_factor, 8);
                $reworkBase = bcmul($rework, (string) $line->conversion_factor, 8);
                $scrapBase = bcmul($scrap, (string) $line->conversion_factor, 8);
                $sum = $this->amounts->sum([$saleable, $quarantine, $rework, $scrap], 8);
                if ($this->amounts->compare($sum, $line->quantity, 8) !== 0) {
                    throw new DomainException('Quality disposition quantities must equal the received return quantity.');
                }
                $disposition = collect([SalesReturnLine::DispositionSaleable => $saleable, SalesReturnLine::DispositionQuarantine => $quarantine, SalesReturnLine::DispositionRework => $rework, SalesReturnLine::DispositionScrap => $scrap])->filter(fn ($quantity) => $this->amounts->compare($quantity, '0', 8) > 0)->keys()->implode(',');
                $line->update([
                    'saleable_quantity' => $saleable, 'saleable_base_quantity' => $saleableBase,
                    'quarantine_quantity' => $quarantine, 'quarantine_base_quantity' => $quarantineBase,
                    'rework_quantity' => $rework, 'rework_base_quantity' => $reworkBase,
                    'scrap_quantity' => $scrap, 'scrap_base_quantity' => $scrapBase,
                    'quality_disposition' => $disposition, 'inspection_notes' => $result['notes'] ?? null,
                ]);
                $inventoryLine = $locked->returnInventoryDocument?->lines->firstWhere('source_line_id', $line->getKey());
                $inventoryLine?->update(['quantity' => $saleableBase, 'base_quantity' => $saleableBase, 'rejected_quantity' => $this->amounts->sum([$quarantineBase, $reworkBase, $scrapBase], 8), 'product_snapshot' => ['quality_disposition' => $disposition]]);
            }
            $locked->load('lines');
            if ($locked->lines->where('is_service', false)->contains(fn (SalesReturnLine $line): bool => $line->quality_disposition === null)) {
                throw new DomainException('Every physical return line requires a quality disposition.');
            }
            $this->postDispositionInventory($locked);
            $locked->update(['status' => SalesReturn::StatusInspected, 'inspected_by' => auth()->id(), 'inspected_at' => now(), 'updated_by' => auth()->id()]);
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
                throw new DomainException('The return has not completed its required authorization and quality stages.');
            }
            if ($locked->credit_note_id) {
                return $locked;
            }
            $invoice = $locked->invoice;
            $numbers = $this->documents->nextForCompany('customer_credit_notes', CustomerInvoice::class, (int) $locked->company_id, fn ($query) => $query->where('financial_period_id', $locked->financial_period_id));
            $credit = CustomerInvoice::query()->create([
                ...$numbers, 'company_id' => $locked->company_id, 'financial_period_id' => $locked->financial_period_id,
                'branch_id' => $locked->branch_id, 'customer_id' => $locked->customer_id,
                'sales_order_id' => $locked->sales_order_id, 'delivery_document_id' => $locked->delivery_document_id,
                'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
                'currency_id' => $invoice->currency_id, 'exchange_rate' => $invoice->exchange_rate,
                'subtotal_amount' => $locked->subtotal_amount, 'taxable_amount' => $locked->subtotal_amount,
                'tax_amount' => $locked->tax_amount, 'total_amount' => $locked->total_amount,
                'remaining_amount' => 0, 'document_type' => CustomerInvoice::TypeCreditNote,
                'original_invoice_id' => $invoice->getKey(), 'sales_return_id' => $locked->getKey(),
                'status' => CustomerInvoice::StatusDraft, 'posting_status' => 'unposted', 'created_by' => auth()->id(),
            ]);
            foreach ($locked->lines as $index => $line) {
                $source = $line->invoiceLine;
                $credit->lines()->create([
                    'sales_order_line_id' => $line->sales_order_line_id, 'delivery_line_id' => $line->delivery_line_id,
                    'product_id' => $line->product_id, 'unit_id' => $line->unit_id, 'line_number' => $index + 1,
                    'conversion_factor' => $line->conversion_factor, 'base_quantity' => $line->base_quantity,
                    'description' => $source->description, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price,
                    'tax_amount' => $line->tax_amount, 'line_total' => $line->line_total, 'is_service' => $line->is_service,
                    'unit_cost' => $line->original_unit_cost, 'source_snapshot' => ['original_invoice_line_public_id' => $source->public_id, 'sales_return' => $locked->doc_num],
                ]);
                if ($line->sales_order_line_id) {
                    SalesOrderLine::query()->whereKey($line->sales_order_line_id)->increment('returned_quantity', $line->quantity);
                    SalesOrderLine::query()->whereKey($line->sales_order_line_id)->increment('returned_base_quantity', $line->base_quantity);
                }
            }
            $journal = $this->accounting->postCreditNote($credit);
            $credit->update(['status' => CustomerInvoice::StatusPosted, 'posting_status' => 'posted', 'is_closed' => true, 'journal_entry_id' => $journal->getKey(), 'issued_by' => auth()->id(), 'issued_at' => now()]);
            if ($hasPhysical) {
                $this->accounting->postSaleableReturnCost($locked);
            }
            $invoice->increment('credited_amount', $locked->total_amount);
            $remaining = $this->amounts->subtract($this->amounts->subtract($invoice->total_amount, $invoice->paid_amount), $invoice->fresh()->credited_amount);
            $invoice->update(['remaining_amount' => $this->amounts->compare($remaining, '0') < 0 ? '0.0000' : $remaining]);
            $this->applyCreditToSchedules($invoice, (string) $locked->total_amount);
            $locked->update(['credit_note_id' => $credit->getKey(), 'status' => SalesReturn::StatusClosed, 'closed_by' => auth()->id(), 'closed_at' => now(), 'updated_by' => auth()->id()]);
            $this->recordStatus($locked, $fromStatus, SalesReturn::StatusClosed);
            $this->audit->record($locked, 'sales_return.closed', ['credit_note' => $credit->doc_num]);

            return $locked->refresh()->load(['creditNote', 'lines']);
        });
    }

    /** @return list<string> */
    public function reasonCodes(): array
    {
        return [SalesReturn::ReasonExcess, SalesReturn::ReasonOrderEntry, SalesReturn::ReasonWrongItem, SalesReturn::ReasonWrongSpecification, SalesReturn::ReasonManufacturingDefect, SalesReturn::ReasonDamaged, SalesReturn::ReasonProductionDefect, SalesReturn::ReasonCustomerRejection, SalesReturn::ReasonOther];
    }

    private function applyCreditToSchedules(CustomerInvoice $invoice, string $amount): void
    {
        $remaining = $amount;
        foreach ($invoice->paymentSchedules()->lockForUpdate()->orderBy('due_date')->get() as $schedule) {
            if ($this->amounts->compare($remaining, '0') <= 0) {
                break;
            }
            $credit = $this->amounts->compare($remaining, $schedule->outstanding_amount) > 0 ? $schedule->outstanding_amount : $remaining;
            $schedule->increment('credited_amount', $credit);
            $remaining = $this->amounts->subtract($remaining, $credit);
        }
    }

    private function postDispositionInventory(SalesReturn $return): void
    {
        $return->loadMissing(['lines.product', 'returnInventoryDocument.lines']);
        $dispositions = [
            SalesReturnLine::DispositionSaleable => ['quantity' => 'saleable_quantity', 'base' => 'saleable_base_quantity', 'status' => InventoryTransaction::StatusAvailable],
            SalesReturnLine::DispositionQuarantine => ['quantity' => 'quarantine_quantity', 'base' => 'quarantine_base_quantity', 'status' => InventoryTransaction::StatusQuarantine],
            SalesReturnLine::DispositionRework => ['quantity' => 'rework_quantity', 'base' => 'rework_base_quantity', 'status' => InventoryTransaction::StatusRework],
            SalesReturnLine::DispositionScrap => ['quantity' => 'scrap_quantity', 'base' => 'scrap_base_quantity', 'status' => InventoryTransaction::StatusScrap],
        ];

        foreach ($dispositions as $disposition => $profile) {
            $lines = $return->lines
                ->where('is_service', false)
                ->filter(fn (SalesReturnLine $line): bool => $this->amounts->compare($line->{$profile['base']}, '0', 8) > 0)
                ->values();

            if ($disposition === SalesReturnLine::DispositionSaleable) {
                $document = $return->returnInventoryDocument;
                if (! $document instanceof InventoryDocument) {
                    continue;
                }
                $document->update([
                    'destination_stock_status' => $profile['status'],
                    'movement_reason' => 'sales_return_saleable',
                    'purpose' => 'Sales return accepted as saleable stock',
                ]);
                $this->inventoryPosting->post($document);

                continue;
            }

            if ($lines->isEmpty()) {
                continue;
            }

            $numbers = $this->documents->nextForCompany(
                'inventory_documents',
                InventoryDocument::class,
                (int) $return->company_id,
                fn ($query) => $query->where('financial_period_id', $return->financial_period_id),
            );
            $document = InventoryDocument::query()->create([
                ...$numbers,
                'company_id' => $return->company_id,
                'financial_period_id' => $return->financial_period_id,
                'branch_id' => $return->branch_id,
                'branch_store_id' => $return->branch_store_id,
                'document_type' => InventoryDocument::TypeSalesReturnReceipt,
                'document_date' => $return->return_date,
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

            foreach ($lines as $index => $line) {
                $document->lines()->create([
                    'company_id' => $return->company_id,
                    'financial_period_id' => $return->financial_period_id,
                    'line_number' => $index + 1,
                    'product_id' => $line->product_id,
                    'unit_id' => $line->product?->item_unit_id,
                    'transaction_unit_id' => $line->unit_id,
                    'conversion_factor' => $line->conversion_factor,
                    'transaction_quantity' => $line->{$profile['quantity']},
                    'base_quantity' => $line->{$profile['base']},
                    'quantity' => $line->{$profile['base']},
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

            $this->inventoryPosting->post($document);
        }
    }

    private function recordStatus(SalesReturn $return, ?string $from, string $to): void
    {
        $return->statusHistory()->create(['from_status' => $from, 'to_status' => $to, 'changed_by' => auth()->id(), 'changed_at' => now()]);
    }
}

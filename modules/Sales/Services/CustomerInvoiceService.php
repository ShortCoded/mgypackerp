<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnLine;

class CustomerInvoiceService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly SalesAmountService $amounts,
        private readonly SalesAccountingService $accounting,
        private readonly SalesCycleAuditService $audit,
    ) {}

    /** @param list<array{sales_order_line_id: int, quantity: string|int|float, delivery_line_id?: int|null}> $lines @param list<array{due_date: string, amount: string|int|float, notes?: string|null}> $schedules */
    public function createFromOrder(SalesOrder $order, array $lines, array $schedules, ?InventoryDocument $delivery = null, ?string $invoiceDate = null): CustomerInvoice
    {
        return DB::transaction(function () use ($order, $lines, $schedules, $delivery, $invoiceDate): CustomerInvoice {
            $invoiceDate ??= now()->toDateString();
            $salesOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $period = app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $salesOrder->company_id, $invoiceDate, lockForUpdate: true);
            if (! in_array($salesOrder->status, [SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled, SalesOrder::StatusFulfilled], true)) {
                throw new DomainException(__('The sales order is not eligible for invoicing.'));
            }
            if ($delivery && ($delivery->document_type !== InventoryDocument::TypeSalesDelivery || $delivery->status !== InventoryDocument::StatusPosted || $delivery->source_document_id !== $salesOrder->getKey())) {
                throw new DomainException(__('The selected delivery does not belong to this order or is not posted.'));
            }

            $prepared = [];
            $allocatedAmounts = [];
            $quantitiesByOrderLine = [];
            $quantitiesByDeliveryLine = [];
            foreach ($lines as $input) {
                $orderLine = SalesOrderLine::query()->lockForUpdate()->where('sales_order_id', $salesOrder->getKey())->findOrFail($input['sales_order_line_id']);
                $quantity = (string) $input['quantity'];
                $this->amounts->assertPositive($quantity, __('Invoice quantity must be greater than zero.'));
                $quantitiesByOrderLine[$orderLine->getKey()] = bcadd($quantitiesByOrderLine[$orderLine->getKey()] ?? '0', $quantity, 8);
                $this->amounts->assertNotGreaterThan($quantitiesByOrderLine[$orderLine->getKey()], $orderLine->remainingInvoiceQuantity(), __('Invoice quantity exceeds the delivered or ordered quantity available.'));
                $deliveryLine = null;
                if (! $orderLine->isService()) {
                    if (empty($input['delivery_line_id'])) {
                        throw new DomainException(__('Physical invoice lines require an explicit delivery line.'));
                    }
                    $deliveryLine = InventoryDocumentLine::query()->with('document')->lockForUpdate()->where('source_line_type', SalesOrderLine::class)->where('source_line_id', $orderLine->getKey())->findOrFail($input['delivery_line_id']);
                    if ($deliveryLine->document->document_type !== InventoryDocument::TypeSalesDelivery || $deliveryLine->document->status !== InventoryDocument::StatusPosted || $deliveryLine->document->source_document_id !== $salesOrder->getKey()) {
                        throw new DomainException(__('The selected delivery line does not belong to a posted delivery for this order.'));
                    }
                    $alreadyInvoiced = (string) CustomerInvoiceLine::query()->where('delivery_line_id', $deliveryLine->getKey())->whereHas('invoice', fn ($query) => $query->where('document_type', CustomerInvoice::TypeInvoice))->sum('quantity');
                    $unbilledReturns = (string) SalesReturnLine::query()->where('delivery_line_id', $deliveryLine->getKey())->whereNull('customer_invoice_line_id')->whereHas('salesReturn', fn ($query) => $query->where('status', '<>', SalesReturn::StatusCancelled))->sum('quantity');
                    $alreadyInvoiced = bcadd($alreadyInvoiced, $unbilledReturns, 8);
                    $quantitiesByDeliveryLine[$deliveryLine->getKey()] = bcadd($quantitiesByDeliveryLine[$deliveryLine->getKey()] ?? '0', $quantity, 8);
                    $this->amounts->assertNotGreaterThan($quantitiesByDeliveryLine[$deliveryLine->getKey()], $this->amounts->subtract($deliveryLine->transaction_quantity, $alreadyInvoiced, 8), __('Invoice quantity exceeds the selected delivery line.'));
                }
                ['discount' => $discount, 'tax' => $tax, 'gross' => $gross] = $this->proratedAmounts($orderLine, $quantity, $allocatedAmounts);
                $prepared[] = [
                    'order_line' => $orderLine, 'delivery_line' => $deliveryLine, 'quantity' => $quantity,
                    'discount' => $discount, 'tax' => $tax, 'gross' => $gross,
                    'line_total' => $this->amounts->add($this->amounts->subtract($gross, $discount), $tax),
                ];
            }
            $total = $this->amounts->sum(array_column($prepared, 'line_total'));
            $scheduleTotal = $this->amounts->sum(array_column($schedules, 'amount'));
            if ($schedules === [] || $this->amounts->compare($scheduleTotal, $total) !== 0) {
                throw new DomainException(__('Invoice payment schedules must exist and equal the invoice total.'));
            }

            $numbers = $this->documents->nextForCompany('customer_invoices', CustomerInvoice::class, (int) $salesOrder->company_id);
            $invoice = CustomerInvoice::query()->create([
                ...$numbers, 'company_id' => $salesOrder->company_id, 'financial_period_id' => $period->getKey(),
                'branch_id' => $salesOrder->branch_id, 'customer_id' => $salesOrder->customer_id,
                'sales_order_id' => $salesOrder->getKey(), 'delivery_document_id' => $delivery?->getKey(),
                'invoice_date' => $invoiceDate, 'due_date' => collect($schedules)->max('due_date'),
                'currency_id' => $salesOrder->currency_id, 'exchange_rate' => $salesOrder->exchange_rate,
                'subtotal_amount' => $this->amounts->sum(array_column($prepared, 'gross')),
                'discount_amount' => $this->amounts->sum(array_column($prepared, 'discount')),
                'taxable_amount' => $this->amounts->sum(array_map(fn (array $row): string => $this->amounts->subtract($row['gross'], $row['discount']), $prepared)),
                'tax_amount' => $this->amounts->sum(array_column($prepared, 'tax')), 'total_amount' => $total,
                'remaining_amount' => $total, 'document_type' => CustomerInvoice::TypeInvoice,
                'status' => CustomerInvoice::StatusDraft, 'posting_status' => 'unposted',
                'payment_terms_snapshot' => $salesOrder->payment_terms_snapshot ?? $salesOrder->agreement_snapshot,
                'created_by' => auth()->id(),
            ]);
            foreach ($prepared as $index => $row) {
                $line = $row['order_line'];
                $invoice->lines()->create([
                    'sales_order_line_id' => $line->getKey(), 'delivery_line_id' => $row['delivery_line']?->getKey(),
                    'product_id' => $line->product_id, 'unit_id' => $line->unit_id, 'line_number' => $index + 1,
                    'conversion_factor' => $line->conversion_factor,
                    'description' => $line->description, 'quantity' => $row['quantity'],
                    'base_quantity' => bcmul((string) $row['quantity'], (string) $line->conversion_factor, 8),
                    'unit_price' => $line->unit_price,
                    'discount_amount' => $row['discount'], 'tax_amount' => $row['tax'], 'line_total' => $row['line_total'],
                    'is_service' => $line->isService(), 'unit_cost' => $row['delivery_line']?->unit_cost ?? 0,
                    'source_snapshot' => [
                        'sales_order' => $salesOrder->doc_num,
                        'sales_order_line_public_id' => $line->public_id,
                        'delivery' => $row['delivery_line']?->document?->doc_num,
                        'tax_rate' => $line->tax_rate,
                        'tax_code' => $row['tax'] > 0 ? 'VAT' : 'EXEMPT',
                        'unit_code' => $line->unit?->doc_num,
                    ],
                ]);
                $line->increment('invoiced_quantity', $row['quantity']);
                $line->increment('invoiced_base_quantity', bcmul((string) $row['quantity'], (string) $line->conversion_factor, 8));
            }
            foreach ($schedules as $index => $schedule) {
                $invoice->paymentSchedules()->create([...$schedule, 'sequence' => $index + 1]);
            }
            $invoice->deliveries()->sync(collect($prepared)->pluck('delivery_line.inventory_document_id')->filter()->unique()->values()->all());

            return $invoice->load(['lines.orderLine', 'paymentSchedules']);
        });
    }

    public function post(CustomerInvoice $invoice): CustomerInvoice
    {
        return DB::transaction(function () use ($invoice): CustomerInvoice {
            $locked = CustomerInvoice::query()->with(['lines', 'paymentSchedules', 'customer'])->lockForUpdate()->findOrFail($invoice->getKey());
            if ($locked->posting_status === 'posted') {
                return $locked;
            }
            if (! $locked->isEditable()) {
                throw new DomainException(__('The invoice is locked and cannot be posted.'));
            }
            if ($this->amounts->compare($this->amounts->sum($locked->paymentSchedules->pluck('amount')), $locked->total_amount) !== 0) {
                throw new DomainException(__('Invoice schedules no longer reconcile to the invoice total.'));
            }
            $journal = $this->accounting->postInvoice($locked);
            $locked->update(['status' => CustomerInvoice::StatusPosted, 'posting_status' => 'posted', 'is_closed' => true, 'journal_entry_id' => $journal->getKey(), 'issued_by' => auth()->id(), 'issued_at' => now(), 'updated_by' => auth()->id()]);
            $this->audit->record($locked, 'customer_invoice.posted', ['journal_entry' => $journal->doc_num]);

            return $locked->refresh()->load(['lines', 'paymentSchedules']);
        });
    }

    /** @param array<string, mixed> $data */
    public function createNonStockSourceInvoice(array $data): CustomerInvoice
    {
        return DB::transaction(function () use ($data): CustomerInvoice {
            $customer = Customer::query()->forCompany((int) $data['company_id'])->active()->findOrFail($data['customer_id']);
            $net = $this->amounts->round((string) $data['net_amount']);
            $tax = $this->amounts->round((string) ($data['tax_amount'] ?? 0));
            $total = $this->amounts->add($net, $tax);
            $this->amounts->assertPositive($net, __('A non-stock source Invoice requires a positive net amount.'));
            $numbers = $this->documents->nextForCompany(
                'customer_invoices', CustomerInvoice::class, (int) $data['company_id'],
            );
            $invoice = CustomerInvoice::query()->create([
                ...$numbers, 'company_id' => $data['company_id'], 'financial_period_id' => $data['financial_period_id'],
                'branch_id' => $data['branch_id'], 'customer_id' => $customer->getKey(),
                'sales_order_id' => null, 'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? $data['invoice_date'], 'currency_id' => $data['currency_id'],
                'exchange_rate' => $data['exchange_rate'] ?? 1, 'subtotal_amount' => $net,
                'discount_amount' => 0, 'taxable_amount' => $net, 'tax_amount' => $tax,
                'total_amount' => $total, 'remaining_amount' => $total,
                'document_type' => CustomerInvoice::TypeInvoice, 'status' => CustomerInvoice::StatusDraft,
                'posting_status' => 'unposted', 'source_type' => $data['source_type'],
                'source_id' => $data['source_id'], 'source_doc_num' => $data['source_doc_num'],
                'notes' => $data['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
            $invoice->lines()->create([
                'line_number' => 1, 'product_id' => null, 'unit_id' => null,
                'description' => $data['description'], 'quantity' => '1.00000000',
                'conversion_factor' => '1.00000000', 'base_quantity' => '1.00000000',
                'unit_price' => $net, 'discount_amount' => 0, 'tax_amount' => $tax,
                'line_total' => $total, 'is_service' => true, 'unit_cost' => 0,
                'source_snapshot' => $data['source_snapshot'] ?? [],
            ]);
            $invoice->paymentSchedules()->create([
                'sequence' => 1, 'due_date' => $data['due_date'] ?? $data['invoice_date'], 'amount' => $total,
            ]);

            return $invoice->load(['lines', 'paymentSchedules']);
        });
    }

    /**
     * @param  list<array{invoice_line_public_id: string, quantity: string|int|float}>  $lines
     * @param  list<array{due_date: string, amount: string|int|float, notes?: string|null}>  $schedules
     */
    public function amend(CustomerInvoice $invoice, array $lines, array $schedules): CustomerInvoice
    {
        return DB::transaction(function () use ($invoice, $lines, $schedules): CustomerInvoice {
            $locked = CustomerInvoice::query()->with(['lines', 'paymentSchedules'])->lockForUpdate()->findOrFail($invoice->getKey());
            if ($locked->document_type !== CustomerInvoice::TypeInvoice || ! $locked->isEditable()) {
                throw new DomainException(__('Only a draft or safely reopened invoice may be amended.'));
            }

            $inputByPublicId = collect($lines)->keyBy('invoice_line_public_id');
            if ($inputByPublicId->count() !== $locked->lines->count()) {
                throw new DomainException(__('Every existing invoice line must be included in the correction.'));
            }

            $prepared = [];
            $allocatedAmounts = [];
            $correctedByDelivery = [];
            $correctedByOrder = [];
            foreach ($locked->lines as $invoiceLine) {
                $input = $inputByPublicId->get($invoiceLine->public_id);
                if (! is_array($input)) {
                    throw new DomainException(__('The invoice correction contains an unknown or missing line.'));
                }

                $orderLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($invoiceLine->sales_order_line_id);
                $quantity = (string) $input['quantity'];
                $this->amounts->assertPositive($quantity, __('Invoice quantity must be greater than zero.'));

                if ($invoiceLine->delivery_line_id) {
                    $deliveryLine = InventoryDocumentLine::query()->lockForUpdate()->findOrFail($invoiceLine->delivery_line_id);
                    $otherInvoiced = (string) DB::table('customer_invoice_lines')
                        ->where('delivery_line_id', $deliveryLine->getKey())
                        ->where('customer_invoice_id', '<>', $locked->getKey())
                        ->sum('quantity');
                    $unbilledReturns = (string) SalesReturnLine::query()->where('delivery_line_id', $deliveryLine->id)->whereNull('customer_invoice_line_id')->whereHas('salesReturn', fn ($query) => $query->where('status', '<>', SalesReturn::StatusCancelled))->sum('quantity');
                    $otherInvoiced = bcadd($otherInvoiced, $unbilledReturns, 8);
                    $correctedByDelivery[$deliveryLine->id] = bcadd($correctedByDelivery[$deliveryLine->id] ?? '0', $quantity, 8);
                    $this->amounts->assertNotGreaterThan($correctedByDelivery[$deliveryLine->id], $this->amounts->subtract($deliveryLine->transaction_quantity, $otherInvoiced, 8), __('Corrected quantity exceeds its source delivery line.'));
                } else {
                    $otherInvoiced = (string) DB::table('customer_invoice_lines')
                        ->where('sales_order_line_id', $orderLine->getKey())
                        ->where('customer_invoice_id', '<>', $locked->getKey())
                        ->sum('quantity');
                    $correctedByOrder[$orderLine->id] = bcadd($correctedByOrder[$orderLine->id] ?? '0', $quantity, 8);
                    $this->amounts->assertNotGreaterThan($correctedByOrder[$orderLine->id], $this->amounts->subtract($orderLine->quantity, $otherInvoiced, 8), __('Corrected service quantity exceeds the order quantity.'));
                }

                ['discount' => $discount, 'tax' => $tax, 'gross' => $gross] = $this->proratedAmounts($orderLine, $quantity, $allocatedAmounts, $locked->id);
                $baseQuantity = bcmul($quantity, (string) $orderLine->conversion_factor, 8);
                $prepared[] = compact('invoiceLine', 'orderLine', 'quantity', 'baseQuantity', 'discount', 'tax', 'gross');
            }

            $subtotal = $this->amounts->sum(array_column($prepared, 'gross'));
            $discount = $this->amounts->sum(array_column($prepared, 'discount'));
            $tax = $this->amounts->sum(array_column($prepared, 'tax'));
            $total = $this->amounts->add($this->amounts->subtract($subtotal, $discount), $tax);
            if ($this->amounts->compare($this->amounts->sum(array_column($schedules, 'amount')), $total) !== 0) {
                throw new DomainException(__('Corrected payment schedules must equal the corrected invoice total.'));
            }

            foreach ($prepared as $row) {
                $quantityDelta = $this->amounts->subtract($row['quantity'], $row['invoiceLine']->quantity, 8);
                $baseDelta = $this->amounts->subtract($row['baseQuantity'], $row['invoiceLine']->base_quantity, 8);
                $row['orderLine']->increment('invoiced_quantity', $quantityDelta);
                $row['orderLine']->increment('invoiced_base_quantity', $baseDelta);
                $row['invoiceLine']->update([
                    'quantity' => $row['quantity'], 'base_quantity' => $row['baseQuantity'],
                    'discount_amount' => $row['discount'], 'tax_amount' => $row['tax'],
                    'line_total' => $this->amounts->add($this->amounts->subtract($row['gross'], $row['discount']), $row['tax']),
                ]);
            }

            $locked->paymentSchedules()->delete();
            foreach ($schedules as $index => $schedule) {
                $locked->paymentSchedules()->create([...$schedule, 'sequence' => $index + 1]);
            }
            $locked->update([
                'subtotal_amount' => $subtotal, 'discount_amount' => $discount,
                'taxable_amount' => $this->amounts->subtract($subtotal, $discount),
                'tax_amount' => $tax, 'total_amount' => $total, 'remaining_amount' => $total,
                'due_date' => collect($schedules)->max('due_date'), 'updated_by' => auth()->id(),
            ]);
            $this->audit->record($locked, 'customer_invoice.amended', ['posting_revision' => $locked->posting_revision]);

            return $locked->refresh()->load(['lines', 'paymentSchedules']);
        });
    }

    public function reopen(CustomerInvoice $invoice, string $reason): CustomerInvoice
    {
        return DB::transaction(function () use ($invoice, $reason): CustomerInvoice {
            $locked = CustomerInvoice::query()->with(['returns', 'creditNotes'])->lockForUpdate()->findOrFail($invoice->getKey());
            if ($locked->posting_status !== 'posted' || $this->amounts->compare($locked->paid_amount, '0') > 0 || $this->amounts->compare($locked->credited_amount, '0') > 0) {
                throw new DomainException(__('Only an unsettled posted invoice may be reopened.'));
            }
            if ($locked->returns->where('status', '<>', 'cancelled')->isNotEmpty() || $locked->creditNotes->isNotEmpty() || $locked->allocations()->whereHas('receipt', fn ($query) => $query->where('status', 'approved'))->exists()) {
                throw new DomainException(__('An invoice with a return or credit note cannot be reopened.'));
            }
            if ($locked->electronic_invoice_uuid !== null || ! in_array($locked->electronic_invoice_status, ['not_configured', 'draft', 'rejected'], true)) {
                throw new DomainException(__('A submitted electronic invoice must be corrected through the tax-authority amendment workflow.'));
            }
            $revision = ((int) $locked->posting_revision) + 1;
            $reversal = $this->accounting->reverseInvoice($locked, $reason, $revision);
            $locked->update([
                'status' => CustomerInvoice::StatusReopened, 'posting_status' => 'reopen_pending_repost',
                'posting_revision' => $revision, 'is_closed' => false,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reopened_by' => auth()->id(), 'reopened_at' => now(),
                'reopen_reason' => trim($reason), 'updated_by' => auth()->id(),
            ]);
            $this->audit->record($locked, 'customer_invoice.reopened', ['reason' => trim($reason)]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<int, array{quantity: string, discount: string, tax: string, gross: string}>  $allocated
     * @return array{discount: string, tax: string, gross: string}
     */
    private function proratedAmounts(SalesOrderLine $line, string $quantity, array &$allocated, ?int $exceptInvoiceId = null): array
    {
        if (! isset($allocated[$line->id])) {
            $prior = CustomerInvoiceLine::query()->where('sales_order_line_id', $line->id)
                ->when($exceptInvoiceId, fn ($query) => $query->where('customer_invoice_id', '<>', $exceptInvoiceId))
                ->whereHas('invoice', fn ($query) => $query->where('document_type', CustomerInvoice::TypeInvoice))->get();
            $allocated[$line->id] = ['quantity' => $prior->reduce(fn ($sum, $row) => bcadd($sum, $row->quantity, 8), '0'),
                'discount' => $this->amounts->sum($prior->pluck('discount_amount')), 'tax' => $this->amounts->sum($prior->pluck('tax_amount')),
                'gross' => $prior->reduce(fn ($sum, $row) => bcadd($sum, bcsub(bcadd($row->line_total, $row->discount_amount, 4), $row->tax_amount, 4), 4), '0')];
        }
        $state = &$allocated[$line->id];
        $newQuantity = bcadd($state['quantity'], $quantity, 8);
        $final = bccomp($newQuantity, $line->quantity, 8) === 0;
        $ratio = bcdiv($quantity, $line->quantity, 16);
        $totals = ['discount' => $line->discount_amount, 'tax' => $line->tax_amount, 'gross' => $this->amounts->multiply($line->quantity, $line->unit_price)];
        $result = [];
        foreach ($totals as $key => $total) {
            $result[$key] = $final ? bcsub($total, $state[$key], 4) : ($key === 'gross' ? $this->amounts->multiply($quantity, $line->unit_price) : $this->amounts->multiply($total, $ratio));
            $state[$key] = bcadd($state[$key], $result[$key], 4);
        }
        $state['quantity'] = $newQuantity;

        return $result;
    }
}

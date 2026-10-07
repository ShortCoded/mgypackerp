<?php

namespace Modules\Sales\Services;

use App\Services\DocumentOwnerEffectProofService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceCorrection;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\SalesIssueOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesRequestLine;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnLine;

class CustomerInvoiceService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly SalesAmountService $amounts,
        private readonly SalesAccountingService $accounting,
        private readonly SalesCycleAuditService $audit,
        private readonly SalesUnitConversionService $unitConversions,
        private readonly PriceListPricingService $priceLists,
        private readonly SalesIssueOrderService $issueOrders,
        private readonly OperatingContextService $operatingContext,
        private readonly FinancialPeriodService $periods,
    ) {}

    /** @param array<string, mixed> $data */
    public function createDirect(array $data, ?SalesRequest $sourceRequest = null): CustomerInvoice
    {
        return DB::transaction(function () use ($data, $sourceRequest): CustomerInvoice {
            $source = $sourceRequest
                ? SalesRequest::query()->with(['lines.product', 'lines.unit'])->lockForUpdate()->findOrFail($sourceRequest->getKey())
                : null;
            $customer = Customer::query()->forCompany((int) $data['company_id'])->active()->where('doc_num', $data['customer_doc_num'])->firstOrFail();
            $currency = Currency::query()->forCompany((int) $data['company_id'])->active()->where('doc_num', $data['currency_doc_num'])->firstOrFail();
            $period = app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $data['company_id'], $data['invoice_date'], lockForUpdate: true);
            if ($source && (! in_array($source->status, ['approved', 'partially_converted'], true)
                || (int) $source->company_id !== (int) $data['company_id']
                || (int) $source->branch_id !== (int) $data['branch_id']
                || (int) $source->customer_id !== (int) $customer->getKey()
                || (int) $source->currency_id !== (int) $currency->getKey())) {
                throw new DomainException(__('The selected sales request is not eligible for direct invoicing.'));
            }

            $eligiblePriceListIds = $this->priceLists->lockForPersistedResolution(
                (int) $data['company_id'],
                $customer->getKey(),
                $currency->getKey(),
                Product::query()->forCompany((int) $data['company_id'])->active()
                    ->whereIn('doc_num', array_column($data['lines'], 'product_doc_num'))
                    ->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                (string) $data['invoice_date'],
            );

            $prepared = [];
            $unpricedProducts = [];
            $sourceLineIds = [];
            foreach ($data['lines'] as $input) {
                $product = Product::query()->forCompany((int) $data['company_id'])->active()->where('doc_num', $input['product_doc_num'])->firstOrFail();
                if (! $product->isSalesEligible()) {
                    throw new DomainException(__('The selected product is not eligible for sales.'));
                }
                $unit = ItemUnit::query()->forCompany((int) $data['company_id'])->active()->where('doc_num', $input['unit_doc_num'])->firstOrFail();
                $sourceLine = $source?->lines->firstWhere('public_id', $input['source_request_line_public_id'] ?? null);
                if ($source && (! $sourceLine || in_array($sourceLine->getKey(), $sourceLineIds, true)
                    || (int) $sourceLine->product_id !== (int) $product->getKey()
                    || (int) $sourceLine->unit_id !== (int) $unit->getKey())) {
                    throw new DomainException(__('Each invoice line must keep its selected sales request product and unit.'));
                }

                $quantity = (string) $input['quantity'];
                try {
                    $price = $this->priceLists->resolveFromLockedCandidates(
                        (int) $data['company_id'],
                        $customer->getKey(),
                        $currency->getKey(),
                        $product,
                        $unit->getKey(),
                        $quantity,
                        (string) $data['invoice_date'],
                        $eligiblePriceListIds,
                    );
                } catch (DomainException) {
                    $unpricedProducts[] = $product->doc_num.' / '.$product->name;

                    continue;
                }
                $unitPrice = $price['unit_price'];
                $discount = app(SalesOrderDiscountService::class)->lineAmount([...$input, 'unit_price' => $unitPrice]);
                $tax = (string) ($input['tax_amount'] ?? '0');
                $this->amounts->assertPositive($quantity, __('Invoice quantity must be greater than zero.'));
                $this->amounts->assertPositive($unitPrice, __('Invoice unit price must be greater than zero.'));
                if ($sourceLine && bccomp($quantity, $sourceLine->remainingQuantity(), 8) > 0) {
                    throw new DomainException(__('Invoice quantity exceeds the remaining request quantity.'));
                }
                $gross = $this->amounts->unitPriceTotal($quantity, $unitPrice);
                $this->amounts->assertNotGreaterThan($discount, $gross, __('Line discount cannot exceed its gross amount.'));
                $this->amounts->assertNotGreaterThan($discount, $price['maximum_discount_amount'], __('price_lists.messages.discount_exceeded', ['product' => $product->doc_num.' / '.$product->name, 'maximum' => $price['maximum_discount_amount']]));
                $conversion = $this->unitConversions->snapshot($product, $unit->getKey(), $quantity);
                if ($sourceLine) {
                    $sourceLineIds[] = $sourceLine->getKey();
                }
                $prepared[] = [
                    'source_line' => $sourceLine,
                    'product' => $product,
                    'unit' => $unit,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'discount_amount' => $discount,
                    'discount_type' => $input['discount_type'] ?? null,
                    'discount_value' => $input['discount_value'] ?? '0',
                    'tax' => $tax,
                    'tax_amount' => $tax,
                    'tax_rate' => $input['tax_rate'] ?? null,
                    'tax_calculation_basis' => filled($input['tax_rate'] ?? null) ? SalesTaxService::Rate : SalesTaxService::LegacyAmount,
                    'gross' => $gross,
                    'conversion' => $conversion,
                    'price' => $price,
                    'price_list_line_id' => $price['price_list_line_id'],
                    'allowed_discount_type' => $price['allowed_discount_type'],
                    'allowed_discount_value' => $price['allowed_discount_value'],
                    'conversion_factor' => $conversion['conversion_factor'],
                    'description' => $product->name,
                    'line_total' => $this->amounts->add($this->amounts->subtract($gross, $discount), $tax),
                ];
            }
            if ($unpricedProducts !== []) {
                throw new DomainException(__('price_lists.messages.unpriced_products', ['products' => implode('، ', $unpricedProducts)]));
            }
            if ($prepared === []) {
                throw new DomainException(__('A sales invoice requires at least one line.'));
            }

            $discountInputs = app(SalesOrderDiscountService::class)->calculate($prepared, $data['discount_type'] ?? null, $data['discount_value'] ?? '0');
            $prepared = $discountInputs['lines'];
            $subtotal = $this->amounts->sum(array_column($prepared, 'gross'));
            $discount = $this->amounts->sum(array_column($prepared, 'discount_amount'));
            $tax = $this->amounts->sum(array_column($prepared, 'tax_amount'));
            $total = $this->amounts->add($this->amounts->subtract($subtotal, $discount), $tax);
            $numbers = $this->documents->nextForCompany('customer_invoices', CustomerInvoice::class, (int) $data['company_id']);
            $invoice = CustomerInvoice::query()->create([
                ...$numbers,
                'company_id' => $data['company_id'], 'financial_period_id' => $period->getKey(), 'branch_id' => $data['branch_id'],
                'customer_id' => $customer->getKey(), 'sales_order_id' => null,
                'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'] ?? $data['invoice_date'],
                'currency_id' => $currency->getKey(), 'exchange_rate' => $data['exchange_rate'] ?? 1,
                'subtotal_amount' => $subtotal, 'discount_amount' => $discount,
                'discount_type' => $discountInputs['discount_type'], 'discount_value' => $discountInputs['discount_value'],
                'header_discount_amount' => $discountInputs['header_discount_amount'],
                'taxable_amount' => $this->amounts->subtract($subtotal, $discount), 'tax_amount' => $tax,
                ...app(SalesWithholdingService::class)->calculate($total, $data['withholding_rate'] ?? '0', $data['withholding_basis'] ?? null, $this->amounts->subtract($subtotal, $discount)),
                'total_amount' => $total, 'remaining_amount' => $total,
                'document_type' => CustomerInvoice::TypeInvoice, 'status' => CustomerInvoice::StatusDraft, 'posting_status' => 'unposted',
                'source_type' => $source ? 'sales_request' : 'direct', 'source_id' => $source?->getKey(), 'source_doc_num' => $source?->doc_num,
                'notes' => $data['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
            foreach ($prepared as $index => $row) {
                $product = $row['product'];
                $invoice->lines()->create([
                    'sales_order_line_id' => null, 'line_number' => $index + 1,
                    'product_id' => $product->getKey(), 'unit_id' => $row['unit']->getKey(),
                    'description' => $product->name, 'quantity' => $row['quantity'],
                    'conversion_factor' => $row['conversion']['conversion_factor'], 'base_quantity' => $row['conversion']['base_quantity'],
                    'unit_price' => $row['unit_price'], 'discount_amount' => $row['discount_amount'], 'tax_amount' => $row['tax_amount'],
                    'tax_rate' => $row['tax_rate'], 'tax_calculation_basis' => $row['tax_calculation_basis'],
                    'discount_type' => $row['discount_type'], 'discount_value' => $row['discount_value'],
                    'header_discount_amount' => $row['header_discount_amount'],
                    'price_list_line_id' => $row['price']['price_list_line_id'],
                    'allowed_discount_type' => $row['price']['allowed_discount_type'],
                    'allowed_discount_value' => $row['price']['allowed_discount_value'],
                    'line_total' => $row['line_total'], 'is_service' => $product->isService(), 'unit_cost' => 0,
                    'source_snapshot' => array_filter([
                        'source_type' => $source ? 'sales_request' : 'direct',
                        'tax_rate' => $row['tax_rate'], 'tax_calculation_basis' => $row['tax_calculation_basis'],
                        'unit_code' => $row['unit']->doc_num,
                        'tax_code' => bccomp($row['tax_amount'], '0', 4) > 0 ? 'VAT' : 'EXEMPT',
                        'sales_request' => $source?->doc_num,
                        'sales_request_line_id' => $row['source_line']?->getKey(),
                        'sales_request_line_public_id' => $row['source_line']?->public_id,
                    ]),
                ]);
                if ($row['source_line']) {
                    SalesRequestLine::query()->lockForUpdate()->findOrFail($row['source_line']->getKey())->increment('converted_quantity', $row['quantity']);
                }
            }
            $invoice->paymentSchedules()->create([
                'sequence' => 1, 'due_date' => $data['due_date'] ?? $data['invoice_date'], 'amount' => $total,
            ]);
            if ($source) {
                $source->update(['status' => $source->lines()->whereColumn('converted_quantity', '<', 'quantity')->exists() ? 'partially_converted' : 'converted']);
                $this->audit->record($source, 'sales_request.converted', ['target' => 'invoice', 'document' => $invoice->doc_num]);
            }

            return $invoice->load(['customer', 'currency', 'lines.product', 'lines.unit', 'paymentSchedules']);
        });
    }

    /** @param list<array{sales_order_line_id: int, quantity: string}> $lines @return array{lines: list<array<string,mixed>>, total: string} */
    public function quoteOrderCorrection(SalesOrder $order, array $lines, int $exceptInvoiceId): array
    {
        $allocated = [];
        $rows = [];
        foreach ($lines as $input) {
            $line = $order->lines()->lockForUpdate()->findOrFail($input['sales_order_line_id']);
            $amounts = $this->proratedAmounts($line, (string) $input['quantity'], $allocated, $exceptInvoiceId);
            $rows[] = [...$input, ...$amounts, 'product' => $line->product->name, 'unit' => $line->unit->name, 'unit_price' => (string) $line->unit_price,
                'total' => $this->amounts->add($this->amounts->subtract($amounts['gross'], $amounts['discount']), $amounts['tax'])];
        }

        return ['lines' => $rows, 'total' => $this->amounts->sum(array_column($rows, 'total'))];
    }

    /** @param list<array{sales_order_line_id: int, quantity: string|int|float, delivery_line_id?: int|null}> $lines @param list<array{due_date: string, amount: string|int|float, notes?: string|null}> $schedules */
    public function createFromOrder(SalesOrder $order, array $lines, array $schedules, ?InventoryDocument $delivery = null, ?string $invoiceDate = null): CustomerInvoice
    {
        return DB::transaction(function () use ($order, $lines, $schedules, $delivery, $invoiceDate): CustomerInvoice {
            $invoiceDate ??= now()->toDateString();
            $salesOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $period = app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $salesOrder->company_id, $invoiceDate, lockForUpdate: true);
            $isDeclinedRemainderClosure = $salesOrder->status === SalesOrder::StatusClosed
                && $salesOrder->lines()->where('declined_quantity', '>', 0)->exists();
            if (! in_array($salesOrder->status, [SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled, SalesOrder::StatusFulfilled], true)
                && ! $isDeclinedRemainderClosure) {
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
                $this->amounts->assertNotGreaterThan($quantitiesByOrderLine[$orderLine->getKey()], $orderLine->remainingInvoiceQuantity(), __('Invoice quantity exceeds the remaining approved order quantity.'));
                $deliveryLine = null;
                if (! $orderLine->isService() && ! empty($input['delivery_line_id'])) {
                    $deliveryLine = InventoryDocumentLine::query()->with('document')->lockForUpdate()->where('source_line_type', SalesOrderLine::class)->where('source_line_id', $orderLine->getKey())->findOrFail($input['delivery_line_id']);
                    if ($deliveryLine->document->document_type !== InventoryDocument::TypeSalesDelivery || $deliveryLine->document->status !== InventoryDocument::StatusPosted || $deliveryLine->document->source_document_id !== $salesOrder->getKey()) {
                        throw new DomainException(__('The selected delivery line does not belong to a posted delivery for this order.'));
                    }
                    $alreadyInvoiced = (string) CustomerInvoiceLine::query()->where('delivery_line_id', $deliveryLine->getKey())->whereHas('invoice', fn ($query) => $query->where('document_type', CustomerInvoice::TypeInvoice)->where('status', '<>', CustomerInvoice::StatusCancelled))->sum('quantity');
                    $unbilledReturns = (string) SalesReturnLine::query()->where('delivery_line_id', $deliveryLine->getKey())->whereNull('customer_invoice_line_id')->whereHas('salesReturn', fn ($query) => $query->where('status', '<>', SalesReturn::StatusCancelled))->sum('quantity');
                    $alreadyInvoiced = bcadd($alreadyInvoiced, $unbilledReturns, 8);
                    $quantitiesByDeliveryLine[$deliveryLine->getKey()] = bcadd($quantitiesByDeliveryLine[$deliveryLine->getKey()] ?? '0', $quantity, 8);
                    $this->amounts->assertNotGreaterThan($quantitiesByDeliveryLine[$deliveryLine->getKey()], $this->amounts->subtract($deliveryLine->transaction_quantity, $alreadyInvoiced, 8), __('Invoice quantity exceeds the selected delivery line.'));
                }
                ['discount' => $discount, 'tax' => $tax, 'gross' => $gross, 'header_discount_amount' => $headerDiscount] = $this->proratedAmounts($orderLine, $quantity, $allocatedAmounts);
                $prepared[] = [
                    'order_line' => $orderLine, 'delivery_line' => $deliveryLine, 'quantity' => $quantity,
                    'discount' => $discount, 'tax' => $tax, 'gross' => $gross,
                    'line_total' => $this->amounts->add($this->amounts->subtract($gross, $discount), $tax),
                    'header_discount_amount' => $headerDiscount,
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
                'discount_type' => $salesOrder->discount_type,
                'discount_value' => $salesOrder->discount_type === 'fixed' ? $this->amounts->sum(array_column($prepared, 'header_discount_amount')) : $salesOrder->discount_value,
                'header_discount_amount' => $this->amounts->sum(array_column($prepared, 'header_discount_amount')),
                'taxable_amount' => $this->amounts->sum(array_map(fn (array $row): string => $this->amounts->subtract($row['gross'], $row['discount']), $prepared)),
                'tax_amount' => $this->amounts->sum(array_column($prepared, 'tax')), 'total_amount' => $total,
                ...app(SalesWithholdingService::class)->calculate($total, $salesOrder->withholding_rate ?? '0', $salesOrder->withholding_basis, $this->amounts->sum(array_map(fn (array $row): string => $this->amounts->subtract($row['gross'], $row['discount']), $prepared))),
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
                    'price_list_line_id' => $line->price_list_line_id,
                    'allowed_discount_type' => $line->allowed_discount_type,
                    'allowed_discount_value' => $line->allowed_discount_value,
                    'discount_amount' => $row['discount'], 'tax_amount' => $row['tax'], 'line_total' => $row['line_total'],
                    'tax_rate' => $line->tax_rate, 'tax_calculation_basis' => SalesTaxService::SourceAllocation,
                    'discount_type' => $line->discount_type,
                    'discount_value' => $line->discount_type === 'fixed' ? $this->amounts->subtract($row['discount'], $row['header_discount_amount']) : $line->discount_value,
                    'header_discount_amount' => $row['header_discount_amount'],
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
            if ($locked->source_type === 'fixed_asset_disposal'
                && ! FixedAssetDisposal::query()->whereKey($locked->source_id)->where('status', FixedAssetDisposal::StatusPosted)->exists()) {
                throw new DomainException(__('A reversed fixed asset disposal cannot repost its invoice.'));
            }
            if (! $locked->isEditable()) {
                throw new DomainException(__('The invoice is locked and cannot be posted.'));
            }
            if ($this->amounts->compare($this->amounts->sum($locked->paymentSchedules->pluck('amount')), $locked->total_amount) !== 0) {
                throw new DomainException(__('Invoice schedules no longer reconcile to the invoice total.'));
            }
            $journal = $this->accounting->postInvoice($locked);
            $locked->update(['status' => CustomerInvoice::StatusPosted, 'posting_status' => 'posted', 'is_closed' => true, 'journal_entry_id' => $journal->getKey(), 'issued_by' => auth()->id(), 'issued_at' => now(), 'updated_by' => auth()->id()]);
            $this->issueOrders->ensureForPostedInvoice($locked->refresh()->load(['lines', 'order', 'deliveries.lines']));
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
     * @param  list<array{invoice_line_public_id: string, quantity: string|int|float, discount_type?: string|null, discount_value?: string|int|float|null, discount_amount?: string|int|float}>  $lines
     * @param  list<array{due_date: string, amount: string|int|float, notes?: string|null}>  $schedules
     * @param  array{discount_type?: string|null, discount_value?: string|int|float|null}  $discountInputs
     */
    public function amend(CustomerInvoice $invoice, array $lines, array $schedules, ?string $withholdingRate = null, array $discountInputs = [], ?string $withholdingBasis = null): CustomerInvoice
    {
        return DB::transaction(function () use ($invoice, $lines, $schedules, $withholdingRate, $discountInputs, $withholdingBasis): CustomerInvoice {
            $locked = CustomerInvoice::query()->with(['lines', 'paymentSchedules'])->lockForUpdate()->findOrFail($invoice->getKey());
            $this->assertReopenContext($locked);

            if ($locked->document_type !== CustomerInvoice::TypeInvoice || $locked->source_type === 'fixed_asset_disposal' || ! $locked->isEditable()) {
                throw new DomainException(__('Only a draft or safely reopened invoice may be amended.'));
            }

            $inputByPublicId = collect($lines)->keyBy('invoice_line_public_id');
            if ($inputByPublicId->count() !== $locked->lines->count()) {
                throw new DomainException(__('Every existing invoice line must be included in the correction.'));
            }

            $isDirectInvoice = $locked->sales_order_id === null && $locked->lines->every(fn (CustomerInvoiceLine $line): bool => $line->sales_order_line_id === null);
            $hasTaxInputs = collect($lines)->contains(fn (array $line): bool => filled($line['tax_rate'] ?? null));
            if (! $isDirectInvoice && $hasTaxInputs) {
                throw new DomainException(__('sales_ui.invoice_source_tax_locked'));
            }
            $hasDiscountInputs = $discountInputs !== [] || collect($lines)->contains(fn (array $line): bool => array_key_exists('discount_type', $line)
                || array_key_exists('discount_value', $line) || array_key_exists('discount_amount', $line));
            if (! $isDirectInvoice && $hasDiscountInputs) {
                throw new DomainException(__('sales_ui.invoice_source_discount_locked'));
            }
            $recalculateDirectDiscounts = $isDirectInvoice && ($hasDiscountInputs || $hasTaxInputs || $locked->lines->contains(fn (CustomerInvoiceLine $line): bool => $line->tax_calculation_basis === SalesTaxService::Rate) || $locked->discount_type !== null
                || $locked->lines->contains(fn (CustomerInvoiceLine $line): bool => $line->discount_type !== null));

            $sourceRequest = null;
            if ($locked->source_type === 'sales_request') {
                $sourceRequest = SalesRequest::query()->with('lines')->lockForUpdate()->findOrFail($locked->source_id);
            }
            $prepared = [];
            $allocatedAmounts = [];
            $correctedByDelivery = [];
            $correctedByOrder = [];
            $correctedRequestLineIds = [];
            $requestLineChanges = [];
            foreach ($locked->lines as $invoiceLine) {
                $input = $inputByPublicId->get($invoiceLine->public_id);
                if (! is_array($input)) {
                    throw new DomainException(__('The invoice correction contains an unknown or missing line.'));
                }

                $quantity = (string) $input['quantity'];
                $this->amounts->assertPositive($quantity, __('Invoice quantity must be greater than zero.'));

                if ($invoiceLine->sales_order_line_id === null) {
                    $requestLine = null;
                    if ($sourceRequest !== null) {
                        $sourceLinePublicId = $invoiceLine->source_snapshot['sales_request_line_public_id'] ?? null;
                        $requestLine = $sourceRequest->lines->firstWhere('public_id', $sourceLinePublicId);
                        if (! $requestLine instanceof SalesRequestLine
                            || (isset($invoiceLine->source_snapshot['sales_request_line_id'])
                                && (int) $invoiceLine->source_snapshot['sales_request_line_id'] !== (int) $requestLine->getKey())
                            || (int) $requestLine->product_id !== (int) $invoiceLine->product_id
                            || (int) $requestLine->unit_id !== (int) $invoiceLine->unit_id
                            || (! isset($invoiceLine->source_snapshot['sales_request_line_id'])
                                && $sourceRequest->lines->where('product_id', $invoiceLine->product_id)
                                    ->where('unit_id', $invoiceLine->unit_id)->count() !== 1)
                            || in_array($requestLine->getKey(), $correctedRequestLineIds, true)) {
                            throw new DomainException(__('The sales request source line for this invoice is missing or invalid.'));
                        }
                        $correctedRequestLineIds[] = $requestLine->getKey();
                        $correctedConverted = bcadd(
                            (string) $requestLine->converted_quantity,
                            bcsub($quantity, (string) $invoiceLine->quantity, 8),
                            8,
                        );
                        if (bccomp($correctedConverted, '0', 8) < 0
                            || bccomp($correctedConverted, (string) $requestLine->quantity, 8) > 0) {
                            throw new DomainException(__('Invoice quantity exceeds the remaining request quantity.'));
                        }
                        if (bccomp($correctedConverted, (string) $requestLine->converted_quantity, 8) !== 0) {
                            $requestLineChanges[] = [
                                'line' => $requestLine,
                                'converted_before' => (string) $requestLine->converted_quantity,
                                'converted_after' => $correctedConverted,
                            ];
                        }
                    }
                    $ratio = bcdiv($quantity, (string) $invoiceLine->quantity, 12);
                    $bookedGross = bcsub(bcadd((string) $invoiceLine->line_total, (string) $invoiceLine->discount_amount, 4), (string) $invoiceLine->tax_amount, 4);
                    $gross = $this->amounts->round($this->amounts->multiply($bookedGross, $ratio, 16));
                    $prepared[] = [
                        'invoiceLine' => $invoiceLine,
                        'orderLine' => null,
                        'requestLine' => $requestLine,
                        'quantity' => $quantity,
                        'baseQuantity' => bcmul($quantity, (string) $invoiceLine->conversion_factor, 8),
                        'discount' => $this->amounts->multiply((string) $invoiceLine->discount_amount, $ratio),
                        'tax' => $this->amounts->multiply((string) $invoiceLine->tax_amount, $ratio),
                        'gross' => $gross,
                        'unit_price' => $invoiceLine->unit_price,
                        'discount_type' => array_key_exists('discount_type', $input) ? $input['discount_type'] : $invoiceLine->discount_type,
                        'discount_value' => $input['discount_value'] ?? $invoiceLine->discount_value ?? '0',
                        'discount_amount' => $input['discount_amount'] ?? $this->amounts->multiply($this->amounts->subtract($invoiceLine->discount_amount, $invoiceLine->header_discount_amount ?? '0'), $ratio),
                        'tax_amount' => $this->amounts->multiply((string) $invoiceLine->tax_amount, $ratio),
                        'tax_rate' => filled($input['tax_rate'] ?? null) ? app(SalesTaxService::class)->rate($input['tax_rate']) : $invoiceLine->tax_rate,
                        'tax_calculation_basis' => filled($input['tax_rate'] ?? null) ? SalesTaxService::Rate : $invoiceLine->tax_calculation_basis,
                        'price_list_line_id' => $invoiceLine->price_list_line_id,
                        'allowed_discount_type' => $invoiceLine->allowed_discount_type,
                        'allowed_discount_value' => $invoiceLine->allowed_discount_value,
                        'conversion_factor' => $invoiceLine->conversion_factor,
                        'description' => $invoiceLine->description,
                    ];

                    continue;
                }

                $orderLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($invoiceLine->sales_order_line_id);

                if ($invoiceLine->delivery_line_id) {
                    $deliveryLine = InventoryDocumentLine::query()->lockForUpdate()->findOrFail($invoiceLine->delivery_line_id);
                    $otherInvoiced = (string) DB::table('customer_invoice_lines')
                        ->where('delivery_line_id', $deliveryLine->getKey())
                        ->where('customer_invoice_id', '<>', $locked->getKey())
                        ->whereIn('customer_invoice_id', CustomerInvoice::query()->where('status', '<>', CustomerInvoice::StatusCancelled)->select('id'))
                        ->sum('quantity');
                    $unbilledReturns = (string) SalesReturnLine::query()->where('delivery_line_id', $deliveryLine->id)->whereNull('customer_invoice_line_id')->whereHas('salesReturn', fn ($query) => $query->where('status', '<>', SalesReturn::StatusCancelled))->sum('quantity');
                    $otherInvoiced = bcadd($otherInvoiced, $unbilledReturns, 8);
                    $correctedByDelivery[$deliveryLine->id] = bcadd($correctedByDelivery[$deliveryLine->id] ?? '0', $quantity, 8);
                    $this->amounts->assertNotGreaterThan($correctedByDelivery[$deliveryLine->id], $this->amounts->subtract($deliveryLine->transaction_quantity, $otherInvoiced, 8), __('Corrected quantity exceeds its source delivery line.'));
                } else {
                    $otherInvoiced = (string) DB::table('customer_invoice_lines')
                        ->where('sales_order_line_id', $orderLine->getKey())
                        ->where('customer_invoice_id', '<>', $locked->getKey())
                        ->whereIn('customer_invoice_id', CustomerInvoice::query()->where('status', '<>', CustomerInvoice::StatusCancelled)->select('id'))
                        ->sum('quantity');
                    $correctedByOrder[$orderLine->id] = bcadd($correctedByOrder[$orderLine->id] ?? '0', $quantity, 8);
                    $this->amounts->assertNotGreaterThan($correctedByOrder[$orderLine->id], $this->amounts->subtract($orderLine->quantity, $otherInvoiced, 8), __('Corrected service quantity exceeds the order quantity.'));
                }

                ['discount' => $discount, 'tax' => $tax, 'gross' => $gross, 'header_discount_amount' => $headerDiscount] = $this->proratedAmounts($orderLine, $quantity, $allocatedAmounts, $locked->id);
                $baseQuantity = bcmul($quantity, (string) $orderLine->conversion_factor, 8);
                $prepared[] = [...compact('invoiceLine', 'orderLine', 'quantity', 'baseQuantity', 'discount', 'tax', 'gross'),
                    'discount_type' => $orderLine->discount_type,
                    'discount_value' => $orderLine->discount_type === 'fixed' ? $this->amounts->subtract($discount, $headerDiscount) : $orderLine->discount_value,
                    'header_discount_amount' => $headerDiscount];
            }

            $headerDiscountInputs = [];
            if ($recalculateDirectDiscounts) {
                $headerDiscountType = array_key_exists('discount_type', $discountInputs) ? $discountInputs['discount_type'] : $locked->discount_type;
                $headerDiscountValue = array_key_exists('discount_value', $discountInputs) ? $discountInputs['discount_value'] : ($headerDiscountType === null ? '0' : ($locked->discount_value ?? '0'));
                $calculated = app(SalesOrderDiscountService::class)->calculate($prepared, $headerDiscountType, $headerDiscountValue);
                $prepared = array_map(function (array $row): array {
                    $row['discount'] = $row['discount_amount'];
                    $row['tax'] = $row['tax_amount'];
                    $row['gross'] = $this->amounts->unitPriceTotal($row['quantity'], $row['unit_price']);
                    if ($row['price_list_line_id']) {
                        $this->priceLists->assertOrderDiscountWithinSnapshot($row);
                    }

                    return $row;
                }, $calculated['lines']);
                $headerDiscountInputs = collect($calculated)->except('lines')->all();
            } elseif (! $isDirectInvoice) {
                $headerAmount = $this->amounts->sum(array_column($prepared, 'header_discount_amount'));
                $headerDiscountInputs = ['header_discount_amount' => $headerAmount,
                    'discount_value' => $locked->discount_type === 'fixed' ? $headerAmount : $locked->discount_value];
            }

            $subtotal = $this->amounts->sum(array_column($prepared, 'gross'));
            $discount = $this->amounts->sum(array_column($prepared, 'discount'));
            $tax = $this->amounts->sum(array_column($prepared, 'tax'));
            $total = $this->amounts->add($this->amounts->subtract($subtotal, $discount), $tax);
            if ($this->amounts->compare($this->amounts->sum(array_column($schedules, 'amount')), $total) !== 0) {
                throw new DomainException(__('Corrected payment schedules must equal the corrected invoice total.'));
            }

            $orderLineChanges = [];
            foreach ($prepared as $row) {
                $quantityDelta = $this->amounts->subtract($row['quantity'], $row['invoiceLine']->quantity, 8);
                $baseDelta = $this->amounts->subtract($row['baseQuantity'], $row['invoiceLine']->base_quantity, 8);
                if ($row['orderLine'] !== null) {
                    $orderLineId = (int) $row['orderLine']->getKey();
                    $orderLineChanges[$orderLineId] ??= ['line' => $row['orderLine'], 'quantity_delta' => '0', 'base_delta' => '0'];
                    $orderLineChanges[$orderLineId]['quantity_delta'] = bcadd($orderLineChanges[$orderLineId]['quantity_delta'], $quantityDelta, 8);
                    $orderLineChanges[$orderLineId]['base_delta'] = bcadd($orderLineChanges[$orderLineId]['base_delta'], $baseDelta, 8);
                }
                $row['invoiceLine']->update([
                    'quantity' => $row['quantity'], 'base_quantity' => $row['baseQuantity'],
                    'discount_amount' => $row['discount'], 'tax_amount' => $row['tax'],
                    'line_total' => $this->amounts->add($this->amounts->subtract($row['gross'], $row['discount']), $row['tax']),
                    ...($recalculateDirectDiscounts || ! $isDirectInvoice ? collect($row)->only(['discount_type', 'discount_value', 'header_discount_amount', 'tax_rate', 'tax_calculation_basis'])->all() : []),
                ]);
            }
            foreach ($orderLineChanges as $change) {
                $correctedInvoiced = bcadd((string) $change['line']->invoiced_quantity, $change['quantity_delta'], 8);
                $correctedBaseInvoiced = bcadd((string) $change['line']->invoiced_base_quantity, $change['base_delta'], 8);
                if (bccomp($correctedInvoiced, '0', 8) < 0 || bccomp($correctedBaseInvoiced, '0', 8) < 0) {
                    throw new DomainException(__('The sales order invoiced quantity is less than the invoice quantity.'));
                }
                $change['line']->forceFill([
                    'invoiced_quantity' => $correctedInvoiced,
                    'invoiced_base_quantity' => $correctedBaseInvoiced,
                ])->save();
            }
            if ($sourceRequest !== null && $requestLineChanges !== []) {
                foreach ($requestLineChanges as $change) {
                    $change['line']->forceFill(['converted_quantity' => $change['converted_after']])->save();
                }
                $previousStatus = (string) $sourceRequest->status;
                $nextStatus = $previousStatus;
                if (in_array($previousStatus, [SalesRequest::StatusApproved, 'partially_converted', 'converted'], true)) {
                    $hasConverted = $sourceRequest->lines()->where('converted_quantity', '>', 0)->exists();
                    $hasRemaining = $sourceRequest->lines()->whereColumn('converted_quantity', '<', 'quantity')->exists();
                    $nextStatus = ! $hasConverted ? SalesRequest::StatusApproved : ($hasRemaining ? 'partially_converted' : 'converted');
                }
                $sourceRequest->forceFill([
                    'status' => $nextStatus,
                    'status_history' => [
                        ...($sourceRequest->status_history ?? []),
                        [
                            'event' => 'conversion_amended',
                            'from' => $previousStatus,
                            'to' => $nextStatus,
                            'at' => now()->toIso8601String(),
                            'by' => auth()->id(),
                            'invoice' => $locked->doc_num,
                            'lines' => collect($requestLineChanges)->map(fn (array $change): array => [
                                'source_line_public_id' => $change['line']->public_id,
                                'converted_before' => $change['converted_before'],
                                'converted_after' => $change['converted_after'],
                            ])->all(),
                        ],
                    ],
                ])->save();
                $this->audit->record($sourceRequest, 'sales_request.conversion_amended', [
                    'invoice' => $locked->doc_num,
                    'from' => $previousStatus,
                    'to' => $nextStatus,
                    'lines' => collect($requestLineChanges)->map(fn (array $change): array => [
                        'source_line_public_id' => $change['line']->public_id,
                        'converted_before' => $change['converted_before'],
                        'converted_after' => $change['converted_after'],
                    ])->all(),
                ]);
            }

            $locked->paymentSchedules()->delete();
            foreach ($schedules as $index => $schedule) {
                $locked->paymentSchedules()->create([...$schedule, 'sequence' => $index + 1]);
            }
            $locked->update([
                'subtotal_amount' => $subtotal, 'discount_amount' => $discount,
                ...$headerDiscountInputs,
                'taxable_amount' => $this->amounts->subtract($subtotal, $discount),
                ...app(SalesWithholdingService::class)->calculate($total, $withholdingRate ?? $locked->withholding_rate ?? '0', $withholdingBasis ?? $locked->withholding_basis, $this->amounts->subtract($subtotal, $discount)),
                'tax_amount' => $tax, 'total_amount' => $total, 'remaining_amount' => $total,
                'due_date' => collect($schedules)->max('due_date'), 'updated_by' => auth()->id(),
            ]);
            $this->audit->record($locked, 'customer_invoice.amended', ['posting_revision' => $locked->posting_revision]);

            return $locked->refresh()->load(['lines', 'paymentSchedules']);
        });
    }

    public function deleteDraft(CustomerInvoice $invoice): void
    {
        if ($invoice->status === CustomerInvoice::StatusReopened) {
            Gate::authorize('customer_invoices.cancel');
        }
        $this->finalizeUnusedDraft($invoice);
    }

    public function restoreArchived(CustomerInvoice $invoice): CustomerInvoice
    {
        Gate::authorize('customer_invoices.restore');

        return DB::transaction(function () use ($invoice): CustomerInvoice {
            Customer::query()->where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->customer_id);
            $locked = CustomerInvoice::onlyTrashed()->with('lines')->lockForUpdate()->findOrFail($invoice->getKey());
            $this->assertReopenContext($locked);
            $nativeArchive = DB::table('activity_log')->where('company_id', $locked->company_id)
                ->where('subject_type', CustomerInvoice::class)->where('subject_id', $locked->id)
                ->where('event', 'customer_invoice.deleted')->latest('id')->first();
            $settledHistorical = $locked->status === CustomerInvoice::StatusCancelled
                && (int) $locked->posting_revision > 0
                && app(DocumentOwnerEffectProofService::class)->salesInvoiceIsSettled($locked);
            $unusedDraft = $locked->document_type === CustomerInvoice::TypeInvoice
                && $locked->status === CustomerInvoice::StatusDraft && $locked->isEditable()
                && bccomp((string) $locked->paid_amount, '0', 4) === 0 && bccomp((string) $locked->credited_amount, '0', 4) === 0
                && bccomp((string) $locked->applied_advance_amount, '0', 4) === 0 && $locked->electronic_invoice_uuid === null
                && $locked->electronic_invoice_submitted_at === null
                && in_array($locked->electronic_invoice_status, ['not_configured', 'draft', 'rejected'], true)
                && ! $locked->issueOrder()->exists() && ! $locked->deliveries()->withTrashed()->exists()
                && ! $locked->deliveryReceipts()->exists() && ! $locked->returns()->withTrashed()->exists()
                && ! $locked->creditNotes()->withTrashed()->exists() && ! $locked->allocations()->exists()
                && ! $locked->appliedCredits()->exists() && ! $locked->withholdingSettlements()->exists()
                && ! $locked->electronicInvoiceSubmissions()->exists();
            if (! $nativeArchive || (! $settledHistorical && ! $unusedDraft)
                || DB::table('activity_log')->where('company_id', $locked->company_id)
                    ->where('subject_type', CustomerInvoice::class)->where('subject_id', $locked->id)
                    ->where('event', 'customer_invoice.restored')->where('id', '>', $nativeArchive->id)->exists()
                || CustomerInvoice::query()->where('company_id', $locked->company_id)
                    ->where('financial_period_id', $locked->financial_period_id)->where('doc_num', $locked->doc_num)->exists()) {
                throw new DomainException(__('cancellation_review.archive_restore_ineligible'));
            }
            $restoredSourceLines = [];
            if ($unusedDraft) {
                if ($locked->sales_order_id !== null && in_array($locked->source_type, [null, 'direct', 'sales_order'], true)
                    && ($locked->source_id === null || (int) $locked->source_id === (int) $locked->sales_order_id)) {
                    $restoredSourceLines = $this->restoreOrderDraftQuantities($locked);
                } elseif ($locked->source_type === 'sales_request' && $locked->source_id !== null
                    && $locked->sales_order_id === null && $locked->delivery_document_id === null) {
                    $restoredSourceLines = $this->restoreRequestDraftQuantities($locked, (int) $nativeArchive->id);
                } elseif ($locked->source_type !== 'direct' || $locked->source_id !== null || $locked->sales_order_id !== null
                    || $locked->delivery_document_id !== null
                    || $locked->lines->contains(fn (CustomerInvoiceLine $line): bool => $line->sales_order_line_id !== null || $line->delivery_line_id !== null)) {
                    throw new DomainException(__('cancellation_review.archive_restore_ineligible'));
                }
            }
            $locked->restore();
            $locked->forceFill(['deleted_by' => null, 'updated_by' => auth()->id()])->save();
            $this->audit->record($locked, 'customer_invoice.restored', ['restored_as_cancelled' => $settledHistorical,
                'archive_event_id' => $nativeArchive->id, 'restored_source_lines' => $restoredSourceLines]);

            return $locked->refresh();
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    private function restoreOrderDraftQuantities(CustomerInvoice $invoice): array
    {
        Gate::authorize('sales_orders.view');
        Gate::authorize('sales_orders.invoice');
        $order = SalesOrder::query()->where('company_id', $invoice->company_id)->where('branch_id', $invoice->branch_id)
            ->lockForUpdate()->findOrFail($invoice->sales_order_id);
        $this->assertRestoreSourceScope($invoice, $order);
        $declinedClosure = $order->status === SalesOrder::StatusClosed && $order->lines()->where('declined_quantity', '>', 0)->exists();
        if ((! in_array($order->status, [SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled, SalesOrder::StatusFulfilled], true) && ! $declinedClosure)
            || $invoice->lines->isEmpty() || $invoice->lines->contains(fn (CustomerInvoiceLine $line): bool => $line->sales_order_line_id === null)) {
            throw new DomainException(__('cancellation_review.archive_restore_source_changed'));
        }
        if ($invoice->delivery_document_id !== null) {
            InventoryDocument::query()->where('company_id', $invoice->company_id)->where('branch_id', $invoice->branch_id)
                ->where('document_type', InventoryDocument::TypeSalesDelivery)->where('status', InventoryDocument::StatusPosted)
                ->where('source_document_id', $order->id)->lockForUpdate()->findOrFail($invoice->delivery_document_id);
        }
        $restored = [];
        $deliveryQuantities = [];
        foreach ($invoice->lines->sortBy('id')->groupBy('sales_order_line_id') as $sourceId => $lines) {
            $source = $order->lines()->lockForUpdate()->findOrFail($sourceId);
            $active = CustomerInvoiceLine::query()->where('sales_order_line_id', $source->id)
                ->whereHas('invoice', fn ($query) => $query->where('company_id', $invoice->company_id)->where('branch_id', $invoice->branch_id)
                    ->where('document_type', CustomerInvoice::TypeInvoice)->where('status', '<>', CustomerInvoice::StatusCancelled)
                    ->where('posting_status', '<>', 'reversed')->whereDoesntHave('creditNotes', fn ($credit) => $credit
                    ->where('source_type', CustomerInvoiceCorrection::class)->where('posting_status', 'posted')))
                ->selectRaw('COALESCE(SUM(quantity), 0) AS owned_quantity, COALESCE(SUM(base_quantity), 0) AS owned_base_quantity,
                    COALESCE(SUM(discount_amount), 0) AS owned_discount, COALESCE(SUM(tax_amount), 0) AS owned_tax,
                    COALESCE(SUM(header_discount_amount), 0) AS owned_header_discount,
                    COALESCE(SUM(line_total + discount_amount - tax_amount), 0) AS owned_gross')->first();
            if (bccomp((string) $source->invoiced_quantity, (string) $active->owned_quantity, 8) !== 0
                || bccomp((string) $source->invoiced_base_quantity, (string) $active->owned_base_quantity, 8) !== 0) {
                throw new DomainException(__('cancellation_review.archive_restore_source_reconcile'));
            }
            $quantity = $lines->reduce(fn (string $total, CustomerInvoiceLine $line): string => bcadd($total, $line->quantity, 8), '0');
            $baseQuantity = $lines->reduce(fn (string $total, CustomerInvoiceLine $line): string => bcadd($total, $line->base_quantity, 8), '0');
            $this->amounts->assertNotGreaterThan($quantity, $source->remainingInvoiceQuantity(), __('Invoice quantity exceeds the remaining approved order quantity.'));
            $netBaseQuantity = bcsub((string) $source->invoiced_base_quantity, (string) $source->remainder_credited_base_quantity, 8);
            $this->amounts->assertNotGreaterThan($baseQuantity, bcsub($source->effectiveBaseQuantity(), bccomp($netBaseQuantity, '0', 8) < 0 ? '0' : $netBaseQuantity, 8), __('Invoice quantity exceeds the remaining approved order quantity.'));
            foreach ($lines as $line) {
                if ((int) $line->product_id !== (int) $source->product_id || (int) $line->unit_id !== (int) $source->unit_id
                    || (isset($line->source_snapshot['sales_order_line_public_id']) && $line->source_snapshot['sales_order_line_public_id'] !== $source->public_id)
                    || bccomp($line->quantity, '0', 8) <= 0 || bccomp($source->conversion_factor, '0', 8) <= 0
                    || bccomp($line->conversion_factor, $source->conversion_factor, 8) !== 0
                    || bccomp($line->base_quantity, bcmul($line->quantity, $source->conversion_factor, 8), 8) !== 0
                    || bccomp($line->unit_price, $source->unit_price, 8) !== 0
                    || ($line->tax_rate !== null && bccomp($line->tax_rate, $source->tax_rate ?? '0', 4) !== 0)) {
                    throw new DomainException(__('cancellation_review.archive_restore_source_changed'));
                }
                if ($line->delivery_line_id !== null) {
                    $deliveryLine = InventoryDocumentLine::query()->with('document')->where('source_line_type', SalesOrderLine::class)
                        ->where('source_line_id', $source->id)->lockForUpdate()->findOrFail($line->delivery_line_id);
                    $delivery = $deliveryLine->document;
                    if (! $delivery || (int) $delivery->company_id !== (int) $invoice->company_id || (int) $delivery->branch_id !== (int) $invoice->branch_id
                        || $delivery->document_type !== InventoryDocument::TypeSalesDelivery || $delivery->status !== InventoryDocument::StatusPosted
                        || (int) $delivery->source_document_id !== (int) $order->id) {
                        throw new DomainException(__('cancellation_review.archive_restore_source_changed'));
                    }
                    $billed = (string) CustomerInvoiceLine::query()->where('delivery_line_id', $deliveryLine->id)
                        ->whereHas('invoice', fn ($query) => $query->where('company_id', $invoice->company_id)->where('branch_id', $invoice->branch_id)
                            ->where('document_type', CustomerInvoice::TypeInvoice)->where('status', '<>', CustomerInvoice::StatusCancelled))->sum('quantity');
                    $returned = (string) SalesReturnLine::query()->where('delivery_line_id', $deliveryLine->id)->whereNull('customer_invoice_line_id')
                        ->whereHas('salesReturn', fn ($query) => $query->where('company_id', $invoice->company_id)->where('branch_id', $invoice->branch_id)
                            ->where('status', '<>', SalesReturn::StatusCancelled))->sum('quantity');
                    $deliveryQuantities[$deliveryLine->id] = bcadd($deliveryQuantities[$deliveryLine->id] ?? '0', $line->quantity, 8);
                    $this->amounts->assertNotGreaterThan($deliveryQuantities[$deliveryLine->id], bcsub(bcsub($deliveryLine->transaction_quantity, $billed, 8), $returned, 8), __('Invoice quantity exceeds the selected delivery line.'));
                }
            }
            $totals = ['discount_amount' => ['owned_discount', $source->discount_amount], 'tax_amount' => ['owned_tax', $source->tax_amount],
                'header_discount_amount' => ['owned_header_discount', $source->header_discount_amount ?? '0']];
            foreach ($totals as $field => [$ownedField, $sourceTotal]) {
                $this->amounts->assertNotGreaterThan(bcadd((string) $active->{$ownedField}, $this->amounts->sum($lines->pluck($field)), 4), $sourceTotal, __('sales_ui.invoice_source_amount_exceeded'));
            }
            $gross = $lines->reduce(fn (string $total, CustomerInvoiceLine $line): string => bcadd($total, bcsub(bcadd($line->line_total, $line->discount_amount, 4), $line->tax_amount, 4), 4), '0');
            $this->amounts->assertNotGreaterThan(bcadd((string) $active->owned_gross, $gross, 4), bcsub(bcadd($source->line_total, $source->discount_amount, 4), $source->tax_amount, 4), __('sales_ui.invoice_source_amount_exceeded'));
            $beforeQuantity = (string) $source->invoiced_quantity;
            $beforeBaseQuantity = (string) $source->invoiced_base_quantity;
            $source->forceFill(['invoiced_quantity' => bcadd($beforeQuantity, $quantity, 8), 'invoiced_base_quantity' => bcadd($beforeBaseQuantity, $baseQuantity, 8)])->save();
            $restored[] = ['owner_type' => SalesOrderLine::class, 'owner_id' => $source->id, 'invoice_line_ids' => $lines->pluck('id')->all(),
                'quantity' => $quantity, 'base_quantity' => $baseQuantity, 'quantity_before' => $beforeQuantity,
                'quantity_after' => (string) $source->invoiced_quantity, 'base_quantity_before' => $beforeBaseQuantity,
                'base_quantity_after' => (string) $source->invoiced_base_quantity, 'release_proof' => 'native_archive_and_active_owner_reconciliation'];
        }

        return $restored;
    }

    /** @return list<array<string, mixed>> */
    private function restoreRequestDraftQuantities(CustomerInvoice $invoice, int $archiveEventId): array
    {
        Gate::authorize('sales_requests.view');
        Gate::authorize('customer_invoices.create');
        $source = SalesRequest::query()->where('company_id', $invoice->company_id)->where('branch_id', $invoice->branch_id)
            ->lockForUpdate()->findOrFail($invoice->source_id);
        $this->assertRestoreSourceScope($invoice, $source);
        if (! in_array($source->status, [SalesRequest::StatusApproved, 'partially_converted'], true) || $invoice->lines->isEmpty()) {
            throw new DomainException(__('cancellation_review.archive_restore_source_changed'));
        }
        $lastRestoreId = (int) DB::table('activity_log')->where('company_id', $invoice->company_id)->where('subject_type', CustomerInvoice::class)
            ->where('subject_id', $invoice->id)->where('event', 'customer_invoice.restored')->where('id', '<', $archiveEventId)->max('id');
        $release = DB::table('activity_log')->where('company_id', $invoice->company_id)->where('subject_type', SalesRequest::class)
            ->where('subject_id', $source->id)->where('event', 'sales_request.conversion_reversed')
            ->where('properties->invoice', $invoice->doc_num)->where('id', '>', $lastRestoreId)->where('id', '<', $archiveEventId)->latest('id')->first();
        $proof = $release ? json_decode($release->properties, true) : null;
        if (! is_array($proof) || ! is_array($proof['lines'] ?? null)
            || (isset($proof['invoice_id']) && (int) $proof['invoice_id'] !== (int) $invoice->id)
            || (! isset($proof['invoice_id']) && CustomerInvoice::withTrashed()->where('company_id', $invoice->company_id)
                ->where('doc_num', $invoice->doc_num)->where('source_type', 'sales_request')->where('source_id', $source->id)->where('id', '<>', $invoice->id)->exists())
            || count($proof['lines']) !== $invoice->lines->count()) {
            throw new DomainException(__('cancellation_review.archive_restore_release_unproven'));
        }
        $sourceLines = $source->lines()->orderBy('id')->lockForUpdate()->get();
        $restored = [];
        $seen = [];
        foreach ($invoice->lines->sortBy('id') as $line) {
            $publicId = $line->source_snapshot['sales_request_line_public_id'] ?? null;
            $sourceLine = $sourceLines->firstWhere('public_id', $publicId);
            $matchingProof = array_values(array_filter($proof['lines'], fn (mixed $row): bool => is_array($row) && ($row['source_line_public_id'] ?? null) === $publicId));
            if (! $sourceLine instanceof SalesRequestLine || in_array($sourceLine->id, $seen, true)
                || $line->sales_order_line_id !== null || $line->delivery_line_id !== null
                || (isset($line->source_snapshot['sales_request_line_id']) && (int) $line->source_snapshot['sales_request_line_id'] !== (int) $sourceLine->id)
                || (! isset($line->source_snapshot['sales_request_line_id']) && $sourceLines->where('product_id', $line->product_id)->where('unit_id', $line->unit_id)->count() !== 1)
                || (int) $sourceLine->product_id !== (int) $line->product_id || (int) $sourceLine->unit_id !== (int) $line->unit_id
                || bccomp($line->quantity, '0', 8) <= 0 || bccomp($line->conversion_factor, '0', 8) <= 0
                || bccomp($line->conversion_factor, $sourceLine->conversion_factor, 8) !== 0
                || bccomp($line->base_quantity, bcmul($line->quantity, $line->conversion_factor, 8), 8) !== 0
                || count($matchingProof) !== 1 || ! isset($matchingProof[0]['quantity'], $matchingProof[0]['converted_before'], $matchingProof[0]['converted_after'])
                || bccomp((string) $matchingProof[0]['quantity'], $line->quantity, 8) !== 0
                || bccomp(bcsub((string) $matchingProof[0]['converted_before'], (string) $matchingProof[0]['converted_after'], 8), $line->quantity, 8) !== 0) {
                throw new DomainException(__('cancellation_review.archive_restore_release_unproven'));
            }
            $seen[] = $sourceLine->id;
            if (bccomp($sourceLine->converted_quantity, '0', 8) < 0 || bccomp($sourceLine->converted_quantity, $sourceLine->quantity, 8) > 0) {
                throw new DomainException(__('cancellation_review.archive_restore_source_reconcile'));
            }
            $this->amounts->assertNotGreaterThan($line->quantity, $sourceLine->remainingQuantity(), __('Invoice quantity exceeds the remaining request quantity.'));
            $before = (string) $sourceLine->converted_quantity;
            $sourceLine->forceFill(['converted_quantity' => bcadd($before, $line->quantity, 8)])->save();
            $restored[] = ['owner_type' => SalesRequestLine::class, 'owner_id' => $sourceLine->id, 'invoice_line_id' => $line->id,
                'source_line_public_id' => $sourceLine->public_id, 'quantity' => (string) $line->quantity,
                'converted_before' => $before, 'converted_after' => (string) $sourceLine->converted_quantity, 'release_event_id' => $release->id];
        }
        $beforeStatus = (string) $source->status;
        $nextStatus = $source->lines()->whereColumn('converted_quantity', '<', 'quantity')->exists() ? 'partially_converted' : 'converted';
        $source->forceFill(['status' => $nextStatus, 'status_history' => [...($source->status_history ?? []),
            ['event' => 'conversion_restored', 'from' => $beforeStatus, 'to' => $nextStatus, 'at' => now()->toIso8601String(),
                'by' => auth()->id(), 'invoice' => $invoice->doc_num, 'invoice_id' => $invoice->id, 'lines' => $restored]]])->save();
        $this->audit->record($source, 'sales_request.conversion_restored', ['invoice' => $invoice->doc_num, 'invoice_id' => $invoice->id,
            'from' => $beforeStatus, 'to' => $nextStatus, 'lines' => $restored]);

        return $restored;
    }

    private function assertRestoreSourceScope(CustomerInvoice $invoice, SalesOrder|SalesRequest $source): void
    {
        if ((int) $source->company_id !== (int) $invoice->company_id || (int) $source->branch_id !== (int) $invoice->branch_id
            || (int) $source->customer_id !== (int) $invoice->customer_id || (int) $source->currency_id !== (int) $invoice->currency_id) {
            throw new DomainException(__('cancellation_review.archive_restore_source_changed'));
        }
        $company = Company::query()->findOrFail($invoice->company_id);
        abort_unless(app(OperatingScopeAccessService::class)->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])
            ->where('financial_periods.id', $source->financial_period_id)->exists(), 404);
    }

    public function cancelDraft(CustomerInvoice $invoice, string $reason): CustomerInvoice
    {
        Gate::authorize('customer_invoices.cancel');
        if (blank($reason) || mb_strlen($reason) > 2000) {
            throw new DomainException(__('A reason is required for this action.'));
        }

        return $this->finalizeUnusedDraft($invoice, trim($reason));
    }

    private function finalizeUnusedDraft(CustomerInvoice $invoice, ?string $reason = null): CustomerInvoice
    {
        return DB::transaction(function () use ($invoice, $reason): CustomerInvoice {
            Customer::query()->where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->customer_id);
            $locked = CustomerInvoice::query()
                ->with(['lines', 'deliveries', 'allocations', 'returns', 'creditNotes'])
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());
            $this->assertReopenContext($locked);

            if ($reason !== null && $locked->status === CustomerInvoice::StatusCancelled
                && (int) $locked->posting_revision > 0
                && app(DocumentOwnerEffectProofService::class)->salesInvoiceIsSettled($locked)) {
                return $locked;
            }
            if ($reason !== null && $locked->status === CustomerInvoice::StatusCancelled
                && $locked->posting_status === 'cancelled' && $locked->journal_entry_id === null
                && $locked->reversal_journal_entry_id === null && $locked->cancelled_at !== null
                && $locked->cancelled_by !== null && filled($locked->cancel_reason)
                && DB::table('activity_log')->where('company_id', $locked->company_id)
                    ->where('subject_type', CustomerInvoice::class)->where('subject_id', $locked->id)
                    ->where('event', 'customer_invoice.draft_cancelled')->exists()) {
                return $locked;
            }

            $reopened = $locked->status === CustomerInvoice::StatusReopened;
            if ($reason === null && $reopened) {
                Gate::authorize('customer_invoices.cancel');
            }
            if (! ($reason === null ? $locked->canDeleteDraft() : $locked->canCancelDraft())
                || ($locked->journal_entry_id !== null && ! ($reopened && app(DocumentOwnerEffectProofService::class)->reopenedInvoicePostingIsReversed($locked)))
                || $locked->deliveries->isNotEmpty()
                || $locked->allocations->isNotEmpty()
                || $locked->returns->isNotEmpty()
                || $locked->creditNotes->isNotEmpty()) {
                throw new DomainException($reason === null ? __('Only an unused draft sales invoice can be deleted.') : __('sales_ui.draft_cancel_ineligible'));
            }

            $releasedOrderLines = [];
            $reversedLines = [];
            foreach ($locked->lines as $line) {
                if ($line->sales_order_line_id !== null) {
                    $orderLine = SalesOrderLine::query()->with('order')->lockForUpdate()->findOrFail($line->sales_order_line_id);
                    if ((int) $orderLine->sales_order_id !== (int) $locked->sales_order_id
                        || (int) $orderLine->order?->company_id !== (int) $locked->company_id
                        || (int) $orderLine->order?->branch_id !== (int) $locked->branch_id
                        || (int) $orderLine->product_id !== (int) $line->product_id || (int) $orderLine->unit_id !== (int) $line->unit_id) {
                        throw new DomainException(__('The document is outside the active operating context.'));
                    }
                    $remainingQuantity = bcsub((string) $orderLine->invoiced_quantity, (string) $line->quantity, 8);
                    $remainingBaseQuantity = bcsub((string) $orderLine->invoiced_base_quantity, (string) $line->base_quantity, 8);
                    if (bccomp($remainingQuantity, '0', 8) < 0 || bccomp($remainingBaseQuantity, '0', 8) < 0) {
                        throw new DomainException(__('The sales order invoiced quantity is less than the invoice quantity.'));
                    }
                    $releasedOrderLines[] = ['invoice_line_id' => $line->id, 'order_line_id' => $orderLine->id,
                        'quantity' => (string) $line->quantity, 'base_quantity' => (string) $line->base_quantity,
                        'quantity_before' => (string) $orderLine->invoiced_quantity, 'quantity_after' => $remainingQuantity,
                        'base_quantity_before' => (string) $orderLine->invoiced_base_quantity, 'base_quantity_after' => $remainingBaseQuantity];
                    $orderLine->forceFill([
                        'invoiced_quantity' => $remainingQuantity,
                        'invoiced_base_quantity' => $remainingBaseQuantity,
                    ])->save();
                }
            }

            if ($locked->source_type === 'sales_request' && $locked->source_id !== null) {
                $source = SalesRequest::withTrashed()->with('lines')->lockForUpdate()->find($locked->source_id);
                if (! $source instanceof SalesRequest || (int) $source->company_id !== (int) $locked->company_id
                    || (int) $source->branch_id !== (int) $locked->branch_id || (int) $source->customer_id !== (int) $locked->customer_id
                    || (int) $source->currency_id !== (int) $locked->currency_id) {
                    throw new DomainException(__('The sales request source for this invoice is missing.'));
                }

                $previousStatus = (string) $source->status;
                $previousClosedAt = $source->closed_at?->toIso8601String();
                $seenSourceLineIds = [];
                foreach ($locked->lines as $line) {
                    $sourceLinePublicId = $line->source_snapshot['sales_request_line_public_id'] ?? null;
                    $sourceLine = $source->lines->firstWhere('public_id', $sourceLinePublicId);
                    if (! $sourceLine instanceof SalesRequestLine
                        || (isset($line->source_snapshot['sales_request_line_id'])
                            && (int) $line->source_snapshot['sales_request_line_id'] !== (int) $sourceLine->getKey())
                        || (int) $sourceLine->product_id !== (int) $line->product_id
                        || (int) $sourceLine->unit_id !== (int) $line->unit_id
                        || (! isset($line->source_snapshot['sales_request_line_id'])
                            && $source->lines->where('product_id', $line->product_id)
                                ->where('unit_id', $line->unit_id)->count() !== 1)
                        || in_array($sourceLine->getKey(), $seenSourceLineIds, true)) {
                        throw new DomainException(__('The sales request source line for this invoice is missing or invalid.'));
                    }
                    $seenSourceLineIds[] = $sourceLine->getKey();

                    $remainingConverted = bcsub((string) $sourceLine->converted_quantity, (string) $line->quantity, 8);
                    if (bccomp($remainingConverted, '0', 8) < 0) {
                        throw new DomainException(__('The sales request converted quantity is less than the invoice quantity.'));
                    }
                    $reversedLines[] = [
                        'source_line_public_id' => $sourceLine->public_id,
                        'quantity' => (string) $line->quantity,
                        'converted_before' => (string) $sourceLine->converted_quantity,
                        'converted_after' => $remainingConverted,
                    ];
                    $sourceLine->forceFill(['converted_quantity' => $remainingConverted])->save();
                }

                $nextStatus = $previousStatus;
                if (in_array($previousStatus, [SalesRequest::StatusApproved, 'partially_converted', 'converted'], true)) {
                    $hasConverted = $source->lines()->where('converted_quantity', '>', 0)->exists();
                    $hasRemaining = $source->lines()->whereColumn('converted_quantity', '<', 'quantity')->exists();
                    $nextStatus = ! $hasConverted ? SalesRequest::StatusApproved : ($hasRemaining ? 'partially_converted' : 'converted');
                }
                $source->forceFill([
                    'status' => $nextStatus,
                    'status_history' => [
                        ...($source->status_history ?? []),
                        [
                            'event' => 'conversion_reversed',
                            'from' => $previousStatus,
                            'to' => $nextStatus,
                            'at' => now()->toIso8601String(),
                            'by' => auth()->id(),
                            'invoice' => $locked->doc_num,
                            'invoice_id' => $locked->id,
                            'lines' => $reversedLines,
                        ],
                    ],
                ])->save();
                $this->audit->record($source, 'sales_request.conversion_reversed', [
                    'invoice' => $locked->doc_num,
                    'invoice_id' => $locked->id,
                    'from' => $previousStatus,
                    'to' => $nextStatus,
                    'closed_at_before' => $previousClosedAt,
                    'closed_at_after' => $source->closed_at?->toIso8601String(),
                    'lines' => $reversedLines,
                ]);
            }

            if ($reason !== null || $reopened) {
                $cancellationReason = $reason ?? __('cancellation_review.archive_reopened_reason');
                $locked->forceFill(['status' => CustomerInvoice::StatusCancelled, 'posting_status' => 'cancelled',
                    'is_closed' => true, 'remaining_amount' => '0', 'cancelled_by' => auth()->id(), 'cancelled_at' => now(),
                    'cancel_reason' => $cancellationReason, 'updated_by' => auth()->id()])->save();
                $this->audit->record($locked, $reopened ? 'customer_invoice.reopened_cancelled' : 'customer_invoice.draft_cancelled', [
                    'reason' => $cancellationReason, 'scope' => $reopened ? 'reopened_original_reversed' : 'unused_draft',
                    'source_quantities_released' => true, 'released_order_lines' => $releasedOrderLines]);
            }
            if ($reason === null) {
                $locked->forceFill(['deleted_by' => auth()->id()])->saveQuietly();
                $locked->delete();
                $this->audit->record($locked, 'customer_invoice.deleted', ['source_quantities_released' => true,
                    'released_order_lines' => $releasedOrderLines, 'released_request_lines' => $reversedLines]);
            }

            return $locked->refresh();
        });
    }

    public function reopen(CustomerInvoice $invoice, string $reason, ?FixedAssetDisposal $sourceDisposal = null): CustomerInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $sourceDisposal): CustomerInvoice {
            $issueOrder = SalesIssueOrder::query()->where('customer_invoice_id', $invoice->getKey())->lockForUpdate()->first();
            $locked = CustomerInvoice::query()->with(['returns', 'creditNotes', 'deliveries'])->lockForUpdate()->findOrFail($invoice->getKey());
            $this->assertReopenContext($locked);
            if ($locked->source_type === 'fixed_asset_disposal'
                && (! $sourceDisposal
                    || (int) $locked->source_id !== (int) $sourceDisposal->getKey()
                    || (int) $locked->company_id !== (int) $sourceDisposal->company_id
                    || $sourceDisposal->status !== FixedAssetDisposal::StatusPosted)) {
                throw new DomainException(__('Source-owned invoices must be corrected in their source workflow.'));
            }
            app(CustomerInvoiceBalanceService::class)->assertNoActiveWithholding($locked);
            if ($locked->withholdingSettlements()->whereIn('status', ['approved', 'reversed'])->exists()) {
                throw new DomainException(__('sales_ui.wht.preserve_invoice_history'));
            }
            if (blank($reason)) {
                throw new DomainException(__('A reason is required for this action.'));
            }
            if ($locked->posting_status !== 'posted' || $this->amounts->compare($locked->paid_amount, '0') > 0 || $this->amounts->compare($locked->credited_amount, '0') > 0) {
                throw new DomainException(__('Only an unsettled posted invoice may be reopened.'));
            }
            if ($locked->document_type !== CustomerInvoice::TypeInvoice
                || ($issueOrder !== null && $issueOrder->status !== SalesIssueOrder::StatusPending)) {
                throw new DomainException(__('An invoice with an issued sales issue order cannot be reopened.'));
            }
            if ((int) ($locked->issueOrder()->value('id') ?? 0) !== (int) ($issueOrder?->getKey() ?? 0)) {
                throw new DomainException(__('The invoice issue order changed while reopening; please retry.'));
            }
            if ($locked->deliveries->isNotEmpty() || $locked->returns->where('status', '<>', 'cancelled')->isNotEmpty() || $locked->creditNotes->isNotEmpty() || $locked->allocations()->whereHas('receipt', fn ($query) => $query->where('status', 'approved'))->exists()) {
                throw new DomainException(__('An invoice with a delivery, return, receipt, or credit note cannot be reopened.'));
            }
            if ($locked->electronic_invoice_uuid !== null || ! in_array($locked->electronic_invoice_status, ['not_configured', 'draft', 'rejected'], true)) {
                throw new DomainException(__('A submitted electronic invoice must be corrected through the tax-authority amendment workflow.'));
            }
            $issueOrder?->delete();
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

    public function cancelDirectService(CustomerInvoice $invoice, string $reason): CustomerInvoice
    {
        return DB::transaction(function () use ($invoice, $reason): CustomerInvoice {
            Customer::query()->where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->customer_id);
            $locked = CustomerInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $this->assertReopenContext($locked);
            if (blank($reason)) {
                throw new DomainException(__('A reason is required for this action.'));
            }
            if ($locked->document_type === CustomerInvoice::TypeInvoice
                && $locked->source_type === 'direct'
                && $locked->source_id === null
                && $locked->sales_order_id === null
                && $locked->status === CustomerInvoice::StatusCancelled
                && $locked->posting_status === 'cancelled'
                && $locked->cancelled_at !== null
                && $locked->cancelled_by !== null
                && filled($locked->cancel_reason)
                && $locked->reversal_journal_entry_id !== null
                && $locked->journalEntry?->reversed_entry_id === $locked->reversal_journal_entry_id
                && $locked->reversalJournalEntry?->source_type === 'customer_invoice_reversal_'.$locked->posting_revision
                && (int) $locked->reversalJournalEntry?->source_id === (int) $locked->getKey()
                && $locked->reversalJournalEntry?->is_posted
                && (int) $locked->reversalJournalEntry?->company_id === (int) $locked->company_id) {
                return $locked;
            }
            if (! $locked->canCancelDirectService()) {
                throw new DomainException(__('sales_ui.direct_service_cancel_ineligible'));
            }
            $revision = ((int) $locked->posting_revision) + 1;
            $reversal = $this->accounting->reverseInvoice($locked, trim($reason), $revision);
            $locked->update([
                'status' => CustomerInvoice::StatusCancelled, 'posting_status' => 'cancelled',
                'posting_revision' => $revision, 'is_closed' => true, 'remaining_amount' => '0',
                'reversal_journal_entry_id' => $reversal->getKey(),
                'cancelled_by' => auth()->id(), 'cancelled_at' => now(),
                'cancel_reason' => trim($reason), 'updated_by' => auth()->id(),
            ]);
            $this->audit->record($locked, 'customer_invoice.cancelled', [
                'reason' => trim($reason), 'journal_entry_id' => $locked->journal_entry_id,
                'reversal_journal_entry_id' => $reversal->getKey(), 'scope' => 'direct_service',
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    private function assertReopenContext(CustomerInvoice $invoice): void
    {
        $request = request();
        if (! $request->hasSession()) {
            throw new DomainException(__('operating_context.messages.required'));
        }

        $context = $this->operatingContext->snapshot($request);
        if (! $context['company_id'] || ! $context['branch_id'] || ! $context['financial_period_id']) {
            throw new DomainException(__('operating_context.messages.required'));
        }
        if ((int) $invoice->company_id !== (int) $context['company_id']
            || (int) $invoice->branch_id !== (int) $context['branch_id']
            || (int) $invoice->financial_period_id !== (int) $context['financial_period_id']) {
            throw new DomainException(__('The document is outside the active operating context.'));
        }

        $this->periods->resolveOpenForPostingDate(
            (int) $invoice->company_id,
            $invoice->invoice_date,
            (int) $invoice->financial_period_id,
            lockForUpdate: true,
        );
    }

    /**
     * @param  array<int, array{quantity: string, discount: string, tax: string, gross: string, header_discount_amount: string}>  $allocated
     * @return array{discount: string, tax: string, gross: string, header_discount_amount: string}
     */
    private function proratedAmounts(SalesOrderLine $line, string $quantity, array &$allocated, ?int $exceptInvoiceId = null): array
    {
        if (! isset($allocated[$line->id])) {
            $prior = CustomerInvoiceLine::query()->where('sales_order_line_id', $line->id)
                ->when($exceptInvoiceId, fn ($query) => $query->where('customer_invoice_id', '<>', $exceptInvoiceId))
                ->whereHas('invoice', fn ($query) => $query->where('document_type', CustomerInvoice::TypeInvoice)
                    ->where('status', '<>', CustomerInvoice::StatusCancelled)
                    ->whereDoesntHave('creditNotes', fn ($credit) => $credit->where('source_type', CustomerInvoiceCorrection::class)->where('posting_status', 'posted')))->get();
            $allocated[$line->id] = ['quantity' => $prior->reduce(fn ($sum, $row) => bcadd($sum, $row->quantity, 8), '0'),
                'discount' => $this->amounts->sum($prior->pluck('discount_amount')), 'tax' => $this->amounts->sum($prior->pluck('tax_amount')),
                'header_discount_amount' => $this->amounts->sum($prior->map(fn (CustomerInvoiceLine $row): string => $row->discount_type === null && bccomp($row->header_discount_amount ?? '0', '0', 4) === 0
                    ? $this->amounts->multiply($line->header_discount_amount ?? '0', bcdiv($row->quantity, $line->quantity, 16)) : ($row->header_discount_amount ?? '0'))),
                'gross' => $prior->reduce(fn ($sum, $row) => bcadd($sum, bcsub(bcadd($row->line_total, $row->discount_amount, 4), $row->tax_amount, 4), 4), '0')];
        }
        $state = &$allocated[$line->id];
        $newQuantity = bcadd($state['quantity'], $quantity, 8);
        $final = bccomp($newQuantity, $line->quantity, 8) === 0;
        $ratio = bcdiv($quantity, $line->quantity, 16);
        $totals = ['discount' => $line->discount_amount, 'tax' => $line->tax_amount, 'gross' => $this->amounts->subtract($this->amounts->add($line->line_total, $line->discount_amount), $line->tax_amount), 'header_discount_amount' => $line->header_discount_amount ?? '0'];
        $result = [];
        foreach ($totals as $key => $total) {
            $remaining = bcsub($total, $state[$key], 4);
            $slice = $key === 'gross' ? $this->amounts->unitPriceTotal($quantity, $line->unit_price) : $this->amounts->multiply($total, $ratio);
            if (bccomp($remaining, '0', 4) < 0) {
                throw new DomainException(__('sales_ui.invoice_source_amount_exceeded'));
            }
            $result[$key] = $final || bccomp($slice, $remaining, 4) > 0 ? $remaining : $slice;
            $state[$key] = bcadd($state[$key], $result[$key], 4);
        }
        $state['quantity'] = $newQuantity;

        return $result;
    }
}

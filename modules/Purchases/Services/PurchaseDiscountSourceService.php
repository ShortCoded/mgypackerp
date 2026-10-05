<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderLine;

class PurchaseDiscountSourceService
{
    public function __construct(private readonly PurchaseInvoiceCalculationService $calculator, private readonly NumericFormatService $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{calculation: array<string, mixed>, defaults: array<string, mixed>, inherited: bool}
     */
    public function calculate(array $data, ?PurchaseInvoice $invoice = null, bool $lock = false, bool $suggestDefaults = true): array
    {
        $lines = array_values($data['lines'] ?? []);
        $quotes = [];
        $snapshots = [];
        $defaults = ['lines' => $lines, 'header_discount_type' => $data['header_discount_type'] ?? null, 'header_discount_value' => $data['header_discount_value'] ?? '0'];
        $source = null;
        if (filled($data['purchase_order_doc_num'] ?? null)) {
            $context = app(OperatingContextService::class)->snapshot(request());
            $source = PurchaseOrder::query()->forCompany((int) $context['company_id'])
                ->where('doc_num', $data['purchase_order_doc_num'])->whereIn('status', [PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed])
                ->when($lock, fn ($query) => $query->lockForUpdate())->with(['lines.product', 'lines.unit'])->firstOrFail();
            if ($invoice && ((int) $invoice->company_id !== (int) $source->company_id || (int) $invoice->branch_id !== (int) $source->branch_id)) {
                throw new DomainException(__('purchase_invoices.messages.purchase_order_context_mismatch'));
            }
            $states = $this->allocated($source, $invoice?->getKey());
            $existing = $invoice?->lines->keyBy('public_id');
            foreach ($lines as $index => $input) {
                $line = $source->lines->firstWhere('public_id', $input['purchase_order_line_public_id'] ?? null);
                if (! $line instanceof PurchaseOrderLine) {
                    continue;
                }
                if (($input['product_doc_num'] ?? null) !== $line->product?->doc_num || ($input['unit_doc_num'] ?? null) !== $line->unit?->doc_num) {
                    throw new DomainException(__('purchase_invoices.messages.purchase_order_context_mismatch'));
                }
                $previous = $existing?->get($input['public_id'] ?? '');
                $snapshot = $previous?->source_discount_snapshot;
                $quantity = $this->numbers->normalizeToScale($input['quantity'] ?? 0, 8);
                $state = &$states[$line->id];
                $newQuantity = bcadd($state['quantity'], $quantity, 8);
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($newQuantity, (string) $line->ordered_quantity, 8) > 0) {
                    continue;
                }
                $totals = ['gross' => (string) $line->subtotal_amount, 'discount' => (string) $line->discount_amount,
                    'header' => (string) ($line->header_discount_amount ?? 0), 'tax' => (string) $line->tax_amount];
                $quote = $this->allocate((string) $line->ordered_quantity, $totals, $quantity, $state,
                    is_array($snapshot) && (int) $previous->purchase_order_line_id === (int) $line->id ? $snapshot : null);
                foreach ($totals as $key => $total) {
                    $state[$key] = bcadd($state[$key], $quote[$key], 4);
                }
                $state['quantity'] = $newQuantity;
                $quotes[$index] = $quote;
                $snapshots[$index] = $quote;
                $defaults['lines'][$index] = [...$input, 'discount_type' => $line->discount_type ?: 'fixed',
                    'discount_value' => $line->discount_type === 'percentage' ? (string) $line->discount_value : $quote['discount']];
            }
            $header = array_reduce($quotes, fn (string $sum, array $row): string => bcadd($sum, $row['header'], 4), '0.0000');
            $defaults['header_discount_type'] = $source->header_discount_type;
            $defaults['header_discount_value'] = $source->header_discount_type === 'percentage' ? (string) $source->header_discount_value : $header;
            if (! array_key_exists('header_discount_type', $data)) {
                $data['header_discount_type'] = $invoice?->header_discount_type ?? $defaults['header_discount_type'];
                $data['header_discount_value'] = $invoice?->header_discount_value ?? $defaults['header_discount_value'];
            }
        }
        if ($suggestDefaults) {
            foreach ($lines as $index => $line) {
                if (! empty($line['inherit_source_discount']) && isset($quotes[$index])) {
                    $lines[$index] = $defaults['lines'][$index];
                }
            }
            if (! empty($data['inherit_header_discount']) && $source) {
                $data['header_discount_type'] = $defaults['header_discount_type'];
                $data['header_discount_value'] = $defaults['header_discount_value'];
            }
        }
        $inherited = $source !== null && count($quotes) === count($lines) && $lines !== [];
        foreach ($lines as $index => $input) {
            $line = $source?->lines->firstWhere('public_id', $input['purchase_order_line_public_id'] ?? null);
            $expected = $defaults['lines'][$index];
            $inherited = $inherited && $line instanceof PurchaseOrderLine
                && ($input['discount_type'] ?? 'fixed') === $expected['discount_type']
                && bccomp((string) ($input['discount_value'] ?? 0), (string) $expected['discount_value'], 4) === 0
                && bccomp((string) ($input['unit_price'] ?? 0), (string) $line->unit_price, 8) === 0
                && bccomp((string) ($input['tax_rate'] ?? 0), (string) $line->tax_rate, 4) === 0;
        }
        $headerType = $data['header_discount_type'] ?? null;
        $headerValue = $data['header_discount_value'] ?? '0';
        $inherited = $inherited && (($headerType ?? 'fixed') === ($defaults['header_discount_type'] ?? 'fixed'))
            && bccomp((string) $headerValue, (string) $defaults['header_discount_value'], 4) === 0;
        $calculation = $this->calculator->calculate($lines, $headerType, $headerValue, $data['freight_amount'] ?? 0, $data['freight_tax_rate'] ?? 0, $inherited ? $quotes : []);
        foreach ($calculation['lines'] as $index => &$line) {
            $line['source_discount_snapshot'] = isset($snapshots[$index]) ? [...$snapshots[$index], 'inherited' => $inherited] : null;
        }
        unset($line);

        return ['calculation' => $calculation, 'defaults' => ['lines' => $lines, 'header_discount_type' => $headerType, 'header_discount_value' => $headerValue], 'inherited' => $inherited];
    }

    /**
     * @param  array{gross: string, discount: string, header: string, tax: string}  $totals
     * @param  array{quantity: string, gross: string, discount: string, header: string, tax: string}  $state
     * @param  array<string, mixed>|null  $snapshot
     * @return array{quantity: string, gross: string, discount: string, header: string, tax: string}
     */
    public function allocate(string $sourceQuantity, array $totals, string $quantity, array $state, ?array $snapshot = null): array
    {
        $newQuantity = bcadd($state['quantity'], $quantity, 8);
        if (bccomp($quantity, '0', 8) <= 0 || bccomp($newQuantity, $sourceQuantity, 8) > 0) {
            throw new DomainException(__('Selected quantity exceeds the supplier offer.'));
        }
        $quote = ['quantity' => $quantity];
        foreach ($totals as $key => $total) {
            $quote[$key] = is_array($snapshot) && bccomp((string) ($snapshot['quantity'] ?? 0), $quantity, 8) === 0
                ? (string) $snapshot[$key]
                : (bccomp($newQuantity, $sourceQuantity, 8) === 0
                    ? bcsub($total, $state[$key], 4)
                    : bcround(bcdiv(bcmul($total, $quantity, 20), $sourceQuantity, 20), 4));
            $remaining = bcsub($total, $state[$key], 4);
            if (bccomp($quote[$key], $remaining, 4) > 0) {
                $quote[$key] = $remaining;
            }
            if (bccomp($quote[$key], '0', 4) < 0 || bccomp($quote[$key], $total, 4) > 0) {
                throw new DomainException(__('purchase_invoices.messages.source_discount_allocation_invalid'));
            }
        }

        return $this->balanceDiscounts($quote, $totals, $state);
    }

    /**
     * @param  array{quantity: string, gross: string, discount: string, header: string, tax: string}  $quote
     * @param  array{gross: string, discount: string, header: string, tax: string}  $totals
     * @param  array{quantity: string, gross: string, discount: string, header: string, tax: string}  $state
     * @return array{quantity: string, gross: string, discount: string, header: string, tax: string}
     */
    private function balanceDiscounts(array $quote, array $totals, array $state): array
    {
        $remainingGross = bcsub($totals['gross'], $state['gross'], 4);
        $remainingDiscount = bcsub($totals['discount'], $state['discount'], 4);
        $remainingHeader = bcsub($totals['header'], $state['header'], 4);
        $remainingNet = bcsub(bcsub($remainingGross, $remainingDiscount, 4), $remainingHeader, 4);
        if (bccomp($remainingNet, '0', 4) < 0) {
            throw new DomainException(__('purchase_invoices.messages.source_discount_allocation_invalid'));
        }
        $budget = $quote['gross'];
        foreach (['discount', 'header'] as $key) {
            if (bccomp($quote[$key], $budget, 4) > 0) {
                $quote[$key] = $budget;
            }
            $budget = bcsub($budget, $quote[$key], 4);
        }
        $excessNet = bcsub($budget, $remainingNet, 4);
        foreach (['discount', 'header'] as $key) {
            if (bccomp($excessNet, '0', 4) <= 0) {
                break;
            }
            $available = bcsub(bcsub($totals[$key], $state[$key], 4), $quote[$key], 4);
            $extra = bccomp($excessNet, $available, 4) > 0 ? $available : $excessNet;
            $quote[$key] = bcadd($quote[$key], $extra, 4);
            $excessNet = bcsub($excessNet, $extra, 4);
        }

        return $quote;
    }

    /** @return array<int, array{quantity: string, gross: string, discount: string, header: string, tax: string}> */
    private function allocated(PurchaseOrder $order, ?int $exceptInvoiceId): array
    {
        $query = PurchaseInvoiceLine::query()->where('company_id', $order->company_id)->whereIn('purchase_order_line_id', $order->lines->modelKeys())
            ->when($exceptInvoiceId !== null, fn ($query) => $query->where('purchase_invoice_id', '<>', $exceptInvoiceId))
            ->whereHas('purchaseInvoice', fn ($query) => $query->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->whereNotIn('status', [PurchaseInvoice::StatusCancelled, 'reversed']));
        $columns = ['quantity', 'gross', 'discount', 'header', 'tax'];
        $grammar = DB::connection()->getQueryGrammar();
        $selects = ['purchase_order_line_id', 'SUM(CASE WHEN source_discount_snapshot IS NULL THEN quantity ELSE 0 END) AS legacy_quantity'];
        foreach ($columns as $column) {
            $selector = $grammar->wrap('source_discount_snapshot->'.$column);
            $selects[] = 'COALESCE(SUM(CAST('.$selector.' AS DECIMAL(30,8))), 0) AS '.$column;
        }
        $aggregates = $query->selectRaw(implode(', ', $selects))->groupBy('purchase_order_line_id')->get()->keyBy('purchase_order_line_id');
        $states = [];
        foreach ($order->lines as $line) {
            $row = $aggregates->get($line->id);
            $legacyQuantity = $this->numbers->normalizeToScale($row?->legacy_quantity ?? 0, 8);
            $state = ['quantity' => bcadd($this->numbers->normalizeToScale($row?->quantity ?? 0, 8), $legacyQuantity, 8)];
            foreach (['gross' => $line->subtotal_amount, 'discount' => $line->discount_amount, 'header' => $line->header_discount_amount ?? 0, 'tax' => $line->tax_amount] as $key => $total) {
                $legacy = bccomp($legacyQuantity, '0', 8) > 0 ? bcround(bcdiv(bcmul((string) $total, $legacyQuantity, 20), (string) $line->ordered_quantity, 20), 4) : '0.0000';
                $state[$key] = bcadd($this->numbers->normalizeToScale($row?->{$key} ?? 0, 4), $legacy, 4);
            }
            $states[$line->id] = $state;
        }

        return $states;
    }
}

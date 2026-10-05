<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\NumericFormatService;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Models\SupplierQuotationLine;
use Modules\Purchases\Models\SupplierSelection;
use Modules\Purchases\Models\SupplierSelectionLine;

class SupplierQuotationDiscountService
{
    public function __construct(private readonly NumericFormatService $numbers, private readonly PurchaseOrderCalculationService $orders, private readonly PurchaseDiscountSourceService $sources) {}

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function calculate(array $data, ?SupplierQuotation $draft = null): array
    {
        $lines = [];
        foreach (array_values($data['lines'] ?? []) as $input) {
            $previous = $draft?->lines->first(fn ($line) => in_array($input['source_line_public_id'] ?? $input['rfq_line_public_id'] ?? '', [$line->rfqLine?->public_id, $line->requisitionLine?->public_id, $line->purchaseOrderLine?->public_id], true));
            $type = filled($input['discount_type'] ?? null) ? $input['discount_type'] : (array_key_exists('discount_amount', $input) ? 'fixed' : ($previous?->discount_type ?: 'fixed'));
            $value = $input['discount_value'] ?? (array_key_exists('discount_amount', $input) ? $input['discount_amount'] : ($previous?->discount_value ?? $previous?->discount_amount ?? 0));
            $value = $this->numbers->normalize($value);
            $quantity = $this->numbers->normalizeToScale($input['offered_quantity'] ?? 0, 8);
            $price = $this->numbers->normalizeToScale($input['unit_price'] ?? 0, 8);
            $base = bcround(bcmul($quantity, $price, 16), 4);
            if (! in_array($type, ['fixed', 'percentage'], true) || ! is_string($value) || ! preg_match('/^\d{1,14}(?:\.\d{1,4})?$/D', $value)
                || ($type === 'percentage' && bccomp($value, '100', 4) > 0) || ($type === 'fixed' && bccomp($value, $base, 4) > 0)) {
                throw new DomainException(__('procurement.messages.commercial_discount_invalid'));
            }
            $lines[] = [...$input, 'ordered_quantity' => $quantity, 'unit_price' => $price, 'discount_type' => $type, 'discount_value' => $value];
        }

        return $this->orders->calculate($lines, $data['freight_amount'] ?? $draft?->freight_amount ?? 0,
            array_key_exists('header_discount_type', $data) ? $data['header_discount_type'] : $draft?->header_discount_type,
            $data['header_discount_value'] ?? $draft?->header_discount_value ?? 0);
    }

    /** @return array{quantity: string, gross: string, discount: string, header: string, tax: string} */
    public function selectionAllocation(SupplierQuotationLine $line, string $quantity, ?SupplierSelection $selection = null, ?SupplierSelectionLine $previous = null): array
    {
        $query = SupplierSelectionLine::query()->where('supplier_quotation_line_id', $line->id)
            ->when($selection, fn ($q) => $q->where('supplier_selection_id', '<>', $selection->id))
            ->whereHas('selection', fn ($q) => $q->where('company_id', $line->quotation->company_id)->whereNotIn('status', ['cancelled', 'rejected']));
        $grammar = DB::connection()->getQueryGrammar();
        $parts = ['COALESCE(SUM(CASE WHEN source_discount_snapshot IS NULL THEN selected_quantity ELSE 0 END), 0) AS legacy_quantity'];
        foreach (['quantity', 'gross', 'discount', 'header', 'tax'] as $key) {
            $parts[] = 'COALESCE(SUM(CAST('.$grammar->wrap('source_discount_snapshot->'.$key).' AS DECIMAL(30,8))), 0) AS '.$key;
        }
        $allocated = $query->selectRaw(implode(', ', $parts))->first();
        $legacy = $this->numbers->normalizeToScale($allocated->legacy_quantity, 8);
        $totals = ['gross' => $line->subtotal_amount ?? bcround(bcmul($line->offered_quantity, $line->unit_price, 16), 4),
            'discount' => $line->discount_amount, 'header' => $line->header_discount_amount ?? '0', 'tax' => $line->tax_amount];
        $state = ['quantity' => bcadd($this->numbers->normalizeToScale($allocated->quantity, 8), $legacy, 8)];
        foreach ($totals as $key => $total) {
            $state[$key] = bcadd($this->numbers->normalizeToScale($allocated->{$key}, 4), bcround(bcdiv(bcmul($total, $legacy, 20), $line->offered_quantity, 20), 4), 4);
        }

        return $this->sources->allocate($line->offered_quantity, $totals, $quantity, $state,
            $previous?->supplier_quotation_line_id === $line->id ? $previous->source_discount_snapshot : null);
    }

    /** @param list<array<string, mixed>> $lines
     * @return array<int, array{quantity: string, gross: string, discount: string, header: string, tax: string}>
     */
    public function orderAllocations(array $lines, array $context, ?PurchaseOrder $order, ?string $headerType, mixed $headerValue): array
    {
        if ($lines === []) {
            return [];
        }
        $existing = $order?->lines->keyBy('public_id');
        $result = [];
        $header = '0.0000';
        $sourceHeaderType = null;
        $sourceHeaderValue = '0.0000';
        foreach ($lines as $index => $input) {
            $id = $input['supplier_selection_line_id'] ?? $existing?->get($input['public_id'] ?? '')?->supplier_selection_line_id;
            $source = $id ? SupplierSelectionLine::query()->with(['selection', 'product', 'unit'])->whereKey($id)
                ->whereHas('selection', fn ($q) => $q->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->whereIn('status', ['draft', 'approved']))
                ->where(fn ($q) => $order ? $q->where('purchase_order_id', $order->id) : $q->whereNull('purchase_order_id'))->first() : null;
            if (! $source || $source->subtotal_amount === null || $source->product?->doc_num !== ($input['product_doc_num'] ?? null) || $source->unit?->doc_num !== ($input['unit_doc_num'] ?? null)
                || ($input['discount_type'] ?? 'fixed') !== ($source->discount_type ?: 'fixed')) {
                return [];
            }
            foreach (['ordered_quantity' => 'selected_quantity', 'unit_price' => 'unit_price', 'discount_value' => 'discount_value', 'tax_rate' => 'tax_rate'] as $inputKey => $sourceKey) {
                if (bccomp((string) ($input[$inputKey] ?? 0), (string) ($source->{$sourceKey} ?? $source->discount_amount), in_array($inputKey, ['ordered_quantity', 'unit_price'], true) ? 8 : 4) !== 0) {
                    return [];
                }
            }
            $result[$index] = ['quantity' => $source->selected_quantity, 'gross' => $source->subtotal_amount,
                'discount' => $source->discount_amount, 'header' => $source->header_discount_amount, 'tax' => $source->tax_amount];
            $header = bcadd($header, $source->header_discount_amount, 4);
            $sourceHeaderType = $source->header_discount_type;
            $sourceHeaderValue = $source->header_discount_value ?? '0';
        }
        $expected = $sourceHeaderType === 'percentage' ? $sourceHeaderValue : $header;

        return ($headerType ?? null) === $sourceHeaderType && bccomp((string) ($headerValue ?? 0), $expected, 4) === 0 ? $result : [];
    }
}

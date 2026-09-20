<?php

namespace Modules\Sales\Services;

use Modules\Sales\Models\PriceList;

class PriceListReportData
{
    /**
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>, export_headings: list<string>, export_rows: list<list<mixed>>}
     */
    public function build(PriceList $priceList): array
    {
        $priceList->load(['company', 'customer', 'currency', 'lines.product', 'createdBy', 'reviewedBy', 'approvedBy']);
        $empty = __('common.empty_value');
        $customerIsGeneral = $priceList->customer_id === null;

        $header = [
            'code' => (string) $priceList->doc_num,
            'date' => $priceList->price_list_date?->toDateString(),
            'customer_code' => $customerIsGeneral ? '' : (string) ($priceList->customer?->doc_num ?? $empty),
            'customer_name' => $customerIsGeneral ? __('price_lists.general') : (string) ($priceList->customer?->name ?? $empty),
            'currency_code' => (string) ($priceList->currency?->code ?? $empty),
            'currency_name' => (string) ($priceList->currency?->name ?? $empty),
            'valid_from' => $priceList->valid_from?->toDateString(),
            'valid_until' => $priceList->valid_until?->toDateString(),
            'pricing_use' => $priceList->is_print_only ? __('price_lists.print_only') : __('price_lists.operational'),
            'notes' => (string) ($priceList->notes ?? ''),
            'deleted_state' => $priceList->trashed() ? __('price_lists.deleted') : __('price_lists.active'),
            'prepared_by' => (string) ($priceList->createdBy?->name ?? $empty),
            'reviewed_by' => (string) ($priceList->reviewedBy?->name ?? __('price_lists.pending_identity')),
            'reviewed_at' => $priceList->reviewed_at?->toDateTimeString(),
            'approved_by' => (string) ($priceList->approvedBy?->name ?? __('price_lists.pending_identity')),
            'approved_at' => $priceList->approved_at?->toDateTimeString(),
        ];

        $lines = $priceList->lines->map(fn ($line): array => [
            'line_number' => (int) $line->line_number,
            'product_code' => (string) ($line->product?->doc_num ?? $empty),
            'product_name' => (string) ($line->product?->name ?? $empty),
            'unit_price' => (string) $line->unit_price,
            'discount_type' => $line->allowed_discount_type
                ? __('price_lists.'.$line->allowed_discount_type)
                : __('price_lists.no_discount'),
            'discount_value' => (string) $line->allowed_discount_value,
        ])->values()->all();

        $headings = [
            __('price_lists.export.price_list_code'), __('price_lists.export.price_list_date'),
            __('price_lists.export.customer_code'), __('price_lists.export.customer_name'),
            __('price_lists.export.currency_code'), __('price_lists.export.currency_name'),
            __('price_lists.export.valid_from'), __('price_lists.export.valid_until'),
            __('price_lists.export.pricing_use'), __('price_lists.export.notes'), __('price_lists.export.state'),
            __('price_lists.export.line_number'), __('price_lists.export.product_code'), __('price_lists.export.product_name'),
            __('price_lists.export.unit_price'), __('price_lists.export.discount_type'), __('price_lists.export.discount_limit'),
        ];

        $exportLines = $lines === [] ? [[
            'line_number' => '', 'product_code' => $empty, 'product_name' => $empty,
            'unit_price' => '', 'discount_type' => $empty, 'discount_value' => '',
        ]] : $lines;

        $rows = array_map(fn (array $line): array => [
            $header['code'], $header['date'] ?? '', $header['customer_code'], $header['customer_name'],
            $header['currency_code'], $header['currency_name'], $header['valid_from'] ?? '',
            $header['valid_until'] ?? __('price_lists.open_ended'), $header['pricing_use'], $header['notes'],
            $header['deleted_state'], $line['line_number'], $line['product_code'], $line['product_name'],
            $line['unit_price'], $line['discount_type'], $line['discount_value'],
        ], $exportLines);

        return ['header' => $header, 'lines' => $lines, 'export_headings' => $headings, 'export_rows' => $rows];
    }
}

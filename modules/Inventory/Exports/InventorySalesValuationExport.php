<?php

namespace Modules\Inventory\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

class InventorySalesValuationExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithTitle
{
    /** @param array<string, mixed> $valuation */
    public function __construct(private readonly array $valuation) {}

    public function array(): array
    {
        $rows = $this->valuation['rows'];
        $data = collect($rows)->map(function (object $row): array {
            return [
                $row->branch?->name ?? '',
                $row->branchStore?->name ?? '',
                $row->warehouseLocation?->code ?? '',
                $row->product?->doc_num ?? '',
                $row->product?->name ?? '',
                $row->product?->unit?->name ?? '',
                $row->on_hand,
                $row->unit_selling_price,
                $row->sales_value,
                __('inventory_accounting.sales_valuation.price_statuses.'.$row->price_status),
            ];
        })->all();

        $totals = $this->valuation['totals'];
        $data[] = [
            '', '', '', '', __('inventory_accounting.book_valuation.total'), '',
            $totals['quantity'],
            '',
            $totals['sales_value'],
            '',
        ];
        $data[] = [
            '', '', '', '', __('inventory_accounting.sales_valuation.unpriced'), '',
            $totals['unpriced_quantity'],
            '', '', __('inventory_accounting.sales_valuation.unpriced_product_count').': '.$totals['unpriced_product_count'],
        ];

        return $data;
    }

    public function headings(): array
    {
        return [
            __('stock_balance_inquiry.columns.branch'),
            __('stock_balance_inquiry.columns.store'),
            __('stock_balance_inquiry.columns.location'),
            __('inventory_accounting.book_valuation.columns.item_code'),
            __('stock_balance_inquiry.columns.product'),
            __('stock_balance_inquiry.columns.unit'),
            __('stock_balance_inquiry.columns.on_hand'),
            __('inventory_accounting.sales_valuation.unit_selling_price'),
            __('inventory_accounting.sales_valuation.sales_value'),
            __('inventory_accounting.sales_valuation.price_status'),
        ];
    }

    public function title(): string
    {
        return mb_substr(__('inventory_accounting.sales_valuation.title'), 0, 31);
    }
}

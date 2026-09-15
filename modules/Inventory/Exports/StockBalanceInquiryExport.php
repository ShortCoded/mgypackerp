<?php

namespace Modules\Inventory\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class StockBalanceInquiryExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison
{
    /** @param array<string, string|int> $totals */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $totals,
    ) {}

    /** @return list<array<int, mixed>> */
    public function array(): array
    {
        $rows = $this->rows->map(function ($row): array {
            $product = $row->product;
            $data = [
                $row->branch?->name,
                $row->branchStore?->name,
                $row->branchHall?->name,
                $row->warehouseLocation ? trim($row->warehouseLocation->code.' — '.$row->warehouseLocation->name) : null,
                $product?->doc_num,
                $product?->name,
                $product?->item_classification ? __('products.classifications.'.$product->item_classification) : null,
                $product?->unit?->name,
                $product?->category?->name,
                $product?->group?->name,
                $product?->itemModel?->name,
                $product?->size?->name,
                $product?->color?->name,
                $product?->decal?->name,
                $product?->originCountry?->name,
                (float) $row->on_hand,
                (float) $row->available_stock,
                (float) $row->reserved,
                (float) $row->available,
                (float) $row->held_stock,
            ];

            return $data;
        })->all();
        $total = array_fill(0, 15, null);
        $total[0] = __('stock_balance_inquiry.total');
        array_push(
            $total,
            (float) $this->totals['on_hand'],
            (float) $this->totals['available_stock'],
            (float) $this->totals['reserved'],
            (float) $this->totals['available'],
            (float) $this->totals['held_stock'],
        );

        $rows[] = $total;

        return $rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        $headings = [
            __('stock_balance_inquiry.columns.branch'),
            __('stock_balance_inquiry.columns.store'),
            __('stock_balance_inquiry.columns.hall'),
            __('stock_balance_inquiry.columns.location'),
            __('stock_balance_inquiry.columns.item_code'),
            __('stock_balance_inquiry.columns.item_name'),
            __('stock_balance_inquiry.columns.classification'),
            __('stock_balance_inquiry.columns.unit'),
            __('stock_balance_inquiry.columns.category'),
            __('stock_balance_inquiry.columns.group'),
            __('stock_balance_inquiry.columns.model'),
            __('stock_balance_inquiry.columns.size'),
            __('stock_balance_inquiry.columns.color'),
            __('stock_balance_inquiry.columns.decal'),
            __('stock_balance_inquiry.columns.origin_country'),
            __('stock_balance_inquiry.columns.on_hand'),
            __('stock_balance_inquiry.columns.available_stock'),
            __('stock_balance_inquiry.columns.reserved'),
            __('stock_balance_inquiry.columns.available'),
            __('stock_balance_inquiry.columns.held'),
        ];

        return $headings;
    }
}

<?php

namespace Modules\Inventory\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class StockBalanceInquiryExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison
{
    /** @param array<string, mixed> $totals */
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
        $total = array_fill(0, 14, null);
        $total[0] = __('stock_balance_inquiry.total');
        array_push(
            $total,
            $this->totals['mixed_units'] ? null : (float) $this->totals['on_hand'],
            $this->totals['mixed_units'] ? null : (float) $this->totals['available_stock'],
            $this->totals['mixed_units'] ? null : (float) $this->totals['reserved'],
            $this->totals['mixed_units'] ? null : (float) $this->totals['available'],
            $this->totals['mixed_units'] ? null : (float) $this->totals['held_stock'],
        );

        $rows[] = $total;

        if ($this->totals['mixed_units']) {
            foreach ($this->totals['quantity_by_unit'] as $unitTotal) {
                $subtotal = array_fill(0, 19, null);
                $subtotal[0] = __('inventory_accounting.book_valuation.unit_subtotal', ['unit' => $unitTotal['unit_name']]);
                $subtotal[6] = $unitTotal['unit_name'];
                foreach (['on_hand', 'available_stock', 'reserved', 'available', 'held_stock'] as $offset => $field) {
                    $subtotal[14 + $offset] = (float) $unitTotal[$field];
                }
                $rows[] = $subtotal;
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        $headings = [
            __('stock_balance_inquiry.columns.branch'),
            __('stock_balance_inquiry.columns.store'),
            __('stock_balance_inquiry.columns.hall'),
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

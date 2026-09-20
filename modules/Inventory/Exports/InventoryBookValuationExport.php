<?php

namespace Modules\Inventory\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class InventoryBookValuationExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison
{
    /** @param array<string, string|int|bool> $totals */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $totals,
        private readonly string $currencyCode,
    ) {}

    /** @return list<array<int, mixed>> */
    public function array(): array
    {
        $rows = $this->rows->map(fn ($row): array => [
            $row->branch?->name,
            $row->branchStore?->name,
            $row->branchHall?->name,
            $row->warehouseLocation ? trim($row->warehouseLocation->code.' — '.$row->warehouseLocation->name) : null,
            $row->product?->doc_num,
            $row->product?->name,
            $row->product?->unit?->name,
            (float) $row->on_hand,
            $row->book_unit_cost === null ? null : (float) $row->book_unit_cost,
            (float) $row->book_value,
            $this->currencyCode,
            (float) $row->unvalued_quantity,
            (int) $row->unvalued_row_count,
            __('inventory_accounting.book_valuation.statuses.'.$row->valuation_status),
            $row->is_negative ? __('inventory_accounting.book_valuation.negative') : null,
        ])->all();

        $total = array_fill(0, 15, null);
        $total[0] = __('inventory_accounting.book_valuation.total');
        $total[7] = (float) $this->totals['quantity'];
        $total[9] = (float) $this->totals['book_value'];
        $total[10] = $this->currencyCode;
        $total[11] = (float) $this->totals['unvalued_quantity'];
        $total[12] = (int) $this->totals['unvalued_rows'];
        $rows[] = $total;

        return $rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return collect([
            'branch', 'store', 'hall', 'location', 'item_code', 'item_name', 'unit', 'quantity',
            'book_unit_cost', 'book_value', 'currency', 'unvalued_quantity', 'unvalued_rows', 'status', 'warning',
        ])->map(fn (string $key): string => __('inventory_accounting.book_valuation.columns.'.$key))->all();
    }
}

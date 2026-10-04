<?php

namespace Modules\Inventory\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

final class InventoryBookValuationExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    /** @param array<string, mixed> $totals */
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
            $row->product?->doc_num,
            $row->product?->name,
            $row->product?->unit?->name,
            (float) $row->on_hand,
            $row->book_unit_cost === null ? null : (string) $row->book_unit_cost,
            (float) $row->book_value,
            $this->currencyCode,
            (float) $row->unvalued_quantity,
            (int) $row->unvalued_row_count,
            __('inventory_accounting.book_valuation.statuses.'.$row->valuation_status),
            $row->is_negative ? __('inventory_accounting.book_valuation.negative') : null,
        ])->all();

        $total = array_fill(0, 14, null);
        $total[0] = __('inventory_accounting.book_valuation.total');
        $total[6] = $this->totals['mixed_units'] ? null : (float) $this->totals['quantity'];
        $total[8] = (float) $this->totals['book_value'];
        $total[9] = $this->currencyCode;
        $total[10] = $this->totals['mixed_units'] ? null : (float) $this->totals['unvalued_quantity'];
        $total[11] = (int) $this->totals['unvalued_rows'];
        $rows[] = $total;

        if ($this->totals['mixed_units']) {
            foreach ($this->totals['quantity_by_unit'] as $unitTotal) {
                $subtotal = array_fill(0, 14, null);
                $subtotal[0] = __('inventory_accounting.book_valuation.unit_subtotal', ['unit' => $unitTotal['unit_name']]);
                $subtotal[5] = $unitTotal['unit_name'];
                $subtotal[6] = (float) $unitTotal['quantity'];
                $subtotal[10] = (float) $unitTotal['unvalued_quantity'];
                $rows[] = $subtotal;
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return collect([
            'branch', 'store', 'hall', 'item_code', 'item_name', 'unit', 'quantity',
            'book_unit_cost', 'book_value', 'currency', 'unvalued_quantity', 'unvalued_rows', 'status', 'warning',
        ])->map(fn (string $key): string => __('inventory_accounting.book_valuation.columns.'.$key))->all();
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($cell->getColumn() === 'H' && $cell->getRow() > 1 && $value !== null) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}

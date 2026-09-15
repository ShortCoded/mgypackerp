<?php

namespace Modules\Inventory\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\StockCountLine;

class StockCountExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison
{
    /** @param array<string, string> $totals */
    public function __construct(
        private readonly StockCount $record,
        private readonly array $totals,
    ) {}

    /** @return list<array<int, mixed>> */
    public function array(): array
    {
        $rows = $this->record->lines->map(fn (StockCountLine $line): array => [
            $line->line_number,
            $line->product?->doc_num,
            $line->product?->name,
            $line->unit?->name ?? $line->product?->unit?->name,
            __('inventory.movements.stock_statuses.'.$line->stock_status),
            $line->batch_lot,
            (float) $line->system_quantity,
            (float) $line->physical_quantity,
            (float) $line->variance_quantity,
            $this->varianceType($line),
            $line->variance_reason,
            $line->notes,
        ])->all();

        $rows[] = [
            __('inventory.stock_counts.summary.total'),
            null,
            null,
            null,
            null,
            null,
            (float) $this->totals['system'],
            (float) $this->totals['physical'],
            (float) $this->totals['variance'],
            __('inventory.stock_counts.summary.shortage').' '.(float) $this->totals['shortage'].' / '.__('inventory.stock_counts.summary.surplus').' '.(float) $this->totals['surplus'],
            null,
            null,
        ];

        return $rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            '#',
            __('inventory.stock_counts.attributes.product_code'),
            __('inventory.stock_counts.attributes.product'),
            __('inventory.stock_counts.attributes.unit'),
            __('inventory.stock_counts.attributes.stock_status'),
            __('inventory.stock_counts.attributes.batch_lot'),
            __('inventory.stock_counts.attributes.system_quantity'),
            __('inventory.stock_counts.attributes.physical_quantity'),
            __('inventory.stock_counts.attributes.variance_quantity'),
            __('inventory.stock_counts.attributes.variance_type'),
            __('inventory.stock_counts.attributes.variance_reason'),
            __('inventory.stock_counts.attributes.line_notes'),
        ];
    }

    private function varianceType(StockCountLine $line): string
    {
        $comparison = bccomp((string) $line->variance_quantity, '0', 8);

        return match (true) {
            $comparison < 0 => __('inventory.stock_counts.variance_types.shortage'),
            $comparison > 0 => __('inventory.stock_counts.variance_types.surplus'),
            default => __('inventory.stock_counts.variance_types.match'),
        };
    }
}

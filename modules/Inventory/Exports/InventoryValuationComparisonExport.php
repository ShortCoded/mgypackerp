<?php

namespace Modules\Inventory\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class InventoryValuationComparisonExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison
{
    /** @param array<string, mixed> $comparison */
    public function __construct(private readonly array $comparison) {}

    /** @return list<list<string>> */
    public function array(): array
    {
        return collect($this->comparison['methods'])->map(
            fn (array $result, string $method): array => [
                __('inventory_accounting.valuation_methods.'.$method),
                $result['issue_cost'] ?? '',
                $result['ending_value'],
                $result['ending_unit_cost'],
                $result['difference_vs_reference'] ?? '',
                $result['book_method'] ? __('inventory_accounting.valuation_report.book_method') : ($result['reference_only'] ? __('inventory_accounting.valuation_report.reference_only') : __('inventory_accounting.valuation_report.simulation')),
            ],
        )->values()->all();
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            __('inventory_accounting.valuation_report.method'),
            __('inventory_accounting.valuation_report.issue_cost'),
            __('inventory_accounting.valuation_report.ending_value'),
            __('inventory_accounting.valuation_report.ending_unit_cost'),
            __('inventory_accounting.valuation_report.difference_vs_reference'),
            __('inventory_accounting.valuation_report.classification'),
        ];
    }
}

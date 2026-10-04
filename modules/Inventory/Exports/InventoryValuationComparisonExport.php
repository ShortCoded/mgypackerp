<?php

namespace Modules\Inventory\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

final class InventoryValuationComparisonExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    /** @param array<string, mixed> $comparison */
    public function __construct(private readonly array $comparison) {}

    /** @return list<list<string>> */
    public function array(): array
    {
        $rows = collect($this->comparison['methods'])->map(
            fn (array $result, string $method): array => [
                __('inventory_accounting.valuation_methods.'.$method),
                $result['issue_cost'] ?? '',
                $result['ending_value'] ?? '',
                $result['ending_unit_cost'] ?? '',
                $result['difference_vs_reference'] ?? '',
                $result['book_method'] ? __('inventory_accounting.valuation_report.book_method') : ($result['reference_only'] ? __('inventory_accounting.valuation_report.reference_only') : __('inventory_accounting.valuation_report.simulation')),
                $this->sourceSummary($result),
            ],
        )->values()->all();

        if (($this->comparison['valuation_complete'] ?? true) === false) {
            $rows[] = [
                __('inventory_accounting.valuation_report.partial_warning'),
                '',
                '',
                '',
                (string) $this->comparison['excluded_position_count'],
                $this->comparison['excluded_mixed_units'] ? '' : (string) $this->comparison['excluded_quantity'],
                '',
            ];
            if ($this->comparison['excluded_mixed_units']) {
                foreach ($this->comparison['excluded_quantity_by_unit'] as $unitTotal) {
                    $rows[] = [
                        __('inventory_accounting.valuation_report.excluded_unit_summary'),
                        '', $unitTotal['unit_name'], $unitTotal['quantity'], '', '', '',
                    ];
                }
            }
            $rows[] = ['', '', '', '', '', '', ''];
            $rows[] = [
                __('inventory_accounting.valuation_report.excluded_title'),
                __('inventory_accounting.book_valuation.columns.branch').' / '.__('inventory_accounting.book_valuation.columns.store'),
                __('inventory_accounting.book_valuation.columns.item'),
                __('inventory_accounting.book_valuation.columns.quantity'),
                __('inventory_accounting.valuation_report.excluded_reason'),
                __('inventory_accounting.valuation_report.excluded_documents'),
                '',
            ];

            foreach ($this->comparison['excluded_positions'] as $position) {
                $rows[] = [
                    '',
                    ($position['branch_name'] ?? $position['branch_id']).' / '.($position['store_name'] ?? $position['branch_store_id']),
                    ($position['product_doc_num'] ?? $position['product_id']).' / '.($position['product_name'] ?? ''),
                    $position['quantity'],
                    __($position['reason']),
                    implode('، ', $position['source_doc_nums']),
                    '',
                ];
            }
        }

        return $rows;
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
            __('inventory_accounting.valuation_report.reference_source'),
        ];
    }

    /** @param array<string, mixed> $result */
    private function sourceSummary(array $result): string
    {
        $sources = $result['sources'] ?? [];
        if ($sources === []) {
            return $result['reference_only'] ? __('inventory_accounting.valuation_report.no_reference_source') : '';
        }

        return collect($sources)->map(function (array $source): string {
            $summary = ($source['document'] ?? '—').' · '.($source['date'] ?? '—').' · '
                .__('inventory_accounting.valuation_report.source_types.'.($source['source'] ?? 'other'));
            if (isset($source['unit_cost'])) {
                $summary .= ' · '.$source['unit_cost'];
            }
            if (isset($source['currency'])) {
                $summary .= ' · '.$source['currency'].' × '.($source['exchange_rate'] ?? '1');
            }
            if (isset($source['basis'])) {
                $summary .= ' · '.__('inventory_accounting.valuation_report.source_bases.'.$source['basis']);
            }

            return $summary;
        })->implode(' | ');
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($cell->getColumn() === 'D' && $cell->getRow() > 1
            && $cell->getRow() <= count($this->comparison['methods'] ?? []) + 1 && $value !== null) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}

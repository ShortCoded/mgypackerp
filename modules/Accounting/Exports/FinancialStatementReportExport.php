<?php

namespace Modules\Accounting\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class FinancialStatementReportExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison
{
    /** @param array<string, mixed> $result */
    public function __construct(private readonly array $result) {}

    /** @return list<list<string|null>> */
    public function array(): array
    {
        if ($this->result['statement_type'] === 'equity_changes') {
            return array_map(fn (array $row): array => [
                $this->label($row),
                $row['opening'],
                $row['increases'],
                $row['decreases'],
                $row['period_result'],
                $row['amount'],
                $row['comparison_amount'] ?? null,
            ], $this->result['rows']);
        }

        $rows = array_map(function (array $row): array {
            $comparison = $row['comparison_amount'] ?? null;

            return [
                $this->label($row),
                $row['amount'],
                $comparison,
                $comparison === null ? null : bcsub((string) $row['amount'], (string) $comparison, 4),
            ];
        }, $this->result['rows']);

        if (! in_array($this->result['statement_type'], ['cash_flow_direct', 'cash_flow_indirect'], true)) {
            return $rows;
        }

        foreach ($this->result['cash_components'] as $component) {
            $rows[] = [
                __('financial_statements.messages.cash_components').' — '.$component['label'].' — '.__('financial_statements.columns.opening'),
                $component['opening'],
                null,
                null,
            ];
            $rows[] = [
                __('financial_statements.messages.cash_components').' — '.$component['label'].' — '.__('financial_statements.columns.ending'),
                $component['ending'],
                null,
                $component['change'],
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        if ($this->result['statement_type'] === 'equity_changes') {
            return [
                __('financial_statements.columns.line'),
                __('financial_statements.columns.opening'),
                __('financial_statements.columns.increases'),
                __('financial_statements.columns.decreases'),
                __('financial_statements.columns.period_result'),
                __('financial_statements.columns.current'),
                __('financial_statements.columns.comparison'),
            ];
        }

        return [
            __('financial_statements.columns.line'),
            __('financial_statements.columns.current'),
            __('financial_statements.columns.comparison'),
            __('financial_statements.columns.variance'),
        ];
    }

    /** @param array<string, mixed> $row */
    private function label(array $row): string
    {
        return (string) ($row['label'] ?? __('financial_statements.lines.'.$row['label_key']));
    }
}

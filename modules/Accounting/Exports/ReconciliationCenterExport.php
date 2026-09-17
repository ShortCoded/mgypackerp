<?php

namespace Modules\Accounting\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class ReconciliationCenterExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison
{
    /** @param array<string, mixed> $report */
    public function __construct(private readonly array $report) {}

    /** @return list<list<string>> */
    public function array(): array
    {
        $rows = [];

        foreach ($this->report['results'] as $result) {
            if ($result['rows']->isEmpty()) {
                $rows[] = [
                    $result['title'],
                    '',
                    $result['status'],
                    '0.0000',
                    '0.0000',
                    '0.0000',
                    '0.0000',
                    '0.0000',
                    '0.0000',
                    '0.0000',
                    '0.0000',
                    '0.0000',
                    implode(' | ', $result['notes']),
                ];

                continue;
            }

            foreach ($result['rows'] as $row) {
                $rows[] = [
                    $result['title'],
                    (string) ($row['label'] ?? $row['key']),
                    $row['status'],
                    $row['source_opening'],
                    $row['gl_opening'],
                    $row['opening_difference'],
                    $row['source_movement'],
                    $row['gl_movement'],
                    $row['movement_difference'],
                    $row['source_ending'],
                    $row['gl_ending'],
                    $row['ending_difference'],
                    implode(' | ', $result['notes']),
                ];
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            __('reconciliation_center.columns.reconciliation'),
            __('reconciliation_center.columns.item'),
            __('reconciliation_center.columns.status'),
            __('reconciliation_center.columns.source_opening'),
            __('reconciliation_center.columns.gl_opening'),
            __('reconciliation_center.columns.opening_difference'),
            __('reconciliation_center.columns.source_movement'),
            __('reconciliation_center.columns.gl_movement'),
            __('reconciliation_center.columns.movement_difference'),
            __('reconciliation_center.columns.source_ending'),
            __('reconciliation_center.columns.gl_ending'),
            __('reconciliation_center.columns.ending_difference'),
            __('reconciliation_center.columns.notes'),
        ];
    }
}

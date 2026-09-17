<?php

namespace Modules\Accounting\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class TrialBalanceReportExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison
{
    /** @param array<string, mixed> $result */
    public function __construct(private readonly array $result) {}

    /** @return list<list<string>> */
    public function array(): array
    {
        $rows = [];
        $columns = $this->result['presentation']['columns'];

        foreach ($this->result['rows'] as $row) {
            $rows[] = [
                $row['account_code'],
                str_repeat('    ', max(0, $row['level'] - 1)).$row['name'],
                $row['is_inactive'] ? __('trial_balance.status.inactive') : __('trial_balance.status.active'),
                ...array_map(fn (string $column): string => $row[$column], $columns),
            ];
        }

        $rows[] = [
            '',
            __('trial_balance.total'),
            '',
            ...array_map(fn (string $column): string => $this->result['totals'][$column], $columns),
        ];

        return $rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            __('trial_balance.columns.account_code'),
            __('trial_balance.columns.account_name'),
            __('trial_balance.columns.status'),
            ...array_map(
                fn (string $column): string => __('trial_balance.headings.'.$column),
                $this->result['presentation']['columns'],
            ),
        ];
    }
}

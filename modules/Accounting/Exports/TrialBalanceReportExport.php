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

        foreach ($this->result['rows'] as $row) {
            $rows[] = [
                $row['account_code'],
                str_repeat('    ', max(0, $row['level'] - 1)).$row['name'],
                $row['is_inactive'] ? __('trial_balance.status.inactive') : __('trial_balance.status.active'),
                $row['opening_debit'],
                $row['opening_credit'],
                $row['period_debit'],
                $row['period_credit'],
                $row['ending_debit'],
                $row['ending_credit'],
            ];
        }

        $rows[] = [
            '',
            __('trial_balance.total'),
            '',
            $this->result['totals']['opening_debit'],
            $this->result['totals']['opening_credit'],
            $this->result['totals']['period_debit'],
            $this->result['totals']['period_credit'],
            $this->result['totals']['ending_debit'],
            $this->result['totals']['ending_credit'],
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
            __('trial_balance.columns.opening_debit'),
            __('trial_balance.columns.opening_credit'),
            __('trial_balance.columns.period_debit'),
            __('trial_balance.columns.period_credit'),
            __('trial_balance.columns.ending_debit'),
            __('trial_balance.columns.ending_credit'),
        ];
    }
}

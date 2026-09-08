<?php

namespace Modules\Accounting\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class LedgerReportExport implements FromArray, ShouldAutoSize, WithHeadings
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        private readonly array $result,
        private readonly string $type = 'account_ledger',
    ) {}

    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
        if ($this->isPartnerStatement()) {
            return $this->partnerStatementRows();
        }

        $rows = [[
            (string) data_get($this->result, 'filters.from_date'),
            __('ledger_reports.summary.opening'),
            '',
            '',
            '',
            '',
            '',
            (string) data_get($this->result, 'opening.debit'),
            (string) data_get($this->result, 'opening.credit'),
            (string) data_get($this->result, 'opening.debit'),
            (string) data_get($this->result, 'opening.credit'),
        ]];

        foreach ($this->result['movements'] as $movement) {
            $rows[] = [
                $movement['entry_date'],
                __('ledger_reports.sources.'.($movement['source_type'] ?: 'manual')),
                $movement['doc_num'],
                $movement['reference_no'] ?: $movement['source_doc_num'],
                $movement['description'],
                $movement['cost_center'],
                $movement['branch'],
                $movement['debit'],
                $movement['credit'],
                $movement['running_debit'],
                $movement['running_credit'],
            ];
        }

        $rows[] = [
            (string) data_get($this->result, 'filters.to_date'),
            __('ledger_reports.summary.period'),
            '',
            '',
            '',
            '',
            '',
            (string) data_get($this->result, 'period.debit'),
            (string) data_get($this->result, 'period.credit'),
            (string) data_get($this->result, 'ending.debit'),
            (string) data_get($this->result, 'ending.credit'),
        ];

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        if ($this->isPartnerStatement()) {
            return [
                __('ledger_reports.columns.date'),
                __('ledger_reports.columns.document'),
                __('ledger_reports.columns.reference'),
                __('ledger_reports.columns.description'),
                __('ledger_reports.columns.debit'),
                __('ledger_reports.columns.credit'),
                __('ledger_reports.columns.balance'),
            ];
        }

        return [
            __('ledger_reports.columns.date'),
            __('ledger_reports.columns.source_type'),
            __('ledger_reports.columns.document'),
            __('ledger_reports.columns.reference'),
            __('ledger_reports.columns.description'),
            __('ledger_reports.columns.cost_center'),
            __('ledger_reports.columns.branch'),
            __('ledger_reports.columns.debit'),
            __('ledger_reports.columns.credit'),
            __('ledger_reports.columns.running_debit'),
            __('ledger_reports.columns.running_credit'),
        ];
    }

    /**
     * @return list<list<string>>
     */
    private function partnerStatementRows(): array
    {
        $rows = [];

        if ($this->result['opening_movements'] !== []) {
            $rows[] = ['', __('ledger_reports.summary.prior_details'), '', '', '', '', ''];

            foreach ($this->result['opening_movements'] as $movement) {
                $rows[] = [
                    $movement['entry_date'],
                    $movement['source_doc_num'] ?: $movement['doc_num'],
                    $movement['reference_no'] ?: '',
                    $movement['description'],
                    $movement['debit'],
                    $movement['credit'],
                    $this->balanceLabel($movement['running_debit'], $movement['running_credit']),
                ];
            }
        }

        $rows[] = [
            (string) data_get($this->result, 'filters.from_date'),
            __('ledger_reports.summary.prior'),
            '',
            '',
            (string) data_get($this->result, 'opening.debit'),
            (string) data_get($this->result, 'opening.credit'),
            $this->balanceLabel(
                (string) data_get($this->result, 'opening.debit', '0'),
                (string) data_get($this->result, 'opening.credit', '0'),
            ),
        ];

        foreach ($this->result['movements'] as $movement) {
            $rows[] = [
                $movement['entry_date'],
                $movement['source_doc_num'] ?: $movement['doc_num'],
                $movement['reference_no'] ?: '',
                $movement['description'],
                $movement['debit'],
                $movement['credit'],
                $this->balanceLabel($movement['running_debit'], $movement['running_credit']),
            ];
        }

        $rows[] = [
            (string) data_get($this->result, 'filters.to_date'),
            __('ledger_reports.summary.period'),
            '',
            '',
            (string) data_get($this->result, 'period.debit'),
            (string) data_get($this->result, 'period.credit'),
            $this->balanceLabel(
                (string) data_get($this->result, 'ending.debit', '0'),
                (string) data_get($this->result, 'ending.credit', '0'),
            ),
        ];

        return $rows;
    }

    private function isPartnerStatement(): bool
    {
        return in_array($this->type, ['customer_statement', 'supplier_statement'], true);
    }

    private function balanceLabel(string $debit, string $credit): string
    {
        if ((float) $credit !== 0.0) {
            return $credit.' '.__('ledger_reports.balance.credit');
        }

        return $debit.' '.__('ledger_reports.balance.debit');
    }
}

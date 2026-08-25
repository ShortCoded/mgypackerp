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
    public function __construct(private readonly array $result) {}

    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
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

        foreach ($this->result['subledger_events'] ?? [] as $event) {
            $rows[] = [
                $event['date'], __(str($event['event'])->replace('_', ' ')->title()->toString()),
                $event['document'], $event['related_document'],
                __('Amount: :amount; Remaining credit: :remaining', ['amount' => $event['amount'], 'remaining' => $event['remaining_credit'] ?? '—']),
                '', '', '', '', '', '',
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
}

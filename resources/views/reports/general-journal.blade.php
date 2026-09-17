@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @php($sourceLabel = static function (mixed $sourceType): string {
        $source = filled($sourceType) ? (string) $sourceType : 'manual';
        $key = 'ledger_reports.sources.'.$source;

        return trans()->has($key) ? __($key) : __('ledger_reports.sources.other');
    })

    <div class="report-filter-summary">
        <div>{{ $dates->formatDate(data_get($result, 'filters.from_date'), '') }} — {{ $dates->formatDate(data_get($result, 'filters.to_date'), '') }}</div>
        @if($selectedAccount)<div>{{ $selectedAccount['name'] }}</div>@endif
        <div>{{ data_get($result, 'currency.code') }}</div>
    </div>

    <table class="report-table general-journal-table">
        <thead><tr>
            @foreach(['date', 'document', 'source_type', 'reference', 'account', 'description', 'cost_center', 'branch', 'debit', 'credit'] as $column)
                <th>{{ __('ledger_reports.columns.'.$column) }}</th>
            @endforeach
        </tr></thead>
        <tbody>
            @foreach($result['movements'] as $movement)
                <tr>
                    <td>{{ $dates->formatDate($movement['entry_date'], $movement['entry_date']) }}</td>
                    <td>{{ $movement['doc_num'] }}</td>
                    <td>{{ $sourceLabel($movement['source_type']) }}</td>
                    <td>{{ $movement['reference_no'] ?: ($movement['source_doc_num'] ?: '—') }}</td>
                    <td>{{ $movement['account'] }}</td>
                    <td>{{ $movement['description'] }}</td>
                    <td>{{ $movement['cost_center'] ?: '—' }}</td>
                    <td>{{ $movement['branch'] ?: '—' }}</td>
                    <td>{{ $numbers->format($movement['debit']) }}</td>
                    <td>{{ $numbers->format($movement['credit']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot><tr><td colspan="8">{{ __('ledger_reports.summary.period') }}</td><td>{{ $numbers->format($result['totals']['debit']) }}</td><td>{{ $numbers->format($result['totals']['credit']) }}</td></tr></tfoot>
    </table>

    <style>
        .report-filter-summary { background: #f8fafc; border: 1px solid #d8e2ef; margin-bottom: 8px; padding: 6px 8px; }
        .general-journal-table { table-layout: fixed; }
        .general-journal-table th, .general-journal-table td { font-size: 6.6px; line-height: 1.25; overflow-wrap: anywhere; }
    </style>
@endsection

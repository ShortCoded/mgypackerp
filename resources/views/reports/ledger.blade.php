@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))

    <div class="report-filter-summary">
        <strong>{{ $selected['doc_num'] }} / {{ $selected['name'] }}</strong>
        <div>@unless($type === 'customer_statement'){{ $selected['account'] }} / @endunless{{ data_get($result, 'currency.code') }}</div>
        <div>{{ $dates->formatDate(data_get($result, 'filters.from_date'), '') }} — {{ $dates->formatDate(data_get($result, 'filters.to_date'), '') }}</div>
    </div>

    @if($type === 'customer_statement')
        <table class="report-table customer-statement-table">
            <thead>
                <tr>
                    @foreach(['date', 'document', 'reference', 'description', 'debit', 'credit', 'balance'] as $column)
                        <th>{{ __('ledger_reports.columns.'.$column) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $dates->formatDate(data_get($result, 'filters.from_date'), '') }}</td>
                    <td>{{ __('ledger_reports.summary.opening') }}</td>
                    <td></td>
                    <td></td>
                    <td>{{ $numbers->format($result['opening']['debit']) }}</td>
                    <td>{{ $numbers->format($result['opening']['credit']) }}</td>
                    <td>{{ $numbers->format((float) $result['opening']['credit'] !== 0.0 ? $result['opening']['credit'] : $result['opening']['debit']) }} {{ __('ledger_reports.balance.'.((float) $result['opening']['credit'] !== 0.0 ? 'credit' : 'debit')) }}</td>
                </tr>
                @foreach($result['movements'] as $movement)
                    <tr>
                        <td>{{ $dates->formatDate($movement['entry_date'], $movement['entry_date']) }}</td>
                        <td>{{ $movement['source_doc_num'] ?: $movement['doc_num'] }}</td>
                        <td>{{ $movement['reference_no'] ?: '—' }}</td>
                        <td>{{ $movement['description'] }}</td>
                        <td>{{ $numbers->format($movement['debit']) }}</td>
                        <td>{{ $numbers->format($movement['credit']) }}</td>
                        <td>{{ $numbers->format((float) $movement['running_credit'] !== 0.0 ? $movement['running_credit'] : $movement['running_debit']) }} {{ __('ledger_reports.balance.'.((float) $movement['running_credit'] !== 0.0 ? 'credit' : 'debit')) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>{{ $dates->formatDate(data_get($result, 'filters.to_date'), '') }}</td>
                    <td colspan="3">{{ __('ledger_reports.summary.period') }}</td>
                    <td>{{ $numbers->format($result['period']['debit']) }}</td>
                    <td>{{ $numbers->format($result['period']['credit']) }}</td>
                    <td>{{ $numbers->format((float) $result['ending']['credit'] !== 0.0 ? $result['ending']['credit'] : $result['ending']['debit']) }} {{ __('ledger_reports.balance.'.((float) $result['ending']['credit'] !== 0.0 ? 'credit' : 'debit')) }}</td>
                </tr>
            </tfoot>
        </table>
    @else
    <table class="report-table ledger-report-table">
        <thead>
            <tr>
                @foreach(['date', 'source_type', 'document', 'reference', 'description', 'cost_center', 'branch', 'debit', 'credit', 'running_debit', 'running_credit'] as $column)
                    <th>{{ __('ledger_reports.columns.'.$column) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $dates->formatDate(data_get($result, 'filters.from_date'), '') }}</td>
                <td>{{ __('ledger_reports.summary.opening') }}</td>
                <td colspan="5"></td>
                <td>{{ $numbers->format($result['opening']['debit']) }}</td>
                <td>{{ $numbers->format($result['opening']['credit']) }}</td>
                <td>{{ $numbers->format($result['opening']['debit']) }}</td>
                <td>{{ $numbers->format($result['opening']['credit']) }}</td>
            </tr>
            @foreach($result['movements'] as $movement)
                <tr>
                    <td>{{ $dates->formatDate($movement['entry_date'], $movement['entry_date']) }}</td>
                    <td>{{ __('ledger_reports.sources.'.($movement['source_type'] ?: 'manual')) }}</td>
                    <td>{{ $movement['doc_num'] }}</td>
                    <td>{{ $movement['reference_no'] ?: $movement['source_doc_num'] }}</td>
                    <td>{{ $movement['description'] }}</td>
                    <td>{{ $movement['cost_center'] }}</td>
                    <td>{{ $movement['branch'] }}</td>
                    <td>{{ $numbers->format($movement['debit']) }}</td>
                    <td>{{ $numbers->format($movement['credit']) }}</td>
                    <td>{{ $numbers->format($movement['running_debit']) }}</td>
                    <td>{{ $numbers->format($movement['running_credit']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>{{ $dates->formatDate(data_get($result, 'filters.to_date'), '') }}</td>
                <td>{{ __('ledger_reports.summary.period') }}</td>
                <td colspan="5"></td>
                <td>{{ $numbers->format($result['period']['debit']) }}</td>
                <td>{{ $numbers->format($result['period']['credit']) }}</td>
                <td>{{ $numbers->format($result['ending']['debit']) }}</td>
                <td>{{ $numbers->format($result['ending']['credit']) }}</td>
            </tr>
        </tfoot>
    </table>
    @endif

    <style>
        .report-filter-summary { background: #f8fafc; border: 1px solid #d8e2ef; margin-bottom: 8px; padding: 6px 8px; }
        .ledger-report-table { table-layout: fixed; }
        .ledger-report-table th, .ledger-report-table td { font-size: 6.7px; line-height: 1.25; overflow-wrap: break-word; }
        .customer-statement-table th, .customer-statement-table td { font-size: 8px; line-height: 1.35; overflow-wrap: break-word; }
    </style>
@endsection

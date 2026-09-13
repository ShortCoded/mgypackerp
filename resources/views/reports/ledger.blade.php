@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @php($isPartnerStatement = in_array($type, ['customer_statement', 'supplier_statement'], true))
    @php($showCollectionDetails = $type === 'customer_statement')
    @php($partnerColumns = $showCollectionDetails ? ['date', 'document', 'reference', 'description', 'collector', 'collection_source', 'debit', 'credit', 'balance'] : ['date', 'document', 'reference', 'description', 'debit', 'credit', 'balance'])
    @php($partnerColumnWidths = $showCollectionDetails ? ['8%', '11%', '10%', '18%', '11%', '14%', '8%', '8%', '12%'] : ['11%', '14%', '13%', '28%', '10%', '10%', '14%'])
    @php($partnerTableClass = $showCollectionDetails ? 'customer-statement-table' : 'supplier-statement-table')

    <div class="report-filter-summary">
        <strong>{{ $selected['doc_num'] }} / {{ $selected['name'] }}</strong>
        <div>@unless($isPartnerStatement){{ $selected['account'] }} / @endunless{{ data_get($result, 'currency.code') }}</div>
        <div>{{ $dates->formatDate(data_get($result, 'filters.from_date'), '') }} — {{ $dates->formatDate(data_get($result, 'filters.to_date'), '') }}</div>
    </div>

    @if($isPartnerStatement)
        @if($result['opening_movements'] !== [])
            <h3>{{ __('ledger_reports.summary.prior_details') }}</h3>
            <table class="report-table partner-statement-table {{ $partnerTableClass }}" style="margin-bottom:8px;">
                <colgroup>@foreach($partnerColumnWidths as $width)<col style="width: {{ $width }};">@endforeach</colgroup>
                <thead><tr>@foreach($partnerColumns as $column)<th>{{ __('ledger_reports.columns.'.$column) }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($result['opening_movements'] as $movement)
                        <tr>
                            <td>{{ $dates->formatDate($movement['entry_date'], $movement['entry_date']) }}</td>
                            <td>{{ $movement['source_doc_num'] ?: $movement['doc_num'] }}</td>
                            <td>{{ $movement['reference_no'] ?: '—' }}</td>
                            <td>{{ $movement['description'] }}</td>
                            @if($showCollectionDetails)<td>{{ $movement['collector'] ?: '—' }}</td><td>{{ $movement['collection_source'] ?: '—' }}</td>@endif
                            <td>{{ $numbers->format($movement['debit']) }}</td>
                            <td>{{ $numbers->format($movement['credit']) }}</td>
                            <td>{{ $numbers->format((float) $movement['running_credit'] !== 0.0 ? $movement['running_credit'] : $movement['running_debit']) }} {{ __('ledger_reports.balance.'.((float) $movement['running_credit'] !== 0.0 ? 'credit' : 'debit')) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <table class="report-table partner-statement-table {{ $partnerTableClass }}">
            <colgroup>@foreach($partnerColumnWidths as $width)<col style="width: {{ $width }};">@endforeach</colgroup>
            <thead>
                <tr>
                    @foreach($partnerColumns as $column)
                        <th>{{ __('ledger_reports.columns.'.$column) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $dates->formatDate(data_get($result, 'filters.from_date'), '') }}</td>
                    <td>{{ __('ledger_reports.summary.prior') }}</td>
                    <td></td>
                    <td></td>
                    @if($showCollectionDetails)<td></td><td></td>@endif
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
                        @if($showCollectionDetails)<td>{{ $movement['collector'] ?: '—' }}</td><td>{{ $movement['collection_source'] ?: '—' }}</td>@endif
                        <td>{{ $numbers->format($movement['debit']) }}</td>
                        <td>{{ $numbers->format($movement['credit']) }}</td>
                        <td>{{ $numbers->format((float) $movement['running_credit'] !== 0.0 ? $movement['running_credit'] : $movement['running_debit']) }} {{ __('ledger_reports.balance.'.((float) $movement['running_credit'] !== 0.0 ? 'credit' : 'debit')) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>{{ $dates->formatDate(data_get($result, 'filters.to_date'), '') }}</td>
                    <td colspan="{{ $showCollectionDetails ? 5 : 3 }}">{{ __('ledger_reports.summary.period') }}</td>
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
        .partner-statement-table { table-layout: fixed; }
        .partner-statement-table th, .partner-statement-table td { font-size: 7.6px; line-height: 1.3; overflow-wrap: anywhere; word-break: break-word; }
        .partner-statement-table th { white-space: normal; }
        .partner-statement-table td:nth-last-child(-n+3) { white-space: nowrap; }
    </style>
@endsection

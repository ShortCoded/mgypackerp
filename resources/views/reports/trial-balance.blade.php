@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))

    <div class="report-filter-summary">
        <strong>{{ __('trial_balance.title') }}</strong>
        <div>{{ $dates->formatDate(data_get($result, 'filters.from_date'), '') }} — {{ $dates->formatDate(data_get($result, 'filters.to_date'), '') }}</div>
        <div>{{ data_get($result, 'currency.code') }} / {{ __('trial_balance.messages.'.($result['is_balanced'] ? 'balanced' : 'unbalanced')) }}</div>
    </div>

    <table class="report-table trial-balance-table">
        <thead>
            <tr>
                <th rowspan="2">{{ __('trial_balance.columns.account_code') }}</th>
                <th rowspan="2">{{ __('trial_balance.columns.account_name') }}</th>
                <th colspan="2">{{ __('trial_balance.columns.opening') }}</th>
                <th colspan="2">{{ __('trial_balance.columns.period') }}</th>
                <th colspan="2">{{ __('trial_balance.columns.ending') }}</th>
            </tr>
            <tr>
                @foreach(['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'ending_debit', 'ending_credit'] as $column)
                    <th>{{ __('trial_balance.columns.'.$column) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($result['rows'] as $row)
                <tr class="{{ $row['is_group'] ? 'group-row' : '' }}">
                    <td>{{ $row['account_code'] }}</td>
                    <td style="padding-inline-start: {{ max(0, $row['level'] - 1) * 7 }}px">
                        {{ $row['name'] }}@if($row['is_inactive']) ({{ __('trial_balance.status.inactive') }})@endif
                    </td>
                    @foreach(['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'ending_debit', 'ending_credit'] as $column)
                        <td>{{ $numbers->format($row[$column]) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">{{ __('trial_balance.total') }}</td>
                @foreach(['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'ending_debit', 'ending_credit'] as $column)
                    <td>{{ $numbers->format($result['totals'][$column]) }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>

    <style>
        .report-filter-summary { background: #f8fafc; border: 1px solid #d8e2ef; margin-bottom: 8px; padding: 6px 8px; }
        .trial-balance-table { table-layout: fixed; }
        .trial-balance-table th, .trial-balance-table td { font-size: 7.2px; line-height: 1.25; }
        .trial-balance-table th:nth-child(n+3), .trial-balance-table td:nth-child(n+3) { text-align: right; white-space: nowrap; }
        .trial-balance-table .group-row td { font-weight: bold; background: #f6f7f9; }
    </style>
@endsection

@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @php($visibleColumns = data_get($result, 'presentation.columns', []))

    <div class="report-filter-summary">
        <strong>{{ __('trial_balance.title') }}</strong>
        <div>{{ $dates->formatDate(data_get($result, 'filters.from_date'), '') }} — {{ $dates->formatDate(data_get($result, 'filters.to_date'), '') }}</div>
        <div>{{ data_get($result, 'currency.code') }} / {{ __('trial_balance.messages.'.$result['balance_status']) }}</div>
        <div>
            {{ __('trial_balance.value_modes.'.$result['presentation']['value_mode']) }} /
            {{ __('trial_balance.display_modes.'.$result['presentation']['display_mode']) }} /
            {{ __('trial_balance.totals_bases.'.$result['presentation']['totals_basis']) }}
        </div>
    </div>

    @foreach(array_chunk($visibleColumns, 4) as $columnGroup)
    <table dir="{{ $direction ?? 'ltr' }}" class="report-table trial-balance-table" style="margin-bottom:12px">
        <thead>
            <tr>
                <th style="width:15%">{{ __('trial_balance.columns.account_code') }}</th>
                <th style="width:25%">{{ __('trial_balance.columns.account_name') }}</th>
                @foreach($columnGroup as $column)
                    <th>{{ __('trial_balance.headings.'.$column) }}</th>
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
                    @foreach($columnGroup as $column)
                        <td>{{ $numbers->format($row[$column]) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">{{ __('trial_balance.total') }}</td>
                @foreach($columnGroup as $column)
                    <td>{{ $numbers->format($result['totals'][$column]) }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>
    @endforeach

    <style>
        .report-filter-summary { background: #f8fafc; border: 1px solid #d8e2ef; margin-bottom: 8px; padding: 6px 8px; }
        .trial-balance-table { table-layout: fixed; }
        .trial-balance-table th, .trial-balance-table td { font-size: 12px; line-height: 1.3; }
        .trial-balance-table th:nth-child(n+3), .trial-balance-table td:nth-child(n+3) { text-align: right; white-space: nowrap; }
        .trial-balance-table .group-row td { font-weight: bold; background: #f6f7f9; }
    </style>
@endsection

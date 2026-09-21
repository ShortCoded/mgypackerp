@extends('reports.layouts.pdf')

@section('report')
    @php
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $numericColumns = ['receipt', 'receipts', 'payment', 'payments', 'balance', 'amount', 'amount_base', 'source_amount', 'target_amount', 'exchange_rate', 'allocated', 'unallocated', 'original_amount', 'settled_amount', 'outstanding', 'days_overdue'];
    @endphp

    <div class="report-filter-summary">
        <strong>{{ $report['title'] }}</strong>
        <div>{{ $report['description'] }}</div>
        @if($report['filters'])
            <div>
                <strong>{{ __('finance_reports.active_filters') }}:</strong>
                @foreach($report['filters'] as $label => $value)
                    {{ $label }}: {{ $value }}@unless($loop->last) · @endunless
                @endforeach
            </div>
        @endif
        <div>{{ __('finance_reports.results_count', ['count' => $report['rows']->count()]) }}</div>
    </div>

    @foreach($report['notices'] as $notice)
        <div class="report-warning">{{ $notice }}</div>
    @endforeach

    @foreach($report['currency_totals'] as $currency => $totals)
        <table class="document-totals-table finance-report-totals">
            <thead>
                <tr><th colspan="{{ max(1, count($totals) * 2) }}">{{ $currency }}</th></tr>
            </thead>
            <tbody>
                <tr>
                    @foreach($totals as $label => $value)
                        <th>{{ $label }}</th>
                        <td class="text-end" dir="ltr">{{ $numbers->format($value) }}</td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    @endforeach

    <table class="report-table finance-report-table">
        <thead>
            <tr>
                @foreach($report['columns'] as $key => $label)
                    <th class="{{ in_array($key, $numericColumns, true) ? 'text-end' : '' }}">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($report['rows'] as $row)
                <tr class="{{ ($row['_is_balance_row'] ?? false) ? 'opening-balance-row' : '' }}">
                    @foreach($report['columns'] as $key => $label)
                        @php($value = $row[$key] ?? '')
                        <td class="{{ in_array($key, $numericColumns, true) ? 'text-end' : '' }}" @if(in_array($key, $numericColumns, true)) dir="ltr" @endif>
                            {{ in_array($key, $numericColumns, true) && is_numeric($value) ? $numbers->format($value) : $value }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($report['columns']) }}">{{ __('finance_reports.no_results') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <style>
        .finance-report-totals th,
        .finance-report-totals td {
            font-size: 7.6px;
            white-space: nowrap;
        }

        .finance-report-table {
            table-layout: fixed;
        }

        .finance-report-table th,
        .finance-report-table td {
            font-size: 6.8px;
            line-height: 1.25;
            overflow-wrap: break-word;
        }

        .finance-report-table .opening-balance-row td {
            background: #f8fafc;
            font-weight: 700;
        }
    </style>
@endsection

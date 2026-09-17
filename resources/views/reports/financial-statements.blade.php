@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @php($hasComparison = filled(data_get($result, 'filters.comparison_from_date')) && filled(data_get($result, 'filters.comparison_to_date')))

    <div class="report-filter-summary">
        <strong>{{ __('financial_statements.types.'.$result['statement_type']) }}</strong>
        <div>{{ $dates->formatDate(data_get($result, 'filters.from_date'), '') }} — {{ $dates->formatDate(data_get($result, 'filters.to_date'), '') }}</div>
        <div>{{ data_get($result, 'currency.code') }} / {{ __('financial_statements.view_modes.'.$result['view_mode']) }}</div>
    </div>

    @if(! $result['classification_complete'])
        <div class="report-warning">{{ __('financial_statements.messages.classification_incomplete') }}: {{ implode('، ', $result['classification_warnings']) }}</div>
    @endif

    <table class="report-table financial-statement-table">
        <thead>
            <tr>
                <th>{{ __('financial_statements.columns.line') }}</th>
                @if($result['statement_type'] === 'equity_changes')
                    @foreach(['opening', 'increases', 'decreases', 'period_result', 'current'] as $column)
                        <th>{{ __('financial_statements.columns.'.$column) }}</th>
                    @endforeach
                    @if($hasComparison)
                        <th>{{ __('financial_statements.columns.comparison') }}</th>
                        <th>{{ __('financial_statements.columns.variance') }}</th>
                    @endif
                @else
                    <th>{{ __('financial_statements.columns.current') }}</th>
                    @if($hasComparison)
                        <th>{{ __('financial_statements.columns.comparison') }}</th>
                        <th>{{ __('financial_statements.columns.variance') }}</th>
                    @endif
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($result['rows'] as $row)
                <tr class="row-{{ $row['row_type'] }}">
                    <td>{{ $row['label'] ?? __('financial_statements.lines.'.$row['label_key']) }}</td>
                    @if($result['statement_type'] === 'equity_changes')
                        @foreach(['opening', 'increases', 'decreases', 'period_result', 'amount'] as $column)
                            <td>{{ $numbers->format($row[$column]) }}</td>
                        @endforeach
                        @if($hasComparison)
                            <td>{{ isset($row['comparison_amount']) ? $numbers->format($row['comparison_amount']) : '—' }}</td>
                            <td>{{ isset($row['comparison_amount']) ? $numbers->format(bcsub((string) $row['amount'], (string) $row['comparison_amount'], 4)) : '—' }}</td>
                        @endif
                    @else
                        <td>{{ $numbers->format($row['amount']) }}</td>
                        @if($hasComparison)
                            <td>{{ isset($row['comparison_amount']) ? $numbers->format($row['comparison_amount']) : '—' }}</td>
                            <td>{{ isset($row['comparison_amount']) ? $numbers->format(bcsub((string) $row['amount'], (string) $row['comparison_amount'], 4)) : '—' }}</td>
                        @endif
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(in_array($result['statement_type'], ['cash_flow_direct', 'cash_flow_indirect'], true))
        <h4>{{ __('financial_statements.messages.cash_components') }}</h4>
        <table class="report-table financial-statement-table">
            <thead><tr>
                <th>{{ __('financial_statements.columns.account') }}</th>
                <th>{{ __('financial_statements.columns.opening') }}</th>
                <th>{{ __('financial_statements.columns.ending') }}</th>
                <th>{{ __('financial_statements.columns.change') }}</th>
            </tr></thead>
            <tbody>
                @foreach($result['cash_components'] as $component)
                    <tr>
                        <td>{{ $component['label'] }}</td>
                        <td>{{ $numbers->format($component['opening']) }}</td>
                        <td>{{ $numbers->format($component['ending']) }}</td>
                        <td>{{ $numbers->format($component['change']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <style>
        .report-filter-summary, .report-warning { border: 1px solid #d8e2ef; margin-bottom: 8px; padding: 6px 8px; }
        .report-filter-summary { background: #f8fafc; }
        .report-warning { background: #fff8e1; }
        .financial-statement-table th, .financial-statement-table td { font-size: 8px; }
        .financial-statement-table th:not(:first-child), .financial-statement-table td:not(:first-child) { text-align: right; white-space: nowrap; }
        .financial-statement-table .row-account td:first-child { padding-inline-start: 18px; }
        .financial-statement-table .row-total td, .financial-statement-table .row-grand_total td { font-weight: bold; background: #f1f5f9; }
        .financial-statement-table .row-subtotal td { font-weight: bold; }
    </style>
@endsection

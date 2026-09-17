@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))

    <div class="report-filter-summary">
        <strong>{{ __('reconciliation_center.title') }}</strong>
        <div>{{ data_get($report, 'filters.from_date') }} — {{ data_get($report, 'filters.to_date') }}</div>
        <div>{{ __('reconciliation_center.summary.mismatches') }}: {{ $report['mismatch_count'] }} / {{ __('reconciliation_center.summary.absolute_difference') }}: {{ $numbers->format($report['absolute_difference_total']) }}</div>
    </div>

    @foreach($report['results'] as $result)
        <h4>{{ $result['title'] }} — {{ __('reconciliation_center.statuses.'.$result['status']) }}</h4>
        @foreach($result['notes'] as $note)<p>{{ $note }}</p>@endforeach
        <table class="report-table reconciliation-table">
            <thead><tr>
                <th>{{ __('reconciliation_center.columns.item') }}</th>
                <th>{{ __('reconciliation_center.columns.status') }}</th>
                <th>{{ __('reconciliation_center.columns.source_opening') }}</th>
                <th>{{ __('reconciliation_center.columns.gl_opening') }}</th>
                <th>{{ __('reconciliation_center.columns.source_movement') }}</th>
                <th>{{ __('reconciliation_center.columns.gl_movement') }}</th>
                <th>{{ __('reconciliation_center.columns.source_ending') }}</th>
                <th>{{ __('reconciliation_center.columns.gl_ending') }}</th>
                <th>{{ __('reconciliation_center.columns.ending_difference') }}</th>
            </tr></thead>
            <tbody>
                @forelse($result['rows'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td>{{ __('reconciliation_center.statuses.'.$row['status']) }}</td>
                        @foreach(['source_opening', 'gl_opening', 'source_movement', 'gl_movement', 'source_ending', 'gl_ending', 'ending_difference'] as $column)
                            <td>{{ $numbers->format($row[$column]) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="9">{{ __('reconciliation_center.messages.no_rows') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach

    <style>
        .report-filter-summary { background: #f8fafc; border: 1px solid #d8e2ef; margin-bottom: 8px; padding: 6px 8px; }
        .reconciliation-table { margin-bottom: 12px; table-layout: fixed; }
        .reconciliation-table th, .reconciliation-table td { font-size: 6.5px; line-height: 1.2; }
        .reconciliation-table th:nth-child(n+3), .reconciliation-table td:nth-child(n+3) { text-align: right; white-space: nowrap; }
    </style>
@endsection

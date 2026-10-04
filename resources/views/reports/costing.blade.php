@extends('reports.layout')

@section('report')
    <p class="report-description">{{ $report['description'] }}</p>
    @foreach($report['notices'] as $notice)
        <p class="report-notice">{{ $notice }}</p>
    @endforeach
    @php
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $columnKeys = array_keys($report['columns']);
        $identityKey = array_shift($columnKeys);
        $columnGroups = array_map(fn (array $group): array => [$identityKey, ...$group], array_chunk($columnKeys, 6));
        if ($columnGroups === []) {
            $columnGroups = [[$identityKey]];
        }
    @endphp
    @foreach($columnGroups as $columnGroup)
    <table class="report-table costing-report-table">
        <thead><tr>@foreach($columnGroup as $key)<th>{{ $report['columns'][$key] }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse($report['rows'] as $row)
                <tr>@foreach($columnGroup as $key)
                    @php($value = $row[$key] ?? '')
                    <td @if(in_array($key, $report['numeric_columns'], true)) dir="ltr" class="text-end" @endif>{{ in_array($key, $report['numeric_columns'], true) && is_numeric($value) ? $numbers->format($value) : $value }}</td>
                @endforeach</tr>
            @empty
                <tr><td colspan="{{ count($columnGroup) }}" class="report-empty-cell">{{ __('reports.no_data') }}</td></tr>
            @endforelse
        </tbody>
        @if($report['totals'])
            <tfoot><tr>
                @foreach($columnGroup as $key)
                    @php($value = $report['totals'][$key] ?? null)
                    <th @if($value !== null && is_numeric($value)) dir="ltr" class="text-end" @endif>{{ $loop->first ? __('common.total') : (is_numeric($value) ? $numbers->format($value) : ($value ?? '')) }}</th>
                @endforeach
            </tr></tfoot>
        @endif
    </table>
    @endforeach
    <style>
        .costing-report-table { margin-bottom: 12px; table-layout: fixed; }
        .costing-report-table th, .costing-report-table td { font-size: 12px; line-height: 1.3; white-space: normal; overflow-wrap: break-word; }
        .costing-report-table th:first-child, .costing-report-table td:first-child { width: 25%; }
    </style>
@endsection

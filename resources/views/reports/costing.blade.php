@extends('reports.layout')

@section('report')
    <h1>{{ $report['title'] }}</h1>
    <p>{{ $report['description'] }}</p>
    @foreach($report['notices'] as $notice)<p><strong>{{ $notice }}</strong></p>@endforeach
    @if($report['totals'])<table class="report-table"><tbody><tr>@foreach($report['totals'] as $key => $value)<th>{{ __('costing_reports.columns.'.$key) }}</th><td>{{ $value }}</td>@endforeach</tr></tbody></table>@endif
    <table class="report-table"><thead><tr>@foreach($report['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
        @forelse($report['rows'] as $row)<tr>@foreach($report['columns'] as $key => $label)<td>{{ $row[$key] ?? '' }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($report['columns']) }}" class="report-empty-cell">{{ __('reports.no_data') }}</td></tr>@endforelse
    </tbody></table>
@endsection

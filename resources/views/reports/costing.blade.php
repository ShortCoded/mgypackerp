@extends('reports.layout')

@section('report')
    <h1>{{ $report['title'] }}</h1>
    <p>{{ $report['description'] }}</p>
    @foreach($report['notices'] as $notice)<p><strong>{{ $notice }}</strong></p>@endforeach
    <table><thead><tr>@foreach($report['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
        @foreach($report['rows'] as $row)<tr>@foreach($report['columns'] as $key => $label)<td>{{ $row[$key] ?? '' }}</td>@endforeach</tr>@endforeach
    </tbody></table>
@endsection

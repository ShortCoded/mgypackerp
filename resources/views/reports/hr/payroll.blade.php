@extends('reports.layouts.pdf')

@section('report')
    @if($filters !== [])<div class="report-filter-summary">{{ collect($filters)->map(fn($value, $key) => $key.': '.$value)->implode(' | ') }}</div>@endif
    <table class="report-table"><thead><tr>@foreach($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead><tbody>
        @forelse($rows as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($headings) }}">{{ __('reports.no_data') }}</td></tr>@endforelse
    </tbody></table>
@endsection

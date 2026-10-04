@extends('reports.layouts.pdf')

@section('report')
    @if($filters !== [])<div class="report-filter-summary">{{ collect($filters)->map(fn($value, $key) => __('hr_workforce_reports.filters.'.$key).': '.$value)->implode(' | ') }}</div>@endif
    <table dir="{{ $direction ?? 'ltr' }}" class="report-table">
        <thead><tr>@foreach($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr @class(['total' => $loop->index >= ($detailRowCount ?? count($rows))])>
                    @foreach($row as $value)
                        <td @if($loop->parent->index < ($detailRowCount ?? count($rows)) && in_array($loop->index, $ltrColumns ?? [], true)) dir="ltr" @endif>{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headings) }}">{{ __('reports.no_data') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection

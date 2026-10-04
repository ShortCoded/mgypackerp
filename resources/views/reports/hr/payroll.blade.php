@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @if($filters !== [])
        <div class="report-filter-summary">
            {{ collect($filters)->map(fn($value, $key) => __('hr_payroll_reports.filters.'.$key).': '.($key === 'status' ? __('hr_payroll.status.'.$value) : $value))->implode(' | ') }}
        </div>
    @endif
    <table dir="{{ $direction ?? 'ltr' }}" class="report-table">
        <thead><tr>@foreach($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr @class(['total' => $loop->index >= ($detailRowCount ?? count($rows))])>
                    @foreach($row as $value)
                        @php($isAmount = in_array($loop->index, $numericColumns ?? [], true) && is_numeric($value))
                        <td @if($isAmount || ($loop->parent->index < ($detailRowCount ?? count($rows)) && in_array($loop->index, $ltrColumns ?? [], true))) dir="ltr" @endif>{{ $isAmount ? $numbers->format($value) : $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headings) }}">{{ __('reports.no_data') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection

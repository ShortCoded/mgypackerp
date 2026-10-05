@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @include('reports.partials.company-identity')
    @forelse($shiftEntries as $entry)
        @include('reports.production.partials.shift-sheet')
        @if(!$loop->last)<pagebreak />@endif
    @empty
        <p>{{ __('production_execution.shift_evidence.no_entries') }}</p>
    @endforelse
@endsection

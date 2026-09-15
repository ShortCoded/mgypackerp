@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @include('reports.production.partials.run-header')
    <h3>{{ __('production_execution.print.material_requirement') }}</h3>
    <table class="report-table"><thead><tr><th>#</th><th>{{ __('production_execution.reports.columns.material') }}</th><th>{{ __('production_execution.reports.columns.planned') }}</th><th>{{ __('production_execution.reports.columns.reserved') }}</th><th>{{ __('production_execution.reports.columns.issued') }}</th><th>{{ __('production_execution.reports.columns.additional') }}</th><th>{{ __('production_execution.reports.columns.returned') }}</th><th>{{ __('production_execution.reports.columns.consumed') }}</th><th>{{ __('production_execution.reports.columns.waste') }}</th></tr></thead><tbody>
        @forelse($record->requirements as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td dir="ltr">{{ $numbers->format($line->planned_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->reserved_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->issued_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->additional_issued_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->returned_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->consumed_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->waste_quantity) }}</td></tr>@empty<tr><td colspan="9">{{ __('production_execution.print.no_materials') }}</td></tr>@endforelse
    </tbody></table>
    @include('reports.production.partials.signatures', ['areas' => ['production_supervisor', 'warehouse']])
@endsection

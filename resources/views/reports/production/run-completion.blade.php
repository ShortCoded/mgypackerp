@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @include('reports.production.partials.run-header')
    <h3>{{ __('production_execution.print.output_accountability') }}</h3>
    <table class="report-table"><tbody>
        <tr><th>{{ __('production_execution.reports.columns.good') }}</th><td dir="ltr">{{ $numbers->format($record->good_base_quantity) }}</td><th>{{ __('production_execution.reports.columns.rejected') }}</th><td dir="ltr">{{ $numbers->format($record->rejected_base_quantity) }}</td><th>{{ __('production_execution.reports.columns.rework') }}</th><td dir="ltr">{{ $numbers->format($record->rework_base_quantity) }}</td></tr>
        <tr><th>{{ __('production_execution.reports.columns.scrap') }}</th><td dir="ltr">{{ $numbers->format($record->scrap_base_quantity) }}</td><th>{{ __('production_execution.reports.columns.received') }}</th><td dir="ltr">{{ $numbers->format($record->received_base_quantity) }}</td><th>{{ __('production_execution.fields.actual_duration') }}</th><td>{{ $record->actualDurationHours() === null ? '—' : __('production_execution.labor.hours_value', ['hours' => $numbers->format($record->actualDurationHours())]) }}</td></tr>
    </tbody></table>
    <h3>{{ __('production_execution.labor.actual_details') }}</h3>
    <table class="report-table"><thead><tr><th>#</th><th>{{ __('production_execution.fields.worker_name') }}</th><th>{{ __('production_execution.fields.worker_role') }}</th><th>{{ __('production_execution.fields.planned_hours') }}</th><th>{{ __('production_execution.fields.actual_hours') }}</th><th>{{ __('production_execution.fields.notes') }}</th></tr></thead><tbody>
        @forelse(collect($record->labor_details ?? []) as $index => $labor)<tr><td>{{ $index + 1 }}</td><td>{{ $labor['name'] ?? '—' }}</td><td>{{ $labor['role'] ?? '—' }}</td><td dir="ltr">{{ $numbers->format($labor['planned_hours'] ?? null) }}</td><td dir="ltr">{{ $numbers->format($labor['actual_hours'] ?? null) }}</td><td>{{ $labor['notes'] ?? '—' }}</td></tr>@empty<tr><td colspan="6">{{ __('production_execution.labor.no_details') }}</td></tr>@endforelse
    </tbody></table>
    @if($record->notes)<p><strong>{{ __('production_execution.fields.notes') }}:</strong> {{ $record->notes }}</p>@endif
    @include('reports.production.partials.signatures', ['areas' => ['production_supervisor', 'quality', 'warehouse']])
@endsection

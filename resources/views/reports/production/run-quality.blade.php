@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @include('reports.production.partials.run-header')
    <h3>{{ __('production_execution.print.in_process_quality') }}</h3>
    <table class="report-table"><thead><tr><th>{{ __('production_execution.reports.columns.inspection') }}</th><th>{{ __('production_execution.fields.sampled_at') }}</th><th>{{ __('production_execution.fields.result') }}</th><th>{{ __('production_execution.fields.defect_code') }}</th><th>{{ __('production_execution.fields.affected_quantity') }}</th><th>{{ __('production_execution.fields.corrective_action') }}</th><th>{{ __('production_execution.fields.notes') }}</th></tr></thead><tbody>
        @forelse($record->inspections as $inspection)<tr><td dir="ltr">{{ $inspection->doc_num }}</td><td>{{ $dates->formatDateTime($inspection->sampled_at, '—') }}</td><td>{{ __('production_execution.quality_results.'.$inspection->result) }}</td><td>{{ $inspection->defect_code ?: '—' }}</td><td dir="ltr">{{ $numbers->format($inspection->affected_base_quantity) }}</td><td>{{ $inspection->corrective_action ?: '—' }}</td><td>{{ $inspection->notes ?: '—' }}</td></tr>@empty<tr><td colspan="7">{{ __('production_execution.print.no_inspections') }}</td></tr>@endforelse
    </tbody></table>
    @include('reports.production.partials.signatures', ['areas' => ['quality', 'production_supervisor']])
@endsection

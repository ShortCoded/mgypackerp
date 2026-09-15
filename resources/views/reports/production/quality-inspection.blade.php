@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @include('reports.partials.company-identity')
    <h2>{{ $reportTitle }}</h2>
    <table class="report-table" style="margin-bottom:9px"><tbody>
        <tr><th>{{ __('production_execution.fields.inspection') }}</th><td dir="ltr">{{ $record->doc_num }}</td><th>{{ __('production_execution.fields.status') }}</th><td>{{ __('production_execution.statuses.'.$record->status) }}</td></tr>
        <tr><th>{{ __('production_execution.fields.run') }}</th><td dir="ltr">{{ $record->run?->run_number ?: '—' }}</td><th>{{ __('production_execution.fields.production_order') }}</th><td dir="ltr">{{ $record->run?->order?->doc_num ?: '—' }}</td></tr>
        <tr><th>{{ __('production_execution.fields.product') }}</th><td>{{ $record->product?->doc_num }} — {{ $record->product?->name }}</td><th>{{ __('production_execution.fields.stage') }}</th><td>{{ $record->stageSnapshot?->stage_name ?: '—' }}</td></tr>
        <tr><th>{{ __('production_execution.fields.sampled_at') }}</th><td>{{ $dates->formatDateTime($record->sampled_at, '—') }}</td><th>{{ __('production_execution.fields.result') }}</th><td>{{ __('production_execution.quality_results.'.$record->result) }}</td></tr>
        <tr><th>{{ __('production_execution.fields.inspection_type') }}</th><td>{{ $record->qualityType?->name ?: '—' }}</td><th>{{ __('production_execution.fields.affected_quantity') }}</th><td>{{ $numbers->format($record->affected_base_quantity) }}</td></tr>
        <tr><th>{{ __('production_execution.fields.disposition') }}</th><td>{{ $record->disposition ? __('production_execution.quality_dispositions.'.$record->disposition) : '—' }}</td><th>{{ __('production_execution.fields.defect_code') }}</th><td>{{ $record->defect_code ?: '—' }}</td></tr>
    </tbody></table>

    <h3>{{ __('production_execution.print.inspection_checkpoints') }}</h3>
    <table class="report-table"><thead><tr><th>#</th><th>{{ __('production_execution.print.checkpoint') }}</th><th>{{ __('production_execution.fields.measured_value') }}</th><th>{{ __('production_execution.fields.result') }}</th><th>{{ __('production_execution.fields.notes') }}</th></tr></thead><tbody>
        @forelse($record->results as $index => $result)@php($checkpoint = $checkpointNames->get($result->quality_checkpoint_id))<tr><td>{{ $index + 1 }}</td><td>{{ $checkpoint?->code }} — {{ app()->getLocale() === 'ar' && filled($checkpoint?->name_ar) ? $checkpoint->name_ar : $checkpoint?->name }}</td><td>{{ $result->measured_value ?: '—' }}</td><td>{{ $result->result ? __('production_execution.quality_results.'.$result->result) : '—' }}</td><td>{{ $result->notes ?: '—' }}</td></tr>@empty<tr><td colspan="5">{{ __('production_execution.print.no_inspection_results') }}</td></tr>@endforelse
    </tbody></table>

    <h3>{{ __('production_execution.print.reports_and_decisions') }}</h3>
    <table class="report-table"><thead><tr><th>{{ __('production_execution.fields.date') }}</th><th>{{ __('production_execution.fields.result') }}</th><th>{{ __('production_execution.fields.observations') }}</th><th>{{ __('production_execution.fields.submitted_by') }}</th></tr></thead><tbody>
        @forelse($record->reports as $report)<tr><td>{{ $dates->formatDateTime($report->reported_at, '—') }}</td><td>{{ $report->result ? __('production_execution.quality_results.'.$report->result) : '—' }}</td><td>{{ $report->observations ?: '—' }}</td><td>{{ $report->submittedBy?->name ?: '—' }}</td></tr>@empty<tr><td colspan="4">{{ __('production_execution.print.no_reports') }}</td></tr>@endforelse
    </tbody></table>
    @if($record->notes)<div style="margin-top:8px"><strong>{{ __('production_execution.fields.notes') }}:</strong> {{ $record->notes }}</div>@endif
    @include('reports.production.partials.signatures', ['areas' => ['quality', 'production_supervisor', 'warehouse']])
@endsection

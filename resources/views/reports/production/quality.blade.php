@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="document-title-row"><h1>{{ $reportTitle }}</h1></div>

<table class="report-table">
    <thead><tr>
        <th>{{ __('production_execution.fields.document') }}</th>
        <th>{{ __('production_execution.fields.requested_at') }}</th>
        <th>{{ __('production_execution.fields.product') }}</th>
        <th>{{ __('production_execution.fields.store') }}</th>
        <th>{{ __('production_execution.fields.run') }} / {{ __('production_execution.fields.stage') }}</th>
        <th>{{ __('production_execution.fields.inspection_type') }}</th>
        <th>{{ __('production_execution.fields.result') }} / {{ __('production_execution.fields.disposition') }}</th>
        <th>{{ __('production_execution.fields.affected_quantity') }} / {{ __('production_execution.fields.status') }}</th>
    </tr></thead>
    <tbody>
        @forelse($inspections as $inspection)
            <tr>
                <td>{{ $inspection->doc_num }}</td>
                <td>{{ $dates->formatDateTime($inspection->requested_at, '—') }}</td>
                <td>{{ $inspection->run?->product?->name ?? $inspection->product?->name ?? '—' }}</td>
                <td>{{ $inspection->branchStore?->name ?: '—' }}</td>
                <td>{{ $inspection->run?->run_number ?: '—' }} / {{ $inspection->stageSnapshot?->stage_name ?: '—' }}</td>
                <td>{{ $inspection->qualityType?->name ?: '—' }}</td>
                <td>{{ __('production_execution.quality_results.'.$inspection->result) }} / {{ $inspection->disposition ? __('production_execution.quality_dispositions.'.$inspection->disposition) : '—' }}</td>
                <td>{{ $inspection->affected_base_quantity !== null ? $numbers->format($inspection->affected_base_quantity) : '—' }} / {{ __('production_execution.statuses.'.$inspection->status) }}</td>
            </tr>
            <tr><td colspan="8" class="report-details-cell">
                <strong>{{ __('production_execution.fields.reinspection') }}:</strong> {{ $inspection->reinspection_number ?: '—' }} ·
                <strong>{{ __('production_execution.fields.inspection_subject') }}:</strong> {{ __('production_execution.quality_subjects.'.$inspection->subject_type) }} ·
                <strong>{{ __('production_execution.fields.reports_count') }}:</strong> {{ $inspection->reports->count() }} ·
                <strong>{{ __('production_execution.fields.attachments') }}:</strong> {{ count($inspection->evidence ?? []) }}<br>
                <strong>{{ __('production_execution.fields.notes') }}:</strong> {{ $inspection->notes ?: '—' }} ·
                <strong>{{ __('production_execution.fields.corrective_action') }}:</strong> {{ $inspection->corrective_action ?: '—' }}<br>
                <strong>{{ __('production_execution.fields.reviewed_at') }}:</strong> {{ $dates->formatDateTime($inspection->reviewed_at, '—') }} ·
                <strong>{{ __('production_execution.fields.closed_at') }}:</strong> {{ $dates->formatDateTime($inspection->closed_at, '—') }}
            </td></tr>
        @empty
            <tr><td colspan="8" class="report-empty-cell">{{ __('production_execution.quality.no_inspections') }}</td></tr>
        @endforelse
    </tbody>
</table>

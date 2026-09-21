@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp
<h1>{{ $reportTitle }}</h1>

<table>
    <tbody>
        <tr><th>{{ __('maintenance.fields.document') }}</th><td>{{ $order->doc_num }}</td><th>{{ __('maintenance.fields.status') }}</th><td>{{ __('maintenance.statuses.'.$order->status) }}</td></tr>
        <tr><th>{{ __('maintenance.fields.asset') }}</th><td>{{ $order->asset ? $order->asset->doc_num.' — '.$order->asset->asset_name : ($order->mold ? $order->mold->code.' — '.$order->mold->name : '—') }}</td><th>{{ __('maintenance.fields.priority') }}</th><td>{{ __('maintenance.priorities.'.$order->priority) }}</td></tr>
        <tr><th>{{ __('maintenance.fields.maintenance_type') }}</th><td>{{ __('maintenance.maintenance_types.'.$order->maintenance_type) }}</td><th>{{ __('maintenance.fields.service_mode') }}</th><td>{{ __('maintenance.service_modes.'.$order->service_mode) }}</td></tr>
        <tr><th>{{ __('maintenance.fields.provider') }}</th><td>{{ $order->supplier?->name ?: $order->external_provider_name ?: __('maintenance.internal') }}</td><th>{{ __('maintenance.fields.external_provider_contact') }}</th><td>{{ $order->external_provider_contact ?: '—' }}</td></tr>
        <tr><th>{{ __('maintenance.fields.planned_start') }}</th><td>{{ $dates->formatDateTime($order->planned_start_at, '—') }}</td><th>{{ __('maintenance.fields.planned_end') }}</th><td>{{ $dates->formatDateTime($order->planned_end_at, '—') }}</td></tr>
        <tr><th>{{ __('maintenance.fields.actual_start') }}</th><td>{{ $dates->formatDateTime($order->actual_start_at, '—') }}</td><th>{{ __('maintenance.fields.actual_end') }}</th><td>{{ $dates->formatDateTime($order->actual_end_at, '—') }}</td></tr>
        <tr><th>{{ __('maintenance.fields.machine_released_at') }}</th><td>{{ $dates->formatDateTime($order->machine_released_at, '—') }}</td><th>{{ __('maintenance.fields.cost_closed_at') }}</th><td>{{ $dates->formatDateTime($order->cost_closed_at, '—') }}</td></tr>
        <tr><th>{{ __('maintenance.fields.next_due_date') }}</th><td>{{ $dates->formatDate($order->next_due_date, '—') }}</td><th>{{ __('maintenance.fields.external_cost') }}</th><td class="number">{{ $order->external_cost ?? '—' }}</td></tr>
    </tbody>
</table>

@if($order->events->isNotEmpty())
    <h2>{{ __('maintenance.fields.execution_events') }}</h2>
    <table>
        <thead><tr><th>{{ __('maintenance.fields.occurred_at') }}</th><th>{{ __('maintenance.fields.event_type') }}</th><th>{{ __('maintenance.fields.reason') }}</th><th>{{ __('maintenance.fields.notes') }}</th><th>{{ __('maintenance.fields.participant_name') }}</th></tr></thead>
        <tbody>@foreach($order->events as $event)<tr><td>{{ $dates->formatDateTime($event->occurred_at, '') }}</td><td>{{ __('maintenance.event_types.'.$event->event_type) }}</td><td>{{ $event->reason ?: '—' }}</td><td>{{ collect($event->details ?? [])->filter()->implode(' — ') ?: '—' }}</td><td>{{ $event->recordedBy?->name ?? '—' }}</td></tr>@endforeach</tbody>
    </table>
@endif

<h2>{{ __('maintenance.fields.work_description') }}</h2>
<p>{{ $order->work_description }}</p>

@if($order->request)
    <h2>{{ __('maintenance.fields.source_report') }} — {{ $order->request->doc_num }}</h2>
    <p>{{ $order->request->symptoms }}</p>
@endif

<table>
    <tbody>
        <tr><th>{{ __('maintenance.fields.diagnosis') }}</th><td>{{ $order->diagnosis ?: '—' }}</td></tr>
        <tr><th>{{ __('maintenance.fields.root_cause') }}</th><td>{{ $order->root_cause ?: '—' }}</td></tr>
        <tr><th>{{ __('maintenance.fields.work_performed') }}</th><td>{{ $order->work_performed ?: '—' }}</td></tr>
        <tr><th>{{ __('maintenance.fields.test_result') }}</th><td>{{ $order->test_result ? __('maintenance.test_results.'.$order->test_result) : '—' }}</td></tr>
        <tr><th>{{ __('maintenance.fields.repair_outcome') }}</th><td>{{ $order->repair_outcome ? __('maintenance.repair_outcomes.'.$order->repair_outcome) : '—' }}</td></tr>
        <tr><th>{{ __('maintenance.fields.completion_notes') }}</th><td>{{ $order->completion_notes ?: '—' }}</td></tr>
    </tbody>
</table>

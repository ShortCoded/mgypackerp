<h1>{{ $reportTitle }}</h1>

<table>
    <tbody>
        <tr><th>{{ __('maintenance.fields.document') }}</th><td>{{ $order->doc_num }}</td><th>{{ __('maintenance.fields.status') }}</th><td>{{ __('maintenance.statuses.'.$order->status) }}</td></tr>
        <tr><th>{{ __('maintenance.fields.asset') }}</th><td>{{ $order->asset?->asset_code }} — {{ $order->asset?->asset_name }}</td><th>{{ __('maintenance.fields.priority') }}</th><td>{{ __('maintenance.priorities.'.$order->priority) }}</td></tr>
        <tr><th>{{ __('maintenance.fields.maintenance_type') }}</th><td>{{ __('maintenance.maintenance_types.'.$order->maintenance_type) }}</td><th>{{ __('maintenance.fields.service_mode') }}</th><td>{{ __('maintenance.service_modes.'.$order->service_mode) }}</td></tr>
        <tr><th>{{ __('maintenance.fields.provider') }}</th><td>{{ $order->supplier?->name ?: $order->external_provider_name ?: __('maintenance.internal') }}</td><th>{{ __('maintenance.fields.external_provider_contact') }}</th><td>{{ $order->external_provider_contact ?: '—' }}</td></tr>
        <tr><th>{{ __('maintenance.fields.planned_start') }}</th><td>{{ $order->planned_start_at?->format('Y-m-d H:i') ?? '—' }}</td><th>{{ __('maintenance.fields.planned_end') }}</th><td>{{ $order->planned_end_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
        <tr><th>{{ __('maintenance.fields.actual_start') }}</th><td>{{ $order->actual_start_at?->format('Y-m-d H:i') ?? '—' }}</td><th>{{ __('maintenance.fields.actual_end') }}</th><td>{{ $order->actual_end_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
        <tr><th>{{ __('maintenance.fields.next_due_date') }}</th><td>{{ $order->next_due_date?->toDateString() ?? '—' }}</td><th>{{ __('maintenance.fields.external_cost') }}</th><td class="number">{{ $order->external_cost ?? '—' }}</td></tr>
    </tbody>
</table>

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
        <tr><th>{{ __('maintenance.fields.completion_notes') }}</th><td>{{ $order->completion_notes ?: '—' }}</td></tr>
    </tbody>
</table>

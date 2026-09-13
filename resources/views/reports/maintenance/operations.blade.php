<h1>{{ $reportTitle }}</h1>

<table>
    <tbody>
        @foreach($kpis as $key => $value)
            <tr><th>{{ __('maintenance.reports.kpis.'.$key) }}</th><td class="number">{{ $value }}</td></tr>
        @endforeach
    </tbody>
</table>

<h2>{{ __('maintenance.reports.orders_table') }}</h2>
<table>
    <thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.maintenance_type') }}</th><th>{{ __('maintenance.fields.service_mode') }}</th><th>{{ __('maintenance.fields.provider') }}</th><th>{{ __('maintenance.fields.actual_start') }}</th><th>{{ __('maintenance.fields.actual_end') }}</th><th>{{ __('maintenance.fields.status') }}</th></tr></thead>
    <tbody>
        @forelse($orders as $order)
            <tr><td>{{ $order->doc_num }}</td><td>{{ $order->asset?->asset_code }} — {{ $order->asset?->asset_name }}</td><td>{{ __('maintenance.maintenance_types.'.$order->maintenance_type) }}</td><td>{{ __('maintenance.service_modes.'.$order->service_mode) }}</td><td>{{ $order->supplier?->name ?: $order->external_provider_name ?: __('maintenance.internal') }}</td><td>{{ $order->actual_start_at?->format('Y-m-d H:i') ?? '—' }}</td><td>{{ $order->actual_end_at?->format('Y-m-d H:i') ?? '—' }}</td><td>{{ __('maintenance.statuses.'.$order->status) }}</td></tr>
        @empty
            <tr><td colspan="8">{{ __('maintenance.reports.no_orders') }}</td></tr>
        @endforelse
    </tbody>
</table>

@if($canViewFinancial)
    <h2>{{ __('maintenance.reports.expense_totals') }}</h2>
    <table>
        <thead><tr><th>{{ __('maintenance.fields.currency') }}</th><th>{{ __('maintenance.reports.requested_amount') }}</th><th>{{ __('maintenance.reports.paid_amount') }}</th><th>{{ __('maintenance.reports.request_count') }}</th></tr></thead>
        <tbody>@forelse($expenseTotals as $total)<tr><td>{{ $total['currency'] }}</td><td class="number">{{ $total['requested'] }}</td><td class="number">{{ $total['paid'] }}</td><td class="number">{{ $total['count'] }}</td></tr>@empty<tr><td colspan="4">{{ __('maintenance.reports.no_expenses') }}</td></tr>@endforelse</tbody>
    </table>
@endif

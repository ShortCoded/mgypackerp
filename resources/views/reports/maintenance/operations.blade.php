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
    <thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.maintenance_type') }}</th><th>{{ __('maintenance.fields.service_mode') }}</th><th>{{ __('maintenance.fields.provider') }}</th><th>{{ __('maintenance.fields.actual_start') }}</th><th>{{ __('maintenance.fields.actual_end') }}</th><th>{{ __('maintenance.fields.total_paused_minutes') }}</th><th>{{ __('maintenance.reports.materials') }}</th><th>{{ __('maintenance.fields.status') }}</th></tr></thead>
    <tbody>
        @forelse($orders as $order)
            <tr><td>{{ $order->doc_num }}</td><td>{{ $order->asset ? $order->asset->doc_num.' — '.$order->asset->asset_name : ($order->mold ? $order->mold->code.' — '.$order->mold->name : '—') }}</td><td>{{ __('maintenance.maintenance_types.'.$order->maintenance_type) }}</td><td>{{ __('maintenance.service_modes.'.$order->service_mode) }}</td><td>{{ $order->supplier?->name ?: $order->external_provider_name ?: __('maintenance.internal') }}</td><td>{{ $order->actual_start_at?->format('Y-m-d H:i') ?? '—' }}</td><td>{{ $order->machine_released_at?->format('Y-m-d H:i') ?? $order->actual_end_at?->format('Y-m-d H:i') ?? '—' }}</td><td>{{ $order->total_paused_minutes + ($order->paused_at ? (int) $order->paused_at->diffInMinutes(now()) : 0) }}</td><td>
                @foreach($order->materialRequests as $materialRequest)
                    @foreach($materialRequest->lines as $materialLine)
                        @php
                            $issueLine = $materialRequest->issueDocument?->lines->firstWhere('source_line_id', $materialLine->getKey());
                            $returnLines = $materialRequest->returnDocument?->lines->filter(
                                fn ($rl): bool => (string) ($rl->source_line_id ?? null) === (string) $materialLine->getKey()
                            ) ?? collect();
                            $grossLineCost = $issueLine?->total_cost === null ? '0.0000' : bcadd((string) $issueLine->total_cost, '0', 4);
                            $returnedLineCost = $returnLines->reduce(
                                fn (string $carry, $rl): string => $rl->total_cost === null ? $carry : bcadd($carry, (string) $rl->total_cost, 4),
                                '0.0000'
                            );
                        @endphp
                        <div>{{ __('maintenance.reports.material_line_quantity', ['product' => $materialLine->product?->doc_num.' — '.$materialLine->product?->name, 'unit' => $materialLine->unit?->name ?? '—', 'issued' => $materialLine->issued_quantity, 'returned' => $materialLine->returned_quantity, 'net' => bcsub((string) $materialLine->issued_quantity, (string) $materialLine->returned_quantity, 8)]) }}</div>
                        @if($canViewFinancial)<div>{{ __('maintenance.reports.material_line_cost', ['unit_cost' => $issueLine?->unit_cost ?? '0.00000000', 'gross' => $grossLineCost, 'returned' => $returnedLineCost, 'net' => bcsub($grossLineCost, $returnedLineCost, 4)]) }}</div>@endif
                    @endforeach
                @endforeach
            </td><td>{{ __('maintenance.statuses.'.$order->status) }}</td></tr>
        @empty
            <tr><td colspan="10">{{ __('maintenance.reports.no_orders') }}</td></tr>
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

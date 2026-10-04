@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $visibleKpis = collect($kpis)->filter(fn ($value): bool => bccomp((string) $value, '0', 8) !== 0);
    $hasMaterials = $orders->contains(fn ($order): bool => $order->materialRequests->contains(fn ($request): bool => $request->lines->isNotEmpty()));
@endphp
<div class="report-print" dir="{{ $direction ?? 'ltr' }}">
    <style>
        .report-print h2 { font-size: 12px; margin: 7px 0 3px; page-break-after: avoid; }
        .report-print .maintenance-kpi-strip { width: 100%; border-collapse: separate; border-spacing: 3px; margin: 2px 0 5px; }
        .report-print .maintenance-kpi-strip td { width: 25%; padding: 5px 7px; border: 1px solid #c8dfe9; background: #f2f8fa; color: #173b5e; vertical-align: top; text-align: center; }
        .report-print .maintenance-kpi-strip td strong { display: block; margin-top: 2px; font-size: 12px; color: #123763; }
        .report-print .maintenance-kpi-strip td.is-empty { border: 0; background: transparent; }
        .report-print .report-table th, .report-print .report-table td { font-size: 12px; line-height: 1.25; padding: 4px; white-space: normal; }
    </style>
    <div class="report-filter-summary">{{ __('maintenance.reports.filters.from') }}: {{ $filters['from'] ?? '—' }} · {{ __('maintenance.reports.filters.to') }}: {{ $filters['to'] ?? '—' }}</div>

@if($visibleKpis->isNotEmpty())
    <table class="maintenance-kpi-strip"><tbody>
        @foreach($visibleKpis->chunk(4) as $group)
            <tr>
                @foreach($group as $key => $value)<td>{{ __('maintenance.reports.kpis.'.$key) }}<br><strong dir="ltr">{{ $numbers->format($value) }}</strong></td>@endforeach
                @for($empty = $group->count(); $empty < 4; $empty++)<td class="is-empty"></td>@endfor
            </tr>
        @endforeach
    </tbody></table>
@else
    <p class="report-empty-state">{{ __('reports.no_data') }}</p>
@endif

@if($requests->isNotEmpty())
    <h2 style="page-break-after: avoid">{{ __('maintenance.reports.requests_table') }}</h2>
    <table class="report-table">
        <thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.reported_at') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.request_type') }}</th><th>{{ __('maintenance.fields.priority') }}</th><th>{{ __('maintenance.fields.is_machine_stopped') }}</th><th>{{ __('maintenance.fields.status') }}</th></tr></thead>
        <tbody>@foreach($requests as $maintenanceRequest)<tr><td>{{ $maintenanceRequest->doc_num }}</td><td>{{ $dates->formatDateTime($maintenanceRequest->reported_at, '—') }}</td><td>{{ $maintenanceRequest->asset?->asset_name ?: $maintenanceRequest->mold?->name ?: '—' }}</td><td>{{ __('maintenance.request_types.'.$maintenanceRequest->request_type) }}</td><td>{{ __('maintenance.priorities.'.$maintenanceRequest->priority) }}</td><td>{{ $maintenanceRequest->is_machine_stopped ? __('Yes') : __('No') }}</td><td>{{ __('maintenance.statuses.'.$maintenanceRequest->status) }}</td></tr>@endforeach</tbody>
    </table>
@endif

@if($planDues->isNotEmpty())
    <h2 style="page-break-after: avoid">{{ __('maintenance.reports.plan_due_table') }}</h2>
    <table class="report-table">
        <thead><tr><th>{{ __('maintenance.fields.source_plan') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.due_at') }}</th><th>{{ __('maintenance.fields.status') }}</th></tr></thead>
        <tbody>@foreach($planDues as $due)<tr><td>{{ $due->plan?->doc_num }} — {{ $due->plan?->name }}</td><td>{{ $due->plan?->asset?->asset_name ?: $due->plan?->mold?->name ?: '—' }}</td><td>{{ $dates->formatDateTime($due->due_at, '—') }}</td><td>{{ __('maintenance.statuses.'.$due->status) }}</td></tr>@endforeach</tbody>
    </table>
@endif

@if($materialQuantityTotals->isNotEmpty())
    <h2>{{ __('maintenance.reports.material_quantities_by_unit') }}</h2>
    <table class="report-table">
        <thead><tr><th>{{ __('Unit') }}</th><th>{{ __('maintenance.reports.requested_material_quantity') }}</th><th>{{ __('maintenance.reports.issued_material_quantity') }}</th><th>{{ __('maintenance.reports.consumed_material_quantity') }}</th><th>{{ __('maintenance.reports.returned_material_quantity') }}</th><th>{{ __('maintenance.reports.net_material_quantity') }}</th></tr></thead>
        <tbody>@foreach($materialQuantityTotals as $total)<tr><td>{{ $total['unit'] }}</td><td class="number">{{ $numbers->format($total['requested']) }}</td><td class="number">{{ $numbers->format($total['issued']) }}</td><td class="number">{{ $numbers->format($total['consumed']) }}</td><td class="number">{{ $numbers->format($total['returned']) }}</td><td class="number">{{ $numbers->format($total['net']) }}</td></tr>@endforeach</tbody>
    </table>
@endif

<h2 style="page-break-after: avoid">{{ __('maintenance.reports.orders_table') }}</h2>
<table class="report-table">
    <thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.maintenance_type') }}</th><th>{{ __('maintenance.fields.service_mode') }}</th><th>{{ __('maintenance.fields.provider') }}</th><th>{{ __('maintenance.fields.actual_start') }}</th><th>{{ __('maintenance.fields.actual_end') }}</th><th>{{ __('maintenance.fields.total_paused_minutes') }}</th><th>{{ __('maintenance.reports.materials') }}</th><th>{{ __('maintenance.fields.status') }}</th></tr></thead>
    <tbody>
        @forelse($orders as $order)
            <tr><td>{{ $order->doc_num }}</td><td>{{ $order->asset ? $order->asset->doc_num.' — '.$order->asset->asset_name : ($order->mold ? $order->mold->code.' — '.$order->mold->name : '—') }}</td><td>{{ __('maintenance.maintenance_types.'.$order->maintenance_type) }}</td><td>{{ __('maintenance.service_modes.'.$order->service_mode) }}</td><td>{{ $order->supplier?->name ?: $order->external_provider_name ?: __('maintenance.internal') }}</td><td>{{ $dates->formatDateTime($order->actual_start_at, '—') }}</td><td>{{ $dates->formatDateTime($order->machine_released_at ?? $order->actual_end_at, '—') }}</td><td>{{ $order->total_paused_minutes + ($order->paused_at ? (int) $order->paused_at->diffInMinutes(now()) : 0) }}</td><td>{{ $order->materialRequests->flatMap->lines->count() }}</td><td>{{ __('maintenance.statuses.'.$order->status) }}</td></tr>
        @empty
            <tr><td colspan="10">{{ __('maintenance.reports.no_orders') }}</td></tr>
        @endforelse
    </tbody>
</table>

@if($hasMaterials)
    <h2 style="page-break-after: avoid">{{ __('maintenance.reports.materials') }}</h2>
    <table class="report-table">
        <thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.item') }}</th><th>{{ __('maintenance.reports.net_material_quantity') }}</th>@if($canViewFinancial)<th>{{ __('maintenance.reports.net_material_cost') }}</th>@endif</tr></thead>
        <tbody>
            @foreach($orders as $order)
                @foreach($order->materialRequests as $materialRequest)
                    @foreach($materialRequest->lines as $materialLine)
                        @php
                            $issueLine = $materialRequest->issueDocument?->lines->firstWhere('source_line_id', $materialLine->getKey());
                            $returnLines = $materialRequest->returnDocument?->lines->filter(fn ($rl): bool => (string) ($rl->source_line_id ?? null) === (string) $materialLine->getKey()) ?? collect();
                            $grossLineCost = $issueLine?->total_cost === null ? '0.0000' : bcadd((string) $issueLine->total_cost, '0', 4);
                            $returnedLineCost = $returnLines->reduce(fn (string $carry, $rl): string => $rl->total_cost === null ? $carry : bcadd($carry, (string) $rl->total_cost, 4), '0.0000');
                        @endphp
                        <tr>
                            <td>{{ $order->doc_num }}</td>
                            <td>{{ $materialLine->product?->doc_num }} — {{ $materialLine->product?->name }}</td>
                            <td>{{ __('maintenance.reports.material_line_quantity', ['product' => $materialLine->product?->doc_num, 'unit' => $materialLine->unit?->name ?? '—', 'issued' => $materialLine->issued_quantity, 'returned' => $materialLine->returned_quantity, 'net' => bcsub((string) $materialLine->issued_quantity, (string) $materialLine->returned_quantity, 8)]) }}</td>
                            @if($canViewFinancial)<td>{{ __('maintenance.reports.material_line_cost', ['unit_cost' => $issueLine?->unit_cost ?? '0.00000000', 'gross' => $grossLineCost, 'returned' => $returnedLineCost, 'net' => bcsub($grossLineCost, $returnedLineCost, 4)]) }}</td>@endif
                        </tr>
                    @endforeach
                @endforeach
            @endforeach
        </tbody>
    </table>
@endif

@if($canViewFinancial && $expenseTotals->isNotEmpty())
    <h2>{{ __('maintenance.reports.expense_totals') }}</h2>
    <table class="report-table">
        <thead><tr><th>{{ __('maintenance.fields.currency') }}</th><th>{{ __('maintenance.reports.requested_amount') }}</th><th>{{ __('maintenance.reports.paid_amount') }}</th><th>{{ __('maintenance.reports.request_count') }}</th></tr></thead>
        <tbody>@forelse($expenseTotals as $total)<tr><td>{{ $total['currency'] }}</td><td class="number">{{ $total['requested'] }}</td><td class="number">{{ $total['paid'] }}</td><td class="number">{{ $total['count'] }}</td></tr>@empty<tr><td colspan="4">{{ __('maintenance.reports.no_expenses') }}</td></tr>@endforelse</tbody>
    </table>
@endif
</div>

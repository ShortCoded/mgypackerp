@extends('layouts.app')

@section('title', __('maintenance.reports.title'))

@section('content')
<div class="production-mobile-workflow" data-client-report-tables>
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
        <div>
            <h4 class="mb-1">{{ __('maintenance.reports.title') }}</h4>
            <div class="text-muted">{{ __('maintenance.reports.description') }}</div>
        </div>
        @can('maintenance.reports.export')
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-success" href="{{ route('admin.maintenance.reports.export', request()->query()) }}">{{ __('maintenance.actions.export_excel') }}</a>
                <a class="btn btn-outline-secondary" target="_blank" href="{{ route('admin.maintenance.reports.print', request()->query()) }}">{{ __('maintenance.actions.print_pdf') }}</a>
            </div>
        @endcan
    </div>

    <form class="card card-body mb-3" method="GET">
        <div class="row g-3 align-items-end">
            <div class="col-6 col-lg-2"><label class="form-label">{{ __('maintenance.reports.filters.from') }}</label><x-forms.date-input name="from" :value="$filters['from'] ?? null" /></div>
            <div class="col-6 col-lg-2"><label class="form-label">{{ __('maintenance.reports.filters.to') }}</label><x-forms.date-input name="to" :value="$filters['to'] ?? null" /></div>
            <div class="col-12 col-md-4 col-lg-2"><label class="form-label">{{ __('maintenance.fields.maintenance_type') }}</label><x-forms.select variant="local" name="maintenance_type"><option value="">{{ __('maintenance.reports.filters.all') }}</option>@foreach(['preventive', 'corrective', 'emergency', 'condition_based', 'external'] as $value)<option value="{{ $value }}" @selected(($filters['maintenance_type'] ?? null) === $value)>{{ __('maintenance.maintenance_types.'.$value) }}</option>@endforeach</x-forms.select></div>
            <div class="col-12 col-md-4 col-lg-2"><label class="form-label">{{ __('maintenance.fields.service_mode') }}</label><x-forms.select variant="local" name="service_mode"><option value="">{{ __('maintenance.reports.filters.all') }}</option>@foreach(['internal', 'external', 'mixed'] as $value)<option value="{{ $value }}" @selected(($filters['service_mode'] ?? null) === $value)>{{ __('maintenance.service_modes.'.$value) }}</option>@endforeach</x-forms.select></div>
            <div class="col-12 col-md-4 col-lg-2"><label class="form-label">{{ __('maintenance.fields.status') }}</label><x-forms.select variant="local" name="status"><option value="">{{ __('maintenance.reports.filters.all') }}</option>@foreach(['draft', 'approved', 'in_progress', 'completed', 'closed', 'cancelled'] as $value)<option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ __('maintenance.statuses.'.$value) }}</option>@endforeach</x-forms.select></div>
            <div class="col-12 col-md-4 col-lg-2"><label class="form-label">{{ __('maintenance.fields.priority') }}</label><x-forms.select variant="local" name="priority"><option value="">{{ __('maintenance.reports.filters.all') }}</option>@foreach(['low', 'normal', 'high', 'urgent'] as $value)<option value="{{ $value }}" @selected(($filters['priority'] ?? null) === $value)>{{ __('maintenance.priorities.'.$value) }}</option>@endforeach</x-forms.select></div>
            <div class="col-12 col-md-4 col-lg-2"><label class="form-label">{{ __('maintenance.fields.discipline') }}</label><x-forms.select variant="local" name="discipline"><option value="">{{ __('maintenance.reports.filters.all') }}</option>@foreach(['electrical', 'mechanical', 'molds', 'other'] as $value)<option value="{{ $value }}" @selected(($filters['discipline'] ?? null) === $value)>{{ __('maintenance.disciplines.'.$value) }}</option>@endforeach</x-forms.select></div>
            <div class="col-12 col-md-4 col-lg-2"><label class="form-label">{{ __('maintenance.fields.test_result') }}</label><x-forms.select variant="local" name="test_result"><option value="">{{ __('maintenance.reports.filters.all') }}</option>@foreach(['passed', 'failed'] as $value)<option value="{{ $value }}" @selected(($filters['test_result'] ?? null) === $value)>{{ __('maintenance.test_results.'.$value) }}</option>@endforeach</x-forms.select></div>
            <div class="col-12 col-lg-2"><button class="btn btn-primary w-100" type="submit">{{ __('maintenance.reports.filters.apply') }}</button></div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <span class="d-none" data-report-count="maintenance_breakdowns">{{ $kpis['breakdown_reports'] }}</span>
        <span class="d-none" data-report-count="maintenance_overdue">{{ $kpis['overdue'] }}</span>
        @foreach($kpis as $key => $value)
            <div class="col-6 col-lg-3 col-xxl">
                <div class="card h-100"><div class="card-body"><div class="text-600 small">{{ __('maintenance.reports.kpis.'.$key) }}</div><div class="fs-5 fw-bold mt-1">{{ $value }}</div></div></div>
            </div>
        @endforeach
    </div>

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('maintenance.reports.requests_table') }}</h5></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.reported_at') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.request_type') }}</th><th>{{ __('maintenance.fields.priority') }}</th><th>{{ __('maintenance.fields.is_machine_stopped') }}</th><th>{{ __('maintenance.fields.status') }}</th></tr></thead>
                <tbody>@forelse($requests as $maintenanceRequest)<tr><td><a href="{{ route('admin.maintenance.requests.show', $maintenanceRequest) }}">{{ $maintenanceRequest->doc_num }}</a></td><td>{{ $maintenanceRequest->reported_at?->format('Y-m-d H:i') }}</td><td>{{ $maintenanceRequest->asset?->asset_name ?: $maintenanceRequest->mold?->name ?: '—' }}</td><td>{{ __('maintenance.request_types.'.$maintenanceRequest->request_type) }}</td><td>{{ __('maintenance.priorities.'.$maintenanceRequest->priority) }}</td><td>{{ $maintenanceRequest->is_machine_stopped ? __('Yes') : __('No') }}</td><td>{{ __('maintenance.statuses.'.$maintenanceRequest->status) }}</td></tr>@empty<tr><td colspan="7" class="text-center text-muted py-4">{{ __('maintenance.reports.no_requests') }}</td></tr>@endforelse</tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('maintenance.reports.plan_due_table') }}</h5></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>{{ __('maintenance.fields.source_plan') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.due_at') }}</th><th>{{ __('maintenance.fields.status') }}</th></tr></thead>
                <tbody>@forelse($planDues as $due)<tr><td>{{ $due->plan?->doc_num }} — {{ $due->plan?->name }}</td><td>{{ $due->plan?->asset?->asset_name ?: $due->plan?->mold?->name ?: '—' }}</td><td class="{{ $due->due_at?->isPast() ? 'text-danger fw-bold' : '' }}">{{ $due->due_at?->format('Y-m-d H:i') }}</td><td>{{ __('maintenance.statuses.'.$due->status) }}</td></tr>@empty<tr><td colspan="4" class="text-center text-muted py-4">{{ __('maintenance.reports.no_plan_dues') }}</td></tr>@endforelse</tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('maintenance.reports.orders_table') }}</h5></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.maintenance_type') }}</th><th>{{ __('maintenance.fields.service_mode') }}</th><th>{{ __('maintenance.fields.provider') }}</th><th>{{ __('maintenance.fields.actual_start') }}</th><th>{{ __('maintenance.fields.actual_end') }}</th><th>{{ __('maintenance.fields.total_paused_minutes') }}</th><th>{{ __('maintenance.reports.materials') }}</th>@if($canViewFinancial)<th>{{ __('maintenance.reports.expenses') }}</th>@endif<th>{{ __('maintenance.fields.status') }}</th></tr></thead>
                <tbody>
                    @forelse($orders as $order)
                        @php
                            $materialLines = $order->materialRequests->flatMap->lines;
                            $grossMaterialCost = $order->materialRequests->flatMap(fn ($request) => $request->issueDocument?->lines ?? collect())->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->total_cost, 4), '0.0000');
                            $returnedMaterialCost = $order->materialRequests->flatMap(fn ($request) => $request->returnDocument?->lines ?? collect())->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->total_cost, 4), '0.0000');
                            $issuedMaterialQuantity = $materialLines->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->issued_quantity, 8), '0.00000000');
                            $consumedMaterialQuantity = $materialLines->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->consumed_quantity, 8), '0.00000000');
                            $returnedMaterialQuantity = $materialLines->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->returned_quantity, 8), '0.00000000');
                            $netMaterialQuantity = $materialLines->reduce(fn (string $carry, $line): string => bcadd($carry, bcsub((string) $line->issued_quantity, (string) $line->returned_quantity, 8), 8), '0.00000000');
                            $displayMaterialQuantity = fn (string $quantity): string => rtrim(rtrim($quantity, '0'), '.') ?: '0';
                            $expenseSummary = $order->expenses->groupBy(fn ($expense) => $expense->currency?->code ?: '—')->map(fn ($rows, $currency) => $currency.': '.$rows->sum('amount'))->implode(' | ');
                        @endphp
                        <tr>
                            <td><a href="{{ route('admin.maintenance.orders.show', $order) }}">{{ $order->doc_num }}</a></td>
                            <td>{{ $order->asset ? $order->asset->doc_num.' — '.$order->asset->asset_name : ($order->mold ? $order->mold->code.' — '.$order->mold->name : '—') }}</td>
                            <td>{{ __('maintenance.maintenance_types.'.$order->maintenance_type) }}</td>
                            <td>{{ __('maintenance.service_modes.'.$order->service_mode) }}</td>
                            <td>{{ $order->supplier?->name ?: $order->external_provider_name ?: __('maintenance.internal') }}</td>
                            <td>{{ $order->actual_start_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>{{ $order->actual_end_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>{{ $order->total_paused_minutes + ($order->paused_at ? (int) $order->paused_at->diffInMinutes(now()) : 0) }}</td>
                            <td>
                                <div>{{ __('maintenance.reports.material_summary', ['issued' => $displayMaterialQuantity($issuedMaterialQuantity), 'consumed' => $displayMaterialQuantity($consumedMaterialQuantity), 'returned' => $displayMaterialQuantity($returnedMaterialQuantity), 'net' => $displayMaterialQuantity($netMaterialQuantity)]) }}</div>
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
                                        <div class="small mt-1">
                                            {{ __('maintenance.reports.material_line_quantity', ['product' => $materialLine->product?->doc_num.' — '.$materialLine->product?->name, 'unit' => $materialLine->unit?->name ?? '—', 'issued' => $materialLine->issued_quantity, 'returned' => $materialLine->returned_quantity, 'net' => bcsub((string) $materialLine->issued_quantity, (string) $materialLine->returned_quantity, 8)]) }}
                                            @if($canViewFinancial)
                                                <span class="text-600">{{ __('maintenance.reports.material_line_cost', ['unit_cost' => $issueLine?->unit_cost ?? '0.00000000', 'gross' => $grossLineCost, 'returned' => $returnedLineCost, 'net' => bcsub($grossLineCost, $returnedLineCost, 4)]) }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                @endforeach
                                @if($canViewFinancial)
                                    <div class="small text-600">{{ __('maintenance.reports.material_cost_summary', ['gross' => $grossMaterialCost, 'returned' => $returnedMaterialCost, 'net' => bcsub($grossMaterialCost, $returnedMaterialCost, 4)]) }}</div>
                                @endif
                            </td>
                            @if($canViewFinancial)<td>{{ $expenseSummary ?: '—' }}</td>@endif
                            <td><span class="badge rounded-pill badge-subtle-secondary">{{ __('maintenance.statuses.'.$order->status) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canViewFinancial ? 11 : 10 }}" class="text-center text-muted py-4">{{ __('maintenance.reports.no_orders') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($canViewFinancial)
        <div class="card">
            <div class="card-header"><h5 class="mb-0">{{ __('maintenance.reports.expense_totals') }}</h5></div>
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('maintenance.fields.currency') }}</th><th class="text-end">{{ __('maintenance.reports.requested_amount') }}</th><th class="text-end">{{ __('maintenance.reports.paid_amount') }}</th><th class="text-end">{{ __('maintenance.reports.request_count') }}</th></tr></thead><tbody>@forelse($expenseTotals as $total)<tr><td>{{ $total['currency'] }}</td><td class="text-end">{{ $total['requested'] }}</td><td class="text-end">{{ $total['paid'] }}</td><td class="text-end">{{ $total['count'] }}</td></tr>@empty<tr><td colspan="4" class="text-center text-muted">{{ __('maintenance.reports.no_expenses') }}</td></tr>@endforelse</tbody></table></div>
        </div>
    @endif
</div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush

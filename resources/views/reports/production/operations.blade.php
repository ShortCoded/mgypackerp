<div class="report-print" dir="{{ $direction ?? 'ltr' }}">
    <div class="document-title-row"><h1>{{ $reportTitle }}</h1></div>

@if($section === 'control')
    <style>
        .report-print .report-table th, .report-print .report-table td { font-size: 8px; line-height: 1.3; padding: 4px; white-space: normal; }
        .report-print h3 { margin: 9px 0 4px; }
        .report-print .report-warning { font-size: 8px; line-height: 1.3; padding: 4px; margin-bottom: 5px; }
    </style>
    <div class="report-filter-summary">{{ __('production_execution.reports.control.all_branches') }}: {{ $controlBranches->firstWhere('id', $controlFilters['branch_id'] ?? null)?->name ?? __('stock_balance_inquiry.options.all') }} · {{ __('production_execution.reports.filters.from') }}: {{ $controlFilters['from'] ?? '—' }} · {{ __('production_execution.reports.filters.to') }}: {{ $controlFilters['to'] ?? '—' }}</div>
    <p class="report-warning">{{ __('production_execution.reports.control.measurement_note') }}</p>
    @php($visibleControlKpis = collect($controlKpis)->filter(fn ($value): bool => bccomp((string) $value, '0', 8) !== 0))
    @if($visibleControlKpis->isNotEmpty())
        <table class="report-table"><thead><tr><th>{{ __('production_execution.reports.columns.metric') }}</th><th class="number">{{ __('production_execution.reports.columns.value') }}</th></tr></thead><tbody>
            @foreach($visibleControlKpis as $key => $value)<tr><td>{{ __('production_execution.reports.control.kpis.'.$key) }}</td><td class="number">{{ $numbers->format($value) }}</td></tr>@endforeach
        </tbody></table>
    @endif
    <h3>{{ __('production_execution.reports.control.run_details') }}</h3>
    <table class="report-table"><thead><tr>@foreach(['branch','date','shift','machine','run','product','planned','good','received','yield'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($controlRuns as $run)
            <tr><td>{{ $run->order?->branch?->name }}</td><td>{{ $dates->formatDate($run->actual_start_at ?? $run->planned_start_at) }}</td><td>{{ $run->shift?->name ?: '—' }}</td><td>{{ $run->fixedAsset?->asset_name ?? $run->machine?->name ?? '—' }}</td><td>{{ $run->run_number }}</td><td>{{ $run->product?->doc_num }} / {{ $run->product?->name }}</td><td class="number">{{ $numbers->format($run->planned_base_quantity) }}</td><td class="number">{{ $numbers->format($run->good_base_quantity) }}</td><td class="number">{{ $numbers->format($run->received_base_quantity) }}</td><td class="number">{{ $run->yield_percent === null ? '—' : $numbers->format($run->yield_percent) }}</td></tr>
        @empty<tr><td colspan="10" class="report-empty-cell">{{ __('production_execution.reports.control.no_runs') }}</td></tr>@endforelse
    </tbody></table>
    @if($controlRuns->isNotEmpty())
        <h3>{{ __('production_execution.reports.control.loss_details') }}</h3>
        <table class="report-table"><thead><tr>@foreach(['order','run','rejected','rework','scrap','unreceived','hours','material_exceptions','quality_holds'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
            @foreach($controlRuns as $run)
                <tr><td>{{ $run->order?->doc_num }}</td><td>{{ $run->run_number }}</td><td class="number">{{ $numbers->format($run->rejected_base_quantity) }}</td><td class="number">{{ $numbers->format($run->rework_base_quantity) }}</td><td class="number">{{ $numbers->format($run->scrap_base_quantity) }}</td><td class="number">{{ $numbers->format($run->receipt_remaining_base_quantity) }}</td><td class="number">{{ $run->actualDurationHours() ?? '—' }}</td><td class="number">{{ $run->material_exception_count }}</td><td class="number">{{ $run->quality_hold_count }}</td></tr>
            @endforeach
        </tbody></table>
    @endif
    <h3>{{ __('production_execution.reports.control.material_details') }}</h3>
    <table class="report-table"><thead><tr>@foreach(['branch','run','material','unit','planned','issued','returned','consumed','waste','variance'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($controlMaterials as $entry)
            @php($run = $entry['run']) @php($line = $entry['line'])
            @php($issued = bcadd((string) $line->issued_quantity, (string) $line->additional_issued_quantity, 8))
            @php($variance = bcsub($issued, bcadd(bcadd((string) $line->returned_quantity, (string) $line->consumed_quantity, 8), (string) $line->waste_quantity, 8), 8))
            <tr><td>{{ $run->order?->branch?->name }}</td><td>{{ $run->run_number }}</td><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td><td>{{ $line->unit?->name }}</td>@foreach([$line->planned_quantity, $issued, $line->returned_quantity, $line->consumed_quantity, $line->waste_quantity, $variance] as $value)<td class="number">{{ $numbers->format($value) }}</td>@endforeach</tr>
        @empty<tr><td colspan="10" class="report-empty-cell">{{ __('production_execution.reports.control.no_materials') }}</td></tr>@endforelse
    </tbody></table>
@elseif($section === 'overview')
    @php($visibleKpis = collect($kpis)->filter(fn ($value): bool => bccomp((string) $value, '0', 8) !== 0))
    @if($visibleKpis->isNotEmpty())
        <table class="report-table"><thead><tr><th>{{ __('production_execution.reports.columns.metric') }}</th><th class="number">{{ __('production_execution.reports.columns.value') }}</th></tr></thead><tbody>@foreach($visibleKpis as $label => $value)<tr><td>{{ __('production_execution.reports.kpis.'.$label) }}</td><td class="number">{{ $numbers->format($value) }}</td></tr>@endforeach</tbody></table>
    @else
        <p class="report-empty-state">{{ __('reports.no_data') }}</p>
    @endif
@elseif($section === 'orders')
    <table class="report-table">
        <thead><tr><th>{{ __('production_execution.reports.columns.order') }}</th><th>{{ __('production_execution.reports.columns.date') }}</th><th>{{ __('production_execution.reports.columns.source') }}</th><th>{{ __('production_execution.reports.columns.sales_order') }}</th><th class="number">{{ __('production_execution.reports.columns.planned_quantity') }}</th><th class="number">{{ __('production_execution.reports.columns.received_quantity') }}</th><th>{{ __('production_execution.reports.columns.status') }}</th></tr></thead>
        <tbody>
            @forelse($orders as $order)
                <tr><td>{{ $order->doc_num }}</td><td>{{ $dates->formatDate($order->production_order_date) }}</td><td>{{ __('production_execution.source_types.'.$order->source_type) }}</td><td>{{ $order->salesOrder?->doc_num ?: '—' }}</td><td class="number">{{ $numbers->format($order->lines->sum('base_quantity')) }}</td><td class="number">{{ $numbers->format($order->lines->sum('received_base_quantity')) }}</td><td>{{ __('production_execution.statuses.'.$order->status) }}</td></tr>
            @empty<tr><td colspan="7" class="report-empty-cell">{{ __('production_execution.reports.empty.orders') }}</td></tr>@endforelse
        </tbody>
    </table>
@elseif($section === 'runs')
    <table class="report-table">
        <thead><tr><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.order') }}</th><th>{{ __('production_execution.reports.columns.stage') }}</th><th>{{ __('production_execution.reports.columns.fixed_asset') }}</th><th>{{ __('production_execution.reports.columns.product') }}</th><th class="number">{{ __('production_execution.reports.columns.planned') }}</th><th class="number">{{ __('production_execution.reports.columns.good') }}</th><th>{{ __('production_execution.reports.columns.status') }}</th></tr></thead>
        <tbody>
            @forelse($runs as $run)
                <tr><td>{{ $run->run_number }}</td><td>{{ $run->order?->doc_num }}</td><td>{{ $run->stageSnapshot?->stage_name ?: '—' }}</td><td>{{ $run->fixedAsset?->asset_name ?: '—' }}</td><td>{{ $run->product?->name }}</td><td class="number">{{ $numbers->format($run->planned_base_quantity) }}</td><td class="number">{{ $numbers->format($run->good_base_quantity) }}</td><td>{{ __('production_execution.statuses.'.$run->status) }}</td></tr>
            @empty<tr><td colspan="8" class="report-empty-cell">{{ __('production_execution.reports.empty.runs') }}</td></tr>@endforelse
        </tbody>
    </table>
    @if($runs->isNotEmpty())
        <h3>{{ __('production_execution.reports.control.loss_details') }}</h3>
        <table class="report-table"><thead><tr><th>{{ __('production_execution.reports.columns.run') }}</th><th class="number">{{ __('production_execution.reports.columns.rejected') }}</th><th class="number">{{ __('production_execution.reports.columns.rework') }}</th><th class="number">{{ __('production_execution.reports.columns.scrap') }}</th><th class="number">{{ __('production_execution.reports.columns.received') }}</th><th class="number">{{ __('production_execution.reports.columns.receipt_remaining') }}</th><th class="number">{{ __('production_execution.reports.columns.yield_percent') }}</th></tr></thead><tbody>
            @foreach($runs as $run)<tr><td>{{ $run->run_number }}</td><td class="number">{{ $numbers->format($run->rejected_base_quantity) }}</td><td class="number">{{ $numbers->format($run->rework_base_quantity) }}</td><td class="number">{{ $numbers->format($run->scrap_base_quantity) }}</td><td class="number">{{ $numbers->format($run->received_base_quantity) }}</td><td class="number">{{ $numbers->format($run->receipt_remaining_base_quantity) }}</td><td class="number">{{ $run->yield_percent === null ? '—' : $numbers->format($run->yield_percent) }}</td></tr>@endforeach
        </tbody></table>
    @endif
@elseif($section === 'materials')
    <table class="report-table"><thead><tr><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.material') }}</th><th class="number">{{ __('production_execution.reports.columns.planned') }}</th><th class="number">{{ __('production_execution.reports.columns.issued') }}</th><th class="number">{{ __('production_execution.reports.columns.returned') }}</th><th class="number">{{ __('production_execution.reports.columns.consumed') }}</th><th class="number">{{ __('production_execution.reports.columns.waste') }}</th><th class="number">{{ __('production_execution.reports.columns.quantity_variance') }}</th><th class="number">{{ __('production_execution.reports.columns.accountability_variance') }}</th></tr></thead><tbody>
        @forelse($materials as $line)<tr><td>{{ $line->run?->run_number }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td class="number">{{ $numbers->format($line->planned_quantity) }}</td><td class="number">{{ $numbers->format($line->issued_total_quantity) }}</td><td class="number">{{ $numbers->format($line->returned_quantity) }}</td><td class="number">{{ $numbers->format($line->consumed_quantity) }}</td><td class="number">{{ $numbers->format($line->waste_quantity) }}</td><td class="number">{{ $numbers->format($line->quantity_variance) }}</td><td class="number">{{ $numbers->format($line->accountability_variance) }}</td></tr>
        @empty<tr><td colspan="9" class="report-empty-cell">{{ __('production_execution.reports.empty.materials') }}</td></tr>@endforelse
    </tbody></table>
@elseif($section === 'quality')
    <table class="report-table"><thead><tr><th>{{ __('production_execution.reports.columns.inspection') }}</th><th>{{ __('production_execution.reports.columns.sampled_at') }}</th><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.stage') }}</th><th>{{ __('production_execution.reports.columns.inspection_type') }}</th><th>{{ __('production_execution.reports.columns.result') }}</th><th>{{ __('production_execution.reports.columns.disposition') }}</th><th class="number">{{ __('production_execution.reports.columns.affected_quantity') }}</th><th>{{ __('production_execution.reports.columns.status') }}</th></tr></thead><tbody>
        @forelse($qualityInspections as $inspection)<tr><td>{{ $inspection->doc_num }}</td><td>{{ $dates->formatDateTime($inspection->sampled_at) }}</td><td>{{ $inspection->run?->run_number }}</td><td>{{ $inspection->stageSnapshot?->stage_name ?: '—' }}</td><td>{{ $inspection->qualityType?->name ?: '—' }}</td><td>{{ __('production_execution.quality_results.'.$inspection->result) }}</td><td>{{ __('production_execution.quality_dispositions.'.($inspection->disposition ?: 'hold')) }}</td><td class="number">{{ $numbers->format($inspection->affected_base_quantity) ?: '—' }}</td><td>{{ __('production_execution.statuses.'.$inspection->status) }}</td></tr>
        @empty<tr><td colspan="9" class="report-empty-cell">{{ __('production_execution.reports.empty.quality') }}</td></tr>@endforelse
    </tbody></table>
@elseif($section === 'receipts')
    <table class="report-table"><thead><tr><th>{{ __('production_execution.reports.columns.receipt') }}</th><th>{{ __('production_execution.reports.columns.date') }}</th><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.order') }}</th><th>{{ __('production_execution.reports.columns.store') }}</th><th>{{ __('production_execution.reports.columns.product') }}</th><th class="number">{{ __('production_execution.reports.columns.quantity') }}</th></tr></thead><tbody>
        @forelse($finishedGoodsReceipts as $document)
            @foreach($document->lines as $line)<tr><td>{{ $document->doc_num }}</td><td>{{ $dates->formatDate($document->document_date) }}</td><td>{{ $document->productionRun?->run_number }}</td><td>{{ $document->productionRun?->order?->doc_num }}</td><td>{{ $document->branchStore?->name }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td class="number">{{ $numbers->format($line->base_quantity) }}</td></tr>@endforeach
        @empty<tr><td colspan="7" class="report-empty-cell">{{ __('production_execution.reports.empty.receipts') }}</td></tr>@endforelse
    </tbody></table>
@endif
</div>

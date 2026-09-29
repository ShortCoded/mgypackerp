<div class="report-print" dir="{{ $direction ?? 'ltr' }}">
    @if($section !== 'control')
        <div class="document-title-row"><h1>{{ $reportTitle }}</h1></div>
    @endif

@if($section === 'control')
    <style>
        .report-print .report-table th, .report-print .report-table td { font-size: 8px; line-height: 1.2; padding: 2px; white-space: normal; }
        .report-print .report-control-summary-table { table-layout: fixed; }
        .report-print .report-control-summary-table th, .report-print .report-control-summary-table td { overflow-wrap: anywhere; }
        .report-print h3 { margin: 4px 0 2px; }
        .report-print .report-control-note { font-size: 8px; line-height: 1.2; font-weight: 400; color: #475569; margin: 1px 0 2px; }
    </style>
    <div class="report-filter-summary">{{ __('production_execution.reports.control.all_branches') }}: {{ $controlBranches->firstWhere('id', $controlFilters['branch_id'] ?? null)?->name ?? __('stock_balance_inquiry.options.all') }} · {{ __('production_execution.reports.filters.from') }}: {{ $controlFilters['from'] ?? '—' }} · {{ __('production_execution.reports.filters.to') }}: {{ $controlFilters['to'] ?? '—' }}</div>
    @php($visibleControlKpis = collect($controlKpis)->filter(fn ($value): bool => bccomp((string) $value, '0', 8) !== 0))
    @if($visibleControlKpis->isNotEmpty())
        <table class="report-table"><thead><tr><th>{{ __('production_execution.reports.columns.metric') }}</th><th class="number">{{ __('production_execution.reports.columns.value') }}</th></tr></thead><tbody>
            @foreach($visibleControlKpis as $key => $value)<tr><td>{{ __('production_execution.reports.control.kpis.'.$key) }}</td><td class="number">{{ $numbers->format($value) }}</td></tr>@endforeach
        </tbody></table>
    @endif
    @if($controlRuns->isNotEmpty() && $controlRuns->every(fn ($run): bool => bccomp((string) $run->report_recorded_base_quantity, '0', 8) === 0))
        <p class="report-empty-state">{{ __('production_execution.reports.control.no_output') }}</p>
    @endif
    @if(filled($controlFilters['from'] ?? null) || filled($controlFilters['to'] ?? null))
        <p class="report-control-note">{{ __('production_execution.reports.control.date_scope_note') }}</p>
    @endif
    <h3>{{ __('production_execution.reports.control.product_summary') }}</h3>
    <p class="report-control-note">{{ __('production_execution.reports.control.final_stage_note') }}</p>
    <table class="report-table"><thead><tr>@foreach(['branch','product','customer','stage','unit','pack_size','planned','good','equivalent_good','yield'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($controlProducts as $row)
            <tr><td>{{ $row['branch'] ?: '—' }}</td><td>{{ $row['product']?->doc_num }} / {{ $row['product']?->name }}@if($row['color'])<br><small>{{ $row['color'] }}</small>@endif @if($row['components'])<br><small>{{ $row['components'] }}</small>@endif</td><td>{{ $row['customer'] ?: '—' }}</td><td>{{ $row['stage'] ?: '—' }}</td><td>{{ $row['unit'] ?: '—' }}</td>
                @foreach(['pack_size','planned','good','equivalent_good','yield'] as $field)<td class="number">{{ $row[$field] === null ? '—' : $numbers->format($row[$field]) }}@if($field === 'good' && $row['good_weight_kg'] !== null)<br><small>{{ __('production_execution.reports.control.columns.good_weight_kg') }}: {{ $numbers->format($row['good_weight_kg']) }}</small>@if($row['unit_weight_kg'] !== null)<br><small>{{ __('production_execution.reports.control.columns.unit_weight_kg') }}: {{ $numbers->format($row['unit_weight_kg']) }}</small>@endif @endif @if($field === 'yield' && $row['production_scrap_weight_kg'] !== null)<br><small>{{ __('production_execution.reports.control.columns.production_scrap_weight_kg') }}: {{ $numbers->format($row['production_scrap_weight_kg']) }}</small>@if($row['production_scrap_percent'] !== null)<br><small>{{ __('production_execution.reports.control.columns.production_scrap_percent') }}: {{ $numbers->format($row['production_scrap_percent']) }}</small>@endif @endif</td>@endforeach
            </tr>
        @empty<tr><td colspan="10" class="report-empty-cell">{{ __('production_execution.reports.control.no_runs') }}</td></tr>@endforelse
    </tbody></table>
    <h3>{{ __('production_execution.reports.control.daily_output') }}</h3>
    <table class="report-table"><thead><tr>@foreach(['date','branch','machine','shift','run','product','good','equivalent_good','scrap'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($controlDaily as $row)
            @php($run = $row['run'])
            <tr><td>{{ $dates->formatDate($row['date']) }}</td><td>{{ $run->order?->branch?->name }}</td><td>{{ $run->fixedAsset?->asset_name ?? $run->machine?->name ?? '—' }}</td><td>{{ $run->shift?->name ?: '—' }}</td><td>{{ $run->run_number }}</td><td>{{ $run->product?->doc_num }} / {{ $run->product?->name }}@if($run->output_color_name)<br><small>{{ $run->output_color_name }}</small>@endif</td><td class="number">{{ $numbers->format($row['good']) }}@if($row['good_weight_kg'] !== null)<br><small>{{ __('production_execution.reports.control.columns.good_weight_kg') }}: {{ $numbers->format($row['good_weight_kg']) }}</small>@endif</td><td class="number">{{ $row['equivalent_good'] === null ? '—' : $numbers->format($row['equivalent_good']) }}</td><td class="number">{{ $numbers->format($row['scrap']) }}@if($row['production_scrap_weight_kg'] !== null)<br><small>{{ __('production_execution.reports.control.columns.production_scrap_weight_kg') }}: {{ $numbers->format($row['production_scrap_weight_kg']) }}</small>@endif</td></tr>
        @empty<tr><td colspan="9" class="report-empty-cell">{{ __('production_execution.reports.control.no_daily') }}</td></tr>@endforelse
    </tbody></table>
    <h3>{{ __('production_execution.reports.control.daily_materials') }}</h3>
    <table class="report-table"><thead><tr>@foreach(['date','branch','run','product','material','unit','consumed','waste','total_used','waste_percent','document'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($controlDailyMaterials as $row)
            <tr><td>{{ $dates->formatDate($row['date']) }}</td><td>{{ $row['run']->order?->branch?->name }}</td><td>{{ $row['run']->run_number }}</td><td>{{ $row['run']->product?->doc_num }} / {{ $row['run']->product?->name }}</td><td>{{ $row['material']?->doc_num }} / {{ $row['material']?->name }}</td><td>{{ $row['unit'] ?: '—' }}</td>
                @foreach(['consumed','waste','total_used','waste_percent'] as $field)<td class="number">{{ $row[$field] === null ? '—' : $numbers->format($row[$field]) }}</td>@endforeach
                <td>{{ $row['documents'] }}</td></tr>
        @empty<tr><td colspan="11" class="report-empty-cell">{{ __('production_execution.reports.control.no_daily_materials') }}</td></tr>@endforelse
    </tbody></table>
    <h3>{{ __('production_execution.reports.control.machine_summary') }}</h3>
    <table class="report-table"><thead><tr>@foreach(['branch','machine','stage','product','unit','runs_count','planned','good','yield'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($controlMachines as $row)
            <tr><td>{{ $row['branch'] ?: '—' }}</td><td>{{ $row['machine'] ?: '—' }}</td><td>{{ $row['stage'] ?: '—' }}</td><td>{{ $row['product']?->doc_num }} / {{ $row['product']?->name }}@if($row['color'])<br><small>{{ $row['color'] }}</small>@endif</td><td>{{ $row['unit'] ?: '—' }}</td><td class="number">{{ $row['runs'] }}</td><td class="number">{{ $numbers->format($row['planned']) }}</td><td class="number">{{ $row['good'] === null ? '—' : $numbers->format($row['good']) }}@if($row['good_weight_kg'] !== null)<br><small>{{ __('production_execution.reports.control.columns.good_weight_kg') }}: {{ $numbers->format($row['good_weight_kg']) }}</small>@endif</td><td class="number">{{ $row['yield'] === null ? '—' : $numbers->format($row['yield']) }}@if($row['production_scrap_weight_kg'] !== null)<br><small>{{ __('production_execution.reports.control.columns.production_scrap_weight_kg') }}: {{ $numbers->format($row['production_scrap_weight_kg']) }}</small>@endif</td></tr>
        @empty<tr><td colspan="9" class="report-empty-cell">{{ __('production_execution.reports.control.no_runs') }}</td></tr>@endforelse
    </tbody></table>
    <h3>{{ __('production_execution.reports.control.material_summary') }}</h3>
    <p class="report-control-note">{{ __('production_execution.reports.control.material_unit_note') }}</p>
    <table class="report-table"><thead><tr>@foreach(['product','material','unit','planned','issued','consumed','waste','total_used','consumed_per_equivalent','waste_percent','variance'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($controlMaterialSummary as $row)
            <tr><td>{{ $row['product']?->doc_num }} / {{ $row['product']?->name }}@if($row['color'])<br><small>{{ $row['color'] }}</small>@endif @if($row['stage'])<br><small>{{ $row['stage'] }}</small>@endif</td><td>{{ $row['material']?->doc_num }} / {{ $row['material']?->name }}</td><td>{{ $row['unit'] ?: '—' }}</td>
                @foreach(['planned','issued','consumed','waste','total_used'] as $field)<td class="number">{{ $row[$field] === null ? '—' : $numbers->format($row[$field]) }}</td>@endforeach
                <td class="number">{{ $row['consumed_per_equivalent'] === null ? '—' : $numbers->format($row['consumed_per_equivalent']) }}@if($row['consumed_per_equivalent'] !== null && $row['consumption_unit'])<br><small>{{ $row['consumption_unit'] }}</small>@endif</td>
                @foreach(['waste_percent','variance'] as $field)<td class="number">{{ $row[$field] === null ? '—' : $numbers->format($row[$field]) }}</td>@endforeach
            </tr>
        @empty<tr><td colspan="11" class="report-empty-cell">{{ __('production_execution.reports.control.no_materials') }}</td></tr>@endforelse
    </tbody></table>
    <h3>{{ __('production_execution.reports.control.run_details') }}</h3>
    <table class="report-table"><thead><tr>@foreach(['branch','date','shift','machine','run','product','planned','good','received','yield'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($controlRuns as $run)
            <tr><td>{{ $run->order?->branch?->name }}</td><td>{{ $dates->formatDate($run->actual_start_at ?? $run->planned_start_at) }}</td><td>{{ $run->shift?->name ?: '—' }}</td><td>{{ $run->fixedAsset?->asset_name ?? $run->machine?->name ?? '—' }}</td><td>{{ $run->run_number }}</td><td>{{ $run->product?->doc_num }} / {{ $run->product?->name }}</td><td class="number">{{ $numbers->format($run->planned_base_quantity) }}</td><td class="number">{{ $run->report_yield_percent === null ? '—' : $numbers->format($run->report_good_base_quantity) }}@if($run->report_good_weight_kg !== null)<br><small>{{ $numbers->format($run->report_good_weight_kg) }} kg</small>@endif</td><td class="number">{{ bccomp((string) $run->report_received_base_quantity, '0', 8) > 0 ? $numbers->format($run->report_received_base_quantity) : '—' }}</td><td class="number">{{ $run->report_yield_percent === null ? '—' : $numbers->format($run->report_yield_percent) }}@if($run->report_production_scrap_weight_kg !== null)<br><small>{{ __('production_execution.reports.control.columns.production_scrap_weight_kg') }}: {{ $numbers->format($run->report_production_scrap_weight_kg) }}</small>@endif</td></tr>
        @empty<tr><td colspan="10" class="report-empty-cell">{{ __('production_execution.reports.control.no_runs') }}</td></tr>@endforelse
    </tbody></table>
    @if($controlRuns->isNotEmpty())
        <h3>{{ __('production_execution.reports.control.loss_details') }}</h3>
        <table class="report-table"><thead><tr>@foreach(['order','run','rejected','rework','scrap','unreceived','hours','material_exceptions','quality_holds'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
            @foreach($controlRuns as $run)
                <tr><td>{{ $run->order?->doc_num }}</td><td>{{ $run->run_number }}</td><td class="number">{{ $numbers->format($run->report_rejected_base_quantity) }}</td><td class="number">{{ $numbers->format($run->report_rework_base_quantity) }}</td><td class="number">{{ $numbers->format($run->report_scrap_base_quantity) }}</td><td class="number">{{ $numbers->format($run->report_receipt_remaining_base_quantity) }}</td><td class="number">{{ $run->actualDurationHours() ?? '—' }}</td><td class="number">{{ $run->material_exception_count }}</td><td class="number">{{ $run->quality_hold_count }}</td></tr>
            @endforeach
        </tbody></table>
    @endif
    <h3>{{ __('production_execution.reports.control.material_details') }}</h3>
    <table class="report-table"><thead><tr>@foreach(['branch','run','material','unit','planned','issued','returned','consumed','waste','variance'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($controlMaterials as $entry)
            @php($run = $entry['run']) @php($line = $entry['line'])
            <tr><td>{{ $run->order?->branch?->name }}</td><td>{{ $run->run_number }}</td><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td><td>{{ $line->unit?->name }}</td><td class="number">{{ $numbers->format($line->planned_quantity) }}@if($entry['basis_quantity'] !== null)<br><small dir="ltr">{{ $numbers->format($entry['basis_quantity']) }} × {{ $numbers->format($line->component_quantity_snapshot) }}</small>@endif</td>@foreach([$entry['issued'], $line->returned_quantity, $line->consumed_quantity, $line->waste_quantity, $entry['variance']] as $value)<td class="number">{{ $entry['status'] === 'pending' ? '—' : $numbers->format($value) }}</td>@endforeach</tr>
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

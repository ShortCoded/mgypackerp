@extends('layouts.app')

@php
    $sectionRoutes = [
        'overview' => 'admin.production.reports.index',
        'orders' => 'admin.production.reports.orders',
        'runs' => 'admin.production.reports.runs',
        'control' => 'admin.production.reports.control',
        'materials' => 'admin.production.reports.materials',
        'quality' => 'admin.production.reports.quality',
        'receipts' => 'admin.production.reports.receipts',
    ];
    $exportQuery = [...request()->query(), 'section' => $section];
    $sectionPermission = $section;
@endphp

@section('title', __('production_execution.reports.sections.'.$section))

@section('content')
    <div class="production-mobile-workflow admin-report-page" data-client-report-tables>
        <div class="card mb-3">
            <div class="card-header py-2">
                <div class="row flex-between-center g-2">
                    <div class="col"><h5 class="mb-0">{{ __('production_execution.reports.sections.'.$section) }}</h5></div>
                    @if(auth()->user()?->can("production.reports.{$sectionPermission}.export") || auth()->user()?->can("production.reports.{$sectionPermission}.print"))
                        <div class="col-auto d-flex flex-wrap gap-2">
                            @can("production.reports.{$sectionPermission}.export")<a class="btn btn-falcon-success btn-sm" href="{{ route('admin.production.reports.export', $exportQuery) }}"><span class="fas fa-file-excel me-1"></span>{{ __('production_execution.actions.export_excel') }}</a>@endcan
                            @can("production.reports.{$sectionPermission}.print")<a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ route('admin.production.reports.print', $exportQuery) }}"><span class="fas fa-file-pdf me-1"></span>{{ __('production_execution.actions.print_pdf') }}</a>@endcan
                        </div>
                    @endif
                </div>
            </div>
            <div class="card-body py-3">
                <form method="GET" action="{{ route($sectionRoutes[$section]) }}" class="row g-3 align-items-end">
                    @if(request('operational_focus'))<x-forms.input type="hidden" name="operational_focus" value="{{ request('operational_focus') }}" />@endif
                    @if($section === 'control')
                        <div class="col-md-3"><x-forms.label for="production-control-branch" :label="__('production_execution.reports.control.columns.branch')" /><x-forms.select variant="ajax" id="production-control-branch" name="branch_doc_num" :url="route('admin.select2.branches', ['access_scope' => 'operating_scope', 'company_doc_num' => $controlCompanyDocNum, 'branch_type' => \Modules\Core\Models\Branch::TypeFactory])" :placeholder="__('production_execution.reports.control.all_branches')"><option value=""></option>@if($selectedControlBranch = $controlBranches->firstWhere('doc_num', $controlFilters['branch_doc_num'] ?? null))<option value="{{ $selectedControlBranch->doc_num }}" selected>{{ $selectedControlBranch->name }} / {{ $selectedControlBranch->doc_num }}</option>@endif</x-forms.select></div>
                    @endif
                    <div class="col-md-3"><x-forms.label for="production-report-from" :label="__('production_execution.reports.filters.from')" /><x-forms.date-input id="production-report-from" name="from" :value="request('from')" /></div>
                    <div class="col-md-3"><x-forms.label for="production-report-to" :label="__('production_execution.reports.filters.to')" /><x-forms.date-input id="production-report-to" name="to" :value="request('to')" /></div>
                    <div class="col-md-3">
                        <x-forms.label for="production-report-status" :label="__('production_execution.fields.status')" />
                        <x-forms.select variant="local" id="production-report-status" name="status" :placeholder="__('production_execution.reports.filters.all_statuses')">
                            <option value=""></option>
                            @foreach(['draft', 'planned', 'released', 'in_progress', 'setup', 'ready', 'running', 'held', 'partially_completed', 'completed', 'short_closed', 'cancelled'] as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('production_execution.statuses.'.$status) }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    @if($section === 'control')
                        @foreach(['product', 'machine', 'shift', 'stage', 'order'] as $field)
                            <div class="col-md-3"><x-forms.label for="production-control-{{ $field }}" :label="__('production_execution.reports.control.filter_'.$field)" /><x-forms.input id="production-control-{{ $field }}" name="{{ $field }}" :value="request($field)" /></div>
                        @endforeach
                    @endif
                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-primary flex-fill" type="submit"><span class="fas fa-filter me-1"></span>{{ __('production_execution.reports.filters.apply') }}</button>
                        <a class="btn btn-falcon-default" href="{{ route($sectionRoutes[$section]) }}">{{ __('production_execution.reports.filters.reset') }}</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3" role="navigation" aria-label="{{ __('production_execution.reports.title') }}">
            @foreach($sectionRoutes as $reportSection => $routeName)
                @can('production.reports.'.$reportSection.'.view')<a class="btn btn-sm {{ $section === $reportSection ? 'btn-primary' : 'btn-falcon-default' }}" href="{{ route($routeName, request()->only(['from', 'to', 'status'])) }}">{{ __('production_execution.reports.sections.'.$reportSection) }}</a>@endcan
            @endforeach
        </div>

        @if($section === 'overview')
            @php($visibleKpis = collect($kpis)->filter(fn ($value): bool => bccomp((string) $value, '0', 8) !== 0))
            @if($visibleKpis->isNotEmpty())
                <div class="row g-3 mb-3">
                    @foreach($visibleKpis as $label => $value)
                        <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body py-3"><div class="fw-semi-bold text-700">{{ __('production_execution.reports.kpis.'.$label) }}</div><div class="fs-4 fw-bold mt-1" dir="ltr">{{ $numbers->format($value) }}</div></div></div></div>
                    @endforeach
                </div>
            @else
                <div class="alert alert-light border mb-3">{{ __('reports.no_data') }}</div>
            @endif
            <div class="row g-3">
                @foreach(array_diff(array_keys($sectionRoutes), ['overview']) as $reportSection)
                    @can('production.reports.'.$reportSection.'.view')<div class="col-md-6 col-xl-4"><a class="card h-100 text-decoration-none" href="{{ route($sectionRoutes[$reportSection], request()->only(['from', 'to', 'status'])) }}"><div class="card-body d-flex justify-content-between align-items-center gap-3"><h6 class="mb-0 text-900">{{ __('production_execution.reports.sections.'.$reportSection) }}</h6><span class="fas fa-chevron-left text-primary rtl-flip"></span></div></a></div>@endcan
                @endforeach
            </div>
        @elseif($section === 'orders')
            <div class="d-none" data-report-count="production_remaining">{{ $orders->count() }}</div>
            <x-production.report-card :title="__('production_execution.reports.sections.orders')" :empty-message="__('production_execution.reports.empty.orders')" :has-rows="$orders->isNotEmpty()" :columns="8">
                <x-slot:head><th>{{ __('production_execution.reports.columns.order') }}</th><th>{{ __('production_execution.reports.columns.date') }}</th><th>{{ __('production_execution.reports.columns.source') }}</th><th>{{ __('production_execution.reports.columns.sales_order') }}</th><th class="text-end">{{ __('production_execution.reports.columns.planned_quantity') }}</th><th class="text-end">{{ __('production_execution.reports.columns.received_quantity') }}</th><th class="text-end">{{ __('production_execution.reports.columns.remaining_quantity') }}</th><th>{{ __('production_execution.reports.columns.status') }}</th></x-slot:head>
                @foreach($orders as $order)<tr><td><a href="{{ route('admin.production.work-orders.show', $order) }}">{{ $order->doc_num }}</a></td><td>{{ $dates->formatDate($order->production_order_date) }}</td><td>{{ __('production_execution.source_types.'.$order->source_type) }}</td><td>{{ $order->salesOrder?->doc_num ?: '—' }}</td><td class="text-end" dir="ltr">{{ $numbers->format($order->planned_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($order->received_base_quantity) }}</td><td class="text-end fw-bold" dir="ltr">{{ $numbers->format($order->remaining_base_quantity) }}</td><td>{{ __('production_execution.statuses.'.$order->status) }}</td></tr>@endforeach
            </x-production.report-card>
        @elseif($section === 'control')
            <div class="alert alert-info mb-3">{{ __('production_execution.reports.control.measurement_note') }}</div>
            @php($visibleControlKpis = collect($controlKpis)->filter(fn ($value): bool => bccomp((string) $value, '0', 8) !== 0))
            @if($visibleControlKpis->isNotEmpty())
                <div class="row g-3 mb-3">
                    @foreach($visibleControlKpis as $metric => $value)
                        <div class="col-sm-6 col-lg-4 col-xxl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body py-3"><div class="small text-muted">{{ __('production_execution.reports.control.kpis.'.$metric) }}</div><div class="fs-4 fw-bold text-primary" dir="ltr">{{ $numbers->format($value) }}</div></div></div></div>
                    @endforeach
                </div>
            @else
                <div class="alert alert-light border mb-3">{{ __('reports.no_data') }}</div>
            @endif
            <x-production.report-card :title="__('production_execution.reports.control.run_details')" :empty-message="__('production_execution.reports.control.no_runs')" :has-rows="$controlRuns->isNotEmpty()" :columns="19" :wide="true">
                <x-slot:head>@foreach(['branch','date','shift','machine','stage','order','run','product','planned','good','rejected','rework','scrap','received','unreceived','yield','hours','material_exceptions','quality_holds'] as $column)<th class="{{ in_array($column, ['planned','good','rejected','rework','scrap','received','unreceived','yield','hours','material_exceptions','quality_holds'], true) ? 'text-end' : '' }}">{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</x-slot:head>
                @foreach($controlRuns as $run)
                    <tr>
                        <td>{{ $run->order?->branch?->name }}</td><td>{{ $dates->formatDate($run->actual_start_at ?? $run->planned_start_at) }}</td><td>{{ $run->shift?->name ?: '—' }}</td><td>{{ $run->fixedAsset?->asset_name ?? $run->machine?->name ?? '—' }}</td><td>{{ $run->stageSnapshot?->stage_name ?: '—' }}</td>
                        <td>{{ $run->order?->doc_num }}</td><td><a href="{{ route('admin.production.runs.show', $run) }}">{{ $run->run_number }}</a></td><td>{{ $run->product?->doc_num }} — {{ $run->product?->name }} ({{ $run->product?->unit?->name }})</td>
                        @foreach(['planned_base_quantity','good_base_quantity','rejected_base_quantity','rework_base_quantity','scrap_base_quantity','received_base_quantity','receipt_remaining_base_quantity','yield_percent'] as $value)<td class="text-end" dir="ltr">{{ $run->{$value} === null ? '—' : $numbers->format($run->{$value}) }}</td>@endforeach
                        <td class="text-end" dir="ltr">{{ $run->actualDurationHours() ?? '—' }}</td><td class="text-end" dir="ltr">{{ $run->material_exception_count }}</td><td class="text-end" dir="ltr">{{ $run->quality_hold_count }}</td>
                    </tr>
                @endforeach
            </x-production.report-card>
            <x-production.report-card :title="__('production_execution.reports.control.material_details')" :empty-message="__('production_execution.reports.control.no_materials')" :has-rows="$controlMaterials->isNotEmpty()" :columns="12" :wide="true">
                <x-slot:head>@foreach(['branch','run','product','material','unit','planned','issued','returned','consumed','waste','variance','status'] as $column)<th>{{ __('production_execution.reports.control.columns.'.$column) }}</th>@endforeach</x-slot:head>
                @foreach($controlMaterials as $entry)
                    @php($run = $entry['run']) @php($line = $entry['line'])
                    @php($issued = bcadd((string) $line->issued_quantity, (string) $line->additional_issued_quantity, 8))
                    @php($variance = bcsub($issued, bcadd(bcadd((string) $line->returned_quantity, (string) $line->consumed_quantity, 8), (string) $line->waste_quantity, 8), 8))
                    <tr><td>{{ $run->order?->branch?->name }}</td><td>{{ $run->run_number }}</td><td>{{ $run->product?->name }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td>{{ $line->unit?->name }}</td>
                        @foreach([$line->planned_quantity, $issued, $line->returned_quantity, $line->consumed_quantity, $line->waste_quantity, $variance] as $value)<td class="text-end" dir="ltr">{{ $numbers->format($value) }}</td>@endforeach
                        <td>{{ bccomp($variance, '0', 8) === 0 ? '✓' : '!' }}</td></tr>
                @endforeach
            </x-production.report-card>
        @elseif($section === 'runs')
            <x-production.report-card :title="__('production_execution.reports.sections.runs')" :empty-message="__('production_execution.reports.empty.runs')" :has-rows="$runs->isNotEmpty()" :columns="15">
                <x-slot:head><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.order') }}</th><th>{{ __('production_execution.reports.columns.stage') }}</th><th>{{ __('production_execution.reports.columns.fixed_asset') }}</th><th>{{ __('production_execution.reports.columns.product') }}</th><th class="text-end">{{ __('production_execution.reports.columns.planned') }}</th><th class="text-end">{{ __('production_execution.reports.columns.recorded') }}</th><th class="text-end">{{ __('production_execution.reports.columns.remaining_quantity') }}</th><th class="text-end">{{ __('production_execution.reports.columns.good') }}</th><th class="text-end">{{ __('production_execution.reports.columns.received') }}</th><th class="text-end">{{ __('production_execution.reports.columns.receipt_remaining') }}</th><th class="text-end">{{ __('production_execution.reports.columns.rejected') }}</th><th class="text-end">{{ __('production_execution.reports.columns.rework') }}</th><th class="text-end">{{ __('production_execution.reports.columns.scrap') }}</th><th>{{ __('production_execution.reports.columns.status') }}</th></x-slot:head>
                @foreach($runs as $run)<tr><td><a href="{{ route('admin.production.runs.show', $run) }}">{{ $run->run_number }}</a></td><td>{{ $run->order?->doc_num }}</td><td>{{ $run->stageSnapshot?->stage_name ?: '—' }}</td><td>{{ $run->fixedAsset?->asset_name ?: '—' }}</td><td>{{ $run->product?->name }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->planned_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->recorded_base_quantity) }}</td><td class="text-end fw-bold" dir="ltr">{{ $numbers->format($run->remaining_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->good_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->received_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->receipt_remaining_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->rejected_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->rework_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->scrap_base_quantity) }}</td><td>{{ __('production_execution.statuses.'.$run->status) }}</td></tr>@endforeach
            </x-production.report-card>
        @elseif($section === 'materials')
            @if(request('operational_focus') === 'shortage')
                <div class="d-none" data-report-count="material_shortages">{{ $materialShortages->count() }}</div>
                <x-production.report-card :title="__('production_execution.reports.material_shortages')" :empty-message="__('production_execution.reports.empty.material_shortages')" :has-rows="$materialShortages->isNotEmpty()" :columns="9">
                    <x-slot:head><th>{{ __('production_execution.reports.columns.request') }}</th><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.store') }}</th><th>{{ __('production_execution.reports.columns.material') }}</th><th class="text-end">{{ __('production_execution.reports.columns.planned') }}</th><th class="text-end">{{ __('production_execution.reports.columns.reserved') }}</th><th class="text-end">{{ __('production_execution.reports.columns.issued') }}</th><th class="text-end">{{ __('production_execution.reports.columns.shortage') }}</th><th>{{ __('production_execution.reports.columns.status') }}</th></x-slot:head>
                    @foreach($materialShortages as $line)<tr><td><a href="{{ route('admin.production.material-requests.show', $line->request) }}">{{ $line->request?->doc_num }}</a></td><td>{{ $line->request?->run?->run_number }}</td><td>{{ $line->request?->store?->name }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }} ({{ $line->unit?->name }})</td><td class="text-end">{{ $numbers->format($line->planned_quantity) }}</td><td class="text-end">{{ $numbers->format($line->reserved_quantity) }}</td><td class="text-end">{{ $numbers->format($line->issued_quantity) }}</td><td class="text-end text-danger fw-bold">{{ $numbers->format($line->shortage_quantity) }}</td><td>{{ __('production_execution.statuses.'.$line->request?->status) }}</td></tr>@endforeach
                </x-production.report-card>
            @else
            <x-production.report-card :title="__('production_execution.reports.sections.materials')" :empty-message="__('production_execution.reports.empty.materials')" :has-rows="$materials->isNotEmpty()" :columns="9">
                <x-slot:head><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.material') }}</th><th class="text-end">{{ __('production_execution.reports.columns.planned') }}</th><th class="text-end">{{ __('production_execution.reports.columns.issued') }}</th><th class="text-end">{{ __('production_execution.reports.columns.returned') }}</th><th class="text-end">{{ __('production_execution.reports.columns.consumed') }}</th><th class="text-end">{{ __('production_execution.reports.columns.waste') }}</th><th class="text-end">{{ __('production_execution.reports.columns.quantity_variance') }}</th><th class="text-end">{{ __('production_execution.reports.columns.accountability_variance') }}</th></x-slot:head>
                @foreach($materials as $line)<tr><td>{{ $line->run?->run_number }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->planned_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->issued_total_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->returned_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->consumed_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->waste_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->quantity_variance) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->accountability_variance) }}</td></tr>@endforeach
            </x-production.report-card>
            @endif
        @elseif($section === 'quality')
            <div class="d-none" data-report-count="quality_{{ request('operational_focus') }}">{{ $qualityInspections->count() }}</div>
            @php($visibleQualityKpis = collect($qualitySummary)->filter(fn ($value): bool => bccomp((string) $value, '0', 8) !== 0))
            @if($visibleQualityKpis->isNotEmpty())
                <div class="row g-3 mb-3">
                    @foreach($visibleQualityKpis as $key => $value)
                        <div class="col-sm-6 col-lg-4 col-xxl-3"><div class="card h-100"><div class="card-body py-3"><div class="fw-semi-bold text-700">{{ __('production_execution.reports.quality_kpis.'.$key) }}</div><div class="fs-4 fw-bold mt-1" dir="ltr">{{ $numbers->format($value) }}</div></div></div></div>
                    @endforeach
                </div>
            @endif
            <x-production.report-card :title="__('production_execution.reports.sections.quality')" :empty-message="__('production_execution.reports.empty.quality')" :has-rows="$qualityInspections->isNotEmpty()" :columns="10">
                <x-slot:head><th>{{ __('production_execution.reports.columns.inspection') }}</th><th>{{ __('production_execution.reports.columns.sampled_at') }}</th><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.stage') }}</th><th>{{ __('production_execution.reports.columns.inspection_type') }}</th><th>{{ __('production_execution.reports.columns.result') }}</th><th>{{ __('production_execution.reports.columns.disposition') }}</th><th class="text-end">{{ __('production_execution.reports.columns.affected_quantity') }}</th><th>{{ __('production_execution.reports.columns.reinspection') }}</th><th>{{ __('production_execution.reports.columns.status') }}</th></x-slot:head>
                @foreach($qualityInspections as $inspection)<tr><td><a href="{{ route('admin.production.quality.show', $inspection) }}">{{ $inspection->doc_num }}</a></td><td>{{ $dates->formatDateTime($inspection->sampled_at) }}</td><td>{{ $inspection->run?->run_number }}</td><td>{{ $inspection->stageSnapshot?->stage_name ?: '—' }}</td><td>{{ $inspection->qualityType?->name ?: '—' }}</td><td>{{ __('production_execution.quality_results.'.$inspection->result) }}</td><td>{{ __('production_execution.quality_dispositions.'.($inspection->disposition ?: 'hold')) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($inspection->affected_base_quantity) ?: '—' }}</td><td>{{ $inspection->reinspection_number > 0 ? __('production_execution.quality.reinspection_number', ['number' => $inspection->reinspection_number]) : '—' }}</td><td>{{ __('production_execution.statuses.'.$inspection->status) }}</td></tr>@endforeach
            </x-production.report-card>
        @elseif($section === 'receipts')
            @php($receiptLineCount = $finishedGoodsReceipts->sum(fn ($document) => $document->lines->count()))
            <x-production.report-card :title="__('production_execution.reports.sections.receipts')" :empty-message="__('production_execution.reports.empty.receipts')" :has-rows="$receiptLineCount > 0" :columns="7">
                <x-slot:head><th>{{ __('production_execution.reports.columns.receipt') }}</th><th>{{ __('production_execution.reports.columns.date') }}</th><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.order') }}</th><th>{{ __('production_execution.reports.columns.store') }}</th><th>{{ __('production_execution.reports.columns.product') }}</th><th class="text-end">{{ __('production_execution.reports.columns.quantity') }}</th></x-slot:head>
                @foreach($finishedGoodsReceipts as $document)@foreach($document->lines as $line)<tr><td><a href="{{ route('admin.inventory.documents.show', $document) }}">{{ $document->doc_num }}</a></td><td>{{ $dates->formatDate($document->document_date) }}</td><td>{{ $document->productionRun?->run_number }}</td><td>{{ $document->productionRun?->order?->doc_num }}</td><td>{{ $document->branchStore?->name }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->base_quantity) }}</td></tr>@endforeach @endforeach
            </x-production.report-card>
        @endif
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush
@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>
@endpush

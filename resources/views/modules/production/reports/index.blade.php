@extends('layouts.app')

@php
    $sectionRoutes = [
        'overview' => 'admin.production.reports.index',
        'orders' => 'admin.production.reports.orders',
        'runs' => 'admin.production.reports.runs',
        'materials' => 'admin.production.reports.materials',
        'quality' => 'admin.production.reports.quality',
        'receipts' => 'admin.production.reports.receipts',
    ];
    $exportQuery = [...request()->query(), 'section' => $section];
@endphp

@section('title', __('production_execution.reports.sections.'.$section))

@section('content')
    <div class="production-mobile-workflow" data-client-report-tables>
        <div class="card mb-3">
            <div class="card-header py-2">
                <div class="row flex-between-center g-2">
                    <div class="col"><h5 class="mb-0">{{ __('production_execution.reports.sections.'.$section) }}</h5></div>
                    @can('production.reports.export')
                        <div class="col-auto d-flex flex-wrap gap-2">
                            <a class="btn btn-falcon-success btn-sm" href="{{ route('admin.production.reports.export', $exportQuery) }}"><span class="fas fa-file-excel me-1"></span>{{ __('production_execution.actions.export_excel') }}</a>
                            <a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ route('admin.production.reports.print', $exportQuery) }}"><span class="fas fa-file-pdf me-1"></span>{{ __('production_execution.actions.print_pdf') }}</a>
                        </div>
                    @endcan
                </div>
            </div>
            <div class="card-body py-3">
                <form method="GET" action="{{ route($sectionRoutes[$section]) }}" class="row g-3 align-items-end">
                    <div class="col-md-3"><x-forms.label for="production-report-from" :label="__('production_execution.reports.filters.from')" /><x-forms.date-input id="production-report-from" name="from" :value="request('from')" /></div>
                    <div class="col-md-3"><x-forms.label for="production-report-to" :label="__('production_execution.reports.filters.to')" /><x-forms.date-input id="production-report-to" name="to" :value="request('to')" /></div>
                    <div class="col-md-3">
                        <x-forms.label for="production-report-status" :label="__('production_execution.fields.status')" />
                        <x-forms.select variant="local" id="production-report-status" name="status" :placeholder="__('production_execution.reports.filters.all_statuses')">
                            @foreach(['draft', 'planned', 'released', 'setup', 'ready', 'running', 'held', 'partially_completed', 'completed', 'short_closed', 'cancelled'] as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('production_execution.statuses.'.$status) }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-primary flex-fill" type="submit"><span class="fas fa-filter me-1"></span>{{ __('production_execution.reports.filters.apply') }}</button>
                        <a class="btn btn-falcon-default" href="{{ route($sectionRoutes[$section]) }}">{{ __('production_execution.reports.filters.reset') }}</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3" role="navigation" aria-label="{{ __('production_execution.reports.title') }}">
            @foreach($sectionRoutes as $reportSection => $routeName)
                <a class="btn btn-sm {{ $section === $reportSection ? 'btn-primary' : 'btn-falcon-default' }}" href="{{ route($routeName, request()->only(['from', 'to', 'status'])) }}">{{ __('production_execution.reports.sections.'.$reportSection) }}</a>
            @endforeach
        </div>

        @if($section === 'overview')
            <div class="row g-3 mb-3">
                @foreach($kpis as $label => $value)
                    <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body py-3"><div class="fw-semi-bold text-700">{{ __('production_execution.reports.kpis.'.$label) }}</div><div class="fs-4 fw-bold mt-1" dir="ltr">{{ $numbers->format($value) }}</div></div></div></div>
                @endforeach
            </div>
            <div class="row g-3">
                @foreach(array_diff(array_keys($sectionRoutes), ['overview']) as $reportSection)
                    <div class="col-md-6 col-xl-4"><a class="card h-100 text-decoration-none" href="{{ route($sectionRoutes[$reportSection], request()->only(['from', 'to', 'status'])) }}"><div class="card-body d-flex justify-content-between align-items-center gap-3"><h6 class="mb-0 text-900">{{ __('production_execution.reports.sections.'.$reportSection) }}</h6><span class="fas fa-chevron-left text-primary rtl-flip"></span></div></a></div>
                @endforeach
            </div>
        @elseif($section === 'orders')
            <x-production.report-card :title="__('production_execution.reports.sections.orders')" :empty-message="__('production_execution.reports.empty.orders')" :has-rows="$orders->isNotEmpty()" :columns="7">
                <x-slot:head><th>{{ __('production_execution.reports.columns.order') }}</th><th>{{ __('production_execution.reports.columns.date') }}</th><th>{{ __('production_execution.reports.columns.source') }}</th><th>{{ __('production_execution.reports.columns.sales_order') }}</th><th class="text-end">{{ __('production_execution.reports.columns.planned_quantity') }}</th><th class="text-end">{{ __('production_execution.reports.columns.received_quantity') }}</th><th>{{ __('production_execution.reports.columns.status') }}</th></x-slot:head>
                @foreach($orders as $order)<tr><td><a href="{{ route('admin.production.work-orders.show', $order) }}">{{ $order->doc_num }}</a></td><td>{{ $dates->formatDate($order->production_order_date) }}</td><td>{{ __('production_execution.source_types.'.$order->source_type) }}</td><td>{{ $order->salesOrder?->doc_num ?: '—' }}</td><td class="text-end" dir="ltr">{{ $numbers->format($order->lines->sum('base_quantity')) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($order->lines->sum('received_base_quantity')) }}</td><td>{{ __('production_execution.statuses.'.$order->status) }}</td></tr>@endforeach
            </x-production.report-card>
        @elseif($section === 'runs')
            <x-production.report-card :title="__('production_execution.reports.sections.runs')" :empty-message="__('production_execution.reports.empty.runs')" :has-rows="$runs->isNotEmpty()" :columns="12">
                <x-slot:head><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.order') }}</th><th>{{ __('production_execution.reports.columns.stage') }}</th><th>{{ __('production_execution.reports.columns.fixed_asset') }}</th><th>{{ __('production_execution.reports.columns.product') }}</th><th class="text-end">{{ __('production_execution.reports.columns.planned') }}</th><th class="text-end">{{ __('production_execution.reports.columns.good') }}</th><th class="text-end">{{ __('production_execution.reports.columns.rejected') }}</th><th class="text-end">{{ __('production_execution.reports.columns.rework') }}</th><th class="text-end">{{ __('production_execution.reports.columns.scrap') }}</th><th class="text-end">{{ __('production_execution.reports.columns.yield_percent') }}</th><th>{{ __('production_execution.reports.columns.status') }}</th></x-slot:head>
                @foreach($runs as $run)<tr><td><a href="{{ route('admin.production.runs.show', $run) }}">{{ $run->run_number }}</a></td><td>{{ $run->order?->doc_num }}</td><td>{{ $run->stageSnapshot?->stage_name ?: '—' }}</td><td>{{ $run->fixedAsset?->asset_name ?: '—' }}</td><td>{{ $run->product?->name }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->planned_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->good_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->rejected_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->rework_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->scrap_base_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($run->yield_percent) }}</td><td>{{ __('production_execution.statuses.'.$run->status) }}</td></tr>@endforeach
            </x-production.report-card>
        @elseif($section === 'materials')
            <x-production.report-card :title="__('production_execution.reports.sections.materials')" :empty-message="__('production_execution.reports.empty.materials')" :has-rows="$materials->isNotEmpty()" :columns="9">
                <x-slot:head><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.material') }}</th><th class="text-end">{{ __('production_execution.reports.columns.planned') }}</th><th class="text-end">{{ __('production_execution.reports.columns.issued') }}</th><th class="text-end">{{ __('production_execution.reports.columns.returned') }}</th><th class="text-end">{{ __('production_execution.reports.columns.consumed') }}</th><th class="text-end">{{ __('production_execution.reports.columns.waste') }}</th><th class="text-end">{{ __('production_execution.reports.columns.quantity_variance') }}</th><th class="text-end">{{ __('production_execution.reports.columns.accountability_variance') }}</th></x-slot:head>
                @foreach($materials as $line)<tr><td>{{ $line->run?->run_number }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->planned_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->issued_total_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->returned_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->consumed_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->waste_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->quantity_variance) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->accountability_variance) }}</td></tr>@endforeach
            </x-production.report-card>
        @elseif($section === 'quality')
            <x-production.report-card :title="__('production_execution.reports.sections.quality')" :empty-message="__('production_execution.reports.empty.quality')" :has-rows="$qualityInspections->isNotEmpty()" :columns="9">
                <x-slot:head><th>{{ __('production_execution.reports.columns.inspection') }}</th><th>{{ __('production_execution.reports.columns.sampled_at') }}</th><th>{{ __('production_execution.reports.columns.run') }}</th><th>{{ __('production_execution.reports.columns.stage') }}</th><th>{{ __('production_execution.reports.columns.inspection_type') }}</th><th>{{ __('production_execution.reports.columns.result') }}</th><th>{{ __('production_execution.reports.columns.disposition') }}</th><th class="text-end">{{ __('production_execution.reports.columns.affected_quantity') }}</th><th>{{ __('production_execution.reports.columns.status') }}</th></x-slot:head>
                @foreach($qualityInspections as $inspection)<tr><td><a href="{{ route('admin.production.quality.show', $inspection) }}">{{ $inspection->doc_num }}</a></td><td>{{ $dates->formatDateTime($inspection->sampled_at) }}</td><td>{{ $inspection->run?->run_number }}</td><td>{{ $inspection->stageSnapshot?->stage_name ?: '—' }}</td><td>{{ $inspection->qualityType?->name ?: '—' }}</td><td>{{ __('production_execution.quality_results.'.$inspection->result) }}</td><td>{{ __('production_execution.quality_dispositions.'.($inspection->disposition ?: 'hold')) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($inspection->affected_base_quantity) ?: '—' }}</td><td>{{ __('production_execution.statuses.'.$inspection->status) }}</td></tr>@endforeach
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

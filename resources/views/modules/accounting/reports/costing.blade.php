@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $exportQuery = request()->query() + ['type' => $report['type']];
@endphp

@section('title', __('costing_reports.title'))

@section('content')
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div><h1 class="h4 mb-1">{{ $report['title'] }}</h1><p class="text-600 mb-0">{{ $report['description'] }}</p></div>
        <div class="d-grid d-sm-flex gap-2">
            @can('reports.costing.'.$report['type'].'.export')
            <a class="btn btn-falcon-success btn-sm" href="{{ route('admin.accounting.reports.costing.export.excel', $exportQuery) }}"><span class="fas fa-file-excel me-1"></span>{{ __('reports.export_excel') }}</a>
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.accounting.reports.costing.export.csv', $exportQuery) }}"><span class="fas fa-file-csv me-1"></span>{{ __('reports.export_csv') }}</a>
            @endcan
            @can('reports.costing.'.$report['type'].'.print')
            <a target="_blank" class="btn btn-falcon-default btn-sm" href="{{ route('admin.accounting.reports.costing.export.pdf', $exportQuery) }}"><span class="fas fa-file-pdf me-1"></span>{{ __('reports.export_pdf') }}</a>
            @endcan
        </div>
    </div>

    <div class="card mb-3"><div class="card-header"><h2 class="h6 mb-0">{{ __('costing_reports.filters_title') }}</h2></div><div class="card-body">
        <form method="GET" action="{{ url()->current() }}" class="row g-3">
            <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="costing-report-type">{{ __('costing_reports.filters.type') }}</label><x-forms.select class="form-select" id="costing-report-type" name="type">@foreach(\Modules\Accounting\Services\CostingReportService::types() as $type)<option value="{{ $type }}" @selected($report['type'] === $type)>{{ __('costing_reports.types.'.$type.'.title') }}</option>@endforeach</x-forms.select></div>
            <div class="col-6 col-xl-2"><label class="form-label" for="costing-report-from">{{ __('costing_reports.filters.from_date') }}</label><x-forms.input class="form-control" id="costing-report-from" type="date" name="from_date" value="{{ $filters['from_date'] ?? '' }}" /></div>
            <div class="col-6 col-xl-2"><label class="form-label" for="costing-report-to">{{ __('costing_reports.filters.to_date') }}</label><x-forms.input class="form-control" id="costing-report-to" type="date" name="to_date" value="{{ $filters['to_date'] ?? '' }}" /></div>
            <div class="col-12 col-md-6 col-xl-2"><label class="form-label" for="costing-report-status">{{ __('costing_reports.filters.status') }}</label><x-forms.select class="form-select" id="costing-report-status" name="status"><option value=""></option>@foreach(['planned', 'setup', 'ready', 'running', 'held', 'completed', 'cancelled'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('production_execution.statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
            <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="costing-report-product">{{ __('costing_reports.filters.product') }}</label><x-forms.select class="form-select js-select2-local" id="costing-report-product" name="product_doc_num" data-allow-clear="true"><option value=""></option>@foreach($filterOptions['products'] as $product)<option value="{{ $product->doc_num }}" @selected(($filters['product_doc_num'] ?? '') === $product->doc_num)>{{ $product->doc_num }} / {{ $product->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="costing-report-order">{{ __('costing_reports.filters.production_order') }}</label><x-forms.select class="form-select js-select2-local" id="costing-report-order" name="production_order_doc_num" data-allow-clear="true"><option value=""></option>@foreach($filterOptions['orders'] as $order)<option value="{{ $order->doc_num }}" @selected(($filters['production_order_doc_num'] ?? '') === $order->doc_num)>{{ $order->doc_num }}</option>@endforeach</x-forms.select></div>
            <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="costing-report-center">{{ __('costing_reports.filters.cost_center') }}</label><x-forms.select class="form-select js-select2-local" id="costing-report-center" name="cost_center_doc_num" data-allow-clear="true"><option value=""></option>@foreach($filterOptions['cost_centers'] as $center)<option value="{{ $center->doc_num }}" @selected(($filters['cost_center_doc_num'] ?? '') === $center->doc_num)>{{ $center->cost_center_code }} / {{ $center->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-falcon-primary" type="submit"><span class="fas fa-filter me-1"></span>{{ __('common.actions.apply') }}</button><a class="btn btn-falcon-default" href="{{ url()->current() }}">{{ __('common.actions.reset') }}</a></div>
        </form>
    </div></div>

    @foreach($report['notices'] as $notice)<div class="alert alert-info py-2"><span class="fas fa-info-circle me-1"></span>{{ $notice }}</div>@endforeach

    @if($report['totals'])<div class="row g-3 mb-3">@foreach($report['totals'] as $key => $value)<div class="col-6 col-md-4 col-xl"><div class="card h-100"><div class="card-body py-2"><div class="text-600 fs-11">{{ __('costing_reports.columns.'.$key) }}</div><strong @if(is_numeric($value)) dir="ltr" @endif>{{ is_numeric($value) ? $numbers->format($value) : $value }}</strong></div></div></div>@endforeach</div>@endif

    <div class="card"><div class="card-header d-flex justify-content-between"><h2 class="h6 mb-0">{{ $report['title'] }}</h2><span class="badge badge-subtle-secondary">{{ __('costing_reports.results_count', ['count' => $report['rows']->count()]) }}</span></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-striped table-hover mb-0">
        <thead><tr>@foreach($report['columns'] as $label)<th class="text-nowrap">{{ $label }}</th>@endforeach</tr></thead>
        <tbody>@forelse($report['rows'] as $row)<tr>@foreach($report['columns'] as $key => $label)@php($value = $row[$key] ?? '')<td class="{{ in_array($key, $report['numeric_columns'], true) ? 'text-end text-nowrap' : '' }}">@if($key === 'allocation_run' && filled($row['_allocation_url'] ?? null))<a href="{{ $row['_allocation_url'] }}">{{ $value }}</a>@elseif($key === 'source_entry' && filled($row['_source_url'] ?? null))<a href="{{ $row['_source_url'] }}">{{ $value }}</a>@elseif(in_array($key, ['run', 'production_run', 'work_order'], true) && filled($row['_url'] ?? null))<a href="{{ $row['_url'] }}">{{ $value }}</a>@elseif(in_array($key, $report['numeric_columns'], true) && is_numeric($value))<span dir="ltr">{{ $numbers->format($value) }}</span>@else{{ $value }}@endif</td>@endforeach</tr>@empty<tr><td colspan="{{ count($report['columns']) }}" class="text-center text-600 py-4">{{ __('costing_reports.no_results') }}</td></tr>@endforelse</tbody>
    </table></div></div></div>
@endsection

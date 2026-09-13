@extends('layouts.app')

@section('title', __('production_execution.orders.title'))

@section('content')
    <div class="production-mobile-workflow">
    <div class="card erp-datatable-card">
        <div class="card-header d-flex justify-content-between"><div><h5 class="mb-0">{{ __('production_execution.orders.title') }}</h5><div class="text-600">{{ __('production_execution.orders.sales_source_help') }}</div></div><a class="btn btn-primary btn-sm" href="{{ route('admin.production.runs.index') }}">{{ __('production_execution.actions.plan_run') }}</a></div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-url="{{ route('admin.production.work-orders.data') }}" data-order-column="2" data-order-direction="desc" data-columns='[{"data":"doc_num","name":"production_orders.doc_num"},{"data":"sales_order_number","name":"sales_order_number","defaultContent":"—"},{"data":"production_order_date","name":"production_order_date"},{"data":"expected_delivery_date","name":"expected_delivery_date"},{"data":"lines_count","name":"lines_count","searchable":false},{"data":"runs_count","name":"runs_count","searchable":false},{"data":"status","name":"status"}]'><thead><tr><th>{{ __('production_execution.fields.production_order') }}</th><th>{{ __('production_execution.fields.sales_order') }}</th><th>{{ __('production_execution.fields.order_date') }}</th><th>{{ __('production_execution.fields.delivery_date') }}</th><th>{{ __('production_execution.fields.products_count') }}</th><th>{{ __('production_execution.fields.runs_count') }}</th><th>{{ __('production_execution.fields.status') }}</th></tr></thead></table></div>
    </div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush

@extends('layouts.app')

@section('title', __('production_execution.product_stages.title'))

@section('content')
    <div class="production-mobile-workflow">
    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('production_execution.product_stages.configure') }}</h5></div>
        <div class="card-body">
            <div class="row g-3 align-items-end"><div class="col-md-8"><label class="form-label">{{ __('production_execution.fields.product') }}</label><x-forms.select class="form-select" id="product-route-selector"><option value="">{{ __('common.placeholders.select') }}</option>@foreach($products as $product)<option value="{{ route('admin.production.product-stages.edit', $product) }}">{{ $product->doc_num }} — {{ $product->name }}</option>@endforeach</x-forms.select></div><div class="col-md-4"><button class="btn btn-primary" type="button" data-navigate-select="#product-route-selector">{{ __('production_execution.actions.configure_route') }}</button></div></div>
        </div>
    </div>
    <div class="card erp-datatable-card">
        <div class="card-header"><h5 class="mb-0">{{ __('production_execution.product_stages.current_routes') }}</h5></div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-url="{{ route('admin.production.product-stages.index') }}" data-order-column="0" data-order-direction="asc" data-columns='[{"data":"product","name":"product"},{"data":"sequence","name":"sequence"},{"data":"stage","name":"stage"},{"data":"status","name":"status"}]'><thead><tr><th>{{ __('production_execution.fields.product') }}</th><th>{{ __('production_execution.fields.order') }}</th><th>{{ __('production_execution.fields.stage') }}</th><th>{{ __('production_execution.fields.status') }}</th></tr></thead></table></div>
    </div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush

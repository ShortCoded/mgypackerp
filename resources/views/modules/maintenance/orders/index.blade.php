@extends('layouts.app')

@section('title', __('maintenance.orders.title'))

@section('content')
    <div class="production-mobile-workflow">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4 class="mb-1">{{ __('maintenance.orders.title') }}</h4><div class="text-muted">{{ __('maintenance.title') }}</div></div>
        <div class="d-flex flex-wrap gap-2">@can('maintenance.orders.export')<a class="btn btn-outline-success" href="{{ route('admin.maintenance.orders.export') }}">{{ __('maintenance.actions.export_excel') }}</a>@endcan @can('maintenance.orders.create')<a class="btn btn-primary" href="{{ route('admin.maintenance.orders.create') }}">{{ __('maintenance.orders.create') }}</a>@endcan</div>
    </div>
    <div class="card erp-datatable-card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-url="{{ route('admin.maintenance.orders.index') }}" data-order-column="1" data-order-direction="desc" data-columns='[{"data":"doc_num","name":"maintenance_work_orders.doc_num"},{"data":"planned_start_at","name":"planned_start_at"},{"data":"asset_name","name":"fixed_assets.asset_name"},{"data":"mold_name","name":"production_molds.name","defaultContent":"—"},{"data":"maintenance_type","name":"maintenance_type"},{"data":"service_mode","name":"service_mode"},{"data":"provider","name":"provider","orderable":false},{"data":"priority","name":"priority"},{"data":"status","name":"status"},{"data":"actions","name":"actions","orderable":false,"searchable":false}]'><thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.planned_start') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.mold') }}</th><th>{{ __('maintenance.fields.maintenance_type') }}</th><th>{{ __('maintenance.fields.service_mode') }}</th><th>{{ __('maintenance.fields.provider') }}</th><th>{{ __('maintenance.fields.priority') }}</th><th>{{ __('maintenance.fields.status') }}</th><th></th></tr></thead></table></div></div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush

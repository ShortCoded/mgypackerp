@extends('layouts.app')

@section('title', __('maintenance.orders.title'))

@section('content')
    <div class="production-mobile-workflow">
        <div class="card erp-datatable-card" data-records-root data-bulk-delete-url="{{ route('admin.maintenance.orders.bulk-delete') }}">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col-auto"><h5 class="mb-0">{{ __('maintenance.orders.title') }}</h5></div>
                    <div class="col-auto ms-auto d-flex flex-wrap align-items-center justify-content-end gap-2">
                        @can('maintenance.orders.view_trashed')<x-forms.select class="form-select form-select-sm w-auto" data-trash-filter><option value="active">{{ __('maintenance.actions.active') }}</option><option value="trashed">{{ __('maintenance.actions.deleted') }}</option><option value="all">{{ __('maintenance.actions.all') }}</option></x-forms.select>@endcan
                        @can('maintenance.orders.delete')<div class="d-none align-items-center gap-2" data-bulk-actions><span class="text-primary fw-semibold" data-selected-count>0</span><button class="btn btn-falcon-danger btn-sm" type="button" data-bulk-delete disabled>{{ __('common.actions.delete') }}</button></div>@endcan
                        @can('maintenance.orders.export')<a class="btn btn-outline-success btn-sm" href="{{ route('admin.maintenance.orders.export') }}">{{ __('maintenance.actions.export_excel') }}</a>@endcan
                        <x-buttons.add-record :href="route('admin.maintenance.orders.create')" permission="maintenance.orders.create" />
                    </div>
                </div>
            </div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-record-selection data-url="{{ route('admin.maintenance.orders.index') }}" data-order-column="2" data-order-direction="desc" data-columns='[{"data":"select","name":"select","orderable":false,"searchable":false},{"data":"doc_num","name":"maintenance_work_orders.doc_num"},{"data":"planned_start_at","name":"planned_start_at"},{"data":"asset_name","name":"fixed_assets.asset_name","defaultContent":"—"},{"data":"mold_name","name":"production_molds.name","defaultContent":"—"},{"data":"maintenance_type","name":"maintenance_type"},{"data":"service_mode","name":"service_mode"},{"data":"provider","name":"provider","orderable":false},{"data":"priority","name":"priority"},{"data":"status","name":"status"},{"data":"actions","name":"actions","orderable":false,"searchable":false}]'><thead><tr><th class="dt-select"><x-forms.input class="form-check-input" type="checkbox" data-select-all /></th><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.planned_start') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.mold') }}</th><th>{{ __('maintenance.fields.maintenance_type') }}</th><th>{{ __('maintenance.fields.service_mode') }}</th><th>{{ __('maintenance.fields.provider') }}</th><th>{{ __('maintenance.fields.priority') }}</th><th>{{ __('maintenance.fields.status') }}</th><th></th></tr></thead></table></div>
        </div>
    </div>
@endsection

@pushOnce('styles', 'maintenance-execution-css')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endPushOnce
@pushOnce('scripts', 'maintenance-execution-js')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endPushOnce

@extends('layouts.app')

@section('title', __('maintenance.expenses.title'))

@section('content')
    <div class="production-mobile-workflow">
        <div class="card erp-datatable-card" data-records-root data-bulk-delete-url="{{ route('admin.maintenance.expenses.bulk-delete') }}">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col-auto"><h5 class="mb-0">{{ __('maintenance.expenses.title') }}</h5></div>
                    <div class="col-auto ms-auto d-flex flex-wrap align-items-center justify-content-end gap-2">
                        @can('maintenance.expenses.view_trashed')
                            <x-forms.select class="form-select form-select-sm w-auto" data-trash-filter aria-label="{{ __('maintenance.actions.record_filter') }}"><option value="active">{{ __('maintenance.actions.active') }}</option><option value="trashed">{{ __('maintenance.actions.deleted') }}</option><option value="all">{{ __('maintenance.actions.all') }}</option></x-forms.select>
                        @endcan
                        @can('maintenance.expenses.delete')
                            <div class="d-none align-items-center gap-2" data-bulk-actions><span class="text-primary fw-semibold" data-selected-count>0</span><button class="btn btn-falcon-danger btn-sm" type="button" data-bulk-delete disabled><span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}</button></div>
                        @endcan
                        <x-buttons.add-record :href="route('admin.maintenance.expenses.create')" permission="maintenance.expenses.create" />
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-record-selection data-url="{{ route('admin.maintenance.expenses.index') }}" data-order-column="2" data-order-direction="desc" data-columns='[{"data":"select","name":"select","orderable":false,"searchable":false},{"data":"doc_num","name":"production_expense_requests.doc_num"},{"data":"request_date","name":"request_date"},{"data":"work_order_number","name":"work_order_number"},{"data":"asset_name","name":"asset_name","defaultContent":"—"},{"data":"amount","name":"amount"},{"data":"currency_code","name":"currency_code"},{"data":"reason","name":"reason"},{"data":"voucher_number","name":"voucher_number","defaultContent":"—"},{"data":"status","name":"status"},{"data":"actions","name":"actions","orderable":false,"searchable":false}]'>
                    <thead><tr><th class="dt-select"><x-forms.input class="form-check-input" type="checkbox" data-select-all aria-label="{{ __('maintenance.actions.select_all') }}" /></th><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.date') }}</th><th>{{ __('maintenance.fields.work_order') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.amount') }}</th><th>{{ __('maintenance.fields.currency') }}</th><th>{{ __('maintenance.fields.reason') }}</th><th>{{ __('maintenance.fields.payment_voucher') }}</th><th>{{ __('maintenance.fields.status') }}</th><th></th></tr></thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@pushOnce('styles', 'maintenance-execution-css')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endPushOnce
@pushOnce('scripts', 'maintenance-execution-js')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endPushOnce

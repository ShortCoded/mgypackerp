@extends('layouts.app')

@php
    $title = __('purchase_orders.title');
    $columns = [
        'doc_num',
        'document_date',
        'supplier',
        'branch_store',
        'currency',
        'total_ordered_quantity',
        'total_amount',
        'expected_delivery_date',
        'status',
        'created_by',
        'created_at',
        'approved_by',
        'approved_at',
    ];
@endphp

@section('title', $title)

@section('content')
    @include('modules.purchases.procurement.partials.document-filters', ['filterStatuses' => ['draft', 'submitted', 'approved', 'closed', 'cancelled']])
    @can('purchase_orders.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#purchase-orders-document-number-settings"
                    aria-expanded="false"
                    aria-controls="purchase-orders-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="purchase-orders-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-purchase-order-document-number-settings-form" action="{{ route('admin.purchases.purchase-orders.document-number-settings.update') }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div class="alert alert-danger alert-dismissible fade show d-none js-form-alert" role="alert">
                            <span class="js-form-alert-message"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="purchase-orders-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="purchase-orders-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="purchase-orders-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <input class="form-control" id="purchase-orders-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 5 }}" required>
                                <div class="invalid-feedback d-block" data-error-for="padding"></div>
                            </div>
                            <div class="col-md-auto">
                                <button type="submit" class="btn btn-falcon-primary">
                                    <span class="fas fa-save me-1"></span>{{ __('common.document_number_settings.save') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    <div class="card erp-datatable-card purchase-order-datatable-card" data-purchase-orders-root>
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col-12 col-lg-auto">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ $title }}</h5>
                </div>
                <div class="col-12 col-lg-auto ms-auto d-flex flex-wrap justify-content-lg-end align-items-center gap-2">
                    @can('purchase_orders.view_trashed')
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 text-700 fs-10" for="purchase_orders_trash_filter">{{ __('purchase_orders.filters.trash') }}</label>
                            <select class="form-select form-select-sm w-auto js-purchase-order-filter" id="purchase_orders_trash_filter" name="trash_filter" aria-label="{{ __('purchase_orders.filters.trash') }}">
                                <option value="active">{{ __('purchase_orders.trash.active') }}</option>
                                <option value="trashed">{{ __('purchase_orders.trash.trashed') }}</option>
                                <option value="all">{{ __('purchase_orders.trash.all') }}</option>
                            </select>
                        </div>
                    @endcan
                    @if(auth()->user()?->can('purchase_orders.delete'))
                        <div class="d-none align-items-center gap-2 purchase-order-bulk-actions-bar" id="bulk_actions_bar">
                            <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                            <select class="form-select form-select-sm w-auto" id="bulk_action_select" aria-label="{{ __('purchase_orders.bulk_action') }}">
                                <option value="">{{ __('purchase_orders.bulk_action') }}</option>
                                <option value="delete">{{ __('common.actions.delete') }}</option>
                            </select>
                            <button type="button" class="btn btn-falcon-danger btn-sm" id="bulk_action_apply" data-label="{{ __('common.actions.apply') }}" disabled>
                                <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                            </button>
                        </div>
                    @endif
                    <x-buttons.add-record :href="route('admin.purchases.purchase-orders.create')" permission="purchase_orders.create" />
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table id="purchase-orders-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-purchase-orders-table"
                            data-url="{{ route('admin.purchases.purchase-orders.data') }}"
                            data-bulk-delete-url="{{ route('admin.purchases.purchase-orders.bulk-delete') }}"
                            data-table-name="purchase_orders">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('purchase_orders.select_all') }}">
                                        </div>
                                    </th>
                                    @foreach($columns as $index => $column)
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap {{ $index === 0 ? 'all no-colvis dt-code' : 'dt-text dt-ellipsis' }}">{{ __("purchase_orders.columns.{$column}") }}</th>
                                    @endforeach
                                    <th class="text-900 no-sort pe-1 align-middle data-table-row-action all no-colvis dt-actions"></th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.purchaseOrderMessages = @json(__('purchase_orders.js'));
        window.purchaseOrderColumns = @json($columns);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Purchases/purchase-orders.js').'?v='.filemtime(public_path('assets/js/modules/Purchases/purchase-orders.js')) }}"></script>
@endpush

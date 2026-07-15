@extends('layouts.app')

@php
    $routePrefix = 'admin.inventory.unpriced-inventory-receipts';
    $title = __('inventory.unpriced_inventory_receipts.title');
    $columns = ['doc_num', 'document_date', 'financial_period', 'branch', 'hall', 'store', 'supplier_reference', 'lines_count', 'total_quantity', 'status', 'pricing_status', 'approved_by', 'approved_at', 'created_by', 'created_at', 'updated_by', 'updated_at'];
@endphp

@section('title', $title)

@section('content')
    @can('inventory.unpriced_inventory_receipts.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#unpriced-inventory-receipts-document-number-settings"
                    aria-expanded="false"
                    aria-controls="unpriced-inventory-receipts-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="unpriced-inventory-receipts-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-unpriced-inventory-receipts-document-number-settings-form" action="{{ route($routePrefix.'.document-number-settings.update') }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div class="alert alert-danger alert-dismissible fade show d-none js-form-alert" role="alert">
                            <span class="js-form-alert-message"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="unpriced-inventory-receipts-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="unpriced-inventory-receipts-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="unpriced-inventory-receipts-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <input class="form-control" id="unpriced-inventory-receipts-document-padding" name="padding" type="number" min="1" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 5 }}" required>
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

    <div class="card erp-datatable-card inventory-datatable-card" data-unpriced-inventory-receipts-root>
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col-12 col-xl-auto d-flex align-items-center pe-xl-0">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ $title }}</h5>
                </div>
                <div class="col-12 col-xl-auto ms-xl-auto text-end ps-xl-0 d-flex flex-wrap justify-content-end align-items-center gap-2">

                    @can('inventory.unpriced_inventory_receipts.view_trashed')
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 text-700 fs-10" for="unpriced_inventory_receipts_trash_filter">{{ __('business_partners.trash.filter_label') }}</label>
                            <select class="form-select form-select-sm w-auto js-unpriced-inventory-receipts-trash-filter" id="unpriced_inventory_receipts_trash_filter" aria-label="{{ __('business_partners.trash.filter_label') }}">
                                <option value="active">{{ __('business_partners.trash.active') }}</option>
                                <option value="trashed">{{ __('business_partners.trash.trashed') }}</option>
                                <option value="all">{{ __('business_partners.trash.all') }}</option>
                            </select>
                        </div>
                    @endcan
                    <x-buttons.add-record :href="route($routePrefix.'.create')" permission="inventory.unpriced_inventory_receipts.create" />
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table id="unpriced-inventory-receipts-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-unpriced-inventory-receipts-table"
                            data-url="{{ route($routePrefix.'.data') }}"
                            data-table-name="unpriced_inventory_receipts">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('finance.select_all') }}">
                                        </div>
                                    </th>
                                    @foreach($columns as $index => $column)
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap {{ $index === 0 ? 'all no-colvis dt-code' : 'dt-text dt-ellipsis' }}">{{ __("inventory.unpriced_inventory_receipts.columns.{$column}") }}</th>
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
        window.unpricedInventoryReceiptsMessages = @json(__('inventory.unpriced_inventory_receipts.js'));
        window.unpricedInventoryReceiptsColumns = @json($columns);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Inventory/unpriced-inventory-receipts.js') }}"></script>
@endpush

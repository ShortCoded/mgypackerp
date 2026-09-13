@extends('layouts.app')

@php
    $routePrefix = 'admin.inventory.opening-stocks';
    $title = __('inventory.opening_stocks.title');
    $columns = ['doc_num', 'document_date', 'branch', 'hall', 'store', 'lines_count', 'total_quantity', 'document_status', 'approval_status', 'approved_by', 'approved_at', 'created_by', 'created_at', 'updated_by', 'updated_at'];
@endphp

@section('title', $title)

@section('content')
    @unless($canUseCurrentBranch)
        <div class="alert alert-warning">{{ __('inventory.opening_stocks.messages.branch_type_required') }}</div>
    @endunless

    @can('inventory.opening_stocks.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#opening-stocks-document-number-settings"
                    aria-expanded="false"
                    aria-controls="opening-stocks-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="opening-stocks-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-opening-stocks-document-number-settings-form" action="{{ route($routePrefix.'.document-number-settings.update') }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div class="alert alert-danger alert-dismissible fade show d-none js-form-alert" role="alert">
                            <span class="js-form-alert-message"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="opening-stocks-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <x-forms.input class="form-control" id="opening-stocks-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}" />
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="opening-stocks-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <x-forms.input class="form-control" id="opening-stocks-document-padding" name="padding" type="number" min="1" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 5 }}" required />
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

    <div class="card erp-datatable-card inventory-datatable-card" data-opening-stocks-root>
        <div class="card-header">
            <div class="row flex-between-center">
                <div class="col-6 col-sm-auto d-flex align-items-center pe-0">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ $title }}</h5>
                </div>
                <div class="col-6 col-sm-auto ms-auto text-end ps-0 d-flex justify-content-end align-items-center gap-2">
                    @can('inventory.opening_stocks.view_trashed')
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 text-700 fs-10" for="opening_stocks_trash_filter">{{ __('business_partners.trash.filter_label') }}</label>
                            <x-forms.select class="form-select form-select-sm w-auto js-opening-stocks-trash-filter" id="opening_stocks_trash_filter" aria-label="{{ __('business_partners.trash.filter_label') }}">
                                <option value="active">{{ __('business_partners.trash.active') }}</option>
                                <option value="trashed">{{ __('business_partners.trash.trashed') }}</option>
                                <option value="all">{{ __('business_partners.trash.all') }}</option>
                            </x-forms.select>
                        </div>
                    @endcan
                    @if($canUseCurrentBranch)
                        <x-buttons.add-record :href="route($routePrefix.'.create')" permission="inventory.opening_stocks.create" />
                    @endif
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table id="opening-stocks-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-opening-stocks-table"
                            data-url="{{ route($routePrefix.'.data') }}"
                            data-table-name="inventory_opening_stocks">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <x-forms.input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('finance.select_all') }}" />
                                        </div>
                                    </th>
                                    @foreach($columns as $index => $column)
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap {{ $index === 0 ? 'all no-colvis dt-code' : 'dt-text dt-ellipsis' }}">{{ __("inventory.opening_stocks.columns.{$column}") }}</th>
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
        window.openingStocksMessages = @json(__('inventory.opening_stocks.js'));
        window.openingStocksColumns = @json($columns);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Inventory/opening-stocks.js') }}"></script>
@endpush

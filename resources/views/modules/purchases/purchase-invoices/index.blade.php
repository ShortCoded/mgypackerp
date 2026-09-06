@extends('layouts.app')

@php
    $title = __('purchase_invoices.title');
    $columns = [
        'doc_num',
        'invoice_date',
        'supplier',
        'financial_period',
        'supplier_invoice_number',
        'payment_type',
        'status',
        'payment_status',
        'currency',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'created_by',
        'created_at',
        'approved_by',
        'approved_at',
    ];
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp

@section('title', $title)

@section('content')
    @include('modules.purchases.procurement.partials.document-filters', ['filterStatuses' => ['draft', 'submitted', 'approved', 'closed', 'cancelled']])
    @can('purchase_invoices.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#purchase-invoices-document-number-settings"
                    aria-expanded="false"
                    aria-controls="purchase-invoices-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="purchase-invoices-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-purchase-invoice-document-number-settings-form" action="{{ route('admin.purchases.purchase-invoices.document-number-settings.update') }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div class="alert alert-danger alert-dismissible fade show d-none js-form-alert" role="alert">
                            <span class="js-form-alert-message"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="purchase-invoices-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="purchase-invoices-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="purchase-invoices-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <input class="form-control" id="purchase-invoices-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 5 }}" required>
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

    <div class="card erp-datatable-card purchase-invoice-datatable-card" data-purchase-invoices-root>
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col-12 col-lg-auto">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ $title }}</h5>
                </div>
                <div class="col-12 col-lg-auto ms-auto d-flex flex-wrap justify-content-lg-end align-items-center gap-2">
                    @can('purchase_invoices.view_trashed')
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 text-700 fs-10" for="purchase_invoices_trash_filter">{{ __('purchase_invoices.filters.trash') }}</label>
                            <select class="form-select form-select-sm w-auto js-purchase-invoice-filter" id="purchase_invoices_trash_filter" name="trash_filter" aria-label="{{ __('purchase_invoices.filters.trash') }}">
                                <option value="active">{{ __('purchase_invoices.trash.active') }}</option>
                                <option value="trashed">{{ __('purchase_invoices.trash.trashed') }}</option>
                                <option value="all">{{ __('purchase_invoices.trash.all') }}</option>
                            </select>
                        </div>
                    @endcan
                    @if(auth()->user()?->can('purchase_invoices.delete'))
                        <div class="d-none align-items-center gap-2 purchase-invoice-bulk-actions-bar" id="bulk_actions_bar">
                            <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                            <select class="form-select form-select-sm w-auto" id="bulk_action_select" aria-label="{{ __('purchase_invoices.bulk_action') }}">
                                <option value="">{{ __('purchase_invoices.bulk_action') }}</option>
                                @can('purchase_invoices.delete')
                                    <option value="delete">{{ __('common.actions.delete') }}</option>
                                @endcan
                            </select>
                            <button type="button" class="btn btn-falcon-danger btn-sm" id="bulk_action_apply" data-label="{{ __('common.actions.apply') }}" disabled>
                                <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                            </button>
                        </div>
                    @endif
                    <x-buttons.add-record :href="route('admin.purchases.purchase-invoices.create')" permission="purchase_invoices.create" />
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table id="purchase-invoices-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-purchase-invoices-table"
                            data-url="{{ route('admin.purchases.purchase-invoices.data') }}"
                            data-bulk-delete-url="{{ route('admin.purchases.purchase-invoices.bulk-delete') }}"
                            data-table-name="purchase_invoices">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('purchase_invoices.select_all') }}">
                                        </div>
                                    </th>
                                    @foreach($columns as $index => $column)
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap {{ $index === 0 ? 'all no-colvis dt-code' : 'dt-text dt-ellipsis' }}">{{ __("purchase_invoices.columns.{$column}") }}</th>
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
        window.purchaseInvoiceMessages = @json(__('purchase_invoices.js'));
        window.purchaseInvoiceColumns = @json($columns);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Purchases/purchase-invoices.js').'?v='.filemtime(public_path('assets/js/modules/Purchases/purchase-invoices.js')) }}"></script>
@endpush

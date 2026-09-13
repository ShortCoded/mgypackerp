@extends('layouts.app')

@section('title', __('currencies.title'))

@section('content')
    @can('currencies.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#currencies-document-number-settings"
                    aria-expanded="false"
                    aria-controls="currencies-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="currencies-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-currency-document-number-settings-form"
                        action="{{ route('admin.currencies.document-number-settings.update') }}"
                        method="POST"
                        novalidate>
                        @csrf
                        @method('PUT')
                        <div class="alert alert-danger alert-dismissible fade show d-none js-currency-alert" role="alert">
                            <span class="js-currency-alert-message"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="currencies-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <x-forms.input class="form-control" id="currencies-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}" />
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="currencies-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <x-forms.input class="form-control" id="currencies-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 0 }}" required />
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

    <div class="card erp-datatable-card currency-datatable-card">
        <div class="card-header">
            <div class="row flex-between-center">
                <div class="col-6 col-sm-auto d-flex align-items-center pe-0">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ __('currencies.title') }}</h5>
                </div>
                <div class="col-6 col-sm-auto ms-auto text-end ps-0 d-flex justify-content-end align-items-center gap-2 currency-toolbar-actions">
                    @can('currencies.view_trashed')
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 text-700 fs-10" for="currency_trash_filter">{{ __('currencies.trash.filter_label') }}</label>
                            <x-forms.select class="form-select form-select-sm w-auto" id="currency_trash_filter" aria-label="{{ __('currencies.trash.filter_label') }}">
                                <option value="active">{{ __('currencies.trash.active') }}</option>
                                <option value="trashed">{{ __('currencies.trash.trashed') }}</option>
                                <option value="all">{{ __('currencies.trash.all') }}</option>
                            </x-forms.select>
                        </div>
                    @endcan
                    @can('currencies.delete')
                        <div class="d-none align-items-center gap-2 currency-bulk-actions-bar" id="bulk_actions_bar">
                            <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                            <x-forms.select class="form-select form-select-sm w-auto" id="bulk_action_select" aria-label="{{ __('currencies.bulk_action') }}">
                                <option value="delete">{{ __('common.actions.delete') }}</option>
                            </x-forms.select>
                            <button type="button" class="btn btn-falcon-danger btn-sm" id="bulk_action_apply" data-label="{{ __('common.actions.apply') }}" title="{{ __('common.shortcuts.bulk_apply') }}" data-bs-title="{{ __('common.shortcuts.bulk_apply') }}" disabled>
                                <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                            </button>
                        </div>
                    @endcan
                    <x-buttons.add-record :href="route('admin.currencies.create')" permission="currencies.create" />
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-currency-table" id="currencies-table"
                            data-url="{{ route('admin.currencies.data') }}"
                            data-bulk-delete-url="{{ route('admin.currencies.bulk-delete') }}"
                            data-table-name="currencies">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <x-forms.input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('currencies.select_all') }}" />
                                        </div>
                                    </th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('currencies.attributes.doc_num') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('currencies.attributes.name') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-code">{{ __('currencies.attributes.code') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('currencies.attributes.minor_unit_name') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap text-center">{{ __('currencies.attributes.minor_unit_factor') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-status">{{ __('currencies.attributes.is_main') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-status">{{ __('currencies.attributes.status') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.created_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.created_at') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.updated_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.updated_at') }}</th>
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
    @php
        $currencyMessages = [
            'deleteConfirmTitle' => __('currencies.messages.delete_confirm_title'),
            'deleteConfirmText' => __('currencies.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('currencies.messages.delete_confirm_yes'),
            'bulkDeleteConfirmTitle' => __('currencies.messages.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __('currencies.messages.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __('currencies.messages.bulk_delete_confirm_yes'),
            'noRowsSelected' => __('currencies.messages.no_rows_selected'),
            'noChanges' => __('common.messages.no_changes'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('auth.alerts.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'restore' => __('currencies.trash.restore'),
            'restoreConfirmTitle' => __('currencies.trash.restore_confirm_title'),
            'restoreConfirmText' => __('currencies.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('currencies.trash.restore_confirm_yes'),
        ];
    @endphp
    <script>
        window.currencyMessages = @json($currencyMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/currencies.js') }}"></script>
@endpush

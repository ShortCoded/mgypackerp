@extends('layouts.app')

@section('title', __('financial_periods.title'))

@section('content')
    @can('financial_periods.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#financial-periods-document-number-settings"
                    aria-expanded="false"
                    aria-controls="financial-periods-document-number-settings">
                    <span class="fw-semibold">{{ __('financial_periods.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="financial-periods-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('financial_periods.document_number_settings.description') }}</p>
                    <form id="financial-periods-document-number-settings-form" action="{{ route('admin.financial-periods.document-number-settings.update') }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div data-form-alert></div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="financial-periods-document-prefix">{{ __('financial_periods.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="financial-periods-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="financial-periods-document-padding">{{ __('financial_periods.document_number_settings.padding') }}</label>
                                <input class="form-control" id="financial-periods-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 5 }}">
                                <div class="invalid-feedback d-block" data-error-for="padding"></div>
                            </div>
                            <div class="col-md-auto">
                                <button class="btn btn-falcon-primary" type="submit">
                                    <span class="fas fa-save me-1"></span>{{ __('financial_periods.document_number_settings.save') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    <div class="card erp-datatable-card financial-periods-datatable-card">
        <x-admin.crud-index-toolbar
            :title="__('financial_periods.title')"
            :add-route="route('admin.financial-periods.create')"
            add-permission="financial_periods.create"
            :show-trash-filter="auth()->user()?->can('financial_periods.view_trashed')"
            :show-bulk-actions="auth()->user()?->can('financial_periods.delete')"
            trash-filter-id="financial_periods_trash_filter"
            bulk-actions-class="financial-periods-bulk-actions-bar"
            bulk-action-label="{{ __('financial_periods.bulk_action') }}"
            toolbar-actions-class="financial-periods-toolbar-actions"
        />
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle" id="financial-periods-table"
                            data-url="{{ route('admin.financial-periods.data') }}"
                            data-ajax-url="{{ route('admin.financial-periods.data') }}"
                            data-bulk-delete-url="{{ route('admin.financial-periods.bulk-delete') }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" data-searchable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('financial_periods.select_all') }}">
                                        </div>
                                    </th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('common.fields.document_number') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('financial_periods.attributes.name') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('financial_periods.attributes.from_date') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('financial_periods.attributes.to_date') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('financial_periods.attributes.is_closed') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.created_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.created_at') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.updated_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.updated_at') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle data-table-row-action all no-colvis dt-actions" data-orderable="false" data-searchable="false"></th>
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
        $financialPeriodsMessages = [
            'deleteConfirmTitle' => __('financial_periods.messages.delete_confirm_title'),
            'deleteConfirmText' => __('financial_periods.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('financial_periods.messages.delete_confirm_yes'),
            'bulkDeleteConfirmTitle' => __('financial_periods.messages.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __('financial_periods.messages.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __('financial_periods.messages.bulk_delete_confirm_yes'),
            'deleted' => __('financial_periods.messages.deleted'),
            'bulkDeleted' => __('financial_periods.messages.bulk_deleted', ['count' => 0]),
            'settingsSaved' => __('financial_periods.document_number_settings.updated_successfully'),
            'noRecordsSelected' => __('financial_periods.messages.no_records_selected'),
            'noChanges' => __('common.messages.no_changes'),
            'saved' => __('common.messages.saved_successfully'),
            'validationFailed' => __('common.messages.validation_failed'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'cancel' => __('common.actions.cancel'),
            'confirm' => __('common.actions.confirm'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'restore' => __('financial_periods.trash.restore'),
            'restoreConfirmTitle' => __('financial_periods.trash.restore_confirm_title'),
            'restoreConfirmText' => __('financial_periods.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('financial_periods.trash.restore_confirm_yes'),
        ];
    @endphp
    <script>
        window.coreFinancialPeriodsMessages = @json($financialPeriodsMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/financial-periods.js') }}"></script>
@endpush

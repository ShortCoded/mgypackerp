@extends('layouts.app')

@section('title', __('companies.title'))

@section('content')
    @php
        $canCreateCompany = $canCreateCompany ?? true;
        $companyLimitReached = ! $canCreateCompany;
    @endphp

    @include('modules.core.companies.partials.document-number-settings')

    <div class="card erp-datatable-card companies-datatable-card">
        <x-admin.crud-index-toolbar
            :title="__('companies.title')"
            :add-route="$canCreateCompany ? route('admin.companies.create') : null"
            add-permission="companies.create"
            :show-trash-filter="auth()->user()?->can('companies.view_trashed')"
            :show-bulk-actions="auth()->user()?->can('companies.delete')"
            trash-filter-id="companies_trash_filter"
            bulk-actions-class="companies-bulk-actions-bar"
            bulk-action-label="{{ __('companies.bulk_action') }}"
            toolbar-actions-class="companies-toolbar-actions"
        />
        <div class="p-0 card-body">
            @if ($companyLimitReached && auth()->user()?->can('companies.create'))
                <div class="mb-0 border-0 alert alert-warning rounded-0" role="alert">
                    <div class="fw-semibold">{{ __('companies.messages.cannot_create_more_companies') }}</div>
                    <div class="small">{{ __('companies.messages.max_companies_reached_help') }}</div>
                </div>
            @endif
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table class="table mb-0 align-middle table-sm table-hover data-table erp-datatable" id="companies-table"
                            data-url="{{ route('admin.companies.data') }}"
                            data-bulk-delete-url="{{ route('admin.companies.bulk-delete') }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="align-middle text-900 no-sort white-space-nowrap all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="mb-0 form-check d-flex align-items-center justify-content-center">
                                            <input class="form-check-input company-select-all js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('companies.select_all') }}">
                                        </div>
                                    </th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap all no-colvis dt-code">{{ __('common.fields.document_number') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.name') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('companies.fields.legal_name') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('companies.fields.commercial_name') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap">{{ __('common.fields.status') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap">{{ __('companies.fields.main_company') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.phone') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.email') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('companies.fields.city') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.created_by') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-date">{{ __('common.fields.created_at') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.updated_by') }}</th>
                                    <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-date">{{ __('common.fields.updated_at') }}</th>
                                    <th class="align-middle text-900 no-sort pe-1 data-table-row-action all no-colvis dt-actions"></th>
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
        $companiesMessages = [
            'deleteConfirmTitle' => __('companies.messages.delete_confirm_title'),
            'deleteConfirmText' => __('companies.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('companies.messages.delete_confirm_yes'),
            'bulkDeleteConfirmTitle' => __('companies.messages.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __('companies.messages.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __('companies.messages.bulk_delete_confirm_yes'),
            'noRowsSelected' => __('companies.messages.no_rows_selected'),
            'noChanges' => __('common.messages.no_changes'),
            'loadFailed' => __('companies.messages.load_failed'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('common.actions.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'restore' => __('companies.trash.restore'),
            'restoreConfirmTitle' => __('companies.trash.restore_confirm_title'),
            'restoreConfirmText' => __('companies.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('companies.trash.restore_confirm_yes'),
        ];
    @endphp
    <script>
        window.companiesMessages = @json($companiesMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/companies.js') }}"></script>
@endpush

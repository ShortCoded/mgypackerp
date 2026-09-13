@extends('layouts.app')

@section('title', __('users.title'))

@section('content')
    @can('users.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#users-document-number-settings"
                    aria-expanded="false"
                    aria-controls="users-document-number-settings">
                    <span class="fw-semibold">{{ __('users.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="users-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('users.document_number_settings.description') }}</p>
                    <form class="js-user-document-number-settings-form"
                        action="{{ route('admin.users.document-number-settings.update') }}"
                        method="POST"
                        novalidate>
                        @csrf
                        @method('PUT')
                        <div class="alert alert-danger alert-dismissible fade show d-none js-user-alert" role="alert">
                            <span class="js-user-alert-message"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="users-document-prefix">{{ __('users.document_number_settings.prefix') }}</label>
                                <x-forms.input class="form-control" id="users-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}" />
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="users-document-padding">{{ __('users.document_number_settings.padding') }}</label>
                                <x-forms.input class="form-control" id="users-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 0 }}" required />
                                <div class="invalid-feedback d-block" data-error-for="padding"></div>
                            </div>
                            <div class="col-md-auto">
                                <button type="submit" class="btn btn-falcon-primary">
                                    <span class="fas fa-save me-1"></span>{{ __('users.document_number_settings.save') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    <div class="card erp-datatable-card users-datatable-card">
        <x-admin.crud-index-toolbar
            :title="__('users.title')"
            :add-route="route('admin.users.create')"
            add-permission="users.create"
            :show-trash-filter="auth()->user()?->can('users.view_trashed')"
            :show-bulk-actions="auth()->user()?->can('users.delete')"
            trash-filter-id="users_trash_filter"
            bulk-actions-class="users-bulk-actions-bar"
            bulk-action-label="{{ __('users.bulk_action') }}"
            toolbar-actions-class="users-toolbar-actions"
        />
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle" id="users-table"
                            data-url="{{ route('admin.users.data') }}"
                            data-bulk-delete-url="{{ route('admin.users.bulk-delete') }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <x-forms.input class="form-check-input user-select-all js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('users.select_all') }}" />
                                        </div>
                                    </th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('common.fields.document_number') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.name') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.username') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.email') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.phone') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.roles') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('common.fields.status') }}</th>
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
        $usersMessages = [
            'deleteConfirmTitle' => __('users.messages.delete_confirm_title'),
            'deleteConfirmText' => __('users.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('users.messages.delete_confirm_yes'),
            'bulkDeleteConfirmTitle' => __('users.messages.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __('users.messages.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __('users.messages.bulk_delete_confirm_yes'),
            'noRowsSelected' => __('users.messages.no_rows_selected'),
            'noChanges' => __('common.messages.no_changes'),
            'loadFailed' => __('users.messages.load_failed'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('auth.alerts.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'restore' => __('users.trash.restore'),
            'restoreConfirmTitle' => __('users.trash.restore_confirm_title'),
            'restoreConfirmText' => __('users.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('users.trash.restore_confirm_yes'),
        ];
    @endphp
    <script>
        window.usersMessages = @json($usersMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Auth/users.js') }}"></script>
@endpush

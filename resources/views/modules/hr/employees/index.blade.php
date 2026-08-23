@extends('layouts.app')

@section('title', __('hr.employees.title'))

@section('content')
    @can('hr.employees.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#hr-employees-document-number-settings"
                    aria-expanded="false"
                    aria-controls="hr-employees-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="hr-employees-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-hr-employees-document-number-settings-form"
                        action="{{ route('admin.hr.employees.document-number-settings.update') }}"
                        method="POST"
                        novalidate>
                        @csrf
                        @method('PUT')
                        <div class="alert alert-danger alert-dismissible fade show d-none js-hr-employees-alert" role="alert">
                            <span class="js-hr-employees-alert-message"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="hr-employees-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="hr-employees-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="hr-employees-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <input class="form-control" id="hr-employees-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 0 }}" required>
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

    <div class="card mb-3 hr-employees-filter-card">
        <div class="card-header py-2">
            <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#hr-employees-filters"
                aria-expanded="false"
                aria-controls="hr-employees-filters">
                <span class="fw-semibold"><span class="fas fa-filter me-1"></span>{{ __('hr.employees.filters.title') }}</span>
                <span class="fas fa-chevron-down fs-11"></span>
            </button>
        </div>
        <div class="collapse" id="hr-employees-filters">
            <div class="card-body">
                <form class="js-hr-employees-filters" novalidate>
                    <div class="row g-3 align-items-end">
                        @foreach ($filterSelects as $fieldName => $option)
                            <div class="col-md-6 col-xl-4">
                                <label class="form-label" for="hr-employees-filter-{{ str_replace('_', '-', $fieldName) }}">{{ __('hr.employees.attributes.' . $fieldName) }}</label>
                                <select id="hr-employees-filter-{{ str_replace('_', '-', $fieldName) }}"
                                    name="{{ $fieldName }}"
                                    class="form-select js-select2-ajax js-hr-employees-filter"
                                    data-url="{{ $option['url'] ?? '' }}"
                                    data-placeholder="{{ __('hr.employees.placeholders.' . $fieldName) }}"
                                    data-allow-clear="true"
                                    @if ($fieldName === 'section_doc_num')
                                        data-depends-on="#hr-employees-filter-department-doc-num"
                                        data-dependent-param="department_doc_num"
                                        data-dependent-result-field="department_doc_num"
                                        data-disable-when-dependency-empty="true"
                                    @endif></select>
                            </div>
                        @endforeach

                        <div class="col-md-6 col-xl-3">
                            <label class="form-label" for="hr-employees-filter-person-type">{{ __('hr.employees.attributes.person_type') }}</label>
                            <select id="hr-employees-filter-person-type" name="person_type" class="form-select js-hr-employees-filter">
                                <option value="">{{ __('hr.employees.filters.all') }}</option>
                                @foreach (['fixed_employee', 'regular_labor', 'casual_labor'] as $personType)
                                    <option value="{{ $personType }}">{{ __('hr.employees.person_types.' . $personType) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6 col-xl-3">
                            <label class="form-label" for="hr-employees-filter-status">{{ __('common.fields.status') }}</label>
                            <select id="hr-employees-filter-status" name="status" class="form-select js-hr-employees-filter">
                                <option value="">{{ __('hr.employees.filters.all') }}</option>
                                @foreach (['active', 'inactive', 'suspended', 'stopped', 'left'] as $status)
                                    <option value="{{ $status }}">{{ __('hr.employees.statuses.' . $status) }}</option>
                                @endforeach
                            </select>
                        </div>

                        @foreach (['insurance_status', 'tax_status'] as $statutoryFilter)
                            <div class="col-md-6 col-xl-3">
                                <label class="form-label" for="hr-employees-filter-{{ str_replace('_', '-', $statutoryFilter) }}">{{ __('hr.employees.attributes.'.$statutoryFilter) }}</label>
                                <select id="hr-employees-filter-{{ str_replace('_', '-', $statutoryFilter) }}" name="{{ $statutoryFilter }}" class="form-select js-hr-employees-filter">
                                    <option value="">{{ __('hr.employees.filters.all') }}</option>
                                    @foreach (['subject', 'not_subject', 'suspended', 'ended'] as $statutoryStatus)
                                        <option value="{{ $statutoryStatus }}">{{ __('hr.employees.statutory_statuses.'.$statutoryStatus) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach

                        <div class="col-md-6 col-xl-3">
                            <label class="form-label" for="hr-employees-filter-hire-from">{{ __('hr.employees.filters.hire_from') }}</label>
                            <input id="hr-employees-filter-hire-from" name="hire_from" type="text" class="form-control js-date-picker js-hr-employees-filter" placeholder="{{ __('common.placeholders.select_date') }}">
                        </div>

                        <div class="col-md-6 col-xl-3">
                            <label class="form-label" for="hr-employees-filter-hire-to">{{ __('hr.employees.filters.hire_to') }}</label>
                            <input id="hr-employees-filter-hire-to" name="hire_to" type="text" class="form-control js-date-picker js-hr-employees-filter" placeholder="{{ __('common.placeholders.select_date') }}">
                        </div>

                        @can('hr.employees.view_trashed')
                            <div class="col-md-6 col-xl-3">
                                <label class="form-label" for="hr_employees_trash_filter">{{ __('hr.trash.filter_label') }}</label>
                                <select class="form-select js-hr-employees-filter" id="hr_employees_trash_filter" name="trash_filter">
                                    <option value="active">{{ __('hr.trash.active') }}</option>
                                    <option value="inactive">{{ __('hr.trash.inactive') }}</option>
                                    <option value="trashed">{{ __('hr.trash.trashed') }}</option>
                                    <option value="all">{{ __('hr.trash.all') }}</option>
                                </select>
                            </div>
                        @endcan

                        <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
                            <button type="button" class="btn btn-falcon-default js-hr-employees-filter-clear">
                                <span class="fas fa-eraser me-1"></span>{{ __('common.actions.clear') }}
                            </button>
                            <button type="submit" class="btn btn-falcon-primary">
                                <span class="fas fa-filter me-1"></span>{{ __('common.actions.apply') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card erp-datatable-card hr-employees-datatable-card">
        <div class="card-header">
            <div class="row flex-between-center">
                <div class="col-6 col-sm-auto d-flex align-items-center pe-0">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ __('hr.employees.title') }}</h5>
                </div>
                <div class="col-6 col-sm-auto ms-auto text-end ps-0 d-flex justify-content-end align-items-center gap-2 hr-employees-toolbar-actions">
                    @if (auth()->user()?->can('hr.employees.delete') || auth()->user()?->can('hr.employees.restore') || auth()->user()?->can('hr.employees.edit'))
                        <div class="d-none align-items-center gap-2 hr-employees-bulk-actions-bar" id="bulk_actions_bar">
                            <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                            <select class="form-select form-select-sm w-auto" id="bulk_action_select" aria-label="{{ __('hr.bulk_action') }}">
                                @can('hr.employees.delete')
                                    <option value="delete">{{ __('hr.bulk_actions.delete_selected') }}</option>
                                @endcan
                                @can('hr.employees.restore')
                                    <option value="restore">{{ __('hr.bulk_actions.restore_selected') }}</option>
                                @endcan
                                @can('hr.employees.edit')
                                    <option value="activate">{{ __('hr.bulk_actions.activate_selected') }}</option>
                                    <option value="deactivate">{{ __('hr.bulk_actions.deactivate_selected') }}</option>
                                @endcan
                            </select>
                            <button type="button" class="btn btn-falcon-danger btn-sm" id="bulk_action_apply" data-label="{{ __('common.actions.apply') }}" title="{{ __('common.shortcuts.bulk_apply') }}" data-bs-title="{{ __('common.shortcuts.bulk_apply') }}" disabled>
                                <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                            </button>
                        </div>
                    @endif
                    <x-buttons.add-record :href="route('admin.hr.employees.create')" permission="hr.employees.create" />
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-hover mb-0 data-table erp-datatable erp-datatable-wide erp-datatable-sticky-columns align-middle js-hr-employees-table" id="hr-employees-table"
                            data-url="{{ route('admin.hr.employees.data') }}"
                            data-bulk-delete-url="{{ route('admin.hr.employees.bulk-delete') }}"
                            data-bulk-restore-url="{{ route('admin.hr.employees.bulk-restore') }}"
                            data-bulk-status-url="{{ route('admin.hr.employees.bulk-status') }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('hr.select_all') }}">
                                        </div>
                                    </th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('common.fields.document_number') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle white-space-nowrap dt-avatar">{{ __('hr.employees.attributes.photo_archive_file_doc_num') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('hr.employees.attributes.full_name') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('hr.employees.attributes.national_id') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('hr.employees.attributes.person_type') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('hr.employees.attributes.branch_doc_num') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('hr.employees.attributes.department_doc_num') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('hr.employees.attributes.section_doc_num') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('hr.employees.attributes.job_doc_num') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('hr.employees.attributes.employment_type_doc_num') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('hr.employees.attributes.pay_basis') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('hr.employees.attributes.basic_salary') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('hr.employees.attributes.payroll_currency_doc_num') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('common.fields.status') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle white-space-nowrap">{{ __('hr.employees.biometric.title') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('hr.employees.attributes.end_date') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.created_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.created_at') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.updated_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.updated_at') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.deleted_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.deleted_at') }}</th>
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
        $hrMessages = [
            'deleteConfirmTitle' => __('hr.employees.messages.delete_confirm_title'),
            'deleteConfirmText' => __('hr.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('hr.messages.delete_confirm_yes'),
            'bulkDeleteConfirmTitle' => __('hr.employees.messages.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __('hr.messages.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __('hr.messages.bulk_delete_confirm_yes'),
            'bulkRestoreConfirmTitle' => __('hr.employees.messages.bulk_restore_confirm_title'),
            'bulkRestoreConfirmText' => __('hr.messages.bulk_restore_confirm_text'),
            'bulkRestoreConfirmYes' => __('hr.messages.bulk_restore_confirm_yes'),
            'bulkStatusConfirmTitle' => __('hr.employees.messages.bulk_status_confirm_title'),
            'bulkActivateConfirmText' => __('hr.messages.bulk_activate_confirm_text'),
            'bulkDeactivateConfirmText' => __('hr.messages.bulk_deactivate_confirm_text'),
            'bulkActivateConfirmYes' => __('hr.messages.bulk_activate_confirm_yes'),
            'bulkDeactivateConfirmYes' => __('hr.messages.bulk_deactivate_confirm_yes'),
            'noRowsSelected' => __('hr.messages.no_rows_selected'),
            'noChanges' => __('common.messages.no_changes'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('auth.alerts.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'restore' => __('hr.trash.restore'),
            'restoreConfirmTitle' => __('hr.trash.restore_confirm_title'),
            'restoreConfirmText' => __('hr.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('hr.trash.restore_confirm_yes'),
        ];
    @endphp
    <script>
        window.hrEmployeesMessages = @json($hrMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/HR/hr-employees.js') }}"></script>
@endpush

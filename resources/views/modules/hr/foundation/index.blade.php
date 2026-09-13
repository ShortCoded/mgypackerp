@extends('layouts.app')

@section('title', __('hr.' . $definition->translationKey . '.title'))

@section('content')
    @can($definition->permission('document_number_settings.update'))
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#{{ $definition->routeKey }}-document-number-settings"
                    aria-expanded="false"
                    aria-controls="{{ $definition->routeKey }}-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="{{ $definition->routeKey }}-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-hr-foundation-document-number-settings-form"
                        action="{{ route($definition->route('document-number-settings.update')) }}"
                        method="POST"
                        novalidate>
                        @csrf
                        @method('PUT')
                        <div class="alert alert-danger alert-dismissible fade show d-none js-hr-foundation-alert" role="alert">
                            <span class="js-hr-foundation-alert-message"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="{{ $definition->routeKey }}-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <x-forms.input class="form-control" id="{{ $definition->routeKey }}-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}" />
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="{{ $definition->routeKey }}-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <x-forms.input class="form-control" id="{{ $definition->routeKey }}-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 0 }}" required />
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

    <div class="card erp-datatable-card hr-foundation-datatable-card">
        <div class="card-header">
            <div class="row flex-between-center">
                <div class="col-6 col-sm-auto d-flex align-items-center pe-0">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ __('hr.' . $definition->translationKey . '.title') }}</h5>
                </div>
                <div class="col-6 col-sm-auto ms-auto text-end ps-0 d-flex justify-content-end align-items-center gap-2 hr-foundation-toolbar-actions">
                    @can($definition->permission('view_trashed'))
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 text-700 fs-10" for="hr_foundation_trash_filter">{{ __('hr.trash.filter_label') }}</label>
                            <x-forms.select class="form-select form-select-sm w-auto" id="hr_foundation_trash_filter" aria-label="{{ __('hr.trash.filter_label') }}">
                                <option value="active">{{ __('hr.trash.active') }}</option>
                                <option value="trashed">{{ __('hr.trash.trashed') }}</option>
                                <option value="all">{{ __('hr.trash.all') }}</option>
                            </x-forms.select>
                        </div>
                    @endcan
                    @can($definition->permission('delete'))
                        <div class="d-none align-items-center gap-2 hr-foundation-bulk-actions-bar" id="bulk_actions_bar">
                            <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                            <x-forms.select class="form-select form-select-sm w-auto" id="bulk_action_select" aria-label="{{ __('hr.bulk_action') }}">
                                <option value="delete">{{ __('common.actions.delete') }}</option>
                            </x-forms.select>
                            <button type="button" class="btn btn-falcon-danger btn-sm" id="bulk_action_apply" data-label="{{ __('common.actions.apply') }}" title="{{ __('common.shortcuts.bulk_apply') }}" data-bs-title="{{ __('common.shortcuts.bulk_apply') }}" disabled>
                                <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                            </button>
                        </div>
                    @endcan
                    <x-buttons.add-record :href="route($definition->route('create'))" :permission="$definition->permission('create')" />
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-hr-foundation-table" id="hr-{{ $definition->routeKey }}-table"
                            data-url="{{ route($definition->route('data')) }}"
                            data-bulk-delete-url="{{ route($definition->route('bulk-delete')) }}"
                            data-table-name="{{ $definition->table }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <x-forms.input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('hr.select_all') }}" />
                                        </div>
                                    </th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('common.fields.document_number') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.name') }}</th>
                                    @foreach ($definition->tableColumns as $column)
                                        <th @class([
                                            'text-900 sort pe-1 align-middle white-space-nowrap',
                                            'dt-number text-end' => in_array($column['type'] ?? null, ['number', 'decimal'], true),
                                            'dt-text dt-ellipsis' => ! in_array($column['type'] ?? null, ['number', 'decimal'], true),
                                        ])>{{ __('hr.foundation.attributes.' . $column['name']) }}</th>
                                    @endforeach
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
        $hrMessages = [
            'deleteConfirmTitle' => __('hr.messages.delete_confirm_title'),
            'deleteConfirmText' => __('hr.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('hr.messages.delete_confirm_yes'),
            'bulkDeleteConfirmTitle' => __('hr.messages.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __('hr.messages.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __('hr.messages.bulk_delete_confirm_yes'),
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
        window.hrFoundationMessages = @json($hrMessages);
        window.hrFoundationDataTableColumns = @json($definition->tableColumns);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/HR/hr-foundation.js') }}"></script>
@endpush

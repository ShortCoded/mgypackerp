@extends('layouts.app')

@section('title', __('project_structure_models.title'))

@php
    $messages = [
        'deleteConfirmTitle' => __('project_structure_models.messages.delete_confirm_title'),
        'deleteConfirmText' => __('project_structure_models.messages.delete_confirm_text'),
        'deleteConfirmYes' => __('project_structure_models.messages.delete_confirm_yes'),
        'bulkDeleteConfirmTitle' => __('project_structure_models.messages.bulk_delete_confirm_title'),
        'bulkDeleteConfirmText' => __('project_structure_models.messages.bulk_delete_confirm_text'),
        'bulkDeleteConfirmYes' => __('project_structure_models.messages.bulk_delete_confirm_yes'),
        'restoreConfirmTitle' => __('project_structure_models.messages.restore_confirm_title'),
        'restoreConfirmText' => __('project_structure_models.messages.restore_confirm_text'),
        'restoreConfirmYes' => __('project_structure_models.messages.restore_confirm_yes'),
        'cancel' => __('common.actions.cancel'),
        'unexpectedError' => __('common.messages.unexpected_error'),
        'validationFailed' => __('common.messages.validation_failed'),
        'saved' => __('common.messages.saved_successfully'),
        'noChanges' => __('common.messages.no_changes'),
    ];
@endphp

@section('content')
    @can('project_structure_models.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between" type="button" data-bs-toggle="collapse" data-bs-target="#project-structure-models-document-number-settings" aria-expanded="false" aria-controls="project-structure-models-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="project-structure-models-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-project-structure-model-document-number-settings-form" action="{{ route('admin.sales.project-structure-models.document-number-settings.update') }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div data-form-alert></div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="project-structure-models-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="project-structure-models-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? 'PSM-' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="project-structure-models-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <input class="form-control" id="project-structure-models-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 5 }}" required>
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

    <div class="card erp-datatable-card project-structure-models-datatable-card">
        <x-admin.crud-index-toolbar
            :title="__('project_structure_models.title')"
            :add-route="route('admin.sales.project-structure-models.create')"
            add-permission="project_structure_models.create"
            :show-trash-filter="auth()->user()?->can('project_structure_models.view_trashed')"
            :show-bulk-actions="auth()->user()?->can('project_structure_models.delete')"
            trash-filter-id="project_structure_models_trash_filter"
            bulk-actions-class="project-structure-models-bulk-actions-bar"
            bulk-action-label="{{ __('project_structure_models.bulk_action') }}"
            toolbar-actions-class="project-structure-models-toolbar-actions"
        />
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table id="project-structure-models-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-project-structure-models-table"
                            data-url="{{ route('admin.sales.project-structure-models.data') }}"
                            data-bulk-delete-url="{{ route('admin.sales.project-structure-models.bulk-delete') }}"
                            data-table-name="project_structure_models">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('project_structure_models.select_all') }}">
                                        </div>
                                    </th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('project_structure_models.attributes.doc_num') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('project_structure_models.attributes.name') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-code">{{ __('project_structure_models.attributes.code') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-code">{{ __('project_structure_models.attributes.short_name') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('project_structure_models.attributes.status') }}</th>
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
    <script>
        window.projectStructureModelMessages = @json($messages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Sales/project-structure-models.js') }}"></script>
@endpush

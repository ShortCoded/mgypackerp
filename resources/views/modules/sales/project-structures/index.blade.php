@extends('layouts.app')

@section('title', __('project_structures.title'))

@php
    $messages = [
        'deleteConfirmTitle' => __('project_structures.messages.delete_confirm_title'),
        'deleteConfirmText' => __('project_structures.messages.delete_confirm_text'),
        'deleteConfirmYes' => __('project_structures.messages.delete_confirm_yes'),
        'bulkDeleteConfirmTitle' => __('project_structures.messages.bulk_delete_confirm_title'),
        'bulkDeleteConfirmText' => __('project_structures.messages.bulk_delete_confirm_text'),
        'bulkDeleteConfirmYes' => __('project_structures.messages.bulk_delete_confirm_yes'),
        'restoreConfirmTitle' => __('project_structures.messages.restore_confirm_title'),
        'restoreConfirmText' => __('project_structures.messages.restore_confirm_text'),
        'restoreConfirmYes' => __('project_structures.messages.restore_confirm_yes'),
        'cancel' => __('common.actions.cancel'),
        'unexpectedError' => __('common.messages.unexpected_error'),
        'validationFailed' => __('common.messages.validation_failed'),
        'saved' => __('common.messages.saved_successfully'),
        'noChanges' => __('common.messages.no_changes'),
        'emptyTree' => __('project_structures.empty_tree'),
        'showTree' => __('project_structures.show_tree'),
        'showList' => __('project_structures.show_list'),
        'title' => __('project_structures.title'),
        'inactive' => __('project_structures.statuses.inactive'),
        'expandAll' => __('project_structures.expand_all'),
        'collapseAll' => __('project_structures.collapse_all'),
        'expandBranch' => __('project_structures.actions.expand_branch'),
        'collapseBranch' => __('project_structures.actions.collapse_branch'),
    ];
@endphp

@section('content')
    @can('project_structures.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between" type="button" data-bs-toggle="collapse" data-bs-target="#project-structures-document-number-settings" aria-expanded="false" aria-controls="project-structures-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="project-structures-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-project-structure-document-number-settings-form" action="{{ route('admin.sales.project-structures.document-number-settings.update') }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div data-form-alert></div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="project-structures-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="project-structures-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? 'PST-' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="project-structures-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <input class="form-control" id="project-structures-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 5 }}" required>
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

    <div class="card erp-datatable-card project-structures-datatable-card">
        <x-admin.crud-index-toolbar
            :title="__('project_structures.title')"
            :add-route="route('admin.sales.project-structures.create')"
            add-permission="project_structures.create"
            :show-trash-filter="auth()->user()?->can('project_structures.view_trashed')"
            :show-bulk-actions="auth()->user()?->can('project_structures.delete')"
            trash-filter-id="project_structures_trash_filter"
            bulk-actions-class="project-structures-bulk-actions-bar"
            bulk-action-label="{{ __('project_structures.bulk_action') }}"
            toolbar-actions-class="project-structures-toolbar-actions"
        >
            @can('project_structures.tree.view')
                <button class="btn btn-falcon-default btn-sm" type="button" data-project-structures-toggle-tree>
                    <span class="fas fa-sitemap me-1"></span>{{ __('project_structures.show_tree') }}
                </button>
            @endcan
            <div class="gap-2 d-none align-items-center project-structures-tree-controls" data-project-structures-tree-controls>
                <button class="btn btn-falcon-default btn-sm" type="button" data-project-structures-tree-expand-all title="{{ __('project_structures.expand_all') }}" aria-label="{{ __('project_structures.expand_all') }}">
                    <span class="fas fa-plus me-1"></span>{{ __('project_structures.expand_all') }}
                </button>
                <button class="btn btn-falcon-default btn-sm" type="button" data-project-structures-tree-collapse-all title="{{ __('project_structures.collapse_all') }}" aria-label="{{ __('project_structures.collapse_all') }}">
                    <span class="fas fa-minus me-1"></span>{{ __('project_structures.collapse_all') }}
                </button>
            </div>
        </x-admin.crud-index-toolbar>
        <div class="card-body p-0">
            <div data-project-structures-list>
                <div class="falcon-data-table">
                    <div class="erp-datatable-wrapper">
                        <div class="erp-datatable-scroll">
                            <table id="project-structures-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-project-structures-table"
                                data-url="{{ route('admin.sales.project-structures.data') }}"
                                data-bulk-delete-url="{{ route('admin.sales.project-structures.bulk-delete') }}"
                                data-table-name="project_structures">
                                <thead class="bg-100 text-900">
                                    <tr>
                                        <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                            <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                                <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('project_structures.select_all') }}">
                                            </div>
                                        </th>
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('project_structures.attributes.doc_num') }}</th>
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('project_structures.attributes.name') }}</th>
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-code">{{ __('project_structures.attributes.code') }}</th>
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('project_structures.attributes.parent') }}</th>
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('project_structures.attributes.status') }}</th>
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
            <div class="p-3 d-none" data-project-structures-tree data-url="{{ route('admin.sales.project-structures.tree') }}">
                <div class="bg-white border rounded-2 project-structures-tree-panel">
                    <div class="p-3 scrollbar-overlay project-structures-tree-list" data-project-structures-tree-list></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .project-structures-toolbar-actions,
        .project-structures-tree-controls {
            flex-wrap: wrap;
        }

        .project-structures-tree-list {
            min-height: 12rem;
            max-width: 100%;
            overflow-x: hidden;
        }

        .project-structures-tree-list .treeview {
            box-sizing: border-box;
            max-width: 100%;
            overflow-x: hidden;
            padding-inline: .5rem !important;
            width: 100%;
        }

        .project-structures-tree-list .treeview-list {
            box-sizing: border-box;
            margin: 0;
            max-width: 100%;
            overflow-x: hidden;
            padding-inline-end: 0;
            padding-inline-start: 1.25rem;
        }

        .project-structures-tree-list .treeview-list-item {
            box-sizing: border-box;
            max-width: 100%;
            position: relative;
        }

        .project-structures-tree-list .treeview-row {
            inset-inline: 0;
            width: auto;
        }

        .project-structures-tree-list .treeview-text {
            gap: .5rem;
            min-height: 1.7rem;
            max-width: 100%;
            overflow: hidden;
        }

        .project-structures-tree-list [data-project-structures-tree-node]:focus {
            border-radius: .25rem;
            outline: 2px solid var(--falcon-primary);
            outline-offset: 2px;
        }

        .project-structures-tree-list .project-structures-tree-code {
            color: var(--falcon-gray-900);
            font-weight: 700;
        }
    </style>
@endpush

@push('scripts')
    <script>
        window.projectStructureMessages = @json($messages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Sales/project-structures.js') }}"></script>
@endpush

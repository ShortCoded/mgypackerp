@extends('layouts.app')

@section('title', __('production_identifiers.title'))

@php
    $productionIdentifierMessages = [
        'deleteConfirmTitle' => __('production_identifiers.messages.delete_confirm_title'),
        'deleteConfirmText' => __('production_identifiers.messages.delete_confirm_text'),
        'deleteConfirmYes' => __('production_identifiers.messages.delete_confirm_yes'),
        'bulkDeleteConfirmTitle' => __('production_identifiers.messages.bulk_delete_confirm_title'),
        'bulkDeleteConfirmText' => __('production_identifiers.messages.bulk_delete_confirm_text'),
        'bulkDeleteConfirmYes' => __('production_identifiers.messages.bulk_delete_confirm_yes'),
        'restoreConfirmTitle' => __('production_identifiers.messages.restore_confirm_title'),
        'restoreConfirmText' => __('production_identifiers.messages.restore_confirm_text'),
        'restoreConfirmYes' => __('production_identifiers.messages.restore_confirm_yes'),
        'noRecordsSelected' => __('production_identifiers.messages.no_records_selected'),
        'noData' => __('production_identifiers.messages.no_data_found'),
        'cancel' => __('common.actions.cancel'),
        'unexpectedError' => __('common.messages.unexpected_error'),
        'validationFailed' => __('common.messages.validation_failed'),
        'saved' => __('common.messages.saved_successfully'),
        'treeView' => __('production_identifiers.tree_view'),
        'listView' => __('production_identifiers.list_view'),
        'title' => __('production_identifiers.title'),
        'inactive' => __('production_identifiers.statuses.inactive'),
        'expandAll' => __('production_identifiers.actions.expand_all'),
        'collapseAll' => __('production_identifiers.actions.collapse_all'),
        'expandBranch' => __('production_identifiers.actions.expand_branch'),
        'collapseBranch' => __('production_identifiers.actions.collapse_branch'),
    ];
@endphp

@section('content')
    @can('production.identifiers.document_number_settings.update')
        <div class="mb-3 card">
            <div class="py-2 card-header">
                <button class="p-0 btn btn-link text-decoration-none w-100 text-start d-flex align-items-center justify-content-between" type="button" data-bs-toggle="collapse" data-bs-target="#production-identifiers-document-number-settings">
                    <span class="fw-semibold">{{ __('production_identifiers.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="production-identifiers-document-number-settings">
                <div class="card-body">
                    <p class="mb-3 text-700">{{ __('production_identifiers.document_number_settings.description') }}</p>
                    <form id="production-identifiers-document-number-settings-form" action="{{ route('admin.production.identifiers.document-number-settings.update') }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div data-form-alert></div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label" for="production-identifiers-document-prefix">{{ __('production_identifiers.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="production-identifiers-document-prefix" name="prefix" value="{{ $documentNumberSettings['prefix'] ?? 'ID-' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="production-identifiers-document-padding">{{ __('production_identifiers.document_number_settings.padding') }}</label>
                                <input class="form-control" id="production-identifiers-document-padding" name="padding" type="number" value="{{ $documentNumberSettings['padding'] ?? 5 }}">
                                <div class="invalid-feedback d-block" data-error-for="padding"></div>
                            </div>
                            <div class="col-md-auto">
                                <button class="btn btn-falcon-primary" type="submit"><span class="fas fa-save me-1"></span>{{ __('production_identifiers.document_number_settings.save') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    <div class="card erp-datatable-card production-identifiers-datatable-card">
        <x-admin.crud-index-toolbar
            :title="__('production_identifiers.title')"
            :add-route="route('admin.production.identifiers.create')"
            add-permission="production.identifiers.create"
            :show-trash-filter="auth()->user()?->can('production.identifiers.view_trashed')"
            :show-bulk-actions="auth()->user()?->can('production.identifiers.delete')"
            trash-filter-id="production_identifiers_trash_filter"
            bulk-actions-class="production-identifiers-bulk-actions-bar"
            bulk-action-label="{{ __('production_identifiers.bulk_action') }}"
            toolbar-actions-class="production-identifiers-toolbar-actions"
        >
            <button class="btn btn-falcon-default btn-sm" type="button" data-production-identifiers-toggle-tree>
                <span class="fas fa-sitemap me-1"></span>{{ __('production_identifiers.tree_view') }}
            </button>
            <div class="gap-2 d-none align-items-center production-identifiers-tree-controls" data-production-identifiers-tree-controls>
                <button class="btn btn-falcon-default btn-sm" type="button" data-production-identifiers-tree-expand-all title="{{ __('production_identifiers.actions.expand_all') }}" aria-label="{{ __('production_identifiers.actions.expand_all') }}">
                    <span class="fas fa-plus me-1"></span>{{ __('production_identifiers.actions.expand_all') }}
                </button>
                <button class="btn btn-falcon-default btn-sm" type="button" data-production-identifiers-tree-collapse-all title="{{ __('production_identifiers.actions.collapse_all') }}" aria-label="{{ __('production_identifiers.actions.collapse_all') }}">
                    <span class="fas fa-minus me-1"></span>{{ __('production_identifiers.actions.collapse_all') }}
                </button>
            </div>
        </x-admin.crud-index-toolbar>
        <div class="p-0 card-body">
                <div data-production-identifiers-list>
                    <div class="falcon-data-table">
                        <div class="erp-datatable-wrapper">
                            <div class="erp-datatable-scroll">
                                <table class="table mb-0 align-middle table-sm table-hover data-table erp-datatable" id="production-identifiers-table" data-url="{{ route('admin.production.identifiers.data') }}" data-ajax-url="{{ route('admin.production.identifiers.data') }}" data-bulk-delete-url="{{ route('admin.production.identifiers.bulk-delete') }}">
                                    <thead class="bg-100 text-900">
                                        <tr>
                                            <th class="align-middle text-900 no-sort white-space-nowrap all no-colvis dt-select" data-orderable="false" data-searchable="false" style="width: 2.25rem;">
                                                <div class="mb-0 form-check d-flex align-items-center justify-content-center">
                                                    <input class="form-check-input js-record-select-all" type="checkbox" id="production_identifiers_select_all_records" aria-label="{{ __('production_identifiers.select_all') }}">
                                                </div>
                                            </th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap all no-colvis dt-code">{{ __('production_identifiers.attributes.doc_num') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('production_identifiers.attributes.name') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('production_identifiers.attributes.parent') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap">{{ __('production_identifiers.attributes.is_group') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap">{{ __('production_identifiers.attributes.status') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.created_by') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-date">{{ __('common.fields.created_at') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.updated_by') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-date">{{ __('common.fields.updated_at') }}</th>
                                            <th class="align-middle text-900 no-sort pe-1 data-table-row-action all no-colvis dt-actions" data-orderable="false" data-searchable="false"></th>
                                        </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-3 d-none" data-production-identifiers-tree data-url="{{ route('admin.production.identifiers.tree') }}">
                    <div class="bg-white border rounded-2 production-identifiers-tree-panel">
                        <div class="p-3 scrollbar-overlay production-identifiers-tree-list" data-production-identifiers-tree-list></div>
                    </div>
                </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .production-identifiers-tree-list {
            min-height: 12rem;
            max-width: 100%;
            overflow-x: hidden;
        }

        .production-identifiers-toolbar-actions,
        .production-identifiers-tree-controls {
            flex-wrap: wrap;
        }

        .production-identifiers-tree-list .treeview {
            box-sizing: border-box;
            max-width: 100%;
            overflow-x: hidden;
            padding-inline: .5rem !important;
            width: 100%;
        }

        .production-identifiers-tree-list .treeview-list {
            box-sizing: border-box;
            margin: 0;
            max-width: 100%;
            overflow-x: hidden;
            padding-inline-end: 0;
            padding-inline-start: 1.25rem;
        }

        .production-identifiers-tree-list .treeview-list-item {
            box-sizing: border-box;
            max-width: 100%;
            position: relative;
        }

        .production-identifiers-tree-list .treeview-row {
            inset-inline: 0;
            width: auto;
        }

        .production-identifiers-tree-list .treeview-text {
            gap: .5rem;
            min-height: 1.7rem;
            max-width: 100%;
            overflow: hidden;
        }

        .production-identifiers-tree-list [data-production-identifiers-tree-node]:focus {
            border-radius: .25rem;
            outline: 2px solid var(--falcon-primary);
            outline-offset: 2px;
        }

        .production-identifiers-tree-list .production-identifiers-tree-doc-num {
            color: var(--falcon-gray-900);
            font-weight: 700;
        }
    </style>
@endpush

@push('scripts')
    <script>
        window.productionIdentifierMessages = @json($productionIdentifierMessages);
        window.productionIdentifierMessages.groupLabel = @json(__('production_identifiers.attributes.is_group'));
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/identifiers.js') }}"></script>
@endpush

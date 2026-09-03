@extends('layouts.app')

@section('title', __('cost_centers.title'))

@php
    $costCenterMessages = [
        'deleteConfirmTitle' => __('cost_centers.messages.delete_confirm_title'),
        'deleteConfirmText' => __('cost_centers.messages.delete_confirm_text'),
        'deleteConfirmYes' => __('cost_centers.messages.delete_confirm_yes'),
        'bulkDeleteConfirmTitle' => __('cost_centers.messages.bulk_delete_confirm_title'),
        'bulkDeleteConfirmText' => __('cost_centers.messages.bulk_delete_confirm_text'),
        'bulkDeleteConfirmYes' => __('cost_centers.messages.bulk_delete_confirm_yes'),
        'restoreConfirmTitle' => __('cost_centers.messages.restore_confirm_title'),
        'restoreConfirmText' => __('cost_centers.messages.restore_confirm_text'),
        'restoreConfirmYes' => __('cost_centers.messages.restore_confirm_yes'),
        'noRecordsSelected' => __('cost_centers.messages.no_records_selected'),
        'noData' => __('cost_centers.messages.no_data_found'),
        'cancel' => __('common.actions.cancel'),
        'unexpectedError' => __('common.messages.unexpected_error'),
        'validationFailed' => __('common.messages.validation_failed'),
        'saved' => __('common.messages.saved_successfully'),
        'treeView' => __('cost_centers.tree_view'),
        'listView' => __('cost_centers.list_view'),
        'title' => __('cost_centers.title'),
        'inactive' => __('cost_centers.statuses.inactive'),
        'expandAll' => __('cost_centers.actions.expand_all'),
        'collapseAll' => __('cost_centers.actions.collapse_all'),
        'expandBranch' => __('cost_centers.actions.expand_branch'),
        'collapseBranch' => __('cost_centers.actions.collapse_branch'),
    ];
@endphp

@section('content')
    @can('cost_centers.document_number_settings.update')
        <div class="mb-3 card">
            <div class="py-2 card-header">
                <button class="p-0 btn btn-link text-decoration-none w-100 text-start d-flex align-items-center justify-content-between" type="button" data-bs-toggle="collapse" data-bs-target="#cost-centers-document-number-settings">
                    <span class="fw-semibold">{{ __('cost_centers.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="cost-centers-document-number-settings">
                <div class="card-body">
                    <p class="mb-3 text-700">{{ __('cost_centers.document_number_settings.description') }}</p>
                    <form id="cost-centers-document-number-settings-form" action="{{ route('admin.accounting.cost-centers.document-number-settings.update') }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div data-form-alert></div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label" for="cost-centers-document-prefix">{{ __('cost_centers.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="cost-centers-document-prefix" name="prefix" value="{{ $documentNumberSettings['prefix'] ?? 'CC-' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="cost-centers-document-padding">{{ __('cost_centers.document_number_settings.padding') }}</label>
                                <input class="form-control" id="cost-centers-document-padding" name="padding" type="number" value="{{ $documentNumberSettings['padding'] ?? 5 }}">
                                <div class="invalid-feedback d-block" data-error-for="padding"></div>
                            </div>
                            <div class="col-md-auto">
                                <button class="btn btn-falcon-primary" type="submit"><span class="fas fa-save me-1"></span>{{ __('cost_centers.document_number_settings.save') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    <x-admin.report.page class="cost-centers-report" :title="__('cost_centers.title')">
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="cost-centers-filter-panel"
                :filter-title="__('cost_centers.actions.toggle_filters')"
                :export-options="[
                    [
                        'permission' => 'cost_centers.export',
                        'url' => route('admin.accounting.cost-centers.export.excel'),
                        'label' => __('reports.export_excel'),
                    ],
                    [
                        'permission' => 'cost_centers.export',
                        'url' => route('admin.accounting.cost-centers.export.csv'),
                        'label' => __('reports.export_csv'),
                    ],
                    [
                        'permission' => 'cost_centers.export',
                        'url' => route('admin.accounting.cost-centers.export.pdf'),
                        'label' => __('reports.export_pdf'),
                        'newTab' => true,
                    ],
                ]"
            >
                <x-slot:extraActions>
                    <button class="btn btn-falcon-default btn-sm" type="button" data-cost-centers-toggle-tree>
                        <span class="fas fa-sitemap me-1"></span>{{ __('cost_centers.tree_view') }}
                    </button>
                    <x-buttons.add-record :href="route('admin.accounting.cost-centers.create')" permission="cost_centers.create" />
                </x-slot:extraActions>
            </x-admin.report.actions-toolbar>
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="cost-centers-filter-panel"
            :title="__('reports.filters')"
            :description="__('cost_centers.messages.filters_hint')"
        >
            <div class="col-12 col-md-6 col-xl-4 report-filter-field">
                <label class="mb-1 form-label" for="cost-centers-search">{{ __('cost_centers.filters.search') }}</label>
                <input class="form-control form-control-sm js-report-filter-control" id="cost-centers-search" name="cost_center_search" type="search" data-filter-label="{{ __('cost_centers.filters.search') }}" placeholder="{{ __('cost_centers.placeholders.search') }}">
            </div>
            <div class="col-12 col-md-6 col-xl-4 report-filter-field">
                <label class="mb-1 form-label" for="cost-centers-status">{{ __('cost_centers.filters.status') }}</label>
                <select class="form-select form-select-sm js-report-filter-control" id="cost-centers-status" name="status" data-filter-label="{{ __('cost_centers.filters.status') }}">
                    <option value="">{{ __('cost_centers.filters.all') }}</option>
                    @foreach (['active', 'inactive'] as $status)
                        <option value="{{ $status }}">{{ __('cost_centers.statuses.' . $status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-4 report-filter-field">
                <label class="mb-1 form-label" for="cost-centers-hierarchy">{{ __('cost_centers.filters.hierarchy') }}</label>
                <select class="form-select form-select-sm js-report-filter-control" id="cost-centers-hierarchy" name="hierarchy" data-filter-label="{{ __('cost_centers.filters.hierarchy') }}">
                    <option value="">{{ __('cost_centers.hierarchy_filters.all') }}</option>
                    <option value="root">{{ __('cost_centers.hierarchy_filters.root') }}</option>
                    <option value="children">{{ __('cost_centers.hierarchy_filters.children') }}</option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-4 report-filter-field">
                <label class="mb-1 form-label" for="cost-centers-linked-account">{{ __('cost_centers.filters.linked_account') }}</label>
                <select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="cost-centers-linked-account" name="linked_account_doc_num" data-filter-label="{{ __('cost_centers.filters.linked_account') }}" data-url="{{ route('admin.accounting.select2.accounts', ['hierarchy' => 1]) }}" data-placeholder="{{ __('cost_centers.placeholders.linked_accounts') }}" data-allow-clear="true"></select>
            </div>
        </x-admin.report.filter-panel>

        <div class="card erp-datatable-card cost-centers-datatable-card">
            <div class="card-header">
                <div class="row flex-between-center">
                    <div class="col-6 col-sm-auto d-flex align-items-center pe-0">
                        <h5 class="py-2 mb-0 fs-9 text-nowrap py-xl-0" data-cost-centers-panel-title>{{ __('cost_centers.title') }}</h5>
                    </div>
                    <div class="gap-2 col-6 col-sm-auto ms-auto text-end ps-0 d-flex justify-content-end align-items-center cost-centers-toolbar-actions">
                        <div class="gap-2 d-none align-items-center cost-centers-tree-controls" data-cost-centers-tree-controls>
                            <button class="btn btn-falcon-default btn-sm" type="button" data-cost-centers-tree-expand-all title="{{ __('cost_centers.actions.expand_all') }}" aria-label="{{ __('cost_centers.actions.expand_all') }}">
                                <span class="fas fa-plus me-1"></span>{{ __('cost_centers.actions.expand_all') }}
                            </button>
                            <button class="btn btn-falcon-default btn-sm" type="button" data-cost-centers-tree-collapse-all title="{{ __('cost_centers.actions.collapse_all') }}" aria-label="{{ __('cost_centers.actions.collapse_all') }}">
                                <span class="fas fa-minus me-1"></span>{{ __('cost_centers.actions.collapse_all') }}
                            </button>
                        </div>
                        @can('cost_centers.view_trashed')
                            <div class="gap-2 d-flex align-items-center">
                                <label class="mb-0 form-label text-700 fs-10" for="cost_centers_trash_filter">{{ __('cost_centers.trash.filter_label') }}</label>
                                <select class="w-auto form-select form-select-sm" id="cost_centers_trash_filter" aria-label="{{ __('cost_centers.trash.filter_label') }}">
                                    <option value="active">{{ __('cost_centers.trash.active') }}</option>
                                    <option value="trashed">{{ __('cost_centers.trash.trashed') }}</option>
                                    <option value="all">{{ __('cost_centers.trash.all') }}</option>
                                </select>
                            </div>
                        @endcan
                        @can('cost_centers.delete')
                            <div class="gap-2 d-none align-items-center cost-centers-bulk-actions-bar" id="cost_centers_bulk_actions_bar">
                                <span class="badge rounded-pill badge-subtle-primary" id="cost_centers_bulk_selected_count">0</span>
                                <select class="w-auto form-select form-select-sm" id="cost_centers_bulk_action_select" aria-label="{{ __('cost_centers.bulk_action') }}">
                                    <option value="delete">{{ __('common.actions.delete') }}</option>
                                </select>
                                <button type="button" class="btn btn-falcon-danger btn-sm" id="cost_centers_bulk_action_apply" data-label="{{ __('common.actions.apply') }}" title="{{ __('common.shortcuts.bulk_apply') }}" data-bs-title="{{ __('common.shortcuts.bulk_apply') }}" disabled>
                                    <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                                </button>
                            </div>
                        @endcan
                    </div>
                </div>
            </div>
            <div class="p-0 card-body">
                <div data-cost-centers-list>
                    <div class="falcon-data-table">
                        <div class="erp-datatable-wrapper">
                            <div class="erp-datatable-scroll">
                                <table class="table mb-0 align-middle table-sm table-hover data-table erp-datatable" id="cost-centers-table" data-url="{{ route('admin.accounting.cost-centers.data') }}" data-ajax-url="{{ route('admin.accounting.cost-centers.data') }}" data-bulk-delete-url="{{ route('admin.accounting.cost-centers.bulk-delete') }}">
                                    <thead class="bg-100 text-900">
                                        <tr>
                                            <th class="align-middle text-900 no-sort white-space-nowrap all no-colvis dt-select" data-orderable="false" data-searchable="false" style="width: 2.25rem;">
                                                <div class="mb-0 form-check d-flex align-items-center justify-content-center">
                                                    <input class="form-check-input js-record-select-all" type="checkbox" id="cost_centers_select_all_records" aria-label="{{ __('cost_centers.select_all') }}">
                                                </div>
                                            </th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap all no-colvis dt-code">{{ __('cost_centers.attributes.doc_num') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-code">{{ __('cost_centers.attributes.cost_center_code') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('cost_centers.attributes.name') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('cost_centers.attributes.parent') }}</th>
                                            <th class="align-middle text-900 no-sort pe-1 white-space-nowrap dt-text dt-ellipsis" data-orderable="false">{{ __('cost_centers.attributes.linked_accounts_short') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap">{{ __('cost_centers.attributes.is_group') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap">{{ __('cost_centers.attributes.status') }}</th>
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
                <div class="p-3 d-none" data-cost-centers-tree data-url="{{ route('admin.accounting.cost-centers.tree') }}">
                    <div class="bg-white border rounded-2 cost-centers-tree-panel">
                        <div class="p-3 scrollbar-overlay cost-centers-tree-list" data-cost-centers-tree-list></div>
                    </div>
                </div>
            </div>
        </div>
    </x-admin.report.page>
@endsection

@push('styles')
    <style>
        .cost-centers-tree-list {
            min-height: 12rem;
            max-width: 100%;
            overflow-x: hidden;
        }

        .cost-centers-toolbar-actions,
        .cost-centers-tree-controls {
            flex-wrap: wrap;
        }

        .cost-centers-tree-list .treeview {
            box-sizing: border-box;
            max-width: 100%;
            overflow-x: hidden;
            padding-inline: .5rem !important;
            width: 100%;
        }

        .cost-centers-tree-list .treeview-list {
            box-sizing: border-box;
            margin: 0;
            max-width: 100%;
            overflow-x: hidden;
            padding-inline-end: 0;
            padding-inline-start: 1.25rem;
        }

        .cost-centers-tree-list .treeview-list-item {
            box-sizing: border-box;
            max-width: 100%;
            position: relative;
        }

        .cost-centers-tree-list .treeview-row {
            inset-inline: 0;
            width: auto;
        }

        .cost-centers-tree-list .treeview-text {
            gap: .5rem;
            min-height: 1.7rem;
            max-width: 100%;
            overflow: hidden;
        }

        .cost-centers-tree-list [data-cost-centers-tree-node]:focus {
            border-radius: .25rem;
            outline: 2px solid var(--falcon-primary);
            outline-offset: 2px;
        }

        .cost-centers-tree-list .cost-centers-tree-code {
            color: var(--falcon-gray-900);
            font-weight: 700;
        }
    </style>
@endpush

@push('scripts')
    <script>
        window.costCenterMessages = @json($costCenterMessages);
        window.costCenterMessages.groupLabel = @json(__('cost_centers.attributes.is_group'));
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/report-ui.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Accounting/cost-centers.js') }}"></script>
@endpush

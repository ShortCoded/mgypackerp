@extends('layouts.app')

@section('title', __('accounts.title'))

@php
    $accountMessages = [
        'deleteConfirmTitle' => __('accounts.messages.delete_confirm_title'),
        'deleteConfirmText' => __('accounts.messages.delete_confirm_text'),
        'deleteConfirmYes' => __('accounts.messages.delete_confirm_yes'),
        'bulkDeleteConfirmTitle' => __('accounts.messages.bulk_delete_confirm_title'),
        'bulkDeleteConfirmText' => __('accounts.messages.bulk_delete_confirm_text'),
        'bulkDeleteConfirmYes' => __('accounts.messages.bulk_delete_confirm_yes'),
        'restoreConfirmTitle' => __('accounts.messages.restore_confirm_title'),
        'restoreConfirmText' => __('accounts.messages.restore_confirm_text'),
        'restoreConfirmYes' => __('accounts.messages.restore_confirm_yes'),
        'noRecordsSelected' => __('accounts.messages.no_records_selected'),
        'noData' => __('accounts.messages.no_data_found'),
        'cancel' => __('common.actions.cancel'),
        'unexpectedError' => __('common.messages.unexpected_error'),
        'validationFailed' => __('common.messages.validation_failed'),
        'saved' => __('common.messages.saved_successfully'),
        'treeView' => __('accounts.tree_view'),
        'listView' => __('accounts.list_view'),
        'title' => __('accounts.title'),
        'inactive' => __('accounts.statuses.inactive'),
        'expandAll' => __('accounts.actions.expand_all'),
        'collapseAll' => __('accounts.actions.collapse_all'),
        'expandBranch' => __('accounts.actions.expand_branch'),
        'collapseBranch' => __('accounts.actions.collapse_branch'),
    ];
@endphp

@section('content')
    @can('accounts.document_number_settings.update')
        <div class="mb-3 card">
            <div class="py-2 card-header">
                <button class="p-0 btn btn-link text-decoration-none w-100 text-start d-flex align-items-center justify-content-between" type="button" data-bs-toggle="collapse" data-bs-target="#accounts-document-number-settings">
                    <span class="fw-semibold">{{ __('accounts.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="accounts-document-number-settings">
                <div class="card-body">
                    <p class="mb-3 text-700">{{ __('accounts.document_number_settings.description') }}</p>
                    <form id="accounts-document-number-settings-form" action="{{ route('admin.accounting.accounts.document-number-settings.update') }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div data-form-alert></div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label" for="accounts-document-prefix">{{ __('accounts.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="accounts-document-prefix" name="prefix" value="{{ $documentNumberSettings['prefix'] ?? 'ACC-' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="accounts-document-padding">{{ __('accounts.document_number_settings.padding') }}</label>
                                <input class="form-control" id="accounts-document-padding" name="padding" type="number" value="{{ $documentNumberSettings['padding'] ?? 5 }}">
                                <div class="invalid-feedback d-block" data-error-for="padding"></div>
                            </div>
                            <div class="col-md-auto">
                                <button class="btn btn-falcon-primary" type="submit"><span class="fas fa-save me-1"></span>{{ __('accounts.document_number_settings.save') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    <x-admin.report.page
        class="accounts-report"
        :title="__('accounts.title')"

    >
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="accounts-filter-panel"
                :filter-title="__('accounts.actions.toggle_filters')"
                :export-options="[
                    [
                        'permission' => 'accounts.export',
                        'url' => route('admin.accounting.accounts.export.excel'),
                        'label' => __('reports.export_excel'),
                    ],
                    [
                        'permission' => 'accounts.export',
                        'url' => route('admin.accounting.accounts.export.csv'),
                        'label' => __('reports.export_csv'),
                    ],
                    [
                        'permission' => 'accounts.export',
                        'url' => route('admin.accounting.accounts.export.pdf'),
                        'label' => __('reports.export_pdf'),
                        'newTab' => true,
                    ],
                ]"
            >
                <x-slot:extraActions>
                    <button class="btn btn-falcon-default btn-sm" type="button" data-accounts-toggle-tree>
                        <span class="fas fa-sitemap me-1"></span>{{ __('accounts.tree_view') }}
                    </button>
                    <x-buttons.add-record :href="route('admin.accounting.accounts.create')" permission="accounts.create" />
                </x-slot:extraActions>
            </x-admin.report.actions-toolbar>
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="accounts-filter-panel"
            :title="__('reports.filters')"
            :description="__('accounts.messages.filters_hint')"
        >
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="mb-1 form-label" for="accounts-search">{{ __('accounts.filters.search') }}</label>
                <input class="form-control form-control-sm js-report-filter-control" id="accounts-search" name="account_search" type="search" data-filter-label="{{ __('accounts.filters.search') }}" placeholder="{{ __('accounts.placeholders.search') }}">
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="mb-1 form-label" for="accounts-statement-type">{{ __('accounts.filters.statement_type') }}</label>
                <select class="form-select form-select-sm js-report-filter-control" id="accounts-statement-type" name="statement_type" data-filter-label="{{ __('accounts.filters.statement_type') }}">
                    <option value="">{{ __('accounts.filters.all') }}</option>
                    @foreach (\Modules\Accounting\Models\Account::statementTypes() as $type)
                        <option value="{{ $type }}">{{ __('accounts.statement_types.' . $type) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="mb-1 form-label" for="accounts-normal-balance">{{ __('accounts.filters.normal_balance') }}</label>
                <select class="form-select form-select-sm js-report-filter-control" id="accounts-normal-balance" name="normal_balance" data-filter-label="{{ __('accounts.filters.normal_balance') }}">
                    <option value="">{{ __('accounts.filters.all') }}</option>
                    @foreach (\Modules\Accounting\Models\Account::normalBalances() as $balance)
                        <option value="{{ $balance }}">{{ __('accounts.normal_balances.' . $balance) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="mb-1 form-label" for="accounts-classification">{{ __('accounts.filters.classification') }}</label>
                <select class="form-select form-select-sm w-100 js-select2-ajax js-report-filter-control" id="accounts-classification" name="classification" data-filter-label="{{ __('accounts.filters.classification') }}" data-url="{{ route('admin.accounting.select2.account-classifications') }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="mb-1 form-label" for="accounts-status">{{ __('accounts.filters.status') }}</label>
                <select class="form-select form-select-sm js-report-filter-control" id="accounts-status" name="status" data-filter-label="{{ __('accounts.filters.status') }}">
                    <option value="">{{ __('accounts.filters.all') }}</option>
                    @foreach (['active', 'inactive'] as $status)
                        <option value="{{ $status }}">{{ __('accounts.statuses.' . $status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="mb-1 form-label" for="accounts-hierarchy">{{ __('accounts.filters.hierarchy') }}</label>
                <select class="form-select form-select-sm js-report-filter-control" id="accounts-hierarchy" name="hierarchy" data-filter-label="{{ __('accounts.filters.hierarchy') }}">
                    <option value="">{{ __('accounts.hierarchy_filters.all') }}</option>
                    <option value="root">{{ __('accounts.hierarchy_filters.root') }}</option>
                    <option value="children">{{ __('accounts.hierarchy_filters.children') }}</option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="mb-1 form-label" for="accounts-level">{{ __('accounts.filters.level') }}</label>
                <input class="form-control form-control-sm js-report-filter-control" id="accounts-level" name="level" type="number" min="1" data-filter-label="{{ __('accounts.filters.level') }}" placeholder="{{ __('accounts.placeholders.level') }}">
            </div>
        </x-admin.report.filter-panel>

        <div class="card erp-datatable-card accounts-datatable-card">
            <div class="card-header">
                <div class="row flex-between-center">
                    <div class="col-6 col-sm-auto d-flex align-items-center pe-0">
                        <h5 class="py-2 mb-0 fs-9 text-nowrap py-xl-0" data-accounts-panel-title>{{ __('accounts.title') }}</h5>
                    </div>
                    <div class="gap-2 col-6 col-sm-auto ms-auto text-end ps-0 d-flex justify-content-end align-items-center accounts-toolbar-actions">
                        <div class="gap-2 d-none align-items-center accounts-tree-controls" data-accounts-tree-controls>
                            <button class="btn btn-falcon-default btn-sm" type="button" data-accounts-tree-expand-all title="{{ __('accounts.actions.expand_all') }}" aria-label="{{ __('accounts.actions.expand_all') }}">
                                <span class="fas fa-plus me-1"></span>{{ __('accounts.actions.expand_all') }}
                            </button>
                            <button class="btn btn-falcon-default btn-sm" type="button" data-accounts-tree-collapse-all title="{{ __('accounts.actions.collapse_all') }}" aria-label="{{ __('accounts.actions.collapse_all') }}">
                                <span class="fas fa-minus me-1"></span>{{ __('accounts.actions.collapse_all') }}
                            </button>
                        </div>
                        @can('accounts.view_trashed')
                            <div class="gap-2 d-flex align-items-center">
                                <label class="mb-0 form-label text-700 fs-10" for="accounts_trash_filter">{{ __('accounts.trash.filter_label') }}</label>
                                <select class="w-auto form-select form-select-sm" id="accounts_trash_filter" aria-label="{{ __('accounts.trash.filter_label') }}">
                                    <option value="active">{{ __('accounts.trash.active') }}</option>
                                    <option value="trashed">{{ __('accounts.trash.trashed') }}</option>
                                    <option value="all">{{ __('accounts.trash.all') }}</option>
                                </select>
                            </div>
                        @endcan
                        @can('accounts.delete')
                            <div class="gap-2 d-none align-items-center accounts-bulk-actions-bar" id="bulk_actions_bar">
                                <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                                <select class="w-auto form-select form-select-sm" id="bulk_action_select" aria-label="{{ __('accounts.bulk_action') }}">
                                    <option value="delete">{{ __('common.actions.delete') }}</option>
                                </select>
                                <button type="button" class="btn btn-falcon-danger btn-sm" id="bulk_action_apply" data-label="{{ __('common.actions.apply') }}" title="{{ __('common.shortcuts.bulk_apply') }}" data-bs-title="{{ __('common.shortcuts.bulk_apply') }}" disabled>
                                    <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                                </button>
                            </div>
                        @endcan
                    </div>
                </div>
            </div>
            <div class="p-0 card-body">
                <div data-accounts-list>
                    <div class="falcon-data-table">
                        <div class="erp-datatable-wrapper">
                            <div class="erp-datatable-scroll">
                                <table class="table mb-0 align-middle table-sm table-hover data-table erp-datatable" id="accounts-table" data-url="{{ route('admin.accounting.accounts.data') }}" data-ajax-url="{{ route('admin.accounting.accounts.data') }}" data-bulk-delete-url="{{ route('admin.accounting.accounts.bulk-delete') }}">
                                    <thead class="bg-100 text-900">
                                        <tr>
                                            <th class="align-middle text-900 no-sort white-space-nowrap all no-colvis dt-select" data-orderable="false" data-searchable="false" style="width: 2.25rem;">
                                                <div class="mb-0 form-check d-flex align-items-center justify-content-center">
                                                    <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('accounts.select_all') }}">
                                                </div>
                                            </th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap all no-colvis dt-code">{{ __('accounts.attributes.doc_num') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-code">{{ __('accounts.attributes.account_code') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('accounts.attributes.name') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('accounts.attributes.parent') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap dt-text dt-ellipsis">{{ __('accounts.attributes.classification') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap">{{ __('accounts.attributes.statement_type') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap">{{ __('accounts.attributes.normal_balance') }}</th>
                                            <th class="align-middle text-900 sort pe-1 white-space-nowrap">{{ __('accounts.attributes.status') }}</th>
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
                <div class="p-3 d-none" data-accounts-tree data-url="{{ route('admin.accounting.accounts.tree') }}">
                    <div class="bg-white border rounded-2 accounts-tree-panel">
                        <div class="p-3 scrollbar-overlay accounts-tree-list" data-accounts-tree-list></div>
                    </div>
                </div>
            </div>
        </div>
    </x-admin.report.page>
@endsection

@push('styles')
    <style>
        .accounts-tree-list {
            min-height: 12rem;
            max-width: 100%;
            overflow-x: hidden;
        }

        .accounts-toolbar-actions,
        .accounts-tree-controls {
            flex-wrap: wrap;
        }

        .accounts-tree-list .treeview {
            box-sizing: border-box;
            max-width: 100%;
            overflow-x: hidden;
            padding-inline: .5rem !important;
            width: 100%;
        }

        .accounts-tree-list .treeview-list {
            box-sizing: border-box;
            margin: 0;
            max-width: 100%;
            overflow-x: hidden;
            padding-inline-end: 0;
            padding-inline-start: 1.25rem;
        }

        .accounts-tree-list .treeview-list-item {
            box-sizing: border-box;
            max-width: 100%;
            position: relative;
        }

        .accounts-tree-list .treeview-row {
            inset-inline: 0;
            width: auto;
        }

        .accounts-tree-list .treeview-text {
            gap: .5rem;
            min-height: 1.7rem;
            max-width: 100%;
            overflow: hidden;
        }

        .accounts-tree-list [data-accounts-tree-node]:focus {
            border-radius: .25rem;
            outline: 2px solid var(--falcon-primary);
            outline-offset: 2px;
        }

        .accounts-tree-list .accounts-tree-code {
            color: var(--falcon-gray-900);
            font-weight: 700;
        }
    </style>
@endpush

@push('scripts')
    <script>
        window.accountMessages = @json($accountMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/report-ui.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Accounting/accounts.js') }}"></script>
@endpush

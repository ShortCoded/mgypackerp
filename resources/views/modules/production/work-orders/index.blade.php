@extends('layouts.app')

@php
    $columns = [
        ['data' => 'checkbox', 'name' => 'checkbox', 'orderable' => false, 'searchable' => false, 'className' => 'dt-select no-colvis all align-middle text-center', 'responsivePriority' => 1],
        ['data' => 'doc_num', 'name' => 'production_orders.doc_number', 'className' => 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', 'responsivePriority' => 2],
        ['data' => 'source_document_number', 'name' => 'source_document_number', 'defaultContent' => '—', 'className' => 'dt-code align-middle white-space-nowrap', 'responsivePriority' => 10],
        ['data' => 'production_order_date', 'name' => 'production_orders.production_order_date', 'className' => 'dt-date align-middle white-space-nowrap', 'responsivePriority' => 15],
        ['data' => 'expected_delivery_date', 'name' => 'production_orders.expected_delivery_date', 'className' => 'dt-date align-middle white-space-nowrap', 'responsivePriority' => 20],
        ['data' => 'lines_count', 'name' => 'lines_count', 'searchable' => false, 'className' => 'dt-number align-middle text-end', 'responsivePriority' => 25],
        ['data' => 'runs_count', 'name' => 'runs_count', 'searchable' => false, 'className' => 'dt-number align-middle text-end', 'responsivePriority' => 25],
        ['data' => 'status', 'name' => 'production_orders.status', 'className' => 'align-middle white-space-nowrap', 'responsivePriority' => 15],
        ['data' => 'created_by', 'name' => 'created_by', 'className' => 'dt-text dt-ellipsis align-middle white-space-nowrap', 'responsivePriority' => 40],
        ['data' => 'created_at', 'name' => 'production_orders.created_at', 'className' => 'dt-date align-middle white-space-nowrap', 'responsivePriority' => 40],
        ['data' => 'updated_by', 'name' => 'updated_by', 'className' => 'dt-text dt-ellipsis align-middle white-space-nowrap', 'responsivePriority' => 45],
        ['data' => 'updated_at', 'name' => 'production_orders.updated_at', 'className' => 'dt-date align-middle white-space-nowrap', 'responsivePriority' => 45],
        ['data' => 'actions', 'name' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'dt-actions no-colvis all align-middle white-space-nowrap', 'responsivePriority' => 3],
    ];
@endphp

@section('title', __('production_execution.orders.title'))

@section('content')
    <div class="production-mobile-workflow">
        @if ($canManageProduction && auth()->user()?->can('production.orders.document_number_settings.update'))
            <div class="card mb-3">
                <div class="card-header py-2">
                    <button
                        class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#production-orders-document-number-settings"
                        aria-expanded="false"
                        aria-controls="production-orders-document-number-settings"
                    >
                        <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                        <span class="fas fa-chevron-down fs-11"></span>
                    </button>
                </div>
                <div class="collapse" id="production-orders-document-number-settings">
                    <div class="card-body">
                        <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                        <form
                            class="js-production-order-document-number-settings-form"
                            action="{{ route('admin.production.work-orders.document-number-settings.update') }}"
                            method="POST"
                            data-unexpected-error="{{ __('common.messages.unexpected_error') }}"
                            novalidate
                        >
                            @csrf
                            @method('PUT')
                            <div class="alert alert-danger alert-dismissible fade show d-none js-form-alert" role="alert">
                                <span class="js-form-alert-message"></span>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                            </div>
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6 col-lg-4">
                                    <label class="form-label" for="production-orders-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                    <x-forms.input class="form-control" id="production-orders-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}" />
                                    <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                                </div>
                                <div class="col-md-3 col-lg-2">
                                    <label class="form-label" for="production-orders-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                    <x-forms.input class="form-control" id="production-orders-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 5 }}" required />
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
        @endif

        <div class="card erp-datatable-card production-orders-datatable-card">
            <x-admin.crud-index-toolbar
                :title="__('production_execution.orders.title')"
                :add-route="$canManageProduction ? route('admin.production.work-orders.create') : null"
                add-permission="production.orders.create"
                :add-label="__('production_execution.orders.create')"
                :show-trash-filter="auth()->user()?->can('production.orders.view_trashed')"
                :show-bulk-actions="$canManageProduction && auth()->user()?->can('production.orders.delete')"
                trash-filter-id="production_orders_trash_filter"
                bulk-actions-class="production-orders-bulk-actions-bar"
                :bulk-action-label="__('common.bulk_action')"
                toolbar-actions-class="production-orders-toolbar-actions"
            />

            <div class="card-body p-0">
                <div class="falcon-data-table">
                    <div class="erp-datatable-wrapper">
                        <div class="erp-datatable-scroll">
                            <table
                                class="table table-sm table-hover mb-0 data-table erp-datatable align-middle"
                                id="production-orders-table"
                                data-server-table
                                data-record-selection
                                data-url="{{ route('admin.production.work-orders.data') }}"
                                data-bulk-delete-url="{{ route('admin.production.work-orders.bulk-delete') }}"
                                data-no-selection-message="{{ __('production_execution.messages.no_orders_selected') }}"
                                data-bulk-confirm-message="{{ __('production_execution.messages.bulk_delete_orders_confirm') }}"
                                data-bulk-success-message="{{ __('production_execution.messages.bulk_delete_orders_done') }}"
                                data-trash-filter="#production_orders_trash_filter"
                                data-initial-trash-filter="{{ request('trash_filter', 'active') }}"
                                data-order-column="1"
                                data-order-direction="desc"
                                data-columns='@json($columns)'
                            >
                                <thead class="bg-100 text-900">
                                    <tr>
                                        <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" data-searchable="false">
                                            <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                                <x-forms.input class="form-check-input js-record-select-all" type="checkbox" id="production_orders_select_all" aria-label="{{ __('common.select_all') }}" />
                                            </div>
                                        </th>
                                        <th>{{ __('production_execution.fields.production_order') }}</th>
                                        <th>{{ __('production_execution.fields.source') }}</th>
                                        <th>{{ __('production_execution.fields.order_date') }}</th>
                                        <th>{{ __('production_execution.fields.delivery_date') }}</th>
                                        <th>{{ __('production_execution.fields.products_count') }}</th>
                                        <th>{{ __('production_execution.fields.runs_count') }}</th>
                                        <th>{{ __('production_execution.fields.status') }}</th>
                                        <th>{{ __('common.fields.created_by') }}</th>
                                        <th>{{ __('common.fields.created_at') }}</th>
                                        <th>{{ __('common.fields.updated_by') }}</th>
                                        <th>{{ __('common.fields.updated_at') }}</th>
                                        <th class="data-table-row-action"></th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush

@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>
@endpush

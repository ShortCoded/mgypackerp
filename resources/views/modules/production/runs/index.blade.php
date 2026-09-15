@extends('layouts.app')

@php
    $columns = [
        ['data' => 'checkbox', 'name' => 'checkbox', 'orderable' => false, 'searchable' => false, 'className' => 'dt-select no-colvis all align-middle text-center', 'responsivePriority' => 1],
        ['data' => 'run_number', 'name' => 'production_runs.run_number', 'className' => 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', 'responsivePriority' => 2],
        ['data' => 'order_number', 'name' => 'order_number', 'className' => 'dt-code align-middle white-space-nowrap', 'responsivePriority' => 8],
        ['data' => 'product_name', 'name' => 'product_name', 'className' => 'dt-text align-middle', 'responsivePriority' => 8],
        ['data' => 'stage_name', 'name' => 'stage_name', 'defaultContent' => '—', 'className' => 'dt-text align-middle', 'responsivePriority' => 12],
        ['data' => 'asset_name', 'name' => 'asset_name', 'defaultContent' => '—', 'className' => 'dt-text align-middle', 'responsivePriority' => 16],
        ['data' => 'planned_start_at', 'name' => 'production_runs.planned_start_at', 'className' => 'dt-date align-middle white-space-nowrap', 'responsivePriority' => 16],
        ['data' => 'planned_base_quantity', 'name' => 'production_runs.planned_base_quantity', 'className' => 'dt-number align-middle text-end', 'responsivePriority' => 10],
        ['data' => 'good_base_quantity', 'name' => 'production_runs.good_base_quantity', 'className' => 'dt-number align-middle text-end', 'responsivePriority' => 10],
        ['data' => 'status', 'name' => 'production_runs.status', 'className' => 'align-middle white-space-nowrap', 'responsivePriority' => 8],
        ['data' => 'created_by', 'name' => 'created_by', 'className' => 'dt-text dt-ellipsis align-middle white-space-nowrap', 'responsivePriority' => 35],
        ['data' => 'created_at', 'name' => 'production_runs.created_at', 'className' => 'dt-date align-middle white-space-nowrap', 'responsivePriority' => 35],
        ['data' => 'updated_by', 'name' => 'updated_by', 'className' => 'dt-text dt-ellipsis align-middle white-space-nowrap', 'responsivePriority' => 40],
        ['data' => 'updated_at', 'name' => 'production_runs.updated_at', 'className' => 'dt-date align-middle white-space-nowrap', 'responsivePriority' => 40],
        ['data' => 'actions', 'name' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'dt-actions no-colvis all align-middle white-space-nowrap', 'responsivePriority' => 3],
    ];
@endphp

@section('title', __('production_execution.runs.title'))

@section('content')
    <div class="production-mobile-workflow">
        <div class="card erp-datatable-card">
            <x-admin.crud-index-toolbar
                :title="__('production_execution.runs.title')"
                :add-route="route('admin.production.runs.create')"
                add-permission="production.runs.plan"
                :add-label="__('production_execution.runs.create')"
                :show-trash-filter="auth()->user()?->can('production.runs.view_trashed')"
                :show-bulk-actions="auth()->user()?->can('production.runs.delete')"
                trash-filter-id="production_runs_trash_filter"
                bulk-actions-class="production-runs-bulk-actions-bar"
                :bulk-action-label="__('common.bulk_action')"
                toolbar-actions-class="production-runs-toolbar-actions"
            />

            <div class="card-body p-0">
                <div class="falcon-data-table">
                    <div class="erp-datatable-wrapper">
                        <div class="erp-datatable-scroll">
                            <table
                                class="table table-sm table-hover mb-0 data-table erp-datatable align-middle"
                                id="production-runs-table"
                                data-server-table
                                data-record-selection
                                data-url="{{ route('admin.production.runs.data') }}"
                                data-bulk-delete-url="{{ route('admin.production.runs.bulk-delete') }}"
                                data-no-selection-message="{{ __('production_execution.messages.no_runs_selected') }}"
                                data-bulk-confirm-message="{{ __('production_execution.messages.bulk_delete_runs_confirm') }}"
                                data-bulk-success-message="{{ __('production_execution.messages.bulk_delete_runs_done') }}"
                                data-trash-filter="#production_runs_trash_filter"
                                data-initial-trash-filter="{{ request('trash_filter', 'active') }}"
                                data-order-column="1"
                                data-order-direction="desc"
                                data-columns='@json($columns)'
                            >
                                <thead class="bg-100 text-900">
                                    <tr>
                                        <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" data-searchable="false">
                                            <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                                <x-forms.input class="form-check-input js-record-select-all" type="checkbox" id="production_runs_select_all" aria-label="{{ __('common.select_all') }}" />
                                            </div>
                                        </th>
                                        <th>{{ __('production_execution.fields.run') }}</th>
                                        <th>{{ __('production_execution.fields.production_order') }}</th>
                                        <th>{{ __('production_execution.fields.product') }}</th>
                                        <th>{{ __('production_execution.fields.stage') }}</th>
                                        <th>{{ __('production_execution.fields.fixed_asset') }}</th>
                                        <th>{{ __('production_execution.fields.starts_at') }}</th>
                                        <th>{{ __('production_execution.fields.planned_quantity') }}</th>
                                        <th>{{ __('production_execution.fields.accepted_quantity') }}</th>
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

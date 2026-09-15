@extends('layouts.app')

@php
    $columns = [
        ['data' => 'checkbox', 'name' => 'checkbox', 'orderable' => false, 'searchable' => false, 'className' => 'dt-select no-colvis all align-middle text-center', 'responsivePriority' => 1],
        ['data' => 'doc_num', 'name' => 'production_expense_requests.doc_num', 'className' => 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', 'responsivePriority' => 2],
        ['data' => 'request_date', 'name' => 'production_expense_requests.request_date', 'className' => 'dt-date align-middle white-space-nowrap', 'responsivePriority' => 12],
        ['data' => 'run_number', 'name' => 'run_number', 'className' => 'dt-code align-middle white-space-nowrap', 'responsivePriority' => 8],
        ['data' => 'amount', 'name' => 'production_expense_requests.amount', 'className' => 'dt-number align-middle text-end', 'responsivePriority' => 12],
        ['data' => 'currency_code', 'name' => 'currency_code', 'className' => 'dt-code align-middle white-space-nowrap', 'responsivePriority' => 16],
        ['data' => 'reason', 'name' => 'production_expense_requests.reason', 'className' => 'dt-text dt-ellipsis align-middle', 'responsivePriority' => 20],
        ['data' => 'voucher_number', 'name' => 'voucher_number', 'defaultContent' => '—', 'className' => 'dt-code align-middle white-space-nowrap', 'responsivePriority' => 25],
        ['data' => 'status', 'name' => 'production_expense_requests.status', 'className' => 'align-middle white-space-nowrap', 'responsivePriority' => 10],
        ['data' => 'created_by', 'name' => 'created_by', 'className' => 'dt-text align-middle white-space-nowrap', 'responsivePriority' => 35],
        ['data' => 'created_at', 'name' => 'production_expense_requests.created_at', 'className' => 'dt-date align-middle white-space-nowrap', 'responsivePriority' => 35],
        ['data' => 'updated_by', 'name' => 'updated_by', 'className' => 'dt-text align-middle white-space-nowrap', 'responsivePriority' => 40],
        ['data' => 'updated_at', 'name' => 'production_expense_requests.updated_at', 'className' => 'dt-date align-middle white-space-nowrap', 'responsivePriority' => 40],
        ['data' => 'actions', 'name' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'dt-actions no-colvis all align-middle white-space-nowrap', 'responsivePriority' => 3],
    ];
@endphp

@section('title', __('production_execution.expenses.title'))

@section('content')
    <div class="production-mobile-workflow">
        <div class="card erp-datatable-card">
            <x-admin.crud-index-toolbar
                :title="__('production_execution.expenses.title')"
                :add-route="route('admin.production.expenses.create')"
                add-permission="production.expenses.create"
                :add-label="__('production_execution.expenses.create')"
                :show-trash-filter="auth()->user()?->can('production.expenses.view_trashed')"
                :show-bulk-actions="auth()->user()?->can('production.expenses.delete')"
                trash-filter-id="production_expenses_trash_filter"
                bulk-actions-class="production-expenses-bulk-actions-bar"
                :bulk-action-label="__('common.bulk_action')"
                toolbar-actions-class="production-expenses-toolbar-actions"
            />
            <div class="card-body p-0"><div class="falcon-data-table"><div class="erp-datatable-wrapper"><div class="erp-datatable-scroll">
                <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle" id="production-expenses-table" data-server-table data-record-selection data-url="{{ route('admin.production.expenses.index') }}" data-bulk-delete-url="{{ route('admin.production.expenses.bulk-delete') }}" data-no-selection-message="{{ __('production_execution.messages.no_expenses_selected') }}" data-bulk-confirm-message="{{ __('production_execution.messages.expenses_bulk_delete_confirm') }}" data-bulk-success-message="{{ __('production_execution.messages.expenses_bulk_deleted') }}" data-trash-filter="#production_expenses_trash_filter" data-initial-trash-filter="{{ request('trash_filter', 'active') }}" data-order-column="1" data-order-direction="desc" data-columns='@json($columns)'>
                    <thead class="bg-100 text-900"><tr>
                        <th class="dt-select no-colvis all"><div class="form-check mb-0 d-flex justify-content-center"><x-forms.input class="form-check-input js-record-select-all" type="checkbox" id="production_expenses_select_all" aria-label="{{ __('common.select_all') }}" /></div></th>
                        <th>{{ __('production_execution.fields.document') }}</th><th>{{ __('production_execution.fields.date') }}</th><th>{{ __('production_execution.fields.run') }}</th><th>{{ __('production_execution.fields.amount') }}</th><th>{{ __('production_execution.fields.currency') }}</th><th>{{ __('production_execution.fields.reason') }}</th><th>{{ __('production_execution.fields.payment_voucher') }}</th><th>{{ __('production_execution.fields.status') }}</th><th>{{ __('common.fields.created_by') }}</th><th>{{ __('common.fields.created_at') }}</th><th>{{ __('common.fields.updated_by') }}</th><th>{{ __('common.fields.updated_at') }}</th><th class="data-table-row-action"></th>
                    </tr></thead>
                </table>
            </div></div></div></div>
        </div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush

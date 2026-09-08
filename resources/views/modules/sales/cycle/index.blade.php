@extends('layouts.app')
@php
    $titles = ['sales_requests' => __('Sales Requests'), 'sales_orders' => __('Sales Orders'), 'customer_invoices' => __('Sales Invoices and Credit Notes'), 'customer_receipts' => __('Customer Receipts'), 'sales_returns' => __('Sales Returns'), 'sales_deliveries' => __('Delivery Notes')];
    $prefix = \Modules\Sales\DataTables\SalesCycleDataTable::routePrefix($kind);
    $canCreate = in_array($kind, ['sales_requests', 'sales_orders', 'customer_receipts', 'customer_invoices', 'sales_returns', 'sales_deliveries']);
    $states = match($kind) {
        'sales_requests' => ['draft','submitted','approved','rejected','partially_converted','converted','closed','cancelled'],
        'sales_orders' => ['draft','pending_approval','held_credit','approved','partially_fulfilled','fulfilled','rejected','reopened','closed','cancelled'],
        'sales_returns' => ['pending_authorization','authorized','received','inspected','closed','cancelled'],
        'customer_receipts' => ['approved','cancelled'],
        default => ['draft','posted','cancelled'],
    };
@endphp
@section('title', $titles[$kind])
@section('content')
<div class="card mb-3">
    <div class="card-header py-2"><button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between" type="button" data-bs-toggle="collapse" data-bs-target="#sales-index-filters" aria-expanded="false"><span class="fw-semibold">{{ __('Filters') }}</span><span class="fas fa-chevron-down fs-11"></span></button></div>
    <div class="collapse" id="sales-index-filters"><div class="card-body"><form id="sales-index-filter-form" class="row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label" for="sales-filter-document">{{ __('Document number') }}</label><input class="form-control" id="sales-filter-document" name="document"></div>
        <div class="col-md-4"><label class="form-label" for="sales-filter-status">{{ __('Status') }}</label><select class="form-select" id="sales-filter-status" name="status"><option value="">{{ __('All statuses') }}</option>@foreach($states as $status)<option value="{{ $status }}">{{ __(str($status)->replace('_', ' ')->title()->toString()) }}</option>@endforeach</select></div>
        <div class="col-md-4"><button class="btn btn-falcon-primary" type="submit">{{ __('Apply') }}</button> <button class="btn btn-falcon-default" type="reset">{{ __('Reset') }}</button></div>
    </form></div></div>
</div>
<div class="alert alert-danger d-none" id="sales-index-error" role="alert"></div>
<div class="card erp-datatable-card">
    <x-admin.crud-index-toolbar :title="$titles[$kind]" :add-route="$canCreate ? route($prefix.'.create') : null" :add-permission="$kind.'.create'" :show-trash-filter="in_array($kind, ['sales_requests', 'sales_orders']) && auth()->user()?->can($kind.'.view_trashed')" />
    <div class="card-body p-0"><div class="falcon-data-table"><div class="erp-datatable-wrapper"><div class="erp-datatable-scroll">
        <table id="sales-cycle-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle" data-url="{{ route($prefix.'.index') }}">
            <thead class="bg-100 text-900"><tr><th class="all no-colvis">{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Status') }}</th><th>{{ __('Amount') }}</th><th class="all no-colvis dt-actions">{{ __('common.fields.actions') }}</th></tr></thead>
        </table>
    </div></div></div></div>
</div>
@endsection
@push('scripts')
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script>window.salesIndexMessages = {{ Illuminate\Support\Js::from(['confirm' => __('common.actions.apply'), 'cancel' => __('common.actions.cancel'), 'reason' => __('Reason'), 'error' => __('The action could not be completed.'), 'saved' => __('Saved successfully.')]) }}; window.dataTableTranslations = @json(__('datatables'));</script>
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Sales/sales-index.js') }}"></script>
@endpush

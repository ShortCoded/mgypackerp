@extends('layouts.app')
@php
    $prefix = 'admin.purchases.'.$definition['route'];
    $permission = 'purchases.'.$definition['permission'];
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $columns = ['doc_num' => __('Document Number'), 'date' => __('Date'), 'party' => $screen === 'purchase_requisitions' ? __('procurement.ui.requester_employee') : __('Supplier'), 'source' => $screen === 'purchase_requisitions' ? __('procurement.ui.request_origin') : __('Source document')]
        + ($screen === 'goods_receipt_inspections' ? ['location' => __('procurement.ui.receiving_location')] : [])
        + ['lines_count' => __('Lines'), 'status' => __('Status'), 'created_at' => __('Created at'), 'updated_at' => __('Updated at')];
@endphp
@section('title', __($definition['title']))
@section('content')
<div class="card mb-3">
    <div class="card-header py-2"><button class="btn btn-link p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#procurement-filters" aria-expanded="false"><span class="fas fa-filter me-2"></span>{{ __('Filters') }}</button></div>
    <div class="collapse" id="procurement-filters"><form class="card-body row g-3" data-procurement-filters>
        <div class="col-md-3"><label class="form-label">{{ __('Status') }}</label><select class="form-select" name="status"><option value="">{{ __('All') }}</option>@foreach($statuses as $status)<option value="{{ $status }}">{{ __('procurement.ui.statuses.'.$status) }}</option>@endforeach</select></div>
        @foreach(['date_from' => __('From date'), 'date_to' => __('To date')] as $field => $label)<div class="col-md-3"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-control js-date-picker" name="{{ $field }}" id="{{ $field }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr"></div>@endforeach
        @if(in_array($screen, ['supplier_quotations', 'goods_receipt_inspections', 'goods_receipts', 'purchase_returns']))<div class="col-md-3"><label class="form-label">{{ __('Supplier') }}</label><select class="form-select js-select2-ajax" name="supplier_doc_num" data-url="{{ route('admin.purchases.select2.suppliers') }}" data-allow-clear="true" data-placeholder="{{ __('Select') }}"></select></div>@endif
        <div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary btn-sm">{{ __('common.actions.apply') }}</button><button type="reset" class="btn btn-falcon-default btn-sm">{{ __('Reset') }}</button></div>
    </form></div>
</div>
<div class="card erp-datatable-card" data-procurement-index>
    <x-admin.crud-index-toolbar :title="__($definition['title'])" :add-route="$createUrl" :add-permission="$permission.'.create'" :show-trash-filter="auth()->user()?->can($permission.'.view_trashed')">
        @if($screen === 'purchase_requisitions' && $isAdministrativeBranch) @can('purchase_orders.create') @can('purchases.prices.view')<button class="btn btn-falcon-primary btn-sm d-none" type="button" data-bulk-create-order="{{ route('admin.purchases.purchase-orders.create') }}">{{ __('procurement.ui.order_selected') }} <span data-selected-count></span></button>@endcan @endcan @endif
    </x-admin.crud-index-toolbar>
    <div class="card-body p-0"><div class="falcon-data-table"><div class="erp-datatable-wrapper"><div class="erp-datatable-scroll">
        <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle" id="procurement-documents-table" data-url="{{ route('admin.purchases.procurement.data', $screen) }}" data-table-name="{{ $screen }}" data-field-keys="{{ json_encode(array_keys($columns)) }}">
        <thead class="bg-100 text-900"><tr><th class="all no-colvis dt-select" data-orderable="false">@if($screen === 'purchase_requisitions' && $isAdministrativeBranch)<input type="checkbox" class="form-check-input" data-procurement-select-all aria-label="{{ __('Select all') }}">@endif</th>@foreach($columns as $field => $label)<th class="{{ $field === 'doc_num' ? 'all dt-code' : 'dt-text' }}">{{ $label }}</th>@endforeach<th class="all no-colvis dt-actions"></th></tr></thead>
        </table>
    </div></div></div></div>
</div>
@endsection
@push('scripts')
<script>window.dataTableTranslations = @json(__('datatables')); window.procurementIndexMessages = {{ \Illuminate\Support\Js::from(['confirm' => __('Are you sure?'), 'reason' => __('Reason'), 'apply' => __('common.actions.apply'), 'cancel' => __('Cancel'), 'error' => __('Unable to complete the action.')]) }};</script>
<script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Purchases/procurement-index.js') }}"></script>
@endpush

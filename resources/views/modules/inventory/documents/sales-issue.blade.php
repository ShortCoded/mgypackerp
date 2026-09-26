@extends('layouts.app')

@section('title', __('sales_issue.warehouse_issue'))

@section('content')
<div class="card">
    <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0">{{ __('sales_issue.warehouse_issue') }}</h5>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.documents.index') }}">{{ __('Back') }}</a>
    </div>
    <div class="card-body">
        @if($errors->any())
            <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" action="{{ route('admin.inventory.documents.sales-issue.store') }}" data-sales-issue-form>
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <x-forms.label for="sales-issue-store" :label="__('sales_issue.store')" required />
                    <x-forms.select id="sales-issue-store" name="branch_store_uuid" variant="ajax" :url="route('admin.inventory.documents.select2.sales-issue-stores')" :placeholder="__('inventory.movements.placeholders.select_store')" required data-sales-issue-store>
                        @if($selectedOrder?->branchStore)
                            <option value="{{ $selectedOrder->branchStore->public_uuid }}" selected>{{ $selectedOrder->branchStore->name }}</option>
                        @endif
                    </x-forms.select>
                    <small class="text-600">{{ __('sales_issue.warehouse_branch_help') }}</small>
                </div>
                <div class="col-lg-5">
                    <x-forms.label for="sales-issue-order" :label="__('sales_issue.issue_order')" required />
                    <x-forms.select id="sales-issue-order" name="sales_issue_order_doc_num" variant="ajax" :url="route('admin.inventory.documents.select2.sales-issue-orders')" :data-extra-params="json_encode(['branch_store_uuid' => '#sales-issue-store'])" data-depends-on="#sales-issue-store" data-disable-when-dependency-empty="true" :placeholder="__('sales_issue.select_order')" required data-sales-issue-order data-details-url="{{ route('admin.inventory.documents.sales-issue-orders.details', ['salesIssueOrder' => '__ORDER__']) }}">
                        @if($selectedOrder)
                            <option value="{{ $selectedOrder->doc_num }}" selected>{{ $selectedOrder->doc_num }} — {{ $selectedOrder->invoice?->doc_num }}</option>
                        @endif
                    </x-forms.select>
                </div>
                <div class="col-lg-3">
                    <x-forms.label for="sales-issue-date" :label="__('sales_issue.date')" required />
                    <x-forms.date-input id="sales-issue-date" name="document_date" :value="old('document_date', now()->toDateString())" required />
                </div>
            </div>
            <div class="mt-3" data-sales-issue-preview aria-live="polite"></div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <button class="btn btn-primary btn-sm" type="submit" data-sales-issue-submit disabled>{{ __('sales_issue.issue_now') }}</button>
                <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.documents.create') }}">{{ __('sales_issue.manual_movement') }}</a>
            </div>
        </form>
    </div>
</div>
<script type="application/json" data-sales-issue-labels>{!! \Illuminate\Support\Js::encode([
    'product' => __('inventory.movements.fields.product'),
    'required' => __('sales_issue.required_quantity'),
    'available' => __('sales_issue.available_quantity'),
    'status' => __('sales_issue.status'),
    'enough' => __('sales_issue.enough'),
    'shortage' => __('sales_issue.shortage'),
    'load_failed' => __('sales_issue.load_failed'),
]) !!}</script>
@endsection

@push('scripts')
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Inventory/sales-issue.js') }}"></script>
@endpush

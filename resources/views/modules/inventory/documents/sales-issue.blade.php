@extends('layouts.app')

@section('title', __('sales_issue.warehouse_issue'))

@section('content')
<div class="card">
    <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0">{{ __('sales_issue.warehouse_issue') }}</h5>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.documents.index') }}">{{ __('common.back') }}</a>
    </div>
    <div class="card-body">
        @if($errors->any())
            <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" action="{{ route('admin.inventory.documents.sales-issue.store') }}" data-sales-issue-form novalidate>
            @csrf
            <x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
            <div class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <x-forms.label for="sales-issue-store" :label="__('sales_issue.store')" required />
                    <x-forms.select id="sales-issue-store" name="branch_store_uuid" variant="ajax" :url="route('admin.inventory.documents.select2.sales-issue-stores')" :placeholder="__('inventory.movements.placeholders.select_store')" required data-sales-issue-store>
                        @if($selectedStore)
                            <option value="{{ $selectedStore->public_uuid }}" selected>{{ $selectedStore->name }}</option>
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
            <template data-sales-issue-layer-group>
                <div class="border rounded p-3 mt-3" data-material-layer-selections data-next-slice="1">
                    <h6 data-layer-product></h6>
                    <p class="text-muted small">{{ __('inventory_cost_policy.sales_selection_help') }}</p>
                    <x-forms.input type="hidden" name="layer_selections[__INDEX__][invoice_line_id]" value="__LINE_ID__" />
                    <div data-material-layer-rows><div class="row g-2 mb-2" data-material-layer-row>
                        <div class="col-md-7"><x-forms.select variant="ajax" name="layer_selections[__INDEX__][receipt_layers][0][layer_id]" :url="route('admin.inventory.documents.select2.receipt-layers')" :placeholder="__('inventory_cost_policy.selected_layer')" /></div>
                        <div class="col-md-4"><x-forms.numeric-input name="layer_selections[__INDEX__][receipt_layers][0][quantity]" :scale="8" /></div>
                        <div class="col-md-1"><button type="button" class="btn btn-link text-danger" data-remove-material-layer aria-label="{{ __('inventory_cost_policy.remove_selection') }}">×</button></div>
                    </div></div>
                    <template data-material-layer-template><div class="row g-2 mb-2" data-material-layer-row>
                        <div class="col-md-7"><x-forms.select variant="ajax" name="layer_selections[__INDEX__][receipt_layers][__SLICE__][layer_id]" :url="route('admin.inventory.documents.select2.receipt-layers')" :placeholder="__('inventory_cost_policy.selected_layer')" /></div>
                        <div class="col-md-4"><x-forms.numeric-input name="layer_selections[__INDEX__][receipt_layers][__SLICE__][quantity]" :scale="8" /></div>
                        <div class="col-md-1"><button type="button" class="btn btn-link text-danger" data-remove-material-layer aria-label="{{ __('inventory_cost_policy.remove_selection') }}">×</button></div>
                    </div></template>
                    <button type="button" class="btn btn-falcon-default btn-sm" data-add-material-layer>{{ __('inventory_cost_policy.add_selection') }}</button>
                </div>
            </template>
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
    'old_order' => $selectedOrder?->doc_num,
    'old_store' => $selectedStore?->public_uuid,
    'old_layer_selections' => $oldLayerSelections,
]) !!}</script>
@endsection

@push('scripts')
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/material-layer-selection.js') }}"></script>
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Inventory/sales-issue.js') }}"></script>
@endpush

@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $sourceRows = $sourceRequest?->lines
        ->filter(fn ($line) => bccomp($line->remainingQuantity(), '0', 8) > 0)
        ->map(fn ($line) => [
            'source_request_line_public_id' => $line->public_id,
            'product_doc_num' => $line->product?->doc_num,
            'unit_doc_num' => $line->unit?->doc_num,
            'quantity' => $line->remainingQuantity(),
            'unit_price' => $line->unit_price,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ])->values()->all() ?? [];
    $lineRows = old('lines', $sourceRows ?: [[]]);
    $productUnits = $products->mapWithKeys(fn ($product) => [$product->doc_num => collect([$product->unit, $product->equivalentUnit])
        ->filter()->unique('id')->map(fn ($unit) => ['id' => $unit->doc_num, 'text' => trim($unit->doc_num.' / '.$unit->name)])->values()]);
@endphp

@section('title', __('sales_ui.create_invoice'))

@section('content')
<form class="js-sales-cycle-form" data-sales-ui data-sales-document-summary data-index-url="{{ route('admin.sales.sales-invoices.index') }}" data-create-url="{{ route('admin.sales.sales-invoices.create', ['direct' => 1]) }}" action="{{ route('admin.sales.sales-invoices.store') }}" method="POST" novalidate>
    @csrf
    <x-forms.line-item-cards :line-label="__('sales_ui.line')" />
    <div class="alert alert-danger d-none js-sales-form-alert"></div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><h5 class="mb-0">{{ __('sales_ui.create_invoice') }}</h5>@if($sourceRequest)<small class="text-600">{{ __('sales_ui.source_sales_request') }}: {{ $sourceRequest->doc_num }}</small>@endif</div>
            @include('modules.finance.partials.form-actions', ['mode' => 'create', 'record' => null, 'resource' => 'customer_invoices', 'routePrefix' => 'admin.sales.sales-invoices', 'canClone' => false])
        </div>
        <div class="card-body">
            @if($sourceRequest)<input type="hidden" name="source_request_doc_num" value="{{ $sourceRequest->doc_num }}">@endif
            <div class="row g-3">
                <div class="col-md-6">
                    <x-forms.label for="invoice_customer_doc_num" :label="__('Customer')" required />
                    <select class="form-select js-select2-ajax" id="invoice_customer_doc_num" name="customer_doc_num" data-url="{{ route('admin.sales.select2.customers') }}" data-placeholder="{{ __('Select customer') }}" data-allow-clear="true" required>
                        @foreach($customers as $customer)<option value="{{ $customer->doc_num }}" selected>{{ $customer->doc_num }} / {{ $customer->name }}</option>@endforeach
                    </select>
                    <div class="invalid-feedback d-block" data-error-for="customer_doc_num"></div>
                </div>
                <div class="col-md-3">
                    <x-forms.label for="invoice_date" :label="__('Invoice date')" required />
                    <input class="form-control js-date-picker" id="invoice_date" name="invoice_date" value="{{ old('invoice_date', $dates->formatDate(now(), '')) }}" autocomplete="off" required>
                    <div class="invalid-feedback d-block" data-error-for="invoice_date"></div>
                </div>
                <div class="col-md-3">
                    <x-forms.label for="due_date" :label="__('Due date')" />
                    <input class="form-control js-date-picker" id="due_date" name="due_date" value="{{ old('due_date', $dates->formatDate(now(), '')) }}" autocomplete="off">
                    <div class="invalid-feedback d-block" data-error-for="due_date"></div>
                </div>
                <div class="col-md-4">
                    <x-forms.label for="invoice_currency_doc_num" :label="__('Currency')" required />
                    <select class="form-select js-select2-ajax" id="invoice_currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.select2.currencies') }}" data-placeholder="{{ __('Currency') }}" required>
                        @foreach($currencies as $currency)<option value="{{ $currency->doc_num }}" @selected(old('currency_doc_num', $sourceRequest?->currency?->doc_num ?? $currencies->firstWhere('is_main', true)?->doc_num) === $currency->doc_num)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach
                    </select>
                    <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                </div>
                <div class="col-md-2">
                    <x-forms.label for="invoice_exchange_rate" :label="__('Exchange rate')" required />
                    <x-forms.numeric-input class="text-end" id="invoice_exchange_rate" name="exchange_rate" :value="old('exchange_rate', $sourceRequest?->exchange_rate ?? 1)" :scale="6" min="0.000001" step="0.000001" required />
                </div>
                <div class="col-md-6">
                    <x-forms.label for="invoice_notes" :label="__('Notes')" />
                    <input class="form-control" id="invoice_notes" name="notes" value="{{ old('notes', $sourceRequest?->notes) }}">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">{{ __('Sales lines') }}</h6>
            <button class="btn btn-falcon-default btn-sm" type="button" data-sales-add-line data-shortcut-action="line.add"><span class="fas fa-plus me-1"></span>{{ __('Add line') }}</button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle sales-order-grid mb-0">
                <thead class="bg-100"><tr><th>#</th><th class="product-column">{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Tax') }}</th><th>{{ __('Line total') }}</th><th></th></tr></thead>
                <tbody data-sales-lines>@foreach($lineRows as $index => $line)@include('modules.sales.cycle.partials.sales-order-line', ['index' => $index, 'line' => $line, 'products' => $products, 'productUnits' => $productUnits, 'showRequestedDate' => false])@endforeach</tbody>
            </table>
        </div>
        <x-forms.document-summary />
    </div>
</form>

<template id="sales-order-line-template">@include('modules.sales.cycle.partials.sales-order-line', ['index' => '__INDEX__', 'line' => [], 'products' => $products, 'productUnits' => $productUnits, 'showRequestedDate' => false])</template>
@endsection

@push('scripts')
<script>window.salesProductUnits = @json($productUnits);</script>
@include('modules.sales.cycle.partials.scripts')
@endpush

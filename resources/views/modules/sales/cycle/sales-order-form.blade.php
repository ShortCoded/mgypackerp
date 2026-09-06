@extends('layouts.app')

@php
    $isEdit = $mode === 'edit';
    $lineRows = old('lines', $isEdit ? $record->lines->map(fn ($line) => [
        'product_doc_num' => $line->product?->doc_num,
        'unit_doc_num' => $line->unit?->doc_num,
        'description' => $line->description,
        'quantity' => $line->quantity,
        'unit_price' => $line->unit_price,
        'discount_amount' => $line->discount_amount,
        'tax_amount' => $line->tax_amount,
        'requested_date' => $line->requested_date?->toDateString(),
        'specifications' => $line->specifications,
        'warehouse_notes' => $line->warehouse_notes,
        'production_notes' => $line->production_notes,
    ])->all() : [[]]);
    $scheduleRows = old('payment_schedules', $isEdit ? $record->paymentSchedules->map(fn ($schedule) => [
        'title' => $schedule->title,
        'due_date' => $schedule->due_date?->toDateString(),
        'amount' => $schedule->amount,
    ])->all() : []);
    $productUnits = $products->mapWithKeys(fn ($product) => [$product->doc_num => collect([$product->unit, $product->equivalentUnit])
        ->filter()->unique('id')->map(fn ($unit) => ['id' => $unit->doc_num, 'text' => trim($unit->doc_num.' / '.$unit->name)])->values()]);
@endphp

@section('title', $isEdit ? __('Edit Sales Order') : __('Create Sales Order'))

@push('styles')
<style>
    .sales-order-grid { min-width: 1600px; }
    .sales-order-grid .product-column { min-width: 18rem; }
    .sales-order-grid .notes-column { min-width: 14rem; }
</style>
@endpush

@section('content')
@if($record?->sales_employee_id && !$record?->business_employee_id)<div class="alert alert-subtle-warning">{{ __('sales_ui.employee_unresolved') }}</div>@endif
<form class="js-sales-cycle-form" data-sales-ui data-index-url="{{ route('admin.sales.sales-orders.index') }}" data-create-url="{{ route('admin.sales.sales-orders.create') }}" data-edit-url="{{ route('admin.sales.sales-orders.edit', '__DOCUMENT__') }}" action="{{ $action }}" method="POST" novalidate>
    @csrf
        <x-forms.line-item-cards :line-label="__('sales_ui.line')" />
    @if($method !== 'POST') @method($method) @endif
    <div class="alert alert-danger d-none js-sales-form-alert"></div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">{{ $isEdit ? __('Edit Sales Order') : __('Create Sales Order') }}</h5>
                @if($isEdit)<small class="text-600">{{ $record->doc_num }} · {{ __(str($record->status)->replace('_', ' ')->title()->toString()) }}</small>@endif
            </div>
@include('modules.finance.partials.form-actions', ['mode' => $record ? 'edit' : 'create', 'record' => $record, 'resource' => 'sales_orders', 'routePrefix' => 'admin.sales.sales-orders', 'canClone' => false])
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="customer_doc_num">{{ __('Customer') }}</label>
                    <select class="form-select js-select2-ajax" id="customer_doc_num" name="customer_doc_num" required data-url="{{ route('admin.sales.select2.customers') }}" data-allow-clear="true">
                        <option value="">{{ __('Select customer') }}</option>
                        @foreach($customers as $customer)<option value="{{ $customer->doc_num }}" @selected(old('customer_doc_num', $record?->customer?->doc_num) === $customer->doc_num)>{{ $customer->doc_num }} / {{ $customer->name }}</option>@endforeach
                    </select>
                    <div class="invalid-feedback d-block" data-error-for="customer_doc_num"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="order_date">{{ __('Order date') }}</label>
                    <input class="form-control js-date-picker" id="order_date" name="order_date" value="{{ old('order_date', app(\Modules\Core\Services\DateFormatService::class)->formatDate($record?->order_date?->toDateString() ?? now()->toDateString())) }}" autocomplete="off" required>
                    <div class="invalid-feedback d-block" data-error-for="order_date"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="expected_delivery_date">{{ __('Required date') }}</label>
                    <input class="form-control js-date-picker" id="expected_delivery_date" name="expected_delivery_date" value="{{ old('expected_delivery_date', app(\Modules\Core\Services\DateFormatService::class)->formatDate($record?->expected_delivery_date?->toDateString() ?? now()->addWeek()->toDateString())) }}" autocomplete="off" required>
                    <div class="invalid-feedback d-block" data-error-for="expected_delivery_date"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="currency_doc_num">{{ __('Currency') }}</label>
                    <select class="form-select js-select2" id="currency_doc_num" name="currency_doc_num" required>
                        @foreach($currencies as $currency)<option value="{{ $currency->doc_num }}" @selected(old('currency_doc_num', $record?->currency?->doc_num) === $currency->doc_num || (! $isEdit && $currency->is_main))>{{ $currency->doc_num }} / {{ $currency->code }}</option>@endforeach
                    </select>
                    <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="branch_store_uuid">{{ __('Finished-goods store') }}</label>
                    <select class="form-select js-select2-ajax" id="branch_store_uuid" name="branch_store_uuid" data-url="{{ route('admin.sales.select2.stores') }}" data-allow-clear="true">
                        <option value="">{{ __('Select store') }}</option>
                        @foreach($stores as $store)<option value="{{ $store->public_uuid }}" @selected(old('branch_store_uuid', $record?->branchStore?->public_uuid) === $store->public_uuid)>{{ $store->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="customer_reference">{{ __('Customer reference / PO') }}</label>
                    <input class="form-control" id="customer_reference" name="customer_reference" value="{{ old('customer_reference', $record?->customer_reference) }}" maxlength="160">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="sales_employee_doc_num">{{ __('Sales representative') }}</label>
                    <select class="form-select js-select2-ajax" id="sales_employee_doc_num" name="sales_employee_doc_num" data-url="{{ route('admin.sales.select2.employees') }}" data-allow-clear="true"><option value="">{{ __('Unassigned') }}</option>@foreach($salesEmployees as $employee)<option value="{{ $employee->doc_num }}" @selected(old('sales_employee_doc_num', $record?->salesEmployee?->doc_num) === $employee->doc_num)>{{ $employee->doc_num }} / {{ $employee->name }}</option>@endforeach</select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="notes">{{ __('Customer-facing notes') }}</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2">{{ old('notes', $record?->notes) }}</textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="internal_notes">{{ __('Internal notes') }}</label>
                    <textarea class="form-control" id="internal_notes" name="internal_notes" rows="2">{{ old('internal_notes', $record?->internal_notes) }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">{{ __('Sales lines') }}</h6>
            <button class="btn btn-falcon-default btn-sm" type="button" data-sales-add-line><span class="fas fa-plus me-1"></span>{{ __('Add line') }}</button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle sales-order-grid mb-0">
                <thead class="bg-100"><tr><th>#</th><th class="product-column">{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Tax') }}</th><th>{{ __('Line total') }}</th><th>{{ __('Required date') }}</th><th>{{ __('Packaging') }}</th><th>{{ __('Customer specification') }}</th><th class="notes-column">{{ __('Warehouse / production notes') }}</th><th></th></tr></thead>
                <tbody data-sales-lines>
                    @foreach($lineRows as $index => $line)
                        @include('modules.sales.cycle.partials.sales-order-line', ['index' => $index, 'line' => $line, 'products' => $products, 'productUnits' => $productUnits])
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center"><h6 class="mb-0">{{ __('Proposed payment schedule') }}</h6><button class="btn btn-falcon-default btn-sm" type="button" data-sales-add-schedule>{{ __('Add installment') }}</button></div>
        <div class="table-responsive"><table class="table table-sm table-bordered mb-0" style="min-width:700px"><thead><tr><th>#</th><th>{{ __('Title') }}</th><th>{{ __('Due date') }}</th><th>{{ __('Amount') }}</th><th></th></tr></thead><tbody data-sales-schedules>
            @foreach($scheduleRows as $index => $schedule)
                <tr><td data-row-number>{{ $index + 1 }}</td><td><input class="form-control form-control-sm" name="payment_schedules[{{ $index }}][title]" value="{{ $schedule['title'] ?? '' }}"></td><td><input class="form-control form-control-sm js-date-picker" name="payment_schedules[{{ $index }}][due_date]" value="{{ app(\Modules\Core\Services\DateFormatService::class)->formatDate($schedule['due_date'] ?? null, '') }}"></td><td><input class="form-control form-control-sm text-end" name="payment_schedules[{{ $index }}][amount]" value="{{ $schedule['amount'] ?? '' }}" inputmode="decimal"></td><td><button class="btn btn-link text-danger p-1" type="button" data-sales-remove-row>&times;</button></td></tr>
            @endforeach
        </tbody></table></div>
    </div>

</form>

<template id="sales-order-line-template">
    @include('modules.sales.cycle.partials.sales-order-line', ['index' => '__INDEX__', 'line' => [], 'products' => $products, 'productUnits' => $productUnits])
</template>
<template id="sales-schedule-template"><tr><td data-row-number></td><td><input class="form-control form-control-sm" name="payment_schedules[__INDEX__][title]"></td><td><input class="form-control form-control-sm js-date-picker" name="payment_schedules[__INDEX__][due_date]"></td><td><input class="form-control form-control-sm text-end" name="payment_schedules[__INDEX__][amount]" inputmode="decimal"></td><td><button class="btn btn-link text-danger p-1" type="button" data-sales-remove-row>&times;</button></td></tr></template>
@endsection

@push('scripts')
<script>window.salesProductUnits = @json($productUnits);</script>
@include('modules.sales.cycle.partials.scripts')
@endpush

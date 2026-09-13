@extends('layouts.app')

@php
    $isEdit = $mode === 'edit';
    $lockedQuotationOrder = $isEdit && (bool) $record->quotation_id;
    $sourceLineRows = $sourceRequest?->lines
        ->filter(fn ($line) => bccomp($line->remainingQuantity(), '0', 8) > 0)
        ->map(fn ($line) => [
            'source_request_line_public_id' => $line->public_id,
            'product_doc_num' => $line->product?->doc_num,
            'unit_doc_num' => $line->unit?->doc_num,
            'description' => $line->description,
            'quantity' => $line->remainingQuantity(),
            'unit_price' => $line->unit_price,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'requested_date' => $sourceRequest->required_delivery_date?->toDateString(),
            'specifications' => $line->specifications,
        ])->values()->all() ?? [];
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
        'price_locked' => true,
        'locked_source_line' => (bool) $record->quotation_id,
    ])->all() : ($sourceLineRows ?: [[]]));
    $scheduleRows = old('payment_schedules', $isEdit ? $record->paymentSchedules->map(fn ($schedule) => [
        'title' => $schedule->title,
        'due_date' => $schedule->due_date?->toDateString(),
        'amount' => $schedule->amount,
    ])->all() : []);
    $productUnits = $products->mapWithKeys(fn ($product) => [$product->doc_num => collect([$product->unit, $product->equivalentUnit])
        ->filter()->unique('id')->map(fn ($unit) => ['id' => $unit->doc_num, 'text' => trim($unit->doc_num.' / '.$unit->name)])->values()]);
@endphp

@section('title', $isEdit ? __('Edit Sales Order') : __('Create Sales Order'))

@section('content')
@if($record?->sales_employee_id && !$record?->business_employee_id)<div class="alert alert-subtle-warning">{{ __('sales_ui.employee_unresolved') }}</div>@endif
<form class="js-sales-cycle-form" data-sales-ui data-sales-document-summary data-index-url="{{ route('admin.sales.sales-orders.index') }}" data-create-url="{{ route('admin.sales.sales-orders.create') }}" data-edit-url="{{ route('admin.sales.sales-orders.edit', '__DOCUMENT__') }}" action="{{ $action }}" method="POST" novalidate>
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
                @unless($isEdit)
                    @can('sales_requests.view')
                    <div class="col-12">
                        <x-forms.label for="source_request_doc_num" :label="__('sales_ui.source_sales_request')" />
                        <x-forms.select class="form-select js-select2-ajax" id="source_request_doc_num" name="source_request_doc_num" data-sales-order-source data-create-url="{{ route('admin.sales.sales-orders.create') }}" data-url="{{ route('admin.sales.select2.convertible-requests') }}" data-placeholder="{{ __('sales_ui.direct_sales_order') }}" data-allow-clear="true">
                            <option value="">{{ __('sales_ui.direct_sales_order') }}</option>
                            @if($sourceRequest)<option value="{{ $sourceRequest->doc_num }}" selected>{{ $sourceRequest->doc_num }} / {{ $sourceRequest->customer?->name }}</option>@endif
                        </x-forms.select>
                        <small class="text-muted">{{ __('sales_ui.source_sales_request_help') }}</small>
                    </div>
                    @endcan
                @endunless
                <div class="col-md-4">
                    <label class="form-label" for="customer_doc_num">{{ __('Customer') }}</label>
                    @if($isEdit)<x-forms.input type="hidden" name="customer_doc_num" value="{{ $record->customer?->doc_num }}" />@endif
                    <x-forms.select class="form-select js-select2-ajax" id="customer_doc_num" name="customer_doc_num" required data-url="{{ route('admin.sales.select2.customers') }}" data-allow-clear="true" :disabled='$isEdit'>
                        <option value="">{{ __('Select customer') }}</option>
                        @foreach($customers as $customer)<option value="{{ $customer->doc_num }}" @selected(old('customer_doc_num', $record?->customer?->doc_num ?? $sourceRequest?->customer?->doc_num) === $customer->doc_num)>{{ $customer->doc_num }} / {{ $customer->name }}</option>@endforeach
                    </x-forms.select>
                    <div class="invalid-feedback d-block" data-error-for="customer_doc_num"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="order_date">{{ __('Order date') }}</label>
                    <x-forms.date-input class="form-control js-date-picker" id="order_date" name="order_date" value="{{ old('order_date', app(\Modules\Core\Services\DateFormatService::class)->formatDate($record?->order_date?->toDateString() ?? now()->toDateString())) }}" autocomplete="off" required />
                    <div class="invalid-feedback d-block" data-error-for="order_date"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="expected_delivery_date">{{ __('Required date') }}</label>
                    <x-forms.date-input class="form-control js-date-picker" id="expected_delivery_date" name="expected_delivery_date" value="{{ old('expected_delivery_date', app(\Modules\Core\Services\DateFormatService::class)->formatDate($record?->expected_delivery_date?->toDateString() ?? $sourceRequest?->required_delivery_date?->toDateString() ?? now()->addWeek()->toDateString())) }}" autocomplete="off" required />
                    <div class="invalid-feedback d-block" data-error-for="expected_delivery_date"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="currency_doc_num">{{ __('Currency') }}</label>
                    @if($isEdit)<x-forms.input type="hidden" name="currency_doc_num" value="{{ $record->currency?->doc_num }}" />@endif
                    <x-forms.select class="form-select js-select2-ajax" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.select2.currencies') }}" data-placeholder="{{ __('Currency') }}" required :disabled='$isEdit'>
                        @foreach($currencies as $currency)<option value="{{ $currency->doc_num }}" @selected(old('currency_doc_num', $record?->currency?->doc_num ?? $sourceRequest?->currency?->doc_num ?? $currencies->firstWhere('is_main', true)?->doc_num) === $currency->doc_num)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach
                    </x-forms.select>
                    <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="sales_employee_doc_num">{{ __('Sales representative') }}</label>
                    <x-forms.select class="form-select js-select2-ajax" id="sales_employee_doc_num" name="sales_employee_doc_num" data-url="{{ route('admin.sales.select2.employees') }}" data-allow-clear="true"><option value="">{{ __('Unassigned') }}</option>@foreach($salesEmployees as $employee)<option value="{{ $employee->doc_num }}" @selected(old('sales_employee_doc_num', $record?->salesEmployee?->doc_num ?? $sourceRequest?->salesEmployee?->doc_num) === $employee->doc_num)>{{ $employee->doc_num }} / {{ $employee->full_name ?: $employee->name }}</option>@endforeach</x-forms.select>
                    <small class="text-muted">{{ __('sales_ui.employee_hint') }} @can('hr.employees.create')<a href="{{ route('admin.hr.employees.create') }}">{{ __('sales_ui.add_employee') }}</a>@endcan</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="notes">{{ __('Customer-facing notes') }}</label>
                    <x-forms.textarea class="form-control" id="notes" name="notes" rows="2">{{ old('notes', $record?->notes ?? $sourceRequest?->notes) }}</x-forms.textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="internal_notes">{{ __('Internal notes') }}</label>
                    <x-forms.textarea class="form-control" id="internal_notes" name="internal_notes" rows="2">{{ old('internal_notes', $record?->internal_notes) }}</x-forms.textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">{{ __('Sales lines') }}</h6>
            <button class="btn btn-falcon-default btn-sm" type="button" data-sales-add-line data-shortcut-action="line.add" title="{{ __('common.shortcuts.add_line') }}" data-bs-title="{{ __('common.shortcuts.add_line') }}"><span class="fas fa-plus me-1"></span>{{ __('Add line') }}</button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle sales-order-grid mb-0">
                <thead class="bg-100"><tr><th>#</th><th class="product-column">{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Tax') }}</th><th>{{ __('Line total') }}</th><th></th></tr></thead>
                <tbody data-sales-lines>
                    @foreach($lineRows as $index => $line)
                        @include('modules.sales.cycle.partials.sales-order-line', ['index' => $index, 'line' => $line, 'products' => $products, 'productUnits' => $productUnits, 'showRequestedDate' => false])
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-forms.document-summary />
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center"><h6 class="mb-0">{{ __('Proposed payment schedule') }}</h6>@unless($lockedQuotationOrder)<button class="btn btn-falcon-default btn-sm" type="button" data-sales-add-schedule>{{ __('Add installment') }}</button>@endunless</div>
        <div class="table-responsive"><table class="table table-sm table-bordered mb-0" style="min-width:700px"><thead><tr><th>#</th><th>{{ __('Title') }}</th><th>{{ __('Due date') }}</th><th>{{ __('Amount') }}</th><th></th></tr></thead><tbody data-sales-schedules>
            @foreach($scheduleRows as $index => $schedule)
                <tr><td data-row-number>{{ $index + 1 }}</td><td><x-forms.input class="form-control form-control-sm" name="payment_schedules[{{ $index }}][title]" value="{{ $schedule['title'] ?? '' }}" :readonly='$lockedQuotationOrder' /></td><td><x-forms.date-input class="form-control form-control-sm js-date-picker" name="payment_schedules[{{ $index }}][due_date]" value="{{ app(\Modules\Core\Services\DateFormatService::class)->formatDate($schedule['due_date'] ?? null, '') }}" :readonly='$lockedQuotationOrder' /></td><td><x-forms.input class="form-control form-control-sm text-end" name="payment_schedules[{{ $index }}][amount]" value="{{ $schedule['amount'] ?? '' }}" inputmode="decimal" :readonly='$lockedQuotationOrder' /></td><td>@unless($lockedQuotationOrder)<button class="btn btn-link text-danger p-1" type="button" data-sales-remove-row>&times;</button>@endunless</td></tr>
            @endforeach
        </tbody></table></div>
    </div>

</form>

<template id="sales-order-line-template">
    @include('modules.sales.cycle.partials.sales-order-line', ['index' => '__INDEX__', 'line' => [], 'products' => $products, 'productUnits' => $productUnits, 'showRequestedDate' => false])
</template>
<template id="sales-schedule-template"><tr><td data-row-number></td><td><x-forms.input class="form-control form-control-sm" name="payment_schedules[__INDEX__][title]" /></td><td><x-forms.date-input class="form-control form-control-sm js-date-picker" name="payment_schedules[__INDEX__][due_date]" /></td><td><x-forms.input class="form-control form-control-sm text-end" name="payment_schedules[__INDEX__][amount]" inputmode="decimal" /></td><td><button class="btn btn-link text-danger p-1" type="button" data-sales-remove-row>&times;</button></td></tr></template>
@endsection

@push('scripts')
<script>window.salesProductUnits = @json($productUnits);</script>
@include('modules.sales.cycle.partials.scripts')
@endpush

@extends('layouts.app')

@php
    $editing = $record && !$readOnly;
    $mode = $readOnly ? 'view' : ($editing ? 'edit' : 'create');
    $lines = old('lines', $record?->lines->map(fn ($line) => [
        'product_doc_num' => $line->product?->doc_num,
        'product_name' => $line->product?->name,
        'unit_price' => $line->unit_price,
        'allowed_discount_type' => $line->allowed_discount_type,
        'allowed_discount_value' => $line->allowed_discount_value,
    ])->all() ?? [[]]);
@endphp

@section('title', $record?->doc_num ?? __('price_lists.create'))

@section('content')
<form method="POST" action="{{ $editing ? route('admin.sales.price-lists.update', $record) : route('admin.sales.price-lists.store') }}" data-price-list-form data-mode="{{ $mode }}" novalidate>
    @csrf @if($editing)@method('PUT')@endif
    <x-forms.input type="hidden" name="submit_action" value="{{ $mode === 'create' ? 'save_new' : 'save' }}" />
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h4 class="mb-1">{{ $record?->doc_num ?? __('price_lists.create') }}</h4><p class="text-600 mb-0">{{ __('price_lists.form_help') }}</p></div>
    @include('modules.sales.price-lists.partials.form-actions')
</div>
    <fieldset @disabled($readOnly)>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-md-3"><label class="form-label">{{ __('price_lists.fields.code') }}</label><x-forms.input class="form-control" value="{{ $record?->doc_num ?? __('price_lists.automatic_code') }}" disabled /></div>
            <div class="col-md-3"><label class="form-label" for="price_list_date">{{ __('price_lists.fields.date') }}</label><x-forms.date-input class="form-control js-date-picker" id="price_list_date" name="price_list_date" value="{{ old('price_list_date', app(\Modules\Core\Services\DateFormatService::class)->formatDate($record?->price_list_date ?? now())) }}" required />@error('price_list_date')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-3"><label class="form-label" for="customer_doc_num">{{ __('price_lists.fields.customer') }}</label><x-forms.select class="form-select js-select2-ajax" id="customer_doc_num" name="customer_doc_num" data-url="{{ route('admin.sales.select2.customers') }}" data-allow-clear="true" data-placeholder="{{ __('price_lists.general') }}"><option value=""></option>@foreach($selectedCustomers as $customer)<option value="{{ $customer->doc_num }}" selected>{{ $customer->doc_num }} / {{ $customer->name }}</option>@endforeach</x-forms.select><div class="form-text">{{ __('price_lists.customer_help') }}</div>@error('customer_doc_num')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-3"><label class="form-label" for="currency_doc_num">{{ __('price_lists.fields.currency') }}</label><x-forms.select class="form-select js-select2-ajax" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.select2.currencies') }}" required>@foreach($currencies as $currency)<option value="{{ $currency->doc_num }}" @selected(old('currency_doc_num', $record?->currency?->doc_num ?? $currencies->first()?->doc_num) === $currency->doc_num)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</x-forms.select>@error('currency_doc_num')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-3"><label class="form-label" for="valid_from">{{ __('price_lists.fields.valid_from') }}</label><x-forms.date-input class="form-control js-date-picker" id="valid_from" name="valid_from" value="{{ old('valid_from', app(\Modules\Core\Services\DateFormatService::class)->formatDate($record?->valid_from ?? now())) }}" required />@error('valid_from')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-3"><label class="form-label" for="valid_until">{{ __('price_lists.fields.valid_until') }}</label><x-forms.date-input class="form-control js-date-picker" id="valid_until" name="valid_until" value="{{ old('valid_until', app(\Modules\Core\Services\DateFormatService::class)->formatDate($record?->valid_until, '')) }}" /><div class="form-text">{{ __('price_lists.valid_until_help') }}</div>@error('valid_until')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-6"><label class="form-label" for="notes">{{ __('price_lists.fields.notes') }}</label><x-forms.textarea class="form-control" id="notes" name="notes" rows="2">{{ old('notes', $record?->notes) }}</x-forms.textarea></div>
        </div></div></div>
        <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><div><h6 class="mb-0">{{ __('price_lists.fields.items') }}</h6><small class="text-600">{{ __('price_lists.base_unit_help') }}</small></div>@unless($readOnly)<button class="btn btn-falcon-primary btn-sm" type="button" data-price-list-add><span class="fas fa-plus me-1"></span>{{ __('price_lists.add_item') }}</button>@endunless</div>
            <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0"><thead class="bg-100"><tr><th>#</th><th style="min-width:20rem">{{ __('price_lists.fields.product') }}</th><th>{{ __('price_lists.fields.price') }}</th><th>{{ __('price_lists.fields.discount_type') }}</th><th>{{ __('price_lists.fields.discount_value') }}</th><th></th></tr></thead><tbody data-price-list-lines>
                @foreach($lines as $index => $line)@include('modules.sales.price-lists.line', ['index' => $index, 'line' => $line, 'readOnly' => $readOnly])@endforeach
            </tbody></table></div>
        </div>
    </fieldset>
    @unless($readOnly)@include('modules.sales.price-lists.partials.form-actions')@endunless
</form>
<template id="price-list-line-template">@include('modules.sales.price-lists.line', ['index' => '__INDEX__', 'line' => [], 'readOnly' => false])</template>
@endsection

@push('scripts')
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Sales/price-lists.js') }}"></script>
@endpush

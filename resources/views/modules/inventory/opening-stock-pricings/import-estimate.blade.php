@extends('layouts.app')

@php
    $title = __('inventory.opening_stock_pricings.actions.import_estimate');
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp

@section('title', $title)

@section('content')
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">{{ $title }}</h5>
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.opening-stock-pricings.index') }}">{{ __('common.actions.back') }}</a>
        </div>
        <div class="card-body">
            <div class="alert alert-info">{{ __('inventory.opening_stock_pricings.messages.estimate_pending') }}</div>
            <form action="{{ route('admin.inventory.opening-stock-pricings.import-estimate.store') }}" method="POST" enctype="multipart/form-data" novalidate>
                @csrf
                <div class="row g-3">
                    <div class="col-12">
                        <x-forms.label for="opening_stock_doc_num" :label="__('inventory.opening_stock_pricings.attributes.opening_stock')" required />
                        <x-forms.select class="form-select js-select2-ajax" id="opening_stock_doc_num" name="opening_stock_doc_num" data-url="{{ route('admin.inventory.select2.opening-stock-pricing-documents') }}" data-placeholder="{{ __('inventory.opening_stock_pricings.placeholders.select_opening_stock') }}"></x-forms.select>
                        @error('opening_stock_doc_num')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="document_date" :label="__('inventory.opening_stock_pricings.attributes.document_date')" required />
                        <x-forms.date-input class="form-control js-date-picker" id="document_date" name="document_date" type="text" value="{{ old('document_date', $dateValue) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" />
                        @error('document_date')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="currency_doc_num" :label="__('inventory.opening_stock_pricings.attributes.currency')" required />
                        <x-forms.select class="form-select js-select2-ajax" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.inventory.select2.opening-stock-pricing-currencies') }}" data-placeholder="{{ __('inventory.opening_stock_pricings.placeholders.select_currency') }}">
                            @if($mainCurrency)
                                <option value="{{ $mainCurrency->doc_num }}" selected>{{ $mainCurrency->name }} ({{ $mainCurrency->code }})</option>
                            @endif
                        </x-forms.select>
                        @error('currency_doc_num')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="exchange_rate" :label="__('inventory.opening_stock_pricings.attributes.exchange_rate')" required />
                        <x-forms.numeric-input id="exchange_rate" name="exchange_rate" :value="old('exchange_rate', '1')" :scale="6" min="0.000001" step="0.000001" />
                        @error('exchange_rate')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <x-forms.label for="estimate_basis_note" :label="__('inventory.opening_stock_pricings.attributes.estimate_basis_note')" required />
                        <x-forms.input class="form-control" id="estimate_basis_note" name="estimate_basis_note" type="text" value="{{ old('estimate_basis_note') }}" maxlength="2000" />
                        @error('estimate_basis_note')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <x-forms.label for="workbook" :label="__('inventory.opening_stock_pricings.attributes.source_file')" required />
                        <x-forms.input class="form-control" id="workbook" name="workbook" type="file" accept=".xlsx" />
                        @error('workbook')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button class="btn btn-falcon-default" type="button" id="download-estimate-template" data-url="{{ route('admin.inventory.opening-stock-pricings.import-estimate.template') }}">{{ __('inventory.opening_stock_pricings.actions.download_estimate_template') }}</button>
                    <button class="btn btn-primary" type="submit">{{ __('inventory.opening_stock_pricings.actions.import_estimate') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.getElementById('download-estimate-template')?.addEventListener('click', function () {
            const source = document.getElementById('opening_stock_doc_num')?.value;
            if (!source) {
                document.getElementById('opening_stock_doc_num')?.focus();
                return;
            }
            window.location.href = this.dataset.url + '?opening_stock_doc_num=' + encodeURIComponent(source);
        });
    </script>
@endpush

@php
    $discountReadonly = $discountReadonly ?? false;
    $discountNumbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp
<div class="card-body border-top" data-sales-document-discount><div class="row g-2 align-items-end">
    <div class="col-md-3"><x-forms.label for="order-discount-type" :label="__('sales_ui.document_discount')" />
        @if($discountReadonly)
            <div class="form-control-plaintext">{{ $record?->discount_type ? __('quotations.discount_types.'.$record->discount_type) : __('quotations.discount_types.none') }}</div>
        @else
            <x-forms.select id="order-discount-type" name="discount_type" class="form-select js-sales-header-discount-type"><option value="">{{ __('quotations.discount_types.none') }}</option>@foreach(['fixed', 'percentage'] as $type)<option value="{{ $type }}" @selected(old('discount_type', $record?->discount_type) === $type)>{{ __('quotations.discount_types.'.$type) }}</option>@endforeach</x-forms.select>
            <div class="invalid-feedback d-block" data-error-for="discount_type"></div>
        @endif
    </div>
    <div class="col-md-3"><x-forms.label for="order-discount-value" :label="__('quotations.attributes.discount_value')" />
        @if($discountReadonly)
            <div class="form-control-plaintext" dir="ltr">{{ $discountNumbers->format($record?->discount_value ?? 0) }}{{ $record?->discount_type === 'percentage' ? '%' : '' }}</div>
        @else
            <x-forms.numeric-input id="order-discount-value" name="discount_value" class="text-end js-sales-header-discount-value" :value="old('discount_value', $record?->discount_value ?? 0)" :scale="4" min="0" />
            <div class="invalid-feedback d-block" data-error-for="discount_value"></div>
        @endif
    </div>
    <div class="col-md-6"><small class="text-muted">{{ __('sales_ui.header_discount_help') }}</small><div>{{ __('sales_ui.document_discount') }}: <strong data-sales-header-discount-amount dir="ltr">{{ $discountNumbers->formatWithMinimumDecimals($record?->header_discount_amount ?? 0, 2) }}</strong></div></div>
</div></div>

@php
    $withholdingRecord = $record ?? null;
    $withholdingReadonly = $withholdingReadonly ?? false;
    $withholdingNumbers = app(\Modules\Core\Services\NumericFormatService::class);
    $withholdingBasis = $withholdingReadonly
        ? ($withholdingRecord?->withholding_basis ?? \Modules\Sales\Services\SalesWithholdingService::GrossIncludingTax)
        : old('withholding_basis', $withholdingRecord?->withholding_basis ?? ($withholdingRecord ? \Modules\Sales\Services\SalesWithholdingService::GrossIncludingTax : \Modules\Sales\Services\SalesWithholdingService::CommercialGrossPreview));
@endphp
<div class="card-body border-top" data-sales-withholding-preview><div class="row g-2 align-items-end">
    <div class="col-md-3">
        <x-forms.label for="sales-withholding-rate" :label="__('sales_ui.withholding_rate')" />
        @if($withholdingReadonly)
            <div class="form-control-plaintext" dir="ltr">{{ $withholdingNumbers->format($withholdingRecord?->withholding_rate ?? 0) }}%</div>
        @else
            <x-forms.numeric-input id="sales-withholding-rate" name="withholding_rate" class="text-end js-sales-withholding-rate" :value="old('withholding_rate', $withholdingRecord?->withholding_rate ?? 0)" :scale="4" min="0" max="100" />
            <div class="invalid-feedback d-block" data-error-for="withholding_rate"></div>
        @endif
    </div>
    <div class="col-md-5">
        <x-forms.label for="sales-withholding-basis" :label="__('sales_ui.wht.basis')" />
        @if($withholdingReadonly)
            <div class="form-control-plaintext">{{ __(\Modules\Sales\Services\SalesWithholdingService::labelKey($withholdingBasis)) }}</div>
        @else
            <x-forms.select class="form-select js-sales-withholding-basis" id="sales-withholding-basis" name="withholding_basis"><option value="commercial_gross_preview" @selected($withholdingBasis === 'commercial_gross_preview')>{{ __('sales_ui.wht.commercial') }}</option><option value="eta_t4_net_excluding_tax" @selected($withholdingBasis === 'eta_t4_net_excluding_tax')>{{ __('sales_ui.wht.eta') }}</option><option value="gross_including_tax" @selected($withholdingBasis === 'gross_including_tax')>{{ __('sales_ui.wht.legacy') }}</option></x-forms.select>
            <div class="invalid-feedback d-block" data-error-for="withholding_basis"></div>
        @endif
    </div>
    <div class="col-md-4"><div class="d-flex flex-wrap gap-3"><span>{{ __('sales_ui.withholding') }}: <strong data-sales-withholding-amount dir="ltr">{{ $withholdingNumbers->formatWithMinimumDecimals($withholdingRecord?->withholding_amount ?? 0, 2) }}</strong></span><span>{{ __('sales_ui.net_payable') }}: <strong data-sales-net-payable dir="ltr">{{ $withholdingNumbers->formatWithMinimumDecimals($withholdingRecord?->net_payable_amount ?? $withholdingRecord?->total_amount ?? $withholdingRecord?->total ?? 0, 2) }}</strong></span></div></div>
</div><p class="small text-muted mt-2 mb-0">{{ __('sales_ui.wht.policy') }}</p><div class="alert alert-warning mt-2 mb-0 d-none" data-sales-withholding-pending>{{ __('sales_ui.withholding_posting_pending') }}</div></div>

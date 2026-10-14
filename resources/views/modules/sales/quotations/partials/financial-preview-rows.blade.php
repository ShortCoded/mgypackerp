@if($revision->discount_type)
    <tr><th>{{ __('sales_ui.document_discount') }} ({{ __('quotations.discount_types.'.$revision->discount_type) }}: {{ $numbers->format($revision->discount_value) }}{{ $revision->discount_type === 'percentage' ? '%' : '' }})</th><td class="number text-end" dir="ltr">{{ $numbers->formatWithMinimumDecimals(bcsub((string) $revision->discount_amount, app(\Modules\Sales\Services\SalesAmountService::class)->sum($revision->lines->pluck('discount_amount')->all()), 4), 2) }}</td></tr>
@endif
@if(bccomp((string) ($revision->withholding_rate ?? 0), '0', 4) > 0)
    <tr><th>{{ __('sales_ui.withholding') }} ({{ $numbers->format($revision->withholding_rate) }}%) / {{ __(\Modules\Sales\Services\SalesWithholdingService::labelKey($revision->withholding_basis)) }}</th><td class="number text-end" dir="ltr">{{ $numbers->formatWithMinimumDecimals($revision->withholding_amount, 2) }}</td></tr>
    <tr><th>{{ __('sales_ui.net_payable') }}</th><td class="number text-end" dir="ltr">{{ $numbers->formatWithMinimumDecimals($revision->net_payable_amount ?? $revision->total, 2) }}</td></tr>
    <tr><td colspan="2">{{ __('sales_ui.wht.policy') }}</td></tr>
@endif

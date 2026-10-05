@php($discountNumbers = app(\Modules\Core\Services\NumericFormatService::class))
<span dir="ltr">{{ $discountNumbers->format($discountAmount ?? 0) }}</span>
@if(filled($discountType))<div class="small">{{ __('purchase_orders.discount_types.'.$discountType) }}: <span dir="ltr">{{ $discountNumbers->format($discountValue ?? 0) }}{{ $discountType === 'percentage' ? '%' : '' }}</span></div>@endif

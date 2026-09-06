@extends('layouts.app')
@section('title', __('Supplier Quotation Comparison'))
@section('content')
@php $showPrices = auth()->user()?->can('purchases.prices.view'); @endphp
<div class="card">
    <div class="card-header py-2 d-flex justify-content-between align-items-center"><div><h5 class="mb-1">{{ __('Quotation Comparison') }}</h5><span class="text-600" dir="ltr">{{ $record->doc_num }}</span></div><div class="d-flex gap-2">@can('purchases.supplier_quotation_comparison.print')<a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ route('admin.purchases.procurement.print', ['quotation-comparison', $record->doc_num]) }}">{{ __('Print') }}</a>@endcan @can('purchases.prices.view')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.supplier-selection.create', $record->doc_num) }}">{{ __('Record supplier decision') }}</a>@endcan</div></div>
    <div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm table-hover align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>{{ __('Item') }}</th><th>{{ __('Supplier') }}</th><th class="text-end">{{ __('Offered quantity') }}</th>@if($showPrices)<th>{{ __('Currency') }}</th><th class="text-end">{{ __('Unit price') }}</th><th class="text-end">{{ __('Discount') }}</th><th class="text-end">{{ __('Tax') }}</th><th class="text-end">{{ __('Freight') }}</th><th class="text-end">{{ __('Line total') }}</th><th class="text-end">{{ __('Landed base / unit') }}</th>@endif<th>{{ __('Delivery') }}</th><th>{{ __('Lead time') }}</th><th>{{ __('Payment terms') }}</th></tr></thead><tbody>
        @foreach($lines->groupBy('request_for_quotation_line_id') as $offers)
            @php
                $landedBasePerUnit = fn($line) => (
                    (float) $line->line_total
                    + (float) $line->quotation->freight_amount
                        * (float) $line->line_total
                        / max((float) $line->quotation->total_amount - (float) $line->quotation->freight_amount, 0.00000001)
                ) * (float) $line->quotation->exchange_rate / max((float) $line->offered_quantity, 0.00000001);
                $lowest = $showPrices ? $offers->min($landedBasePerUnit) : null;
            @endphp
            @foreach($offers as $line)
                @php $normalized = $landedBasePerUnit($line); @endphp
                <tr class="{{ $showPrices && abs($normalized - $lowest) < 0.0001 ? 'table-success' : '' }}"><td>{{ $line->rfqLine?->product?->name }}</td><td>{{ $line->quotation?->supplier?->name }}</td><td class="text-end" dir="ltr">{{ $line->offered_quantity }}</td>@if($showPrices)<td>{{ $line->quotation?->currency?->name ?: '—' }}</td><td class="text-end" dir="ltr">{{ $line->unit_price }}</td><td class="text-end" dir="ltr">{{ $line->discount_amount }}</td><td class="text-end" dir="ltr">{{ $line->tax_amount }}</td><td class="text-end" dir="ltr">{{ $line->quotation->freight_amount }}</td><td class="text-end fw-semibold" dir="ltr">{{ $line->line_total }}</td><td class="text-end" dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($normalized) }}</td>@endif<td>{{ $line->delivery_date?->format('Y-m-d') ?: '—' }}</td><td>{{ $line->quotation?->lead_time_days ?? '—' }}</td><td>{{ $line->quotation?->payment_terms ?: '—' }}</td></tr>
            @endforeach
        @endforeach
    </tbody></table></div></div>
</div>
@endsection

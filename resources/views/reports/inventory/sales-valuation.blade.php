@php
    $valuation = $valuation ?? [];
    $rows = $valuation['rows'] ?? collect();
    $totals = $valuation['totals'] ?? ['quantity' => '0', 'sales_value' => '0', 'unpriced_quantity' => '0'];
    $currencyCode = $valuation['priceListCurrencyCode'] ?? '';
    $numbers = $numbers ?? app(\Modules\Core\Services\NumericFormatService::class);
    $priceList = $valuation['priceList'] ?? null;
@endphp
<div class="report-filter-summary">
    @foreach($filterSummary as $label => $value)<strong>{{ $label }}:</strong> {{ $value }}@unless($loop->last) · @endunless @endforeach
</div>
@if($priceList)
<p><strong>{{ __('inventory_accounting.sales_valuation.price_list') }}:</strong> {{ $priceList->doc_num }} @if($currencyCode) ({{ $currencyCode }})@endif</p>
@endif
<table class="document-meta-table"><tr>
    <td><strong>{{ __('stock_balance_inquiry.columns.positions') }}</strong><br>{{ $totals['position_count'] ?? $rows->count() }}</td>
    <td><strong>{{ __('inventory_accounting.sales_valuation.quantity') }}</strong><br>{{ $numbers->format($totals['quantity']) }}</td>
    <td><strong>{{ __('inventory_accounting.sales_valuation.sales_value') }}</strong><br>{{ $numbers->format($totals['sales_value']) }} @if($currencyCode){{ $currencyCode }}@endif</td>
    <td><strong>{{ __('inventory_accounting.sales_valuation.unpriced_quantity') }}</strong><br>{{ $numbers->format($totals['unpriced_quantity']) }}</td>
    <td><strong>{{ __('inventory_accounting.sales_valuation.unpriced_product_count') }}</strong><br>{{ $totals['unpriced_product_count'] ?? 0 }}</td>
</tr></table>
<table class="report-table">
    <thead><tr><th>{{ __('stock_balance_inquiry.columns.branch') }}</th><th>{{ __('stock_balance_inquiry.columns.store') }}</th><th>{{ __('stock_balance_inquiry.columns.location') }}</th><th>{{ __('stock_balance_inquiry.columns.product') }}</th><th class="text-right">{{ __('stock_balance_inquiry.columns.on_hand') }}</th><th class="text-right">{{ __('inventory_accounting.sales_valuation.unit_selling_price') }}</th><th class="text-right">{{ __('inventory_accounting.sales_valuation.sales_value') }}</th><th>{{ __('inventory_accounting.sales_valuation.price_status') }}</th></tr></thead>
    <tbody>
        @forelse($rows as $row)
            <tr>
                <td>{{ $row->branch?->name }}</td>
                <td>{{ $row->branchStore?->name }}</td>
                <td>{{ collect([$row->branchHall?->name, $row->warehouseLocation?->code])->filter()->implode(' / ') ?: '—' }}</td>
                <td>{{ $row->product?->doc_num }} — {{ $row->product?->name }}</td>
                <td class="number">{{ $numbers->format($row->on_hand) }}</td>
                <td class="number">{{ $row->unit_selling_price !== null ? $numbers->format($row->unit_selling_price) : '—' }}</td>
                <td class="number">{{ $row->sales_value !== null ? $numbers->format($row->sales_value) : '—' }}</td>
                <td>{{ __('inventory_accounting.sales_valuation.price_statuses.'.$row->price_status) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted">{{ __('inventory_accounting.book_valuation.empty') }}</td></tr>
        @endforelse
    </tbody>
    <tfoot><tr class="total"><td colspan="4">{{ __('inventory_accounting.book_valuation.total') }}</td><td class="number">{{ $numbers->format($totals['quantity']) }}</td><td></td><td class="number">{{ $numbers->format($totals['sales_value']) }} @if($currencyCode){{ $currencyCode }}@endif</td><td></td></tr></tfoot>
</table>

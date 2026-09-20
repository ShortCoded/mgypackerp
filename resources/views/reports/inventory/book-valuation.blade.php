@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<div class="report-filter-summary">
    @foreach($filterSummary as $label => $value)<strong>{{ $label }}:</strong> {{ $value }}@unless($loop->last) · @endunless @endforeach
</div>

@if($totals['has_unvalued'])
    <p class="report-warning">{{ __('inventory_accounting.book_valuation.unvalued_warning', ['rows' => $totals['unvalued_rows'], 'quantity' => $numbers->format($totals['unvalued_quantity'])]) }}</p>
@endif

<table class="document-meta-table"><tr>
    <td><strong>{{ __('inventory_accounting.book_valuation.metrics.positions') }}</strong><br>{{ $totals['positions'] }}</td>
    <td><strong>{{ __('inventory_accounting.book_valuation.metrics.quantity') }}</strong><br>{{ $numbers->format($totals['quantity']) }}</td>
    <td><strong>{{ __('inventory_accounting.book_valuation.metrics.book_value') }}</strong><br>{{ $numbers->format($totals['book_value']) }} {{ $currencyCode }}</td>
    <td><strong>{{ __('inventory_accounting.book_valuation.metrics.unvalued_quantity') }}</strong><br>{{ $numbers->format($totals['unvalued_quantity']) }}</td>
</tr></table>

<table class="report-table">
    <thead><tr>@foreach(['branch','store','position','item','unit','quantity','book_unit_cost','book_value','unvalued_quantity','status'] as $column)<th>{{ __('inventory_accounting.book_valuation.columns.'.$column) }}</th>@endforeach</tr></thead>
    <tbody>
        @forelse($rows as $row)<tr>
            <td>{{ $row->branch?->name }}</td><td>{{ $row->branchStore?->name }}</td><td>{{ collect([$row->branchHall?->name, $row->warehouseLocation?->code])->filter()->implode(' / ') }}</td>
            <td>{{ $row->product?->doc_num }} — {{ $row->product?->name }}</td><td>{{ $row->product?->unit?->name }}</td>
            <td class="number">{{ $numbers->format($row->on_hand) }}</td><td class="number">{{ $row->book_unit_cost === null ? '—' : $numbers->format($row->book_unit_cost) }}</td><td class="number">{{ $numbers->format($row->book_value) }}</td><td class="number">{{ $numbers->format($row->unvalued_quantity) }}</td>
            <td>{{ __('inventory_accounting.book_valuation.statuses.'.$row->valuation_status) }}{{ $row->is_negative ? ' / '.__('inventory_accounting.book_valuation.negative') : '' }}</td>
        </tr>@empty<tr><td colspan="10">{{ __('inventory_accounting.book_valuation.empty') }}</td></tr>@endforelse
        <tr class="total"><td colspan="5">{{ __('inventory_accounting.book_valuation.total') }}</td><td class="number">{{ $numbers->format($totals['quantity']) }}</td><td></td><td class="number">{{ $numbers->format($totals['book_value']) }}</td><td class="number">{{ $numbers->format($totals['unvalued_quantity']) }}</td><td></td></tr>
    </tbody>
</table>

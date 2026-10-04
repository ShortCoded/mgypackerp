@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<div class="report-filter-summary">
    @foreach($filterSummary as $label => $value)<strong>{{ $label }}:</strong> {{ $value }}@unless($loop->last) · @endunless @endforeach
</div>

@if($totals['mixed_units'])
    <p class="report-warning">{{ __('inventory_accounting.book_valuation.mixed_units_warning') }}
        @foreach($totals['quantity_by_unit'] as $unitTotal){{ $unitTotal['unit_name'] }}: {{ $numbers->format($unitTotal['quantity']) }}@unless($loop->last) · @endunless @endforeach
    </p>
@endif
@if($totals['has_unvalued'])
    <p class="report-warning">{{ $totals['mixed_units'] ? __('inventory_accounting.book_valuation.unvalued_warning_mixed', ['rows' => $totals['unvalued_rows']]) : __('inventory_accounting.book_valuation.unvalued_warning', ['rows' => $totals['unvalued_rows'], 'quantity' => $numbers->format($totals['unvalued_quantity'])]) }}</p>
@endif
@if($totals['residual_value_positions'] > 0)
    <p class="report-warning">{{ __('inventory_accounting.book_valuation.residual_value_warning', ['count' => $totals['residual_value_positions']]) }}</p>
@endif

<table class="document-meta-table"><tr>
    <td><strong>{{ __('inventory_accounting.book_valuation.metrics.positions') }}</strong><br>{{ $totals['positions'] }}</td>
    <td><strong>{{ __('inventory_accounting.book_valuation.metrics.quantity') }}</strong><br>{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['quantity']) }}</td>
    <td><strong>{{ __('inventory_accounting.book_valuation.metrics.book_value') }}</strong><br>{{ $numbers->format($totals['book_value']) }} {{ $currencyCode }}</td>
    <td><strong>{{ __('inventory_accounting.book_valuation.metrics.unvalued_quantity') }}</strong><br>{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['unvalued_quantity']) }}</td>
</tr></table>

<table class="report-table" dir="{{ $direction ?? 'ltr' }}">
    <thead><tr>@foreach(['branch','store','position','item','unit','quantity','book_unit_cost','book_value','unvalued_quantity','status'] as $column)<th>{{ __('inventory_accounting.book_valuation.columns.'.$column) }}</th>@endforeach</tr></thead>
    <tbody>
        @forelse($rows as $row)<tr>
            <td>{{ $row->branch?->name }}</td><td>{{ $row->branchStore?->name }}</td><td>{{ $row->branchHall?->name ?: '—' }}</td>
            <td>{{ $row->product?->doc_num }} — {{ $row->product?->name }}</td><td>{{ $row->product?->unit?->name }}</td>
            <td class="number">{{ $numbers->format($row->on_hand) }}</td><td class="number">{{ $row->book_unit_cost === null ? '—' : $numbers->format($row->book_unit_cost) }}</td><td class="number">{{ $numbers->format($row->book_value) }}</td><td class="number">{{ $numbers->format($row->unvalued_quantity) }}</td>
            <td>{{ __('inventory_accounting.book_valuation.statuses.'.$row->valuation_status) }}{{ $row->is_negative ? ' / '.__('inventory_accounting.book_valuation.negative') : '' }}</td>
        </tr>@empty<tr><td colspan="10">{{ __('inventory_accounting.book_valuation.empty') }}</td></tr>@endforelse
        <tr class="total"><td colspan="5">{{ __('inventory_accounting.book_valuation.total') }}</td><td class="number">{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['quantity']) }}</td><td></td><td class="number">{{ $numbers->format($totals['book_value']) }}</td><td class="number">{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['unvalued_quantity']) }}</td><td></td></tr>
        @if($totals['mixed_units'])
            @foreach($totals['quantity_by_unit'] as $unitTotal)
                <tr><td colspan="5">{{ __('inventory_accounting.book_valuation.unit_subtotal', ['unit' => $unitTotal['unit_name']]) }}</td><td class="number">{{ $numbers->format($unitTotal['quantity']) }}</td><td colspan="2"></td><td class="number">{{ $numbers->format($unitTotal['unvalued_quantity']) }}</td><td></td></tr>
            @endforeach
        @endif
    </tbody>
</table>

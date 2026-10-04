@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $showHall = $rows->contains(fn ($row) => $row->branchHall !== null);
    $showAttributes = $rows->contains(function ($row): bool {
        $product = $row->product;

        return collect([$product?->category, $product?->group, $product?->itemModel, $product?->size, $product?->color, $product?->decal, $product?->originCountry])->filter()->isNotEmpty();
    });
    $descriptionColumns = 6 + (int) $showHall + (int) $showAttributes;
@endphp

<div class="report-filter-summary">
    @foreach ($filterSummary as $label => $value)
        <strong>{{ $label }}:</strong> {{ $value }}@unless($loop->last) · @endunless
    @endforeach
</div>
@if($totals['mixed_units'])
    <p class="report-warning">{{ __('inventory_accounting.book_valuation.mixed_units_warning') }}
        @foreach($totals['quantity_by_unit'] as $unitTotal){{ $unitTotal['unit_name'] }}: {{ $numbers->format($unitTotal['on_hand']) }}@unless($loop->last) · @endunless @endforeach
    </p>
@endif

<table class="document-meta-table">
    <tr>
        <td><strong>{{ __('stock_balance_inquiry.metrics.on_hand') }}</strong><br>{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['on_hand']) }}</td>
        <td><strong>{{ __('stock_balance_inquiry.metrics.available_stock') }}</strong><br>{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['available_stock']) }}</td>
        <td><strong>{{ __('stock_balance_inquiry.metrics.reserved') }}</strong><br>{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['reserved']) }}</td>
        <td><strong>{{ __('stock_balance_inquiry.metrics.available') }}</strong><br>{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['available']) }}</td>
        <td><strong>{{ __('stock_balance_inquiry.metrics.held') }}</strong><br>{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['held_stock']) }}</td>
        @if ($canViewFinancial)<td><strong>{{ __('stock_balance_inquiry.metrics.inventory_value') }}</strong><br>{{ $numbers->format($totals['inventory_value']) }}</td>@endif
    </tr>
</table>

@unless ($reservationsAreHallScoped)
    <p class="report-warning">{{ __('stock_balance_inquiry.reservations_hall_note') }}</p>
@endunless

<table dir="{{ $direction ?? 'ltr' }}" class="report-table">
    <thead>
        <tr>
            <th>{{ __('stock_balance_inquiry.columns.branch') }}</th>
            <th>{{ __('stock_balance_inquiry.columns.store') }}</th>
            @if ($showHall)<th>{{ __('stock_balance_inquiry.columns.hall') }}</th>@endif
            <th>{{ __('stock_balance_inquiry.columns.item_code') }}</th>
            <th>{{ __('stock_balance_inquiry.columns.item_name') }}</th>
            <th>{{ __('stock_balance_inquiry.columns.classification') }}</th>
            <th>{{ __('stock_balance_inquiry.columns.unit') }}</th>
            @if ($showAttributes)<th>{{ __('stock_balance_inquiry.filter_groups.attributes') }}</th>@endif
            <th class="number">{{ __('stock_balance_inquiry.columns.on_hand') }}</th>
            <th class="number">{{ __('stock_balance_inquiry.columns.reserved') }}</th>
            <th class="number">{{ __('stock_balance_inquiry.columns.available') }}</th>
            <th class="number">{{ __('stock_balance_inquiry.columns.held') }}</th>
            @if ($canViewFinancial)<th class="number">{{ __('stock_balance_inquiry.columns.inventory_value') }}</th>@endif
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            @php
                $product = $row->product;
                $attributes = collect([
                    $product?->category?->name,
                    $product?->group?->name,
                    $product?->itemModel?->name,
                    $product?->size?->name,
                    $product?->color?->name,
                    $product?->decal?->name,
                    $product?->originCountry?->name,
                ])->filter()->implode(' / ');
            @endphp
            <tr>
                <td>{{ $row->branch?->name }}</td>
                <td>{{ $row->branchStore?->name }}</td>
                @if ($showHall)<td>{{ $row->branchHall?->name }}</td>@endif
                <td dir="ltr">{{ $product?->doc_num }}</td>
                <td>{{ $product?->name }}</td>
                <td>{{ $product?->item_classification ? __('products.classifications.'.$product->item_classification) : '' }}</td>
                <td>{{ $product?->unit?->name }}</td>
                @if ($showAttributes)<td>{{ $attributes }}</td>@endif
                <td class="number">{{ $numbers->format($row->on_hand) }}</td>
                <td class="number">{{ $numbers->format($row->reserved) }}</td>
                <td class="number">{{ $numbers->format($row->available) }}</td>
                <td class="number">{{ $numbers->format($row->held_stock) }}</td>
                @if ($canViewFinancial)<td class="number">{{ $numbers->format($row->inventory_value) }}</td>@endif
            </tr>
        @empty
            <tr><td colspan="{{ $descriptionColumns + 4 + (int) $canViewFinancial }}">{{ __('stock_balance_inquiry.empty') }}</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="{{ $descriptionColumns }}">{{ __('stock_balance_inquiry.total') }} · {{ __('stock_balance_inquiry.product_count', ['count' => $totals['products']]) }}</td>
            <td class="number">{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['on_hand']) }}</td>
            <td class="number">{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['reserved']) }}</td>
            <td class="number">{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['available']) }}</td>
            <td class="number">{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['held_stock']) }}</td>
            @if ($canViewFinancial)<td class="number">{{ $numbers->format($totals['inventory_value']) }}</td>@endif
        </tr>
        @if($totals['mixed_units'])
            @foreach($totals['quantity_by_unit'] as $unitTotal)
                <tr><td colspan="{{ $descriptionColumns }}">{{ __('inventory_accounting.book_valuation.unit_subtotal', ['unit' => $unitTotal['unit_name']]) }}</td>
                    <td class="number">{{ $numbers->format($unitTotal['on_hand']) }}</td>
                    <td class="number">{{ $numbers->format($unitTotal['reserved']) }}</td>
                    <td class="number">{{ $numbers->format($unitTotal['available']) }}</td>
                    <td class="number">{{ $numbers->format($unitTotal['held_stock']) }}</td>
                    @if($canViewFinancial)<td></td>@endif
                </tr>
            @endforeach
        @endif
    </tbody>
</table>

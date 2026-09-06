@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $showHall = $rows->contains(fn ($row) => $row->branchHall !== null);
    $showLocation = $rows->contains(fn ($row) => $row->warehouseLocation !== null);
    $showAttributes = $rows->contains(function ($row): bool {
        $product = $row->product;

        return collect([$product?->category, $product?->group, $product?->itemModel, $product?->size, $product?->color, $product?->decal, $product?->originCountry])->filter()->isNotEmpty();
    });
    $descriptionColumns = 6 + (int) $showHall + (int) $showLocation + (int) $showAttributes;
@endphp

<div class="report-filter-summary">
    @foreach ($filterSummary as $label => $value)
        <strong>{{ $label }}:</strong> {{ $value }}@unless($loop->last) · @endunless
    @endforeach
</div>

<table class="document-meta-table">
    <tr>
        <td><strong>{{ __('stock_balance_inquiry.metrics.on_hand') }}</strong><br>{{ $numbers->format($totals['on_hand']) }}</td>
        <td><strong>{{ __('stock_balance_inquiry.metrics.available_stock') }}</strong><br>{{ $numbers->format($totals['available_stock']) }}</td>
        <td><strong>{{ __('stock_balance_inquiry.metrics.reserved') }}</strong><br>{{ $numbers->format($totals['reserved']) }}</td>
        <td><strong>{{ __('stock_balance_inquiry.metrics.available') }}</strong><br>{{ $numbers->format($totals['available']) }}</td>
        <td><strong>{{ __('stock_balance_inquiry.metrics.held') }}</strong><br>{{ $numbers->format($totals['held_stock']) }}</td>
        @if ($canViewFinancial)<td><strong>{{ __('stock_balance_inquiry.metrics.inventory_value') }}</strong><br>{{ $numbers->format($totals['inventory_value']) }}</td>@endif
    </tr>
</table>

@unless ($reservationsAreHallScoped)
    <p class="report-warning">{{ __('stock_balance_inquiry.reservations_hall_note') }}</p>
@endunless

<table class="report-table">
    <thead>
        <tr>
            <th>{{ __('stock_balance_inquiry.columns.branch') }}</th>
            <th>{{ __('stock_balance_inquiry.columns.store') }}</th>
            @if ($showHall)<th>{{ __('stock_balance_inquiry.columns.hall') }}</th>@endif
            @if ($showLocation)<th>{{ __('stock_balance_inquiry.columns.location') }}</th>@endif
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
                @if ($showLocation)<td>{{ $row->warehouseLocation ? $row->warehouseLocation->code.' / '.$row->warehouseLocation->name : '' }}</td>@endif
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
            <td class="number">{{ $numbers->format($totals['on_hand']) }}</td>
            <td class="number">{{ $numbers->format($totals['reserved']) }}</td>
            <td class="number">{{ $numbers->format($totals['available']) }}</td>
            <td class="number">{{ $numbers->format($totals['held_stock']) }}</td>
            @if ($canViewFinancial)<td class="number">{{ $numbers->format($totals['inventory_value']) }}</td>@endif
        </tr>
    </tbody>
</table>

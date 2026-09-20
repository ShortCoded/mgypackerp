@extends('layouts.app')
@section('title', __('inventory_accounting.sales_valuation.title'))

@section('content')
@php
    $numbers = $numbers ?? app(\Modules\Core\Services\NumericFormatService::class);
    $valuation = $valuation ?? [];
    $rows = $valuation['rows'] ?? collect();
    $totals = $valuation['totals'] ?? ['position_count' => 0, 'product_count' => 0, 'unpriced_product_count' => 0, 'quantity' => '0.00000000', 'sales_value' => '0.00000000', 'unpriced_quantity' => '0.00000000'];
    $currencyCode = $valuation['priceListCurrencyCode'] ?? '';
    $priceList = $valuation['priceList'] ?? null;
    $asOf = $valuation['asOf'] ?? $filters['as_of'] ?? today()->toDateString();
    $exportQuery = request()->query();
@endphp

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">@lang('inventory_accounting.sales_valuation.title')</h4>
            <p class="text-muted mb-0">@lang('inventory_accounting.sales_valuation.description')</p>
        </div>
        <div class="btn-group d-print-none">
            @can('inventory.reports.export')
                @if($priceList)
                <a class="btn btn-outline-success" href="{{ route('admin.inventory.sales-valuation.export', ['format' => 'xlsx', ...$exportQuery]) }}">@lang('reports.export_excel')</a>
                <a class="btn btn-outline-secondary" href="{{ route('admin.inventory.sales-valuation.export', ['format' => 'csv', ...$exportQuery]) }}">@lang('reports.export_csv')</a>
                <a class="btn btn-outline-danger" href="{{ route('admin.inventory.sales-valuation.print', $exportQuery) }}" target="_blank">@lang('reports.export_pdf')</a>
                @endif
            @endcan
        </div>
    </div>

    <form class="card card-body mb-3 d-print-none" method="GET" action="{{ route('admin.inventory.sales-valuation') }}">
        <div class="row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label">@lang('stock_balance_inquiry.filters.as_of')</label><x-forms.date-input name="as_of" :value="$asOf" required /></div>
            <div class="col-md-3"><label class="form-label">@lang('stock_balance_inquiry.filters.branch')</label><x-forms.select name="branch_doc_num" class="js-select2-ajax" data-url="{{ route('admin.select2.branches') }}" data-allow-clear="true"><option value="">@lang('stock_balance_inquiry.options.all')</option>@foreach($options['branches'] as $branch)<option value="{{ $branch->doc_num }}" @selected(($filters['branch_doc_num'] ?? null) === $branch->doc_num)>{{ $branch->doc_num }} — {{ $branch->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">@lang('stock_balance_inquiry.filters.store')</label><x-forms.select name="branch_store_uuid" class="js-select2-ajax" data-url="{{ route('admin.inventory.select2.branch-stores') }}" data-allow-clear="true"><option value="">@lang('stock_balance_inquiry.options.all')</option>@foreach($options['stores'] as $store)<option value="{{ $store->public_uuid }}" @selected(($filters['branch_store_uuid'] ?? null) === $store->public_uuid)>{{ $store->branch?->name }} — {{ $store->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">@lang('stock_balance_inquiry.filters.hall')</label><x-forms.select name="branch_hall_uuid" class="js-select2-ajax" data-url="{{ route('admin.inventory.select2.branch-halls') }}" data-allow-clear="true"><option value="">@lang('stock_balance_inquiry.options.all')</option>@foreach($options['halls'] as $hall)<option value="{{ $hall->public_uuid }}" @selected(($filters['branch_hall_uuid'] ?? null) === $hall->public_uuid)>{{ $hall->branch?->name }} — {{ $hall->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">@lang('stock_balance_inquiry.filters.location')</label><x-forms.select name="warehouse_location_uuid"><option value="">@lang('stock_balance_inquiry.options.all')</option>@foreach($options['locations'] as $location)<option value="{{ $location->public_id }}" @selected(($filters['warehouse_location_uuid'] ?? null) === $location->public_id)>{{ $location->branchStore?->name }} — {{ $location->code }} / {{ $location->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">@lang('stock_balance_inquiry.filters.product')</label><x-forms.select name="product_doc_num" class="js-select2-ajax" data-url="{{ route('admin.inventory.select2.opening-stock-products') }}" data-allow-clear="true"><option value="">@lang('stock_balance_inquiry.options.select_item')</option>@if($options['selected_product'])<option value="{{ $options['selected_product']->doc_num }}" selected>{{ $options['selected_product']->doc_num }} — {{ $options['selected_product']->name }}</option>@endif</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">@lang('inventory_accounting.sales_valuation.price_list')</label><x-forms.select name="price_list_id" class="js-select2-ajax" data-url="{{ route('admin.sales.select2.price-lists') }}" required><option value="">@lang('inventory_accounting.sales_valuation.select_price_list')</option>@foreach($options['priceLists'] as $pl)<option value="{{ $pl->id }}" @selected(($filters['price_list_id'] ?? null) == $pl->id)>{{ $pl->doc_num }}</option>@endforeach</x-forms.select>@error('price_list_id')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-3"><label class="form-label">@lang('stock_balance_inquiry.filters.stock_status')</label><x-forms.select name="stock_status"><option value="">@lang('stock_balance_inquiry.options.all')</option>@foreach([\Modules\Inventory\Models\InventoryTransaction::StatusAvailable, \Modules\Inventory\Models\InventoryTransaction::StatusQcHold, \Modules\Inventory\Models\InventoryTransaction::StatusProductionStaging, \Modules\Inventory\Models\InventoryTransaction::StatusWip, \Modules\Inventory\Models\InventoryTransaction::StatusDamaged] as $status)<option value="{{ $status }}" @selected(($filters['stock_status'] ?? null) === $status)>@lang('stock_balance_inquiry.stock_statuses.'.$status)</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">@lang('inventory_accounting.book_valuation.run')</button></div>
        </div>
    </form>

    @if(isset($valuation['priceList']) && $valuation['priceList'])
        <div class="alert alert-info">
            <strong>@lang('inventory_accounting.sales_valuation.selected_price_list'):</strong> {{ $valuation['priceList']->doc_num }} @if($currencyCode) ({{ $currencyCode }})@endif<br>
            <strong>@lang('inventory_accounting.sales_valuation.as_of'):</strong> {{ $asOf }}
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small">@lang('stock_balance_inquiry.columns.positions')</div><div class="fs-5 fw-semibold">{{ $totals['position_count'] }}</div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small">@lang('stock_balance_inquiry.columns.products')</div><div class="fs-5 fw-semibold">{{ $totals['product_count'] }}</div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small">@lang('inventory_accounting.sales_valuation.quantity')</div><div class="fs-5 fw-semibold" dir="ltr">{{ $numbers->format($totals['quantity']) }}</div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small">@lang('inventory_accounting.sales_valuation.sales_value')</div><div class="fs-5 fw-semibold" dir="ltr">{{ $numbers->format($totals['sales_value']) }} @if($currencyCode){{ $currencyCode }}@endif</div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small">@lang('inventory_accounting.sales_valuation.unpriced_quantity')</div><div class="fs-5 fw-semibold" dir="ltr">{{ $numbers->format($totals['unpriced_quantity']) }}</div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small">@lang('inventory_accounting.sales_valuation.unpriced_product_count')</div><div class="fs-5 fw-semibold">{{ $totals['unpriced_product_count'] }}</div></div></div></div>
    </div>

    <div class="card mb-4"><div class="table-responsive"><table class="table table-bordered align-middle mb-0">
        <thead><tr>
            <th>@lang('stock_balance_inquiry.columns.branch')</th>
            <th>@lang('stock_balance_inquiry.columns.store')</th>
            <th>@lang('stock_balance_inquiry.columns.location')</th>
            <th style="min-width:12rem">@lang('stock_balance_inquiry.columns.product')</th>
            <th>@lang('stock_balance_inquiry.columns.unit')</th>
            <th class="text-end">@lang('stock_balance_inquiry.columns.on_hand')</th>
            <th class="text-end">@lang('inventory_accounting.sales_valuation.unit_selling_price')</th>
            <th class="text-end">@lang('inventory_accounting.sales_valuation.sales_value')</th>
            <th>@lang('inventory_accounting.sales_valuation.price_status')</th>
        </tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row->branch?->name }}</td>
                    <td>{{ $row->branchStore?->name }}</td>
                    <td>{{ collect([$row->branchHall?->name, $row->warehouseLocation?->code])->filter()->implode(' / ') ?: '—' }}</td>
                    <td>{{ $row->product?->doc_num }} — {{ $row->product?->name }}@if($row->product?->trashed()) <span class="badge bg-secondary">@lang('inventory_accounting.book_valuation.deleted')</span>@endif</td>
                    <td>{{ $row->product?->unit?->name }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($row->on_hand) }}</td>
                    <td class="text-end" dir="ltr">@if($row->unit_selling_price !== null){{ $numbers->format($row->unit_selling_price) }}@else<span class="text-danger">@lang('inventory_accounting.sales_valuation.unpriced')</span>@endif</td>
                    <td class="text-end" dir="ltr">{{ $row->sales_value !== null ? $numbers->format($row->sales_value) . ($currencyCode ? ' ' . $currencyCode : '') : '—' }}</td>
                    <td><span class="badge {{ $row->price_status === 'unpriced' ? 'bg-warning text-dark' : 'bg-success' }}">@lang('inventory_accounting.sales_valuation.price_statuses.'. $row->price_status)</span></td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted">@lang('inventory_accounting.book_valuation.empty')</td></tr>
            @endforelse
            <tr class="fw-bold">
                <td colspan="5">@lang('inventory_accounting.book_valuation.total')</td>
                <td class="text-end">{{ $numbers->format($totals['quantity']) }}</td>
                <td></td>
                <td class="text-end">{{ $numbers->format($totals['sales_value']) }} @if($currencyCode){{ $currencyCode }}@endif</td>
                <td></td>
            </tr>
        </tbody>
    </table></div></div>
</div>
@endsection

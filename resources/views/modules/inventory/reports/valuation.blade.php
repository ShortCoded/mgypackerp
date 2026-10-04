@extends('layouts.app')

@section('title', __('inventory_accounting.book_valuation.title'))

@section('content')
@php
    $rows = $bookValuation['rows'];
    $totals = $bookValuation['totals'];
    $exportQuery = request()->except(['product_id', 'branch_store_id', 'reference_method']);
    $comparisonExportQuery = [...request()->query(), 'as_of' => $asOf, 'export_view' => 'comparison'];
    $summaryMetrics = [
        ['positions', $totals['positions']],
        ['quantity', $totals['mixed_units'] ? '—' : $numbers->format($totals['quantity'])],
        ['book_value', $numbers->format($totals['book_value']).' '.$currencyCode],
        ['unvalued_quantity', $totals['mixed_units'] ? '—' : $numbers->format($totals['unvalued_quantity'])],
        ['negative_positions', $totals['negative_positions']],
    ];
    $summaryMetrics = array_values(array_filter($summaryMetrics, fn (array $metric): bool =>
        ! in_array($metric[0], ['unvalued_quantity', 'negative_positions'], true)
        || (float) $totals[$metric[0]] !== 0.0));
@endphp
<div class="container-fluid admin-report-page inventory-valuation-report">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">{{ __('inventory_accounting.book_valuation.title') }}</h4>
            <p class="text-muted mb-0">{{ __('inventory_accounting.book_valuation.description', ['currency' => $currencyCode]) }}</p>
        </div>
        <x-admin.report.actions-toolbar
            class="d-print-none"
            :show-filters="false"
            :show-refresh="false"
            :export-options="[
                ['permission' => 'inventory.reports.valuation.export', 'url' => route('admin.inventory.reports.valuation.export.excel', $exportQuery), 'label' => __('reports.export_excel'), 'icon' => 'file-excel'],
                ['permission' => 'inventory.reports.valuation.export', 'url' => route('admin.inventory.reports.valuation.export.csv', $exportQuery), 'label' => __('reports.export_csv'), 'icon' => 'file-csv'],
                ['permission' => 'inventory.reports.valuation.export', 'url' => route('admin.inventory.reports.valuation.export.pdf', $exportQuery), 'label' => __('reports.export_pdf'), 'icon' => 'file-pdf', 'newTab' => true],
            ]"
        />
    </div>

    @if($totals['mixed_units'])
        <div class="alert alert-info" role="status">
            {{ __('inventory_accounting.book_valuation.mixed_units_warning') }}
            @foreach($totals['quantity_by_unit'] as $unitTotal)
                <span class="d-inline-block mx-2">{{ $unitTotal['unit_name'] }}: <b dir="ltr">{{ $numbers->format($unitTotal['quantity']) }}</b>
                    @if(bccomp($unitTotal['unvalued_quantity'], '0', 8) !== 0)
                        ({{ __('inventory_accounting.book_valuation.metrics.unvalued_quantity') }}: {{ $numbers->format($unitTotal['unvalued_quantity']) }})
                    @endif
                </span>
            @endforeach
        </div>
    @endif
    @if($totals['has_unvalued'])
        <div class="alert alert-warning" role="alert">{{ $totals['mixed_units'] ? __('inventory_accounting.book_valuation.unvalued_warning_mixed', ['rows' => $totals['unvalued_rows']]) : __('inventory_accounting.book_valuation.unvalued_warning', ['rows' => $totals['unvalued_rows'], 'quantity' => $numbers->format($totals['unvalued_quantity'])]) }}</div>
    @endif
    @if($totals['residual_value_positions'] > 0)
        <div class="alert alert-danger" role="alert">{{ __('inventory_accounting.book_valuation.residual_value_warning', ['count' => $totals['residual_value_positions']]) }}</div>
    @endif
    @if($totals['negative_positions'] > 0)
        <div class="alert alert-danger" role="alert">{{ __('inventory_accounting.book_valuation.negative_warning', ['count' => $totals['negative_positions']]) }}</div>
    @endif

    <form class="card card-body mb-3 d-print-none" method="GET" action="{{ route('admin.inventory.reports.valuation') }}">
        <div class="row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label">{{ __('inventory_accounting.book_valuation.as_of') }}</label><x-forms.date-input name="as_of" :value="$asOf" :min="$period->from_date->toDateString()" :max="$period->to_date->toDateString()" required /></div>
            <div class="col-md-3"><label class="form-label">{{ __('stock_balance_inquiry.filters.branch') }}</label><x-forms.select variant="local" name="branch_doc_num"><option value="">{{ __('stock_balance_inquiry.options.all') }}</option>@foreach($options['branches'] as $branch)<option value="{{ $branch->doc_num }}" @selected(($filters['branch_doc_num'] ?? null) === $branch->doc_num)>{{ $branch->doc_num }} — {{ $branch->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('stock_balance_inquiry.filters.store') }}</label><x-forms.select variant="ajax" name="branch_store_uuid" :url="route('admin.inventory.select2.branch-stores', ['scope' => 'company'])"><option value="">{{ __('stock_balance_inquiry.options.all') }}</option>@foreach($options['stores'] as $store)<option value="{{ $store->public_uuid }}" @selected(($filters['branch_store_uuid'] ?? null) === $store->public_uuid)>{{ $store->branch?->name }} — {{ $store->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('stock_balance_inquiry.filters.hall') }}</label><x-forms.select variant="ajax" name="branch_hall_uuid" :url="route('admin.inventory.select2.branch-halls', ['scope' => 'company'])"><option value="">{{ __('stock_balance_inquiry.options.all') }}</option>@foreach($options['halls'] as $hall)<option value="{{ $hall->public_uuid }}" @selected(($filters['branch_hall_uuid'] ?? null) === $hall->public_uuid)>{{ $hall->branch?->name }} — {{ $hall->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('stock_balance_inquiry.filters.product') }}</label><x-forms.select name="product_doc_num" class="js-select2-ajax" data-url="{{ route('admin.inventory.select2.opening-stock-products') }}" data-allow-clear="true"><option value=""></option>@if($options['selected_product'])<option value="{{ $options['selected_product']->doc_num }}" selected>{{ $options['selected_product']->doc_num }} — {{ $options['selected_product']->name }}</option>@endif</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('stock_balance_inquiry.filters.item_classification') }}</label><x-forms.select variant="local" name="item_classification"><option value="">{{ __('stock_balance_inquiry.options.all') }}</option>@foreach(\Modules\Core\Models\Product::stockableItemClassifications() as $classification)<option value="{{ $classification }}" @selected(($filters['item_classification'] ?? null) === $classification)>{{ __('products.classifications.'.$classification) }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('stock_balance_inquiry.filters.stock_status') }}</label><x-forms.select variant="local" name="stock_status"><option value="">{{ __('stock_balance_inquiry.options.all') }}</option>@foreach([\Modules\Inventory\Models\InventoryTransaction::StatusAvailable, \Modules\Inventory\Models\InventoryTransaction::StatusQcHold, \Modules\Inventory\Models\InventoryTransaction::StatusProductionStaging, \Modules\Inventory\Models\InventoryTransaction::StatusWip, \Modules\Inventory\Models\InventoryTransaction::StatusDamaged] as $status)<option value="{{ $status }}" @selected(($filters['stock_status'] ?? null) === $status)>{{ __('stock_balance_inquiry.stock_statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('stock_balance_inquiry.filters.item_category_doc_num') }}</label><x-forms.select name="item_category_doc_num" class="js-select2-ajax" data-url="{{ route('admin.select2.item-categories') }}" data-allow-clear="true"><option value=""></option>@if($options['selected_lookups']['item_category_doc_num'])<option value="{{ $options['selected_lookups']['item_category_doc_num']->doc_num }}" selected>{{ $options['selected_lookups']['item_category_doc_num']->name }}</option>@endif</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('stock_balance_inquiry.filters.item_group_doc_num') }}</label><x-forms.select name="item_group_doc_num" class="js-select2-ajax" data-url="{{ route('admin.select2.item-groups') }}" data-allow-clear="true"><option value=""></option>@if($options['selected_lookups']['item_group_doc_num'])<option value="{{ $options['selected_lookups']['item_group_doc_num']->doc_num }}" selected>{{ $options['selected_lookups']['item_group_doc_num']->name }}</option>@endif</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('stock_balance_inquiry.filters.item_unit_doc_num') }}</label><x-forms.select name="item_unit_doc_num" class="js-select2-ajax" data-url="{{ route('admin.select2.item-units') }}" data-allow-clear="true"><option value=""></option>@if($options['selected_lookups']['item_unit_doc_num'])<option value="{{ $options['selected_lookups']['item_unit_doc_num']->doc_num }}" selected>{{ $options['selected_lookups']['item_unit_doc_num']->name }}</option>@endif</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('inventory_accounting.book_valuation.balance_state') }}</label><x-forms.select variant="local" name="quantity_state"><option value="">{{ __('stock_balance_inquiry.options.all') }}</option><option value="positive" @selected(($filters['quantity_state'] ?? null) === 'positive')>{{ __('stock_balance_inquiry.quantity_states.positive') }}</option><option value="negative" @selected(($filters['quantity_state'] ?? null) === 'negative')>{{ __('stock_balance_inquiry.quantity_states.negative') }}</option></x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('inventory_accounting.valuation_report.reference_method') }}</label><x-forms.select variant="local" name="reference_method">@foreach(['moving_average','periodic_weighted_average','fifo','lifo','last_inbound_reference','last_purchase_reference'] as $method)<option value="{{ $method }}" @selected($referenceMethod === $method)>{{ __('inventory_accounting.valuation_methods.'.$method) }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">{{ __('inventory_accounting.book_valuation.run') }}</button></div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        @foreach($summaryMetrics as [$key, $value])
            <div class="col-12 col-sm-6 col-xl-4 col-xxl"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ __('inventory_accounting.book_valuation.metrics.'.$key) }}</div><div class="fs-5 fw-semibold text-nowrap" dir="ltr">{{ $value }}</div></div></div></div>
        @endforeach
    </div>

    <div class="card mb-4"><div class="table-responsive"><table class="table table-bordered align-middle mb-0">
        <thead><tr>@foreach(['branch','store','position','item','unit','quantity','book_unit_cost','book_value','unvalued_quantity','status'] as $column)<th class="{{ in_array($column, ['quantity','book_unit_cost','book_value','unvalued_quantity'], true) ? 'text-end' : '' }}">{{ __('inventory_accounting.book_valuation.columns.'.$column) }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse($rows as $row)<tr>
                <td>{{ $row->branch?->name }}</td><td>{{ $row->branchStore?->name }}</td><td>{{ $row->branchHall?->name ?: '—' }}</td>
                <td>{{ $row->product?->doc_num }} — {{ $row->product?->name }}@if($row->product?->trashed()) <span class="badge bg-secondary">{{ __('inventory_accounting.book_valuation.deleted') }}</span>@endif</td><td>{{ $row->product?->unit?->name }}</td>
                <td class="text-end" dir="ltr">{{ $numbers->format($row->on_hand) }}</td><td class="text-end" dir="ltr">{{ $row->book_unit_cost === null ? '—' : $numbers->format($row->book_unit_cost) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($row->book_value) }} {{ $currencyCode }}</td><td class="text-end" dir="ltr">{{ $numbers->format($row->unvalued_quantity) }}</td>
                <td><span class="badge {{ $row->valuation_status === 'residual_value' ? 'bg-danger' : ($row->valuation_status === 'unvalued' ? 'bg-warning text-dark' : ($row->valuation_status === 'zero_cost' ? 'bg-info text-dark' : 'bg-success')) }}">{{ __('inventory_accounting.book_valuation.statuses.'.$row->valuation_status) }}</span>@if($row->is_negative) <span class="badge bg-danger">{{ __('inventory_accounting.book_valuation.negative') }}</span>@endif</td>
            </tr>@empty<tr><td colspan="10" class="text-center text-muted">{{ __('inventory_accounting.book_valuation.empty') }}</td></tr>@endforelse
            <tr class="fw-bold"><td colspan="5">{{ __('inventory_accounting.book_valuation.total') }}</td><td class="text-end">{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['quantity']) }}</td><td></td><td class="text-end">{{ $numbers->format($totals['book_value']) }} {{ $currencyCode }}</td><td class="text-end">{{ $totals['mixed_units'] ? '—' : $numbers->format($totals['unvalued_quantity']) }}</td><td></td></tr>
            @if($totals['mixed_units'])
                @foreach($totals['quantity_by_unit'] as $unitTotal)
                    <tr><td colspan="5">{{ __('inventory_accounting.book_valuation.unit_subtotal', ['unit' => $unitTotal['unit_name']]) }}</td><td class="text-end">{{ $numbers->format($unitTotal['quantity']) }}</td><td colspan="2"></td><td class="text-end">{{ $numbers->format($unitTotal['unvalued_quantity']) }}</td><td></td></tr>
                @endforeach
            @endif
        </tbody>
    </table></div></div>

    <div class="card d-print-none">
        <div class="card-header"><h5 class="mb-0">{{ __('inventory_accounting.valuation_report.title') }}</h5><small class="text-muted">{{ __('inventory_accounting.valuation_report.description') }}</small></div>
        <div class="card-body">
            <p class="text-muted small mb-3">{{ __('inventory_accounting.valuation_report.scope_note') }}</p>
            @if($comparisonError)<div class="alert alert-danger">{{ $comparisonError }}</div>@elseif($comparison)
                @if(! $comparison['valuation_complete'])
                    <div class="alert alert-warning" role="alert">
                        {{ app()->getLocale() === 'ar' ? 'النتيجة جزئية؛ توجد أرصدة بتكلفة غير معروفة أو بحركات لا يمكن تقييمها. لا تعتمد إجمالي المقارنة قبل تسوية البنود المستبعدة.' : 'Partial comparison: some positions have unknown costs or invalid movements. Resolve the excluded positions before relying on the comparison total.' }}
                        ({{ $comparison['excluded_position_count'] }} / {{ $comparison['excluded_mixed_units'] ? '—' : $numbers->format($comparison['excluded_quantity']) }})
                        @if($comparison['excluded_mixed_units'])
                            <div>{{ __('inventory_accounting.valuation_report.excluded_unit_summary') }}:
                                @foreach($comparison['excluded_quantity_by_unit'] as $unitTotal){{ $unitTotal['unit_name'] }}: {{ $numbers->format($unitTotal['quantity']) }}@unless($loop->last) · @endunless @endforeach
                            </div>
                        @endif
                    </div>
                @endif
                @if($comparison['multiple_products'])<div class="alert alert-info" role="status">{{ __('inventory_accounting.valuation_report.multiple_products_note') }}</div>@endif
                <x-admin.report.actions-toolbar
                    class="mb-3"
                    :show-filters="false"
                    :show-refresh="false"
                    :export-options="[
                        ['permission' => 'inventory.reports.valuation.export', 'url' => route('admin.inventory.reports.valuation.export.excel', $comparisonExportQuery), 'label' => __('reports.export_excel'), 'icon' => 'file-excel'],
                        ['permission' => 'inventory.reports.valuation.export', 'url' => route('admin.inventory.reports.valuation.export.csv', $comparisonExportQuery), 'label' => __('reports.export_csv'), 'icon' => 'file-csv'],
                        ['permission' => 'inventory.reports.valuation.export', 'url' => route('admin.inventory.reports.valuation.export.pdf', $comparisonExportQuery), 'label' => __('reports.export_pdf'), 'icon' => 'file-pdf'],
                    ]"
                />
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr>
                            <th>{{ __('inventory_accounting.valuation_report.method') }}</th>
                            <th>{{ __('inventory_accounting.valuation_report.ending_value') }}</th>
                            <th>{{ __('inventory_accounting.valuation_report.ending_unit_cost') }}</th>
                            <th>{{ __('inventory_accounting.valuation_report.classification') }}</th>
                            <th>{{ __('inventory_accounting.valuation_report.reference_source') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach($comparison['methods'] as $method => $result)
                                <tr>
                                    <td>{{ __('inventory_accounting.valuation_methods.'.$method) }}</td>
                                    <td>{{ $result['ending_value'] === null ? '—' : $numbers->format($result['ending_value']) }}</td>
                                    <td>{{ $result['ending_unit_cost'] === null ? '—' : $numbers->format($result['ending_unit_cost']) }}</td>
                                    <td>{{ $result['book_method'] ? __('inventory_accounting.valuation_report.book_method') : ($result['reference_only'] ? __('inventory_accounting.valuation_report.reference_only') : __('inventory_accounting.valuation_report.simulation')) }}</td>
                                    <td>
                                        @forelse($result['sources'] ?? [] as $source)
                                            <div>
                                                {{ $source['document'] ?? '—' }} · {{ $source['date'] ?? '—' }} · {{ __('inventory_accounting.valuation_report.source_types.'.($source['source'] ?? 'other')) }}
                                                @if(isset($source['unit_cost']))
                                                    · {{ $numbers->format($source['unit_cost']) }}
                                                @endif
                                                @if(isset($source['currency']))
                                                    · {{ $source['currency'] }} × {{ $source['exchange_rate'] ?? '1' }}
                                                @endif
                                                @if(isset($source['basis']))
                                                    · {{ __('inventory_accounting.valuation_report.source_bases.'.$source['basis']) }}
                                                @endif
                                            </div>
                                        @empty
                                            {{ $result['reference_only'] ? __('inventory_accounting.valuation_report.no_reference_source') : '—' }}
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($comparison['excluded_positions'] !== [])
                    <h6 class="mt-4">{{ __('inventory_accounting.valuation_report.excluded_title') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead><tr>
                                <th>{{ __('inventory_accounting.book_valuation.columns.branch') }}</th>
                                <th>{{ __('inventory_accounting.book_valuation.columns.store') }}</th>
                                <th>{{ __('inventory_accounting.book_valuation.columns.item') }}</th>
                                <th class="text-end">{{ __('inventory_accounting.book_valuation.columns.quantity') }}</th>
                                <th>{{ __('inventory_accounting.valuation_report.excluded_reason') }}</th>
                                <th>{{ __('inventory_accounting.valuation_report.excluded_documents') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach($comparison['excluded_positions'] as $position)
                                    <tr>
                                        <td>{{ $position['branch_name'] ?? $position['branch_id'] }}</td>
                                        <td>{{ $position['store_name'] ?? $position['branch_store_id'] }}</td>
                                        <td>{{ $position['product_doc_num'] ?? $position['product_id'] }} — {{ $position['product_name'] }}</td>
                                        <td class="text-end" dir="ltr">{{ $numbers->format($position['quantity']) }}</td>
                                        <td>{{ __($position['reason']) }}</td>
                                        <td>{{ implode('، ', $position['source_doc_nums']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @else<p class="text-muted mb-0">{{ __('inventory_accounting.valuation_report.empty') }}</p>@endif
        </div>
    </div>
</div>
@endsection

@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $localSelectClass = 'form-select form-select-sm w-100 js-select2-local';
    $ajaxSelectClass = 'form-select form-select-sm w-100 js-select2-ajax';
    $stockStatuses = [
        \Modules\Inventory\Models\InventoryTransaction::StatusAvailable,
        \Modules\Inventory\Models\InventoryTransaction::StatusReserved,
        \Modules\Inventory\Models\InventoryTransaction::StatusQcHold,
        \Modules\Inventory\Models\InventoryTransaction::StatusQuarantine,
        \Modules\Inventory\Models\InventoryTransaction::StatusRework,
        \Modules\Inventory\Models\InventoryTransaction::StatusProductionStaging,
        \Modules\Inventory\Models\InventoryTransaction::StatusWip,
        \Modules\Inventory\Models\InventoryTransaction::StatusRejected,
        \Modules\Inventory\Models\InventoryTransaction::StatusDamaged,
        \Modules\Inventory\Models\InventoryTransaction::StatusScrap,
        \Modules\Inventory\Models\InventoryTransaction::StatusInTransit,
    ];
@endphp

@section('title', __('stock_balance_inquiry.title'))

@section('content')
    <x-admin.report.page
        class="stock-balance-inquiry"
        :title="__('stock_balance_inquiry.title')"
        :description="__('stock_balance_inquiry.description')"
    >
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="stock-balance-filter-panel"
                :filter-title="__('reports.filters')"
                :refresh-url="route('admin.inventory.stock-balances.index', request()->query())"
                :export-options="[
                    [
                        'permission' => 'inventory.reports.stock_balances.export',
                        'url' => route('admin.inventory.stock-balances.export', request()->query()),
                        'label' => __('reports.export_excel'),
                        'icon' => 'file-excel',
                    ],
                    [
                        'permission' => 'inventory.reports.stock_balances.print',
                        'url' => route('admin.inventory.stock-balances.print', request()->query()),
                        'label' => __('reports.export_pdf'),
                        'icon' => 'file-pdf',
                        'newTab' => true,
                    ],
                ]"
            />
        </x-slot:actions>

        @if ($errors->any())
            <div class="alert alert-danger py-2" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <x-admin.report.filter-panel
            id="stock-balance-filter-panel"
            :title="__('reports.filters')"
            :description="__('stock_balance_inquiry.filters_hint')"
            :action="route('admin.inventory.stock-balances.index')"
            :expanded="$filtersExpanded"
            :reset-url="route('admin.inventory.stock-balances.index', ['run' => 1])"
        >
            <x-forms.input type="hidden" name="run" value="1" />

            <div class="col-12"><h6 class="border-bottom pb-1 mb-0 text-700">{{ __('stock_balance_inquiry.filter_groups.position') }}</h6></div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="stock-balance-as-of">{{ __('stock_balance_inquiry.filters.as_of') }}</label>
                <x-forms.date-input class="form-control-sm" id="stock-balance-as-of" name="as_of" :value="$filters['as_of']" required />
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="stock-balance-branch">{{ __('stock_balance_inquiry.filters.branch') }}</label>
                <x-forms.select class="{{ $localSelectClass }}" id="stock-balance-branch" name="branch_doc_num" data-placeholder="{{ __('stock_balance_inquiry.options.all') }}" data-allow-clear="true">
                    <option value=""></option>
                    @foreach ($options['branches'] as $branch)
                        <option value="{{ $branch->doc_num }}" data-branch-type="{{ $branch->type }}" @selected(($filters['branch_doc_num'] ?? null) === $branch->doc_num)>{{ $branch->doc_num }} — {{ $branch->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="stock-balance-store">{{ __('stock_balance_inquiry.filters.store') }}</label>
                <x-forms.select class="{{ $localSelectClass }}" id="stock-balance-store" name="branch_store_uuid" data-placeholder="{{ __('stock_balance_inquiry.options.all') }}" data-allow-clear="true">
                    <option value=""></option>
                    @foreach ($options['stores'] as $store)
                        <option value="{{ $store->public_uuid }}" data-branch="{{ $store->branch?->doc_num }}" @selected(($filters['branch_store_uuid'] ?? null) === $store->public_uuid)>{{ $store->branch?->name }} — {{ $store->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field" id="stock-balance-hall-field">
                <label class="form-label mb-1" for="stock-balance-hall">{{ __('stock_balance_inquiry.filters.hall') }}</label>
                <x-forms.select class="{{ $localSelectClass }}" id="stock-balance-hall" name="branch_hall_uuid" data-placeholder="{{ __('stock_balance_inquiry.options.all') }}" data-allow-clear="true">
                    <option value=""></option>
                    @foreach ($options['halls'] as $hall)
                        <option value="{{ $hall->public_uuid }}" data-branch="{{ $hall->branch?->doc_num }}" @selected(($filters['branch_hall_uuid'] ?? null) === $hall->public_uuid)>{{ $hall->branch?->name }} — {{ $hall->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="stock-balance-status">{{ __('stock_balance_inquiry.filters.stock_status') }}</label>
                <x-forms.select class="{{ $localSelectClass }}" id="stock-balance-status" name="stock_status" data-placeholder="{{ __('stock_balance_inquiry.options.all') }}" data-allow-clear="true">
                    <option value=""></option>
                    @foreach ($stockStatuses as $status)
                        <option value="{{ $status }}" @selected(($filters['stock_status'] ?? null) === $status)>{{ __('stock_balance_inquiry.stock_statuses.'.$status) }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="stock-balance-quantity-state">{{ __('stock_balance_inquiry.filters.quantity_state') }}</label>
                <x-forms.select class="{{ $localSelectClass }}" id="stock-balance-quantity-state" name="quantity_state" data-placeholder="{{ __('stock_balance_inquiry.options.all') }}" data-allow-clear="true">
                    <option value=""></option>
                    @foreach (['positive', 'negative', 'held', 'below_reorder'] as $state)
                        <option value="{{ $state }}" @selected(($filters['quantity_state'] ?? null) === $state)>{{ __('stock_balance_inquiry.quantity_states.'.$state) }}</option>
                    @endforeach
                </x-forms.select>
            </div>

            <div class="col-12"><h6 class="border-bottom pb-1 mb-0 mt-1 text-700">{{ __('stock_balance_inquiry.filter_groups.item') }}</h6></div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="stock-balance-product">{{ __('stock_balance_inquiry.filters.product') }}</label>
                <x-forms.select class="{{ $ajaxSelectClass }}" id="stock-balance-product" name="product_doc_num" data-url="{{ route('admin.inventory.select2.opening-stock-products') }}" data-placeholder="{{ __('stock_balance_inquiry.options.select_item') }}" data-allow-clear="true">
                    <option value=""></option>
                    @if ($options['selected_product'])
                        <option value="{{ $options['selected_product']->doc_num }}" selected>{{ $options['selected_product']->doc_num }} — {{ $options['selected_product']->name }}</option>
                    @endif
                </x-forms.select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="stock-balance-search">{{ __('stock_balance_inquiry.filters.search') }}</label>
                <x-forms.input class="form-control form-control-sm" id="stock-balance-search" name="search" value="{{ $filters['search'] ?? '' }}" autocomplete="off" />
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="stock-balance-classification">{{ __('stock_balance_inquiry.filters.item_classification') }}</label>
                <x-forms.select class="{{ $localSelectClass }}" id="stock-balance-classification" name="item_classification" data-placeholder="{{ __('stock_balance_inquiry.options.all') }}" data-allow-clear="true">
                    <option value=""></option>
                    @foreach (\Modules\Core\Models\Product::stockableItemClassifications() as $classification)
                        <option value="{{ $classification }}" @selected(($filters['item_classification'] ?? null) === $classification)>{{ __('products.classifications.'.$classification) }}</option>
                    @endforeach
                </x-forms.select>
            </div>

            <div class="col-12"><h6 class="border-bottom pb-1 mb-0 mt-1 text-700">{{ __('stock_balance_inquiry.filter_groups.attributes') }}</h6></div>
            @foreach ([
                ['field' => 'item_category_doc_num', 'id' => 'category', 'url' => route('admin.select2.item-categories')],
                ['field' => 'item_group_doc_num', 'id' => 'group', 'url' => route('admin.select2.item-groups')],
                ['field' => 'item_model_doc_num', 'id' => 'model', 'url' => route('admin.select2.item-models')],
                ['field' => 'item_size_doc_num', 'id' => 'size', 'url' => route('admin.select2.item-sizes')],
                ['field' => 'item_color_doc_num', 'id' => 'color', 'url' => route('admin.select2.item-colors')],
                ['field' => 'item_decal_doc_num', 'id' => 'decal', 'url' => route('admin.select2.item-decals')],
                ['field' => 'item_unit_doc_num', 'id' => 'unit', 'url' => route('admin.select2.item-units')],
                ['field' => 'item_origin_country_doc_num', 'id' => 'origin-country', 'url' => route('admin.select2.item-origin-countries')],
            ] as $lookup)
                @php
                    $selectedLookup = $options['selected_lookups'][$lookup['field']] ?? null;
                @endphp
                <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                    <label class="form-label mb-1" for="stock-balance-{{ $lookup['id'] }}">{{ __('stock_balance_inquiry.filters.'.$lookup['field']) }}</label>
                    <x-forms.select class="{{ $ajaxSelectClass }}" id="stock-balance-{{ $lookup['id'] }}" name="{{ $lookup['field'] }}" data-url="{{ $lookup['url'] }}" data-placeholder="{{ __('stock_balance_inquiry.options.select_value') }}" data-allow-clear="true">
                        <option value=""></option>
                        @if ($selectedLookup)
                            <option value="{{ $selectedLookup->doc_num }}" selected>{{ $selectedLookup->doc_num }} — {{ $selectedLookup->name }}</option>
                        @endif
                    </x-forms.select>
                </div>
            @endforeach
        </x-admin.report.filter-panel>

        @unless ($reservationsAreHallScoped)
            <div class="alert alert-info py-2 mb-3" role="status">{{ __('stock_balance_inquiry.reservations_hall_note') }}</div>
        @endunless

        <div class="row g-2 mb-3">
            @foreach ([
                ['label' => __('stock_balance_inquiry.metrics.on_hand'), 'value' => $totals['on_hand']],
                ['label' => __('stock_balance_inquiry.metrics.available_stock'), 'value' => $totals['available_stock']],
                ['label' => __('stock_balance_inquiry.metrics.reserved'), 'value' => $totals['reserved']],
                ['label' => __('stock_balance_inquiry.metrics.available'), 'value' => $totals['available']],
                ['label' => __('stock_balance_inquiry.metrics.held'), 'value' => $totals['held_stock']],
            ] as $metric)
                <div class="col-6 col-lg">
                    <div class="card h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-600 fs-11">{{ $metric['label'] }}</div>
                            <div class="fw-semibold fs-8" dir="ltr">{{ $numbers->format($metric['value']) }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
            @if ($canViewFinancial)
                <div class="col-6 col-lg">
                    <div class="card h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-600 fs-11">{{ __('stock_balance_inquiry.metrics.inventory_value') }}</div>
                            <div class="fw-semibold fs-8" dir="ltr">{{ $numbers->format($totals['inventory_value']) }}</div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <x-admin.report.table-card :title="__('stock_balance_inquiry.table_title')" table-id="stock-balance-inquiry-table">
            <thead>
                <tr>
                    <th>{{ __('stock_balance_inquiry.columns.branch') }}</th>
                    <th>{{ __('stock_balance_inquiry.columns.store') }}</th>
                    <th>{{ __('stock_balance_inquiry.columns.item') }}</th>
                    <th class="d-none d-xl-table-cell">{{ __('stock_balance_inquiry.columns.classification') }}</th>
                    <th class="d-none d-lg-table-cell">{{ __('stock_balance_inquiry.columns.unit') }}</th>
                    <th class="d-none d-xl-table-cell">{{ __('stock_balance_inquiry.filter_groups.attributes') }}</th>
                    <th class="text-end">{{ __('stock_balance_inquiry.columns.on_hand') }}</th>
                    <th class="text-end">{{ __('stock_balance_inquiry.columns.reserved') }}</th>
                    <th class="text-end">{{ __('stock_balance_inquiry.columns.available') }}</th>
                    <th class="text-end d-none d-lg-table-cell">{{ __('stock_balance_inquiry.columns.held') }}</th>
                    @if ($canViewFinancial)<th class="text-end d-none d-xl-table-cell">{{ __('stock_balance_inquiry.columns.inventory_value') }}</th>@endif
                    <th class="text-end">{{ __('stock_balance_inquiry.columns.actions') }}</th>
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
                        ])->filter()->implode(' • ');
                    @endphp
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $row->branch?->name }}</div>
                            <small class="text-600">{{ __('branches.types.'.$row->branch?->type) }}</small>
                        </td>
                        <td>
                            <div>{{ $row->branchStore?->name }}</div>
                            @if ($row->branchHall || $row->warehouseLocation)
                                <small class="text-600">
                                    {{ collect([$row->branchHall?->name, $row->warehouseLocation ? $row->warehouseLocation->code.' / '.$row->warehouseLocation->name : null])->filter()->implode(' — ') }}
                                </small>
                            @endif
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $product?->name }}</div>
                            <small class="text-600" dir="ltr">{{ $product?->doc_num }}@if($product?->barcode) · {{ $product->barcode }}@endif</small>
                        </td>
                        <td class="d-none d-xl-table-cell">{{ $product?->item_classification ? __('products.classifications.'.$product->item_classification) : '' }}</td>
                        <td class="d-none d-lg-table-cell">{{ $product?->unit?->name }}</td>
                        <td class="d-none d-xl-table-cell"><small>{{ $attributes }}</small></td>
                        <td class="text-end fw-semibold" dir="ltr">{{ $numbers->format($row->on_hand) }}</td>
                        <td class="text-end" dir="ltr">{{ $numbers->format($row->reserved) }}</td>
                        <td class="text-end fw-semibold" dir="ltr">{{ $numbers->format($row->available) }}</td>
                        <td class="text-end d-none d-lg-table-cell" dir="ltr">{{ $numbers->format($row->held_stock) }}</td>
                        @if ($canViewFinancial)<td class="text-end d-none d-xl-table-cell" dir="ltr">{{ $numbers->format($row->inventory_value) }}</td>@endif
                        <td class="text-end text-nowrap">
                            @can('products.view')
                                <a class="btn btn-link btn-sm p-1" href="{{ route('admin.products.show', $product) }}" title="{{ __('stock_balance_inquiry.open_item') }}" aria-label="{{ __('stock_balance_inquiry.open_item') }}"><span class="fas fa-box-open"></span></a>
                            @endcan
                            @if ((int) $context['branch_id'] === (int) $row->branch_id)
                                <a class="btn btn-link btn-sm p-1" href="{{ route('admin.inventory.reports.index', ['product_id' => $row->product_id, 'branch_store_id' => $row->branch_store_id]) }}" title="{{ __('stock_balance_inquiry.open_stock_card') }}" aria-label="{{ __('stock_balance_inquiry.open_stock_card') }}"><span class="fas fa-list-alt"></span></a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $canViewFinancial ? 12 : 11 }}" class="text-center text-600 py-4">{{ __('stock_balance_inquiry.empty') }}</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="fw-semibold">
                    <td colspan="6">{{ __('stock_balance_inquiry.total') }} · {{ __('stock_balance_inquiry.position_count', ['count' => $totals['positions']]) }} · {{ __('stock_balance_inquiry.product_count', ['count' => $totals['products']]) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($totals['on_hand']) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($totals['reserved']) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($totals['available']) }}</td>
                    <td class="text-end d-none d-lg-table-cell" dir="ltr">{{ $numbers->format($totals['held_stock']) }}</td>
                    @if ($canViewFinancial)<td class="text-end d-none d-xl-table-cell" dir="ltr">{{ $numbers->format($totals['inventory_value']) }}</td>@endif
                    <td></td>
                </tr>
            </tfoot>
        </x-admin.report.table-card>
    </x-admin.report.page>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/modules/Core/report-ui.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Inventory/stock-balance-inquiry.js') }}"></script>
@endpush

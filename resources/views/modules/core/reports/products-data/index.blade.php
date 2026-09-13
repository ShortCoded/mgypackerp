@extends('layouts.app')

@php
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $staticSelectClass = 'form-select form-select-sm w-100 js-select2-local js-report-filter-control';
    $ajaxSelectClass = 'form-select form-select-sm w-100 js-select2-ajax js-report-filter-control';
@endphp

@section('title', __('product_data_report.title'))

@push('styles')
    <style>
        .product-data-report .product-data-filter-group-heading {
            align-items: center;
            border-top: 1px solid var(--falcon-border-color);
            color: var(--falcon-gray-700);
            display: flex;
            font-size: .74rem;
            font-weight: 600;
            gap: .5rem;
            letter-spacing: 0;
            margin-top: .25rem;
            padding-top: .35rem;
        }

        .product-data-report .product-data-filter-group-heading.is-first {
            border-top: 0;
            margin-top: 0;
            padding-top: 0;
        }
    </style>
@endpush

@section('content')
    <x-admin.report.page
        class="product-data-report"
        :title="__('product_data_report.report_title')"
        :description="__('product_data_report.filters_hint')"
    >
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="product-data-filter-panel"
                :filter-title="__('product_data_report.actions.toggle_filters')"
                :export-options="[
                    [
                        'permission' => 'reports.products_data.export',
                        'url' => route('admin.reports.products-data.export.excel'),
                        'label' => __('reports.export_excel'),
                        'icon' => 'file-excel',
                    ],
                    [
                        'permission' => 'reports.products_data.export',
                        'url' => route('admin.reports.products-data.export.csv'),
                        'label' => __('reports.export_csv'),
                        'icon' => 'file-csv',
                    ],
                    [
                        'permission' => 'reports.products_data.pdf',
                        'url' => route('admin.reports.products-data.export.pdf'),
                        'label' => __('reports.export_pdf'),
                        'icon' => 'file-pdf',
                        'newTab' => true,
                    ],
                ]"
            />
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="product-data-filter-panel"
            :title="__('reports.filters')"
            :description="__('product_data_report.filters_hint')"
        >
            <div class="col-12">
                <div class="product-data-filter-group-heading is-first">
                    <span class="fas fa-sliders-h"></span>
                    <span>{{ __('product_data_report.filter_groups.output_options') }}</span>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-result-mode">{{ __('product_data_report.filters.result_mode') }}</label>
                <select class="{{ $staticSelectClass }}" id="product-data-result-mode" name="result_mode" data-filter-label="{{ __('product_data_report.filters.result_mode') }}" data-placeholder="{{ __('product_data_report.filters.result_mode') }}" data-allow-clear="false">
                    <option value="summary" @selected(($initialFilters['result_mode'] ?? 'summary') === 'summary')>{{ __('product_data_report.modes.summary') }}</option>
                    <option value="detailed" @selected(($initialFilters['result_mode'] ?? null) === 'detailed')>{{ __('product_data_report.modes.detailed') }}</option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-item-scope">{{ __('product_data_report.filters.item_scope') }}</label>
                <select class="{{ $staticSelectClass }}" id="product-data-item-scope" name="item_scope" data-filter-label="{{ __('product_data_report.filters.item_scope') }}" data-placeholder="{{ __('product_data_report.filters.item_scope') }}" data-allow-clear="false">
                    <option value="{{ \Modules\Core\Services\Reports\ProductDataReport::ItemScopeAll }}" @selected(($initialFilters['item_scope'] ?? 'all') === \Modules\Core\Services\Reports\ProductDataReport::ItemScopeAll)>{{ __('product_data_report.item_scopes.all') }}</option>
                    <option value="{{ \Modules\Core\Services\Reports\ProductDataReport::ItemScopeProducts }}" @selected(($initialFilters['item_scope'] ?? null) === \Modules\Core\Services\Reports\ProductDataReport::ItemScopeProducts)>{{ __('product_data_report.item_scopes.products') }}</option>
                    <option value="{{ \Modules\Core\Services\Reports\ProductDataReport::ItemScopeRawMaterials }}" @selected(($initialFilters['item_scope'] ?? null) === \Modules\Core\Services\Reports\ProductDataReport::ItemScopeRawMaterials)>{{ __('product_data_report.item_scopes.raw_materials') }}</option>
                    <option value="{{ \Modules\Core\Services\Reports\ProductDataReport::ItemScopePackagingMaterials }}" @selected(($initialFilters['item_scope'] ?? null) === \Modules\Core\Services\Reports\ProductDataReport::ItemScopePackagingMaterials)>{{ __('product_data_report.item_scopes.packaging_materials') }}</option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-record-state">{{ __('product_data_report.filters.record_state') }}</label>
                <select class="{{ $staticSelectClass }}" id="product-data-record-state" name="record_state" data-filter-label="{{ __('product_data_report.filters.record_state') }}" data-placeholder="{{ __('product_data_report.filters.record_state') }}" data-allow-clear="false">
                    <option value="active" @selected(($initialFilters['record_state'] ?? 'active') === 'active')>{{ __('product_data_report.record_states.active') }}</option>
                    <option value="deleted" @selected(($initialFilters['record_state'] ?? null) === 'deleted')>{{ __('product_data_report.record_states.deleted') }}</option>
                    <option value="all" @selected(($initialFilters['record_state'] ?? null) === 'all')>{{ __('product_data_report.record_states.all') }}</option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-status">{{ __('product_data_report.filters.status') }}</label>
                <select class="{{ $staticSelectClass }}" id="product-data-status" name="status" data-filter-label="{{ __('product_data_report.filters.status') }}" data-placeholder="{{ __('product_data_report.options.all') }}" data-allow-clear="true">
                    <option value="" @selected(! isset($initialFilters['status']))></option>
                    <option value="active" @selected(($initialFilters['status'] ?? null) === 'active')>{{ __('products.statuses.active') }}</option>
                    <option value="inactive" @selected(($initialFilters['status'] ?? null) === 'inactive')>{{ __('products.statuses.inactive') }}</option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-components-state">{{ __('product_data_report.filters.components_state') }}</label>
                <select class="{{ $staticSelectClass }}" id="product-data-components-state" name="components_state" data-filter-label="{{ __('product_data_report.filters.components_state') }}" data-placeholder="{{ __('product_data_report.filters.components_state') }}" data-allow-clear="false">
                    <option value="all" @selected(($initialFilters['components_state'] ?? 'all') === 'all')>{{ __('product_data_report.components_states.all') }}</option>
                    <option value="with" @selected(($initialFilters['components_state'] ?? null) === 'with')>{{ __('product_data_report.components_states.with') }}</option>
                    <option value="without" @selected(($initialFilters['components_state'] ?? null) === 'without')>{{ __('product_data_report.components_states.without') }}</option>
                </select>
            </div>

            <div class="col-12">
                <div class="product-data-filter-group-heading">
                    <span class="fas fa-barcode"></span>
                    <span>{{ __('product_data_report.filter_groups.product_identification') }}</span>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-product">{{ __('product_data_report.filters.product') }}</label>
                <select class="{{ $ajaxSelectClass }}" id="product-data-product" name="product_doc_num" data-filter-label="{{ __('product_data_report.filters.product') }}" data-url="{{ route('admin.reports.products-data.filter-options.products') }}" data-placeholder="{{ __('product_data_report.placeholders.select_product') }}" data-allow-clear="true" data-extra-params='@json(["item_scope" => "#product-data-item-scope"])'>
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-doc-num">{{ __('product_data_report.filters.doc_num') }}</label>
                <input class="form-control form-control-sm js-report-filter-control" id="product-data-doc-num" name="doc_num" type="text" data-filter-label="{{ __('product_data_report.filters.doc_num') }}" placeholder="{{ __('product_data_report.placeholders.doc_num') }}" dir="ltr">
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-name">{{ __('product_data_report.filters.name') }}</label>
                <input class="form-control form-control-sm js-report-filter-control" id="product-data-name" name="name" type="text" data-filter-label="{{ __('product_data_report.filters.name') }}" placeholder="{{ __('product_data_report.placeholders.name') }}">
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-barcode">{{ __('product_data_report.filters.barcode') }}</label>
                <input class="form-control form-control-sm js-report-filter-control" id="product-data-barcode" name="barcode" type="text" data-filter-label="{{ __('product_data_report.filters.barcode') }}" placeholder="{{ __('product_data_report.placeholders.barcode') }}" dir="ltr">
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-classification">{{ __('product_data_report.filters.item_classification') }}</label>
                <select class="{{ $staticSelectClass }}" id="product-data-classification" name="item_classification" data-filter-label="{{ __('product_data_report.filters.item_classification') }}" data-placeholder="{{ __('product_data_report.options.all') }}" data-allow-clear="true">
                    <option value=""></option>
                    @foreach ($classificationOptions as $classification)
                        <option value="{{ $classification['id'] }}" data-item-scope="{{ \Modules\Core\Models\Product::contextForClassification($classification['id']) }}">{{ $classification['text'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12">
                <div class="product-data-filter-group-heading">
                    <span class="fas fa-tags"></span>
                    <span>{{ __('product_data_report.filter_groups.product_classification') }}</span>
                </div>
            </div>
            @foreach ([
                ['name' => 'item_category_doc_num', 'id' => 'category', 'label' => __('product_data_report.filters.category'), 'url' => route('admin.select2.item-categories')],
                ['name' => 'item_group_doc_num', 'id' => 'group', 'label' => __('product_data_report.filters.group'), 'url' => route('admin.select2.item-groups')],
                ['name' => 'item_model_doc_num', 'id' => 'model', 'label' => __('product_data_report.filters.model'), 'url' => route('admin.select2.item-models')],
                ['name' => 'item_size_doc_num', 'id' => 'size', 'label' => __('product_data_report.filters.size'), 'url' => route('admin.select2.item-sizes')],
                ['name' => 'item_color_doc_num', 'id' => 'color', 'label' => __('product_data_report.filters.color'), 'url' => route('admin.select2.item-colors')],
                ['name' => 'item_decal_doc_num', 'id' => 'decal', 'label' => __('product_data_report.filters.decal'), 'url' => route('admin.select2.item-decals')],
                ['name' => 'item_unit_doc_num', 'id' => 'unit', 'label' => __('product_data_report.filters.unit'), 'url' => route('admin.select2.item-units')],
                ['name' => 'item_origin_country_doc_num', 'id' => 'origin-country', 'label' => __('product_data_report.filters.origin_country'), 'url' => route('admin.select2.item-origin-countries')],
            ] as $lookup)
                <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                    <label class="form-label mb-1" for="product-data-{{ $lookup['id'] }}">{{ $lookup['label'] }}</label>
                    <select class="{{ $ajaxSelectClass }}" id="product-data-{{ $lookup['id'] }}" name="{{ $lookup['name'] }}" data-filter-label="{{ $lookup['label'] }}" data-url="{{ $lookup['url'] }}" data-placeholder="{{ __('product_data_report.placeholders.select_lookup') }}" data-allow-clear="true">
                        <option value=""></option>
                    </select>
                </div>
            @endforeach

            <div class="col-12">
                <div class="product-data-filter-group-heading">
                    <span class="fas fa-project-diagram"></span>
                    <span>{{ __('product_data_report.filter_groups.bom_components') }}</span>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-component-product">{{ __('product_data_report.filters.component_product') }}</label>
                <select class="{{ $ajaxSelectClass }}" id="product-data-component-product" name="component_product_doc_num" data-filter-label="{{ __('product_data_report.filters.component_product') }}" data-url="{{ route('admin.reports.products-data.filter-options.components') }}" data-placeholder="{{ __('product_data_report.placeholders.select_component') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-component-unit">{{ __('product_data_report.filters.component_unit') }}</label>
                <select class="{{ $ajaxSelectClass }}" id="product-data-component-unit" name="component_unit_doc_num" data-filter-label="{{ __('product_data_report.filters.component_unit') }}" data-url="{{ route('admin.select2.item-units') }}" data-placeholder="{{ __('product_data_report.placeholders.select_lookup') }}" data-allow-clear="true">
                    <option value=""></option>
                </select>
            </div>

            <div class="col-12">
                <div class="product-data-filter-group-heading">
                    <span class="far fa-calendar-alt"></span>
                    <span>{{ __('product_data_report.filter_groups.dates') }}</span>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-created-from">{{ __('product_data_report.filters.created_from') }}</label>
                <div class="input-group input-group-sm w-100 report-date-input-group">
                    <input class="form-control form-control-sm js-date-picker js-report-filter-control" id="product-data-created-from" name="created_from" type="text" data-filter-label="{{ __('product_data_report.filters.created_from') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr">
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="product-data-created-to">{{ __('product_data_report.filters.created_to') }}</label>
                <div class="input-group input-group-sm w-100 report-date-input-group">
                    <input class="form-control form-control-sm js-date-picker js-report-filter-control" id="product-data-created-to" name="created_to" type="text" data-filter-label="{{ __('product_data_report.filters.created_to') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr">
                </div>
            </div>
        </x-admin.report.filter-panel>

        <x-admin.report.table-card
            :title="__('product_data_report.table_title')"
            table-id="product-data-report-table"
            :data-url="route('admin.reports.products-data.data')"
        >
            <thead class="bg-100 text-900">
                <tr>
                    <th>{{ __('product_data_report.fields.doc_num') }}</th>
                    <th>{{ __('product_data_report.fields.name') }}</th>
                    <th>{{ __('product_data_report.fields.item_classification') }}</th>
                    <th>{{ __('product_data_report.fields.barcode') }}</th>
                    <th>{{ __('product_data_report.fields.unit') }}</th>
                    <th>{{ __('product_data_report.fields.category') }}</th>
                    <th>{{ __('product_data_report.fields.group') }}</th>
                    <th>{{ __('product_data_report.fields.model') }}</th>
                    <th>{{ __('product_data_report.fields.size') }}</th>
                    <th>{{ __('product_data_report.fields.color') }}</th>
                    <th>{{ __('product_data_report.fields.decal') }}</th>
                    <th>{{ __('product_data_report.fields.origin_country') }}</th>
                    <th>{{ __('product_data_report.fields.reorder_point') }}</th>
                    <th>{{ __('product_data_report.fields.equivalent') }}</th>
                    <th>{{ __('product_data_report.fields.status') }}</th>
                    <th>{{ __('product_data_report.fields.components_count') }}</th>
                    <th>{{ __('product_data_report.fields.component_doc_num') }}</th>
                    <th>{{ __('product_data_report.fields.component_name') }}</th>
                    <th>{{ __('product_data_report.fields.component_classification') }}</th>
                    <th>{{ __('product_data_report.fields.component_calculation_method') }}</th>
                    <th>{{ __('product_data_report.fields.component_calculation_value') }}</th>
                    <th>{{ __('product_data_report.fields.component_quantity') }}</th>
                    <th>{{ __('product_data_report.fields.component_unit') }}</th>
                    <th>{{ __('product_data_report.fields.component_equivalent') }}</th>
                    <th>{{ __('product_data_report.fields.component_notes') }}</th>
                    <th>{{ __('product_data_report.fields.created_at') }}</th>
                </tr>
            </thead>
        </x-admin.report.table-card>
    </x-admin.report.page>
@endsection

@push('scripts')
    <script>
        window.dataTableTranslations = @js(__('datatables'));
    </script>
    <script src="{{ asset('assets/js/modules/Core/report-ui.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/product-data-report.js') }}"></script>
@endpush

@extends('layouts.app')

@php
    use Modules\Core\Models\Product;
    use Modules\Core\Services\FilePickerService;
    use Modules\Core\Services\OperatingCompanyContextService;
    use Modules\Core\Services\ProductImageResolver;

    $record = $product ?? null;
    $isRawMaterialsContext = $isRawMaterialsContext ?? false;
    $isMaterialContext = $isMaterialContext ?? $isRawMaterialsContext;
    $resourceRoot = match ($productContext ?? Product::ContextProducts) {
        Product::ContextRawMaterials => 'products.raw_materials',
        Product::ContextPackagingMaterials => 'products.packaging_materials',
        default => 'products',
    };
    $showComponentsTab = ! $isMaterialContext;
    $routes = $routes ?? [
        'index' => route('admin.products.index'),
        'create' => route('admin.products.create'),
    ];
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $isCreate = in_array($mode, ['create', 'clone'], true);
    $isPackagingMaterialsContext = ($productContext ?? Product::ContextProducts) === Product::ContextPackagingMaterials;
    $title = match ($mode) {
        'edit' => __($resourceRoot.'.edit'),
        'view' => __($resourceRoot.'.view'),
        'clone' => __($resourceRoot.'.titles.clone'),
        default => __($resourceRoot.'.create'),
    };
    $resourceMessagesRoot = $resourceRoot.'.messages';
    $resourceNameLabel = __($resourceRoot.'.attributes.name');
    $useOldInput = ! $isView && (($errors ?? null)?->any() || ($isCreate && session()->has('_old_input')));
    $fieldValue = fn (string $field, mixed $default = '') => $useOldInput ? old($field, $record?->{$field} ?? $default) : ($record?->{$field} ?? $default);
    $documentNumberValue = $useOldInput ? old('doc_number', ! $isCreate ? $record?->doc_number : '') : (! $isCreate ? $record?->doc_number : '');
    $contextClassification = Product::classificationForContext($productContext ?? Product::ContextProducts);
    $classificationValue = $contextClassification ?? $fieldValue('item_classification', Product::ClassificationFinishedProduct);
    $classificationOptions = $contextClassification === null
        ? Product::productItemClassifications()
        : [$contextClassification];
    $statusValue = $fieldValue('status', 'active');
    $barcodeValue = $fieldValue('barcode');
    $reorderPointValue = $fieldValue('reorder_point', null);
    $productImageRequired = (bool) config('products.image_required', false);
    $showsDocumentNumberColumn = $canControlDocumentNumber || (($isEdit || $isView) && ! $canControlDocumentNumber);
    $selectedImagePublicId = ! $isView ? trim((string) old('image_archive_file_doc_num', '')) : '';
    $removeImageRequested = ! $isView && $useOldInput && filter_var(old('remove_image', false), FILTER_VALIDATE_BOOL);
    $selectedImageFile = null;
    if ($selectedImagePublicId !== '') {
        $selectedImageCompanyId = app(OperatingCompanyContextService::class)->currentCompanyId();
        $selectedImageFile = $selectedImageCompanyId
            ? app(FilePickerService::class)->selectableFileByPublicId($selectedImagePublicId, $selectedImageCompanyId, FilePickerService::AcceptImage)
            : null;
    }
    $existingImageUrl = $record && ! $isClone ? app(ProductImageResolver::class)->url($record) : null;
    $imageUrl = $removeImageRequested && ! $selectedImageFile
        ? null
        : ($selectedImageFile
            ? route('admin.file-manager.files.preview', $selectedImageFile->doc_num)
            : $existingImageUrl);
    $imageFileLabel = $selectedImageFile
        ? $selectedImageFile->original_name
        : ($imageUrl ? __('products.image.existing_file') : __('products.image.no_file_selected'));
    $rawMaterialSelectUrl = $record && ! $isClone
        ? route('admin.select2.component-products', ['current_product_doc_num' => $record->doc_num])
        : route('admin.select2.component-products');
    $lookupFields = [
        'unit' => [
            'name' => 'item_unit_doc_num',
            'label' => __('products.attributes.unit'),
            'option' => $lookupOptions['unit'] ?? null,
            'url' => route('admin.select2.item-units'),
            'inline_url' => route('admin.products.lookups.store', 'item-units'),
            'can_create' => auth()->user()?->can('item_units.create'),
            'add_label' => __('products.inline_lookup.add_unit'),
        ],
        'category' => [
            'name' => 'item_category_doc_num',
            'label' => __('products.attributes.category'),
            'option' => $lookupOptions['category'] ?? null,
            'url' => route('admin.select2.item-categories'),
            'inline_url' => route('admin.products.lookups.store', 'item-categories'),
            'can_create' => auth()->user()?->can('item_categories.create'),
            'add_label' => __('products.inline_lookup.add_category'),
        ],
        'group' => [
            'name' => 'item_group_doc_num',
            'label' => __('products.attributes.group'),
            'option' => $lookupOptions['group'] ?? null,
            'url' => route('admin.select2.item-groups'),
            'inline_url' => route('admin.products.lookups.store', 'item-groups'),
            'can_create' => auth()->user()?->can('item_groups.create'),
            'add_label' => __('products.inline_lookup.add_group'),
        ],
        'size' => [
            'name' => 'item_size_doc_num',
            'label' => __('products.attributes.size'),
            'option' => $lookupOptions['size'] ?? null,
            'url' => route('admin.select2.item-sizes'),
            'inline_url' => route('admin.products.lookups.store', 'item-sizes'),
            'can_create' => auth()->user()?->can('item_sizes.create'),
            'add_label' => __('products.inline_lookup.add_size'),
        ],
        'color' => [
            'name' => 'item_color_doc_num',
            'label' => __('products.attributes.color'),
            'option' => $lookupOptions['color'] ?? null,
            'url' => route('admin.select2.item-colors'),
            'inline_url' => route('admin.products.lookups.store', 'item-colors'),
            'can_create' => auth()->user()?->can('item_colors.create'),
            'add_label' => __('products.inline_lookup.add_color'),
        ],
        'decal' => [
            'name' => 'item_decal_doc_num',
            'label' => __('products.attributes.decal'),
            'option' => $lookupOptions['decal'] ?? null,
            'url' => route('admin.select2.item-decals'),
            'inline_url' => route('admin.products.lookups.store', 'item-decals'),
            'can_create' => auth()->user()?->can('item_decals.create'),
            'add_label' => __('products.inline_lookup.add_decal'),
        ],
        'model' => [
            'name' => 'item_model_doc_num',
            'label' => __('products.attributes.model'),
            'option' => $lookupOptions['model'] ?? null,
            'url' => route('admin.select2.item-models'),
            'inline_url' => route('admin.products.lookups.store', 'item-models'),
            'can_create' => auth()->user()?->can('item_models.create'),
            'add_label' => __('products.inline_lookup.add_model'),
        ],
        'origin_country' => [
            'name' => 'item_origin_country_doc_num',
            'label' => __('products.attributes.origin_country'),
            'option' => $lookupOptions['origin_country'] ?? null,
            'url' => route('admin.select2.item-origin-countries'),
            'inline_url' => route('admin.products.lookups.store', 'item-origin-countries'),
            'can_create' => auth()->user()?->can('item_origin_countries.create'),
            'add_label' => __('products.inline_lookup.add_origin_country'),
        ],
    ];
    $equivalentUnitField = [
        'name' => 'equivalent_unit_doc_num',
        'label' => __('products.attributes.equivalent_unit'),
        'option' => $lookupOptions['equivalent_unit'] ?? null,
        'url' => route('admin.select2.item-units'),
    ];
    $equivalentValue = $fieldValue('equivalent_value', null);
    $componentReadonly = $isView || ($record?->trashed() ?? false);
    $componentInitialRows = $componentRows ?? [];
    if (! $isView && $useOldInput) {
        $oldComponentRows = old('components', $componentInitialRows);
        $componentInitialRows = is_array($oldComponentRows) ? array_values($oldComponentRows) : $componentInitialRows;
    }
    $componentUnitConversionEdges = $componentUnitConversionEdges ?? [];
    $relatedFinishedProductOptions = $relatedFinishedProductOptions ?? [];
@endphp

@section('title', $title)

@push('styles')
    <style>
        .product-form-card .select2-container {
            width: 100% !important;
        }

        .product-form-card .product-lookup-control {
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }

        .product-form-card .product-lookup-control .js-inline-lookup-create {
            align-self: flex-end;
            white-space: nowrap;
        }

        .product-form-card .product-image-field .product-image-picker-panel,
        .product-form-card .product-options-panel {
            min-height: 100%;
        }

        .product-form-card .product-image-preview-frame {
            width: 6.75rem !important;
            height: 6.75rem !important;
            min-width: 6.75rem !important;
            aspect-ratio: 1 / 1;
        }

        .product-form-card .product-image-preview-frame .js-product-image-preview-image {
            display: block;
            width: 100%;
            height: 100%;
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .product-form-card .product-options-panel {
            border: 1px solid var(--falcon-border-color, #d8e2ef);
            border-radius: .375rem;
            background: var(--falcon-gray-100, #f9fafd);
            padding: .75rem;
        }

        .product-form-card .product-option-item {
            height: 100%;
            min-height: 5.25rem;
            border: 1px solid var(--falcon-border-color, #d8e2ef);
            border-radius: .375rem;
            background: var(--falcon-white, #fff);
            padding: .75rem;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: .75rem;
        }

        .product-form-card .product-option-copy {
            min-width: 0;
        }

        .product-form-card .product-option-item .form-switch {
            flex-shrink: 0;
            min-height: auto;
            margin-top: .125rem;
        }

        .product-form-card .product-components-table td,
        .product-form-card .product-components-table th {
            vertical-align: middle;
        }

        .product-form-card .product-components-table {
            min-width: 82rem;
        }

        .product-form-card .product-components-table .dt-actions {
            width: 1%;
            white-space: nowrap;
        }

        .product-form-card .product-components-table .js-product-component-raw-material + .select2-container {
            min-width: 14rem;
        }

        .product-form-card .product-components-table .js-product-component-reference + .select2-container {
            min-width: 12rem;
        }

        .product-form-card .product-component-calculation-state {
            min-height: 1.25rem;
        }

        .product-form-card .product-component-calculated-field {
            background-color: rgba(44, 123, 229, .08);
            box-shadow: inset 0 0 0 1px rgba(44, 123, 229, .18);
        }

        .product-form-card .product-components-table [data-component-unit-display] {
            min-width: 8rem;
            min-height: calc(1.5em + .625rem + 2px);
            background-color: var(--falcon-100, #f9fafd);
        }

        .product-form-card .product-components-total {
            border-top: 1px solid var(--falcon-border-color, #d8e2ef);
        }
    </style>
@endpush

@section('content')
    <form id="product-form" action="{{ $action }}" method="POST" data-mode="{{ $mode }}" data-product-context="{{ $productContext ?? Product::ContextProducts }}" data-default-classification="{{ $classificationValue }}" novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if (! empty($cloneSourceToken))
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif
        @if ($isMaterialContext && ! $isView)
            <input type="hidden" name="item_classification" value="{{ $contextClassification }}">
        @endif

        <div class="mb-3 card product-form-card">
            @include('modules.core.products.partials.form-header')

            <div class="card-body">
                <div data-form-alert></div>

                <ul class="nav nav-tabs" id="product-form-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="product-basic-tab" data-bs-toggle="tab" data-bs-target="#product-basic-pane" type="button" role="tab" aria-controls="product-basic-pane" aria-selected="true">
                            {{ __('products.tabs.basic_data') }}
                        </button>
                    </li>
                    @if ($showComponentsTab)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="product-components-tab" data-bs-toggle="tab" data-bs-target="#product-components-pane" type="button" role="tab" aria-controls="product-components-pane" aria-selected="false">
                                {{ __('products.tabs.components') }}
                            </button>
                        </li>
                    @endif
                </ul>

                <div class="tab-content pt-3">
                    <div class="tab-pane fade show active" id="product-basic-pane" role="tabpanel" aria-labelledby="product-basic-tab" tabindex="0">
                        <div class="row g-3 align-items-start product-form-grid">
                            @if ($canControlDocumentNumber)
                                <div class="col-md-3 col-lg-2">
                                    <label class="form-label" for="doc_number">{{ __('products.attributes.doc_number') }}</label>
                                    @if ($isView)
                                        <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                                    @else
                                        <input class="text-center form-control" id="doc_number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('products.document_number_control.placeholder') }}">
                                    @endif
                                    <div class="form-text">{{ __('products.document_number_control.helper') }}</div>
                                    <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                                </div>
                            @elseif ($isEdit || $isView)
                                <div class="col-md-3 col-lg-2">
                                    <x-forms.view-field
                                        for="doc_number_display"
                                        as="display"
                                        :label="__('products.attributes.doc_num')"
                                        :value="$record?->doc_num"
                                        input-class="text-center"
                                    />
                                </div>
                            @endif

                            <div class="{{ $showsDocumentNumberColumn ? 'col-md-5 col-lg-7' : 'col-md-8' }}">
                                <x-forms.label for="name" :label="$resourceNameLabel" required />
                                @if ($isView)
                                    <x-forms.view-field for="name" :value="$fieldValue('name')" />
                                @else
                                    <input class="form-control" id="name" name="name" type="text" value="{{ $fieldValue('name') }}" required autofocus>
                                @endif
                                <div class="invalid-feedback" data-error-for="name"></div>
                            </div>

                            @unless ($isMaterialContext)
                                <div class="{{ $showsDocumentNumberColumn ? 'col-md-4 col-lg-3' : 'col-md-4' }}">
                                    <x-forms.label for="item_classification" :label="__('products.attributes.item_classification')" required />
                                    @if ($isView)
                                    <x-forms.view-field for="item_classification" :value="__('products.classifications.' . $classificationValue)" />
                                    @else
                                    <select class="form-select" id="item_classification" name="item_classification" required>
                                        @foreach ($classificationOptions as $classification)
                                            <option value="{{ $classification }}" @selected($classificationValue === $classification)>{{ __('products.classifications.' . $classification) }}</option>
                                        @endforeach
                                    </select>
                                    @endif
                                    <div class="invalid-feedback" data-error-for="item_classification"></div>
                                </div>
                            @endunless

                            @if ($isPackagingMaterialsContext)
                                <div class="col-12 js-select2-field">
                                    <label class="form-label" for="packaging-related-finished-products">{{ __('products.packaging_materials.related_finished_products.label') }}</label>
                                    @if ($isView)
                                        <div class="border rounded-2 bg-body-tertiary px-3 py-2">
                                            @forelse ($relatedFinishedProductOptions as $option)
                                                <span class="badge rounded-pill badge-subtle-{{ $option['is_stale'] ? 'warning' : 'primary' }} me-1 mb-1">
                                                    {{ $option['text'] }}
                                                    @if ($option['is_stale'])
                                                        <span class="ms-1">({{ __('products.packaging_materials.related_finished_products.unavailable') }})</span>
                                                    @endif
                                                </span>
                                            @empty
                                                <span class="text-600">—</span>
                                            @endforelse
                                        </div>
                                    @else
                                        <input type="hidden" name="related_finished_product_doc_nums[]" value="">
                                        <select class="form-select js-select2-ajax"
                                            id="packaging-related-finished-products"
                                            name="related_finished_product_doc_nums[]"
                                            multiple
                                            data-url="{{ route('admin.select2.finished-products') }}"
                                            data-placeholder="{{ __('products.packaging_materials.related_finished_products.placeholder') }}"
                                            data-no-results="{{ __('products.packaging_materials.related_finished_products.no_results') }}"
                                            data-allow-clear="true"
                                            data-template="product-image">
                                            @foreach ($relatedFinishedProductOptions as $option)
                                                <option value="{{ $option['id'] }}" selected @if ($option['image_url']) data-image-url="{{ $option['image_url'] }}" @endif>
                                                    {{ $option['text'] }}@if ($option['is_stale']) — {{ __('products.packaging_materials.related_finished_products.unavailable') }}@endif
                                                </option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <div class="form-text">{{ __('products.packaging_materials.related_finished_products.help') }}</div>
                                    <div class="invalid-feedback d-block" data-error-for="related_finished_product_doc_nums"></div>
                                </div>
                            @endif

                            <div class="col-md-4">
                                <label class="form-label" for="barcode">{{ __('products.attributes.barcode') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="barcode" :value="$barcodeValue" dir="ltr" />
                                @else
                                    <input class="form-control" id="barcode" name="barcode" type="text" maxlength="100" value="{{ $barcodeValue }}" dir="ltr">
                                @endif
                                <div class="invalid-feedback" data-error-for="barcode"></div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="reorder_point">{{ __('products.attributes.reorder_point') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="reorder_point" :value="$reorderPointValue" input-class="text-end" dir="ltr" numeric />
                                @else
                                    <x-forms.numeric-input
                                        id="reorder_point"
                                        name="reorder_point"
                                        :value="$reorderPointValue"
                                        :scale="4"
                                        :allow-negative="false"
                                        min="0"
                                        step="0.0001"
                                        class="text-end"
                                    />
                                @endif
                                <div class="invalid-feedback" data-error-for="reorder_point"></div>
                            </div>

                            <div class="col-md-4">
                                <x-forms.label for="status" :label="__('products.attributes.status')" required />
                                @if ($isView)
                                    <x-forms.view-field for="status" :value="__('products.statuses.' . $statusValue)" />
                                @else
                                    <select class="form-select" id="status" name="status" required>
                                        @foreach (['active', 'inactive'] as $status)
                                            <option value="{{ $status }}" @selected($statusValue === $status)>{{ __('products.statuses.' . $status) }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="status"></div>
                            </div>

                            @foreach (['unit', 'category', 'group', 'size', 'color', 'decal', 'model', 'origin_country'] as $lookupKey)
                                @php
                                    $lookup = $lookupFields[$lookupKey];
                                    $columnClass = in_array($lookupKey, ['model', 'origin_country'], true) ? 'col-md-6 col-xl-4' : 'col-md-6 col-xl-4';
                                @endphp
                                @if ($lookupKey === 'unit')
                                    <div class="col-12">
                                        <div class="row g-3 align-items-start">
                                            <div class="col-md-4 js-select2-field product-lookup-field">
                                                <label class="form-label" for="{{ $lookup['name'] }}">{{ $lookup['label'] }}</label>
                                                @if ($isView)
                                                    <x-forms.view-field :for="$lookup['name']" :value="$lookup['option']['text'] ?? null" />
                                                @else
                                                    <div class="product-lookup-control">
                                                        <select class="form-select js-select2-ajax js-product-lookup-select" id="{{ $lookup['name'] }}" name="{{ $lookup['name'] }}" data-url="{{ $lookup['url'] }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true">
                                                            @if ($lookup['option'])
                                                                <option value="{{ $lookup['option']['id'] }}" selected>{{ $lookup['option']['text'] }}</option>
                                                            @endif
                                                        </select>
                                                        @if ($lookup['can_create'])
                                                            <button class="btn btn-falcon-default btn-sm js-inline-lookup-create" type="button" data-target-select="#{{ $lookup['name'] }}" data-url="{{ $lookup['inline_url'] }}" data-label="{{ $lookup['label'] }}">
                                                                <span class="fas fa-plus"></span>
                                                                <span class="ms-1">{{ $lookup['add_label'] }}</span>
                                                            </button>
                                                        @endif
                                                    </div>
                                                @endif
                                                <div class="invalid-feedback d-block" data-error-for="{{ $lookup['name'] }}"></div>
                                            </div>

                                            <div class="col-md-4">
                                                <label class="form-label" for="equivalent_value">{{ __('products.attributes.equivalent_value') }}</label>
                                                @if ($isView)
                                                    <x-forms.view-field for="equivalent_value" :value="$equivalentValue" input-class="text-end" dir="ltr" numeric />
                                                @else
                                                    <x-forms.numeric-input
                                                        id="equivalent_value"
                                                        name="equivalent_value"
                                                        :value="$equivalentValue"
                                                        :scale="6"
                                                        :allow-negative="false"
                                                        min="0.000001"
                                                        step="0.000001"
                                                        class="text-end"
                                                    />
                                                @endif
                                                <div class="invalid-feedback d-block" data-error-for="equivalent_value"></div>
                                            </div>

                                            <div class="col-md-4 js-select2-field product-lookup-field">
                                                <label class="form-label" for="{{ $equivalentUnitField['name'] }}">{{ $equivalentUnitField['label'] }}</label>
                                                @if ($isView)
                                                    <x-forms.view-field :for="$equivalentUnitField['name']" :value="$equivalentUnitField['option']['text'] ?? null" />
                                                @else
                                                    <select class="form-select js-select2-ajax js-product-lookup-select" id="{{ $equivalentUnitField['name'] }}" name="{{ $equivalentUnitField['name'] }}" data-url="{{ $equivalentUnitField['url'] }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true">
                                                        @if ($equivalentUnitField['option'])
                                                            <option value="{{ $equivalentUnitField['option']['id'] }}" selected>{{ $equivalentUnitField['option']['text'] }}</option>
                                                        @endif
                                                    </select>
                                                @endif
                                                <div class="invalid-feedback d-block" data-error-for="{{ $equivalentUnitField['name'] }}"></div>
                                            </div>
                                        </div>
                                    </div>
                                    @continue
                                @endif
                                <div class="{{ $columnClass }} js-select2-field product-lookup-field">
                                    <label class="form-label" for="{{ $lookup['name'] }}">{{ $lookup['label'] }}</label>
                                    @if ($isView)
                                        <x-forms.view-field :for="$lookup['name']" :value="$lookup['option']['text'] ?? null" />
                                    @else
                                        <div class="product-lookup-control">
                                            <select class="form-select js-select2-ajax js-product-lookup-select" id="{{ $lookup['name'] }}" name="{{ $lookup['name'] }}" data-url="{{ $lookup['url'] }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true">
                                                @if ($lookup['option'])
                                                    <option value="{{ $lookup['option']['id'] }}" selected>{{ $lookup['option']['text'] }}</option>
                                                @endif
                                            </select>
                                            @if ($lookup['can_create'])
                                                <button class="btn btn-falcon-default btn-sm js-inline-lookup-create" type="button" data-target-select="#{{ $lookup['name'] }}" data-url="{{ $lookup['inline_url'] }}" data-label="{{ $lookup['label'] }}">
                                                    <span class="fas fa-plus"></span>
                                                    <span class="ms-1">{{ $lookup['add_label'] }}</span>
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                    <div class="invalid-feedback d-block" data-error-for="{{ $lookup['name'] }}"></div>
                                </div>
                            @endforeach

                            <div class="col-lg-4 product-image-field">
                                <x-forms.label :for="$isView ? 'product-image-preview' : 'product-image-picker-button'" :label="__('products.attributes.image')" :required="$productImageRequired" />
                                @unless ($isView)
                                    <input type="hidden" id="product-image-archive-file" name="image_archive_file_doc_num" value="{{ $selectedImagePublicId }}">
                                    <input type="hidden" id="product-remove-image" name="remove_image" value="{{ $removeImageRequested && ! $selectedImageFile ? '1' : '0' }}">
                                @endunless
                                <div id="product-image-picker-field"
                                    class="border rounded-2 bg-body-tertiary p-3 product-image-picker-panel js-product-image-picker-field @if ($isView) opacity-75 @endif"
                                    data-current-url="{{ $imageUrl ?? '' }}"
                                    data-existing-url="{{ $existingImageUrl ?? '' }}"
                                    data-existing-label="{{ __('products.image.existing_file') }}"
                                    data-no-image-label="{{ __('products.image.no_file_selected') }}">
                                    <div class="d-flex flex-column flex-md-row align-items-start gap-3">
                                        <div id="product-image-preview" class="d-flex align-items-center justify-content-center bg-white border rounded-2 overflow-hidden flex-shrink-0 product-image-preview-frame">
                                            <img class="h-100 w-100 object-fit-contain js-product-image-preview-image @if (! $imageUrl) d-none @endif"
                                                src="{{ $imageUrl ?? '' }}"
                                                alt="{{ __('products.attributes.image') }}">
                                            <span class="fas fa-image text-400 fs-5 js-product-image-placeholder @if ($imageUrl) d-none @endif"></span>
                                        </div>

                                        <div class="flex-1">
                                            <div class="fw-semibold js-product-image-file-name">{{ $imageFileLabel }}</div>
                                            <div class="small text-600 mt-1">
                                                {{ __('products.image.help', ['size' => (int) config('archive.logo.max_file_size_mib', 2)]) }}
                                            </div>
                                            @unless ($isView)
                                                @can('file_manager.view')
                                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                                        <button type="button"
                                                            id="product-image-picker-button"
                                                            class="btn btn-falcon-primary btn-sm js-product-image-picker-trigger"
                                                            data-file-picker
                                                            data-picker-accept="image"
                                                            data-picker-max="1"
                                                            data-picker-title="{{ __('products.image.select_from_file_manager') }}"
                                                            data-picker-target-input="#product-image-archive-file"
                                                            data-picker-uploader="#product-image-picker-field"
                                                            data-picker-collection="product_image"
                                                            data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                                            data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                                            <span class="fas fa-images me-1"></span>{{ __('products.image.select') }}
                                                        </button>
                                                        <button type="button"
                                                            class="btn btn-falcon-default btn-sm text-danger js-product-image-remove @if (! $imageUrl) d-none @endif"
                                                            data-shortcut-action="products.image.remove">
                                                            <span class="fas fa-times me-1"></span>{{ __('products.image.remove') }}
                                                        </button>
                                                    </div>
                                                @endcan
                                            @endunless
                                        </div>
                                    </div>
                                </div>
                                <div class="invalid-feedback d-block" data-error-for="image"></div>
                            </div>

                            <div class="col-lg-8 product-options-field">
                                <div class="mb-2">
                                    <label class="form-label mb-0">{{ __('products.attributes.options') }}</label>
                                    <div class="form-text mt-1">{{ __('products.options.help') }}</div>
                                </div>
                                <div class="product-options-panel">
                                    <div class="row g-2">
                                        @foreach (['cost_as_inventory', 'is_displayable'] as $booleanField)
                                            @php
                                                $booleanDefault = $record
                                                    ? (bool) $record->{$booleanField}
                                                    : true;
                                                $booleanChecked = $useOldInput
                                                    ? filter_var(old($booleanField, $booleanDefault), FILTER_VALIDATE_BOOL)
                                                    : $booleanDefault;
                                            @endphp
                                            <div class="col-md-6">
                                                <div class="product-option-item">
                                                    <div class="product-option-copy">
                                                        <div class="fw-semibold text-800">{{ __('products.attributes.' . $booleanField) }}</div>
                                                        <div class="small text-600 mt-1">{{ __('products.options.' . $booleanField) }}</div>
                                                    </div>
                                                    @if ($isView)
                                                        <span class="badge rounded-pill badge-subtle-{{ $record?->{$booleanField} ? 'success' : 'secondary' }}">
                                                            {{ $record?->{$booleanField} ? __('common.actions.yes') : __('common.actions.no') }}
                                                        </span>
                                                    @else
                                                        <input type="hidden" name="{{ $booleanField }}" value="0">
                                                        <div class="form-check form-switch mb-0">
                                                            <input class="form-check-input" id="{{ $booleanField }}" name="{{ $booleanField }}" type="checkbox" value="1" aria-label="{{ __('products.attributes.' . $booleanField) }}" @checked($booleanChecked)>
                                                        </div>
                                                    @endif
                                                </div>
                                                <div class="invalid-feedback" data-error-for="{{ $booleanField }}"></div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="notes">{{ __('products.attributes.notes') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="notes" as="textarea" :value="$fieldValue('notes')" rows="4" />
                                @else
                                    <textarea class="form-control" id="notes" name="notes" rows="4">{{ $fieldValue('notes') }}</textarea>
                                @endif
                                <div class="invalid-feedback" data-error-for="notes"></div>
                            </div>

                            @if (! $isCreate)
                                <x-audit-fields-row
                                    :metadata="$metadata"
                                    :show-deleted="$isView && ($record?->trashed() ?? false)"
                                    :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                                />
                            @endif
                        </div>
                    </div>

                    @if ($showComponentsTab)
                        <div class="tab-pane fade" id="product-components-pane" role="tabpanel" aria-labelledby="product-components-tab" tabindex="0">
                            <div class="product-components-panel"
                                data-current-product-doc-num="{{ $record && ! $isClone ? $record->doc_num : '' }}"
                                data-readonly="{{ $componentReadonly ? 'true' : 'false' }}"
                                data-raw-material-url="{{ $rawMaterialSelectUrl }}"
                                data-raw-material-placeholder="{{ __('products.components.select_component_item') }}">
                                <div data-components-alert></div>

                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                    <div>
                                        <label class="form-label mb-0">{{ __('products.components.title') }}</label>
                                        <div class="form-text mb-0">{{ __('products.components.helper') }}</div>
                                    </div>
                                    @unless ($componentReadonly)
                                        <button class="btn btn-falcon-default btn-sm js-product-component-add-row" type="button" data-shortcut-action="products.add_component" title="{{ __('products.components.add_shortcut') }}" data-bs-title="{{ __('products.components.add_shortcut') }}">
                                            <span class="fas fa-plus me-1"></span>{{ __('products.components.add') }}
                                        </button>
                                    @endunless
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle product-components-table mb-0">
                                        <thead class="bg-100 text-900">
                                            <tr>
                                                <th style="width: 24%">{{ __('products.components.component_item') }}</th>
                                                <th style="width: 11%">{{ __('products.components.unit') }}</th>
                                                <th style="width: 11%">{{ __('products.components.calculation_method') }}</th>
                                                <th class="text-center" style="width: 14%">{{ __('products.components.quantity') }}</th>
                                                <th style="width: 25%">{{ __('products.components.calculation') }}</th>
                                                <th>{{ __('products.components.notes') }}</th>
                                                @unless ($componentReadonly)
                                                    <th class="dt-actions text-center" style="width: 76px">{{ __('common.fields.actions') }}</th>
                                                @endunless
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                @unless ($componentReadonly)
                                    <template id="product-component-row-template">
                                        <tr class="js-product-component-row" data-component-index="__INDEX__">
                                            <td>
                                                <input type="hidden" data-component-field="client_key" name="components[__INDEX__][client_key]" value="">
                                                <input type="hidden" data-component-field="public_id" name="components[__INDEX__][public_id]" value="">
                                                <input type="hidden" data-component-field="_delete" name="components[__INDEX__][_delete]" value="0">
                                                <input type="hidden" data-component-field="input_source" name="components[__INDEX__][input_source]" value="weight">
                                                <select class="form-select js-select2-ajax js-product-component-raw-material" name="components[__INDEX__][component_product_doc_num]" data-component-field="component_product_doc_num" data-url="{{ $rawMaterialSelectUrl }}" data-placeholder="{{ __('products.components.select_component_item') }}" data-allow-clear="true" data-template="product-image"></select>
                                                <div class="invalid-feedback d-block" data-error-for="components.__INDEX__.component_product_doc_num"></div>
                                                <div class="invalid-feedback d-block" data-error-for="components.__INDEX__.client_key"></div>
                                                <div class="invalid-feedback d-block" data-error-for="components.__INDEX__.public_id"></div>
                                                <div class="invalid-feedback d-block" data-error-for="components.__INDEX__._delete"></div>
                                            </td>
                                            <td>
                                                <select class="form-select js-product-component-unit" name="components[__INDEX__][unit_doc_num]" data-component-field="unit_doc_num" data-placeholder="{{ __('common.placeholders.select') }}" disabled>
                                                    <option value="">{{ __('common.placeholders.select') }}</option>
                                                </select>
                                                <div class="invalid-feedback d-block" data-error-for="components.__INDEX__.unit_doc_num"></div>
                                            </td>
                                            <td>
                                                <select class="form-select js-product-component-calculation-method" name="components[__INDEX__][calculation_method]" data-component-field="calculation_method">
                                                    <option value="direct">{{ __('products.components.direct') }}</option>
                                                    <option value="percentage">{{ __('products.components.percentage') }}</option>
                                                </select>
                                                <div class="invalid-feedback d-block" data-error-for="components.__INDEX__.calculation_method"></div>
                                                <div class="invalid-feedback d-block" data-error-for="components.__INDEX__.input_source"></div>
                                            </td>
                                            <td class="text-center">
                                                <x-forms.numeric-input
                                                    name="components[__INDEX__][quantity]"
                                                    value=""
                                                    :scale="8"
                                                    :allow-negative="false"
                                                    min="0.00000001"
                                                    step="0.00000001"
                                                    class="text-center js-product-component-quantity"
                                                    data-component-field="quantity"
                                                />
                                                <div class="invalid-feedback d-block" data-error-for="components.__INDEX__.quantity"></div>
                                            </td>
                                            <td>
                                                <div class="js-product-component-percentage-fields d-none">
                                                    <label class="form-label small mb-1">{{ __('products.components.reference_component') }}</label>
                                                    <select class="form-select js-select2-local js-product-component-reference mb-2" name="components[__INDEX__][reference_component_key]" data-component-field="reference_component_key" data-placeholder="{{ __('products.components.select_reference_component') }}" data-allow-clear="true">
                                                        <option value="">{{ __('products.components.select_reference_component') }}</option>
                                                    </select>
                                                    <label class="form-label small mb-1">{{ __('products.components.percentage_value') }}</label>
                                                    <div class="input-group input-group-sm">
                                                        <x-forms.numeric-input
                                                            name="components[__INDEX__][percentage]"
                                                            value=""
                                                            :scale="8"
                                                            :allow-negative="false"
                                                            min="0.00000001"
                                                            step="0.00000001"
                                                            class="text-end js-product-component-percentage"
                                                            data-component-field="percentage"
                                                        />
                                                        <span class="input-group-text">%</span>
                                                    </div>
                                                    <div class="invalid-feedback d-block" data-error-for="components.__INDEX__.reference_component_key"></div>
                                                    <div class="invalid-feedback d-block" data-error-for="components.__INDEX__.percentage"></div>
                                                </div>
                                                <div class="small mt-1 text-600 product-component-calculation-state js-product-component-calculation-state" aria-live="polite"></div>
                                            </td>
                                            <td>
                                                <input class="form-control js-product-component-notes" name="components[__INDEX__][notes]" data-component-field="notes" type="text" value="">
                                                <div class="invalid-feedback d-block" data-error-for="components.__INDEX__.notes"></div>
                                            </td>
                                            <td class="text-center">
                                                <button class="btn btn-link text-600 p-0 me-2 js-product-component-duplicate-row" type="button" title="{{ __('products.components.duplicate_row_shortcut') }}" data-bs-title="{{ __('products.components.duplicate_row_shortcut') }}">
                                                    <span class="fas fa-copy"></span>
                                                    <span class="visually-hidden">{{ __('products.components.duplicate_row') }}</span>
                                                </button>
                                                <button class="btn btn-link text-danger p-0 js-product-component-remove-row" type="button" title="{{ __('products.components.delete_row_shortcut') }}" data-bs-title="{{ __('products.components.delete_row_shortcut') }}">
                                                    <span class="fas fa-trash-alt"></span>
                                                    <span class="visually-hidden">{{ __('common.actions.delete') }}</span>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                @endunless

                                <div class="product-components-total fw-semibold text-end mt-3 pt-3" data-components-total aria-live="polite"></div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            @include('modules.core.products.partials.form-footer')
        </div>
    </form>

    @unless ($isView)
        <x-file-picker-modal />

        <div class="modal fade" id="product-inline-lookup-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" id="product-inline-lookup-form" method="POST" novalidate>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title js-inline-lookup-title">{{ __('products.inline_lookup.title', ['lookup' => __('products.singular')]) }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div data-form-alert></div>
                        <input type="hidden" name="target_select" value="">
                        <div class="mb-3">
                            <x-forms.label for="inline_lookup_name" :label="__('products.inline_lookup.name')" required />
                            <input class="form-control" id="inline_lookup_name" name="name" type="text" required>
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>
                        <div>
                            <label class="form-label" for="inline_lookup_notes">{{ __('products.inline_lookup.notes') }}</label>
                            <textarea class="form-control" id="inline_lookup_notes" name="notes" rows="3"></textarea>
                            <div class="invalid-feedback" data-error-for="notes"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-falcon-default" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                        <button type="submit" class="btn btn-primary">
                            <span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endunless
@endsection

@php
    $coreProductsMessages = [
        'noChanges' => __('common.messages.no_changes'),
        'saved' => __('common.messages.saved_successfully'),
        'validationFailed' => __('common.messages.validation_failed'),
        'validationSummaryTitle' => __('products.validation.summary_title'),
        'unexpectedError' => __('common.messages.unexpected_error'),
        'deleteConfirmTitle' => __($resourceMessagesRoot . '.delete_confirm_title'),
        'deleteConfirmText' => __($resourceMessagesRoot . '.delete_confirm_text'),
        'deleteConfirmYes' => __($resourceMessagesRoot . '.delete_confirm_yes'),
        'no' => __('common.actions.no'),
        'cancel' => __('common.actions.cancel'),
        'confirm' => __('common.actions.confirm'),
        'edit' => __('common.actions.edit'),
        'delete' => __('common.actions.delete'),
        'restore' => __('products.trash.restore'),
        'restoreConfirmTitle' => __('products.trash.restore_confirm_title'),
        'restoreConfirmText' => __('products.trash.restore_confirm_text'),
        'restoreConfirmYes' => __('products.trash.restore_confirm_yes'),
        'inlineLookupTitle' => __('products.inline_lookup.title', ['lookup' => ':lookup']),
        'inlineLookupCreated' => __('products.inline_lookup.created'),
        'componentEmpty' => __('products.components.empty'),
        'componentQuantityGreaterThanZero' => __('products.components.quantity_gt_zero'),
        'componentDuplicateRowTitle' => __('products.components.duplicate_row_shortcut'),
        'componentDeleteRowTitle' => __('products.components.delete_row_shortcut'),
        'componentDirect' => __('products.components.direct'),
        'componentPercentage' => __('products.components.percentage'),
        'componentDirectFormula' => __('products.components.direct_formula'),
        'componentFormulaTemplate' => __('products.components.formula_template'),
        'componentLineLabel' => __('products.components.line_label'),
        'componentLineOnlyLabel' => __('products.components.line_only_label'),
        'componentReferenceMissing' => __('products.components.reference_missing'),
        'componentReferenceSelf' => __('products.components.reference_self'),
        'componentReferenceCycle' => __('products.components.reference_cycle'),
        'componentReferenceWeightUnavailable' => __('products.components.reference_weight_unavailable'),
        'componentIncompatibleUnits' => __('products.components.incompatible_units'),
        'componentCalculationIncomplete' => __('products.components.calculation_incomplete'),
        'componentCalculatedWeightTitle' => __('products.components.calculated_weight_title'),
        'componentCalculatedPercentageTitle' => __('products.components.calculated_percentage_title'),
        'componentDeleteReferenced' => __('products.components.delete_referenced'),
        'componentTotalTemplate' => __('products.components.total_template'),
        'componentTotalIncomplete' => __('products.components.total_incomplete'),
        'componentTotalUnavailable' => __('products.components.total_unavailable'),
        'componentClientValidationFailed' => __('products.components.client_validation_failed'),
        'componentUnknownReference' => __('products.components.unknown_reference'),
    ];
@endphp
@push('scripts')
    <script>
        window.coreProductsMessages = @json($coreProductsMessages);
        window.coreProductInitialComponents = @json($componentInitialRows);
        window.coreProductUnitConversionEdges = @json($componentUnitConversionEdges);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/file-picker.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/products.js') }}"></script>
@endpush

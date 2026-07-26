<?php

namespace Modules\Core\Http\Requests\Concerns;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ItemCategory;
use Modules\Core\Models\ItemColor;
use Modules\Core\Models\ItemDecal;
use Modules\Core\Models\ItemGroup;
use Modules\Core\Models\ItemLookup;
use Modules\Core\Models\ItemModel;
use Modules\Core\Models\ItemOriginCountry;
use Modules\Core\Models\ItemSize;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\ArchiveFileUsageService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Core\Services\ProductDocumentNumberSettingsService;

trait ValidatesProductPayload
{
    use NormalizesNumericInput;

    /**
     * @var list<int>
     */
    private array $resolvedRelatedFinishedProductIds = [];

    /**
     * @return array<string, mixed>
     */
    protected function productRules(): array
    {
        $companyId = $this->companyId();
        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'clone_source_token' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'image_archive_file_doc_num' => ['nullable', 'string', 'max:255'],
            'remove_image' => ['nullable', 'boolean'],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')
                    ->where('company_id', $companyId)
                    ->ignore($this->productRecord()?->getKey())
                    ->withoutTrashed(),
            ],
            'item_classification' => ['required', 'string', Rule::in($this->allowedClassificationsForContext())],
            'reorder_point' => ['nullable', 'numeric', 'min:0', 'regex:/^(?:\d{1,11}|\d{0,11}\.\d{1,4})$/'],
            'item_unit_doc_num' => ['nullable', 'string', $this->activeLookupExistsRule('item_units', $companyId)],
            'equivalent_value' => ['nullable', 'numeric', 'gt:0', 'regex:/^(?:\d{1,12}|\d{0,12}\.\d{1,6})$/'],
            'equivalent_unit_doc_num' => ['nullable', 'string', $this->activeLookupExistsRule('item_units', $companyId)],
            'item_size_doc_num' => ['nullable', 'string', $this->activeLookupExistsRule('item_sizes', $companyId)],
            'item_color_doc_num' => ['nullable', 'string', $this->activeLookupExistsRule('item_colors', $companyId)],
            'item_decal_doc_num' => ['nullable', 'string', $this->activeLookupExistsRule('item_decals', $companyId)],
            'item_model_doc_num' => ['nullable', 'string', $this->activeLookupExistsRule('item_models', $companyId)],
            'item_origin_country_doc_num' => ['nullable', 'string', $this->activeLookupExistsRule('item_origin_countries', $companyId)],
            'item_category_doc_num' => ['nullable', 'string', $this->activeLookupExistsRule('item_categories', $companyId)],
            'item_group_doc_num' => ['nullable', 'string', $this->activeLookupExistsRule('item_groups', $companyId)],
            'cost_as_inventory' => ['nullable', 'boolean'],
            'is_displayable' => ['nullable', 'boolean'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
            'components' => ['nullable', 'array'],
            'components.*.public_id' => ['nullable', 'string'],
            'components.*.client_key' => ['nullable', 'uuid'],
            'components.*.component_product_doc_num' => ['nullable', 'string'],
            'components.*.unit_doc_num' => ['nullable', 'string'],
            'components.*.calculation_method' => ['nullable', 'string', Rule::in(ProductComponent::calculationMethods())],
            'components.*.quantity' => ['nullable'],
            'components.*.percentage' => ['nullable'],
            'components.*.reference_component_key' => ['nullable', 'uuid'],
            'components.*.input_source' => ['nullable', 'string', Rule::in(ProductComponent::inputSources())],
            'components.*.notes' => ['nullable', 'string'],
            'components.*._delete' => ['nullable', 'boolean'],
        ];

        if ($this->productContext() === Product::ContextPackagingMaterials) {
            $rules['related_finished_product_doc_nums'] = ['nullable', 'array'];
            $rules['related_finished_product_doc_nums.*'] = ['nullable', 'string', 'max:255'];
        }

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique('products', 'doc_number')
                    ->where('company_id', $companyId)
                    ->where(fn (QueryBuilder $query): QueryBuilder => $this->applyDocumentNumberContext($query))
                    ->ignore($this->productRecord()?->getKey())
                    ->withoutTrashed(),
            ];
        }

        return $rules;
    }

    protected function prepareProductForValidation(): void
    {
        $this->normalizeNumericInput([
            'reorder_point',
            'equivalent_value',
            'components.*.quantity',
            'components.*.percentage',
        ]);

        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        foreach (['barcode', 'item_classification', 'image_archive_file_doc_num', 'item_unit_doc_num', 'equivalent_unit_doc_num', 'item_size_doc_num', 'item_color_doc_num', 'item_decal_doc_num', 'item_model_doc_num', 'item_origin_country_doc_num', 'item_category_doc_num', 'item_group_doc_num'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => trim((string) $this->input($field)) ?: null]);
            }
        }

        if ($this->has('equivalent_value')) {
            $equivalentValue = trim((string) $this->input('equivalent_value'));
            $this->merge(['equivalent_value' => $equivalentValue === '' ? null : $equivalentValue]);
        }

        if (Product::isMaterialContext($this->productContext())) {
            $this->merge(['item_classification' => Product::classificationForContext($this->productContext())]);
            $this->request->remove('components');
        }

        if ($this->has('related_finished_product_doc_nums') && is_array($this->input('related_finished_product_doc_nums'))) {
            $docNums = [];
            $seen = [];

            foreach ($this->input('related_finished_product_doc_nums') as $docNum) {
                if (! is_string($docNum)) {
                    $docNums[] = $docNum;

                    continue;
                }

                $docNum = trim($docNum);

                if ($docNum === '' || isset($seen[$docNum])) {
                    continue;
                }

                $seen[$docNum] = true;
                $docNums[] = $docNum;
            }

            $this->merge(['related_finished_product_doc_nums' => $docNums]);
        }

        if ($this->filled('image_archive_file_doc_num')) {
            $this->merge(['remove_image' => false]);
        }

        if ($this->has('components') && is_array($this->input('components'))) {
            $components = [];

            foreach ($this->input('components') as $index => $component) {
                if (! is_array($component)) {
                    continue;
                }

                $publicId = $this->trimComponentInputValue($component['public_id'] ?? '');
                $clientKey = $this->trimComponentInputValue($component['client_key'] ?? $publicId);
                $componentProductDocNum = $this->trimComponentInputValue($component['component_product_doc_num'] ?? '');
                $unitDocNum = $this->trimComponentInputValue($component['unit_doc_num'] ?? '');
                $calculationMethod = $this->trimComponentInputValue(
                    $component['calculation_method'] ?? ProductComponent::CalculationDirect,
                );
                $referenceComponentKey = $this->trimComponentInputValue($component['reference_component_key'] ?? '');
                $inputSource = $this->trimComponentInputValue($component['input_source'] ?? '');

                $component = [
                    'public_id' => $publicId === '' ? null : $publicId,
                    'client_key' => $clientKey === '' ? null : $clientKey,
                    'component_product_doc_num' => $componentProductDocNum === '' ? null : $componentProductDocNum,
                    'unit_doc_num' => $unitDocNum === '' ? null : $unitDocNum,
                    'calculation_method' => $calculationMethod === '' || $calculationMethod === null
                        ? ProductComponent::CalculationDirect
                        : $calculationMethod,
                    'quantity' => $this->trimComponentInputValue($component['quantity'] ?? ''),
                    'percentage' => $this->trimComponentInputValue($component['percentage'] ?? ''),
                    'reference_component_key' => $referenceComponentKey === '' ? null : $referenceComponentKey,
                    'input_source' => $inputSource === '' ? null : $inputSource,
                    'notes' => $this->trimComponentInputValue($component['notes'] ?? ''),
                    '_delete' => filter_var($component['_delete'] ?? false, FILTER_VALIDATE_BOOL),
                ];

                if ($this->emptyNewComponentRow($component)) {
                    continue;
                }

                $component['client_key'] ??= (string) Str::uuid();
                $components[$index] = $component;
            }

            $this->merge(['components' => $components]);
        }
    }

    protected function validateProductDocumentNumber(Validator $validator): void
    {
        if (! $this->canControlDocumentNumber()
            || $validator->errors()->has('doc_number')
            || ! $this->hasFilledDocumentNumber()
        ) {
            return;
        }

        $product = $this->productRecord();
        $docNumber = (int) $this->input('doc_number');
        $docNum = app(DocumentNumberService::class)->format($this->documentNumberKey(), $docNumber);
        $query = Product::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $docNum);

        if ($product instanceof Product) {
            $query->whereKeyNot($product->getKey());
        }

        if ($query->exists()) {
            $validator->errors()->add('doc_number', __('products.validation.doc_number_unique'));
        }
    }

    protected function validateProductComponents(Validator $validator): void
    {
        if (Product::isMaterialContext($this->productContext()) || $validator->errors()->has('components')) {
            return;
        }

        $components = $this->componentPayload();
        $product = $this->productRecord();

        foreach ($components as $index => $component) {
            $delete = (bool) ($component['_delete'] ?? false);
            $publicId = $this->componentStringValue($component['public_id'] ?? null) ?: null;

            if ($publicId !== null && $product instanceof Product && ! ($this->componentRecord($product, $publicId) instanceof ProductComponent)) {
                $validator->errors()->add("components.{$index}.public_id", __('products.components.not_available'));

                continue;
            }

            if ($delete) {
                continue;
            }

            $docNum = $this->componentStringValue($component['component_product_doc_num'] ?? null);
            $unitDocNum = $this->componentStringValue($component['unit_doc_num'] ?? null);
            $quantity = $component['quantity'] ?? '';
            $percentage = $component['percentage'] ?? '';
            $method = $this->componentStringValue(
                $component['calculation_method'] ?? ProductComponent::CalculationDirect,
            );
            $inputSource = $this->componentStringValue($component['input_source'] ?? null);

            if ($docNum === '') {
                $validator->errors()->add("components.{$index}.component_product_doc_num", __('products.components.component_item_required'));
            }

            if ($method === ProductComponent::CalculationDirect) {
                $this->validateComponentCalculationValue(
                    $validator,
                    $index,
                    'quantity',
                    $quantity,
                );
            } elseif ($method === ProductComponent::CalculationPercentage) {
                if (blank($component['reference_component_key'] ?? null)) {
                    $validator->errors()->add("components.{$index}.reference_component_key", __('products.components.reference_required'));
                }

                $authoritativeSource = $this->componentAuthoritativeInputSource(
                    $method,
                    $inputSource,
                    $quantity,
                    $percentage,
                );

                if ($authoritativeSource === null) {
                    $validator->errors()->add("components.{$index}.input_source", __('products.components.input_source_required'));
                } else {
                    $field = $authoritativeSource === ProductComponent::InputWeight
                        ? 'quantity'
                        : 'percentage';
                    $this->validateComponentCalculationValue(
                        $validator,
                        $index,
                        $field,
                        $field === 'quantity' ? $quantity : $percentage,
                    );
                }
            }

            if ($docNum === '') {
                continue;
            }

            $componentProduct = $this->componentProductRecord($docNum);

            if (! $componentProduct instanceof Product) {
                $validator->errors()->add("components.{$index}.component_product_doc_num", __('products.components.not_available'));

                continue;
            }

            if ($product instanceof Product && (int) $product->getKey() === (int) $componentProduct->getKey()) {
                $validator->errors()->add("components.{$index}.component_product_doc_num", __('products.components.self_reference'));

                continue;
            }

            if ($unitDocNum === '' && $this->componentUnitIsRequired($componentProduct, $method)) {
                $validator->errors()->add("components.{$index}.unit_doc_num", __('products.components.unit_required'));
            } elseif ($unitDocNum !== '' && ! $this->componentUnitIsValidForProduct($componentProduct, $unitDocNum)) {
                $validator->errors()->add("components.{$index}.unit_doc_num", __('products.components.invalid_unit'));
            }
        }
    }

    protected function validateProductUnitEquivalence(Validator $validator): void
    {
        $unitDocNum = trim((string) $this->input('item_unit_doc_num'));
        $equivalentValue = trim((string) $this->input('equivalent_value'));
        $equivalentUnitDocNum = trim((string) $this->input('equivalent_unit_doc_num'));
        $hasUnit = $unitDocNum !== '';
        $hasEquivalentValue = $equivalentValue !== '';
        $hasEquivalentUnit = $equivalentUnitDocNum !== '';

        if (! $hasUnit && ($hasEquivalentValue || $hasEquivalentUnit) && ! $validator->errors()->has('item_unit_doc_num')) {
            $validator->errors()->add('item_unit_doc_num', __('products.validation.unit_required_for_equivalence'));
        }

        if ($hasEquivalentValue && ! $hasEquivalentUnit && ! $validator->errors()->has('equivalent_unit_doc_num')) {
            $validator->errors()->add('equivalent_unit_doc_num', __('products.validation.equivalent_unit_required'));
        }

        if ($hasEquivalentUnit && ! $hasEquivalentValue && ! $validator->errors()->has('equivalent_value')) {
            $validator->errors()->add('equivalent_value', __('products.validation.equivalent_value_required'));
        }

        if ($hasUnit
            && $hasEquivalentUnit
            && $unitDocNum === $equivalentUnitDocNum
            && ! $validator->errors()->has('item_unit_doc_num')
            && ! $validator->errors()->has('equivalent_unit_doc_num')
        ) {
            $validator->errors()->add('equivalent_unit_doc_num', __('products.validation.equivalent_unit_different'));
        }
    }

    protected function validateProductClassificationContext(Validator $validator): void
    {
        $contextClassification = Product::classificationForContext($this->productContext());

        if ($contextClassification !== null) {
            if ($this->input('item_classification') !== $contextClassification && ! $validator->errors()->has('item_classification')) {
                $validator->errors()->add('item_classification', __($this->productContext() === Product::ContextRawMaterials
                    ? 'products.validation.raw_material_context_required'
                    : 'products.validation.packaging_material_context_required'));
            }

            return;
        }

        if (in_array($this->input('item_classification'), Product::materialClassifications(), true) && ! $validator->errors()->has('item_classification')) {
            $message = $this->input('item_classification') === Product::ClassificationRawMaterial
                ? 'products.validation.raw_material_not_allowed_in_products'
                : 'products.validation.packaging_material_not_allowed_in_products';

            $validator->errors()->add('item_classification', __($message));
        }
    }

    protected function validateRelatedFinishedProducts(Validator $validator): void
    {
        if ($this->productContext() !== Product::ContextPackagingMaterials
            || ! is_array($this->input('related_finished_product_doc_nums'))
            || $this->hasRelatedFinishedProductRuleErrors($validator)
        ) {
            return;
        }

        $docNums = $this->relatedFinishedProductDocNums();

        if ($docNums === []) {
            $this->resolvedRelatedFinishedProductIds = [];

            return;
        }

        $companyId = $this->companyId();
        $existingRelatedProductIds = $this->existingRelatedFinishedProductIds();
        $currentCompanyProducts = Product::withTrashed()
            ->forCompany($companyId)
            ->whereIn('doc_num', $docNums)
            ->get(['id', 'company_id', 'doc_num', 'item_classification', 'status', 'deleted_at'])
            ->keyBy('doc_num');
        $foreignDocNums = Product::withTrashed()
            ->whereIn('doc_num', $docNums)
            ->where('company_id', '!=', $companyId)
            ->pluck('doc_num')
            ->flip()
            ->all();
        $resolvedIds = [];

        foreach ($docNums as $docNum) {
            /** @var Product|null $product */
            $product = $currentCompanyProducts->get($docNum);

            if (! $product instanceof Product) {
                $validator->errors()->add(
                    'related_finished_product_doc_nums',
                    isset($foreignDocNums[$docNum])
                        ? __('products.validation.related_finished_product_company')
                        : __('products.validation.related_finished_product_not_found'),
                );

                continue;
            }

            $isExistingRelation = in_array((int) $product->getKey(), $existingRelatedProductIds, true);

            if ($product->trashed() || $product->status !== 'active') {
                if ($isExistingRelation) {
                    $resolvedIds[] = (int) $product->getKey();

                    continue;
                }

                $validator->errors()->add('related_finished_product_doc_nums', __('products.validation.related_finished_product_unavailable'));

                continue;
            }

            if (in_array($product->item_classification, Product::materialClassifications(), true)) {
                if ($isExistingRelation) {
                    $resolvedIds[] = (int) $product->getKey();

                    continue;
                }

                $validator->errors()->add('related_finished_product_doc_nums', __('products.validation.related_finished_product_material'));

                continue;
            }

            if ($product->item_classification !== Product::ClassificationFinishedProduct) {
                if ($isExistingRelation) {
                    $resolvedIds[] = (int) $product->getKey();

                    continue;
                }

                $validator->errors()->add('related_finished_product_doc_nums', __('products.validation.related_finished_product_invalid'));

                continue;
            }

            $resolvedIds[] = (int) $product->getKey();
        }

        if (! $validator->errors()->has('related_finished_product_doc_nums')) {
            $this->resolvedRelatedFinishedProductIds = array_values(array_unique($resolvedIds));
        }
    }

    protected function validateProductImageSelection(Validator $validator): void
    {
        $publicId = $this->selectedImageFileDocNum();

        if ($publicId === '') {
            if ($this->productImageRequired() && $this->productImageMissingInFinalState()) {
                $validator->errors()->add('image', __('products.validation.image_required'));
            }

            return;
        }

        if (! $this->user()?->can('file_manager.view')) {
            $validator->errors()->add('image', __('products.validation.selected_file_unavailable'));

            return;
        }

        $files = app(FilePickerService::class);
        $file = $files->fileForCompany($publicId, $this->companyId());

        if (! $file instanceof ArchiveFile) {
            $validator->errors()->add('image', __('products.validation.selected_file_unavailable'));

            return;
        }

        if (! $files->isAvailableFile($file)) {
            $validator->errors()->add('image', __('products.validation.selected_file_unavailable'));

            return;
        }

        if ($files->fileHiddenFromPicker($file)) {
            $validator->errors()->add('image', __('products.validation.selected_file_hidden_from_picker'));

            return;
        }

        if (! $files->isImageFile($file)) {
            $validator->errors()->add('image', __('products.validation.selected_file_not_image'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizedProductData(array $data): array
    {
        if (Product::isMaterialContext($this->productContext())) {
            $data['item_classification'] = Product::classificationForContext($this->productContext());
            unset($data['components']);
        }

        if ($this->productContext() === Product::ContextPackagingMaterials
            && array_key_exists('related_finished_product_doc_nums', $data)
        ) {
            $data['related_finished_product_ids'] = $this->resolvedRelatedFinishedProductIds;
        }

        if (! $this->canControlDocumentNumber()) {
            unset($data['doc_number']);
        } elseif (array_key_exists('doc_number', $data) && ($data['doc_number'] === null || $data['doc_number'] === '')) {
            unset($data['doc_number']);
        } elseif (array_key_exists('doc_number', $data)) {
            $data['doc_number'] = (int) $data['doc_number'];
        }

        $data['item_unit_id'] = $this->lookupId(ItemUnit::class, $data['item_unit_doc_num'] ?? null);
        $data['equivalent_unit_id'] = $this->lookupId(ItemUnit::class, $data['equivalent_unit_doc_num'] ?? null);
        $data['item_size_id'] = $this->lookupId(ItemSize::class, $data['item_size_doc_num'] ?? null);
        $data['item_color_id'] = $this->lookupId(ItemColor::class, $data['item_color_doc_num'] ?? null);
        $data['item_decal_id'] = $this->lookupId(ItemDecal::class, $data['item_decal_doc_num'] ?? null);
        $data['item_model_id'] = $this->lookupId(ItemModel::class, $data['item_model_doc_num'] ?? null);
        $data['item_origin_country_id'] = $this->lookupId(ItemOriginCountry::class, $data['item_origin_country_doc_num'] ?? null);
        $data['item_category_id'] = $this->lookupId(ItemCategory::class, $data['item_category_doc_num'] ?? null);
        $data['item_group_id'] = $this->lookupId(ItemGroup::class, $data['item_group_doc_num'] ?? null);

        if (array_key_exists('components', $data) && is_array($data['components'])) {
            $data['components'] = $this->normalizedComponents($data['components']);
        } elseif (array_key_exists('components', $data)) {
            unset($data['components']);
        }

        if ($data['item_unit_id'] === null) {
            $data['equivalent_value'] = null;
            $data['equivalent_unit_id'] = null;
        } else {
            $data['equivalent_value'] = $this->normalizeNullableEquivalenceDecimal($data['equivalent_value'] ?? null);
        }

        unset(
            $data['item_unit_doc_num'],
            $data['equivalent_unit_doc_num'],
            $data['item_size_doc_num'],
            $data['item_color_doc_num'],
            $data['item_decal_doc_num'],
            $data['item_model_doc_num'],
            $data['item_origin_country_doc_num'],
            $data['item_category_doc_num'],
            $data['item_group_doc_num'],
            $data['related_finished_product_doc_nums'],
        );

        foreach (['cost_as_inventory', 'is_displayable'] as $field) {
            if ($this->has($field)) {
                $data[$field] = $this->boolean($field);
            }
        }

        if (array_key_exists('image_archive_file_doc_num', $data)) {
            $data['image_archive_file_doc_num'] = $this->blankToNull($data['image_archive_file_doc_num']);
        }

        if (array_key_exists('remove_image', $data)) {
            $data['remove_image'] = $this->boolean('remove_image');
        }

        $data['notes'] = $this->blankToNull($data['notes'] ?? null);

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('products.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doc_number.regex' => __('products.validation.doc_number_numeric'),
            'doc_number.unique' => __('products.validation.doc_number_unique'),
            'barcode.unique' => __('products.validation.barcode_unique'),
            'reorder_point.regex' => __('products.validation.reorder_point_precision'),
            'equivalent_value.numeric' => __('products.validation.equivalent_value_numeric'),
            'equivalent_value.gt' => __('products.validation.equivalent_value_gt_zero'),
            'equivalent_value.regex' => __('products.validation.equivalent_value_precision'),
            'equivalent_unit_doc_num.exists' => __('products.validation.equivalent_unit_exists'),
            'related_finished_product_doc_nums.array' => __('products.validation.related_finished_products_array'),
            'related_finished_product_doc_nums.*.string' => __('products.validation.related_finished_product_not_found'),
            'components.*.quantity.numeric' => __('products.components.quantity_gt_zero'),
            'components.*.quantity.gt' => __('products.components.quantity_gt_zero'),
            'components.*.quantity.regex' => __('products.components.quantity_precision'),
            'components.*.percentage.numeric' => __('products.components.percentage_gt_zero'),
            'components.*.percentage.gt' => __('products.components.percentage_gt_zero'),
            'components.*.percentage.regex' => __('products.components.percentage_precision'),
        ];
    }

    private function productImageMissingInFinalState(): bool
    {
        $product = $this->productRecord();

        if (! $product instanceof Product) {
            return true;
        }

        if ($this->boolean('remove_image')) {
            return true;
        }

        if (app(ArchiveFileUsageService::class)->recordHasActiveUsage($product, Product::ImageCollection, Product::MainImageRole)) {
            return false;
        }

        return trim((string) $product->image_path) === '';
    }

    private function productImageRequired(): bool
    {
        return (bool) config('products.image_required', false);
    }

    private function selectedImageFileDocNum(): string
    {
        return trim((string) $this->input('image_archive_file_doc_num'));
    }

    private function canControlDocumentNumber(): bool
    {
        return (bool) $this->user()?->can($this->permissionPrefix().'.document_number.control');
    }

    private function hasFilledDocumentNumber(): bool
    {
        $value = $this->input('doc_number');

        return $value !== null && $value !== '';
    }

    /**
     * @param  class-string<ItemLookup>  $modelClass
     */
    private function lookupId(string $modelClass, mixed $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return $modelClass::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $docNum)
            ->where('status', 'active')
            ->value('id');
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function productRecord(): ?Product
    {
        $record = $this->route('product');

        if ($record instanceof Product) {
            return (int) $record->company_id === $this->companyId() ? $record : null;
        }

        $docNum = trim((string) $record);

        if ($docNum === '') {
            return null;
        }

        return Product::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $docNum)
            ->first();
    }

    /**
     * @return list<string>
     */
    private function relatedFinishedProductDocNums(): array
    {
        return collect($this->input('related_finished_product_doc_nums', []))
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function existingRelatedFinishedProductIds(): array
    {
        $source = $this->productRecord();

        if (! $source instanceof Product && $this->filled('clone_source_token')) {
            $sourceDocNum = (string) $this->session()->get(
                'products.clone_sources.'.$this->string('clone_source_token')->trim()->toString(),
                '',
            );

            if ($sourceDocNum !== '') {
                $source = Product::query()
                    ->forCompany($this->companyId())
                    ->packagingMaterials()
                    ->where('doc_num', $sourceDocNum)
                    ->first();
            }
        }

        if (! $source instanceof Product || ! $source->isPackagingMaterial()) {
            return [];
        }

        return $source->relatedFinishedProducts()
            ->withTrashed()
            ->forCompany($this->companyId())
            ->pluck('products.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function hasRelatedFinishedProductRuleErrors(Validator $validator): bool
    {
        return collect($validator->errors()->keys())
            ->contains(fn (string $field): bool => $field === 'related_finished_product_doc_nums'
                || str_starts_with($field, 'related_finished_product_doc_nums.'));
    }

    protected function productRecordMatchesContext(): bool
    {
        $record = $this->productRecord();

        if (! $record instanceof Product) {
            return false;
        }

        return Product::contextForClassification($record->item_classification) === $this->productContext();
    }

    private function productContext(): string
    {
        $routeName = (string) ($this->route()?->getName() ?? '');

        return match (true) {
            str_starts_with($routeName, 'admin.raw-materials.') => Product::ContextRawMaterials,
            str_starts_with($routeName, 'admin.packaging-materials.') => Product::ContextPackagingMaterials,
            default => Product::ContextProducts,
        };
    }

    private function documentNumberKey(): string
    {
        return match ($this->productContext()) {
            Product::ContextRawMaterials => ProductDocumentNumberSettingsService::RawMaterialsKey,
            Product::ContextPackagingMaterials => ProductDocumentNumberSettingsService::PackagingMaterialsKey,
            default => ProductDocumentNumberSettingsService::ProductsKey,
        };
    }

    private function permissionPrefix(): string
    {
        return match ($this->productContext()) {
            Product::ContextRawMaterials => 'raw_materials',
            Product::ContextPackagingMaterials => 'packaging_materials',
            default => 'products',
        };
    }

    /**
     * @return list<string>
     */
    private function allowedClassificationsForContext(): array
    {
        return Product::classificationForContext($this->productContext()) !== null
            ? [Product::classificationForContext($this->productContext())]
            : Product::productItemClassifications();
    }

    private function applyDocumentNumberContext(QueryBuilder $query): QueryBuilder
    {
        $classification = Product::classificationForContext($this->productContext());

        if ($classification !== null) {
            return $query->where('item_classification', $classification);
        }

        return $query->where(function (QueryBuilder $query): void {
            $query
                ->whereNull('item_classification')
                ->orWhereNotIn('item_classification', Product::materialClassifications());
        });
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    private function componentPayload(): array
    {
        $components = $this->input('components', []);

        return is_array($components) ? $components : [];
    }

    /**
     * @param  array<int|string, mixed>  $components
     * @return list<array<string, mixed>>
     */
    private function normalizedComponents(array $components): array
    {
        $normalized = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            if ($this->emptyNewComponentRow($component)) {
                continue;
            }

            $delete = filter_var($component['_delete'] ?? false, FILTER_VALIDATE_BOOL);
            $docNum = trim((string) ($component['component_product_doc_num'] ?? ''));
            $unitDocNum = trim((string) ($component['unit_doc_num'] ?? ''));
            $componentProduct = $delete ? null : $this->componentProductRecord($docNum);
            $unit = $componentProduct instanceof Product && ! $delete
                ? $this->componentUnitRecord($componentProduct, $unitDocNum)
                : null;
            $calculationMethod = trim((string) ($component['calculation_method'] ?? ProductComponent::CalculationDirect))
                ?: ProductComponent::CalculationDirect;
            $inputSource = $delete
                ? null
                : $this->componentAuthoritativeInputSource(
                    $calculationMethod,
                    $component['input_source'] ?? null,
                    $component['quantity'] ?? null,
                    $component['percentage'] ?? null,
                );
            $quantity = ! $delete && $inputSource === ProductComponent::InputWeight
                ? $this->normalizeNullableComponentQuantity($component['quantity'] ?? null)
                : null;
            $percentage = ! $delete
                && $calculationMethod === ProductComponent::CalculationPercentage
                && $inputSource === ProductComponent::InputPercentage
                    ? $this->normalizeNullableComponentQuantity($component['percentage'] ?? null)
                    : null;

            $normalized[] = [
                'public_id' => $this->blankToNull($component['public_id'] ?? null),
                'client_key' => $this->blankToNull($component['client_key'] ?? null)
                    ?? $this->blankToNull($component['public_id'] ?? null)
                    ?? (string) Str::uuid(),
                'component_product_id' => $componentProduct?->getKey(),
                'unit_id' => $unit?->getKey(),
                'calculation_method' => $calculationMethod,
                'quantity' => $quantity,
                'percentage' => $percentage,
                'reference_component_key' => $this->blankToNull($component['reference_component_key'] ?? null),
                'input_source' => $inputSource,
                'notes' => $this->blankToNull($component['notes'] ?? null),
                '_delete' => $delete,
            ];
        }

        return $normalized;
    }

    private function componentProductRecord(string $docNum): ?Product
    {
        $docNum = trim($docNum);

        if ($docNum === '') {
            return null;
        }

        return Product::query()
            ->forCompany($this->companyId())
            ->active()
            ->with(['unit', 'equivalentUnit'])
            ->materialItems()
            ->where('doc_num', $docNum)
            ->first();
    }

    private function componentUnitRecord(Product $componentProduct, mixed $unitDocNum): ?ItemUnit
    {
        return app(ProductComponentUnitOptionsService::class)
            ->unitForProduct($componentProduct, $unitDocNum, $this->companyId());
    }

    private function componentUnitIsValidForProduct(Product $componentProduct, mixed $unitDocNum): bool
    {
        return app(ProductComponentUnitOptionsService::class)
            ->unitIsValidForProduct($componentProduct, $unitDocNum, $this->companyId());
    }

    private function componentUnitIsRequired(Product $componentProduct, string $calculationMethod): bool
    {
        return $calculationMethod === ProductComponent::CalculationPercentage
            || app(ProductComponentUnitOptionsService::class)->options($componentProduct) !== [];
    }

    private function componentAuthoritativeInputSource(
        string $calculationMethod,
        mixed $inputSource,
        mixed $quantity,
        mixed $percentage,
    ): ?string {
        if ($calculationMethod === ProductComponent::CalculationDirect) {
            return ProductComponent::InputWeight;
        }

        $inputSource = $this->componentStringValue($inputSource);

        if (in_array($inputSource, ProductComponent::inputSources(), true)) {
            return $inputSource;
        }

        $hasQuantity = $this->componentCalculationValueIsPresent($quantity);
        $hasPercentage = $this->componentCalculationValueIsPresent($percentage);

        if ($hasQuantity === $hasPercentage) {
            return null;
        }

        return $hasQuantity
            ? ProductComponent::InputWeight
            : ProductComponent::InputPercentage;
    }

    private function componentCalculationValueIsPresent(mixed $value): bool
    {
        return $value !== null && (! is_string($value) || trim($value) !== '');
    }

    private function trimComponentInputValue(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private function componentStringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function validateComponentCalculationValue(
        Validator $validator,
        int|string $index,
        string $field,
        mixed $value,
    ): void {
        $valueValidator = validator(
            ['value' => $value],
            ['value' => ['bail', 'required', 'numeric', 'gt:0', 'regex:/^(?:\d{1,10}|\d{0,10}\.\d{1,8})$/']],
            [
                'value.required' => __($field === 'quantity'
                    ? 'products.components.quantity_required'
                    : 'products.components.percentage_required'),
                'value.numeric' => __($field === 'quantity'
                    ? 'products.components.quantity_gt_zero'
                    : 'products.components.percentage_gt_zero'),
                'value.gt' => __($field === 'quantity'
                    ? 'products.components.quantity_gt_zero'
                    : 'products.components.percentage_gt_zero'),
                'value.regex' => __($field === 'quantity'
                    ? 'products.components.quantity_precision'
                    : 'products.components.percentage_precision'),
            ],
        );

        if (! $valueValidator->fails()) {
            return;
        }

        foreach ($valueValidator->errors()->get('value') as $message) {
            $validator->errors()->add("components.{$index}.{$field}", $message);
        }
    }

    private function componentRecord(Product $product, string $publicId): ?ProductComponent
    {
        return ProductComponent::query()
            ->forCompany($this->companyId())
            ->where('product_id', $product->getKey())
            ->where('public_id', $publicId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function emptyNewComponentRow(array $component): bool
    {
        $calculationMethod = $component['calculation_method'] ?? ProductComponent::CalculationDirect;
        $inputSource = $component['input_source'] ?? null;

        return blank($component['public_id'] ?? null)
            && blank($component['component_product_doc_num'] ?? null)
            && blank($component['unit_doc_num'] ?? null)
            && blank($component['quantity'] ?? null)
            && blank($component['percentage'] ?? null)
            && blank($component['reference_component_key'] ?? null)
            && blank($component['notes'] ?? null)
            && is_string($calculationMethod)
            && in_array(trim($calculationMethod), ['', ProductComponent::CalculationDirect], true)
            && ($inputSource === null
                || (is_string($inputSource)
                    && in_array(trim($inputSource), ['', ProductComponent::InputWeight], true)))
            && ! filter_var($component['_delete'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    private function normalizeNullableDecimal(mixed $value): ?string
    {
        return app(NumericFormatService::class)->normalizeToScale($value, 4);
    }

    private function normalizeNullableComponentQuantity(mixed $value): ?string
    {
        return app(NumericFormatService::class)->normalizeToScale($value, 8);
    }

    private function normalizeNullableEquivalenceDecimal(mixed $value): ?string
    {
        return app(NumericFormatService::class)->normalizeToScale($value, 6);
    }

    private function activeLookupExistsRule(string $table, int $companyId): Exists
    {
        return Rule::exists($table, 'doc_num')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'active');
    }
}

<?php

namespace Modules\Core\Http\Requests\Concerns;

use Illuminate\Database\Query\Builder as QueryBuilder;
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
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Core\Services\ProductDocumentNumberSettingsService;

trait ValidatesProductPayload
{
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
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'item_unit_doc_num' => ['nullable', 'string', $this->activeLookupExistsRule('item_units', $companyId)],
            'equivalent_value' => ['nullable', 'numeric', 'gt:0'],
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
            'components.*.component_product_doc_num' => ['nullable', 'string'],
            'components.*.unit_doc_num' => ['nullable', 'string'],
            'components.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'components.*.notes' => ['nullable', 'string'],
            'components.*._delete' => ['nullable', 'boolean'],
        ];

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

        if ($this->productContext() === Product::ContextRawMaterials) {
            $this->merge(['item_classification' => Product::ClassificationRawMaterial]);
            $this->request->remove('components');
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

                $component = [
                    'public_id' => trim((string) ($component['public_id'] ?? '')) ?: null,
                    'component_product_doc_num' => trim((string) ($component['component_product_doc_num'] ?? '')) ?: null,
                    'unit_doc_num' => trim((string) ($component['unit_doc_num'] ?? '')) ?: null,
                    'quantity' => trim((string) ($component['quantity'] ?? '')),
                    'notes' => trim((string) ($component['notes'] ?? '')),
                    '_delete' => filter_var($component['_delete'] ?? false, FILTER_VALIDATE_BOOL),
                ];

                if ($this->emptyNewComponentRow($component)) {
                    continue;
                }

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
        if ($this->productContext() === Product::ContextRawMaterials || $validator->errors()->has('components')) {
            return;
        }

        $components = $this->componentPayload();
        $product = $this->productRecord();
        $submittedDocNums = [];

        foreach ($components as $index => $component) {
            $delete = (bool) ($component['_delete'] ?? false);
            $publicId = trim((string) ($component['public_id'] ?? '')) ?: null;

            if ($publicId !== null && $product instanceof Product && ! ($this->componentRecord($product, $publicId) instanceof ProductComponent)) {
                $validator->errors()->add("components.{$index}.public_id", __('products.components.not_available'));

                continue;
            }

            if ($delete) {
                continue;
            }

            $docNum = trim((string) ($component['component_product_doc_num'] ?? ''));
            $unitDocNum = trim((string) ($component['unit_doc_num'] ?? ''));
            $quantity = trim((string) ($component['quantity'] ?? ''));

            if ($docNum === '') {
                $validator->errors()->add("components.{$index}.component_product_doc_num", __('products.components.component_item_required'));
            }

            if ($docNum !== '' && $unitDocNum === '') {
                $validator->errors()->add("components.{$index}.unit_doc_num", __('products.components.unit_required'));
            }

            if ($quantity === '') {
                $validator->errors()->add("components.{$index}.quantity", __('products.components.quantity_required'));
            } elseif ((! is_numeric($quantity) || (float) $quantity <= 0) && ! $validator->errors()->has("components.{$index}.quantity")) {
                $validator->errors()->add("components.{$index}.quantity", __('products.components.quantity_gt_zero'));
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

            if ($unitDocNum !== '' && ! $this->componentUnitIsValidForProduct($componentProduct, $unitDocNum)) {
                $validator->errors()->add("components.{$index}.unit_doc_num", __('products.components.invalid_unit'));
            }

            if (isset($submittedDocNums[$docNum])) {
                $validator->errors()->add("components.{$index}.component_product_doc_num", __('products.components.duplicate'));

                continue;
            }

            $submittedDocNums[$docNum] = true;

            if ($product instanceof Product && $this->componentDuplicateExists($product, $componentProduct, $publicId)) {
                $validator->errors()->add("components.{$index}.component_product_doc_num", __('products.components.duplicate'));
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
        if ($this->productContext() === Product::ContextRawMaterials) {
            if ($this->input('item_classification') !== Product::ClassificationRawMaterial && ! $validator->errors()->has('item_classification')) {
                $validator->errors()->add('item_classification', __('products.validation.raw_material_context_required'));
            }

            return;
        }

        if ($this->input('item_classification') === Product::ClassificationRawMaterial && ! $validator->errors()->has('item_classification')) {
            $validator->errors()->add('item_classification', __('products.validation.raw_material_not_allowed_in_products'));
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
        if ($this->productContext() === Product::ContextRawMaterials) {
            $data['item_classification'] = Product::ClassificationRawMaterial;
            unset($data['components']);
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

        if (array_key_exists('components', $data)) {
            $data['components'] = $this->normalizedComponents($data['components']);
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
        );

        foreach (['cost_as_inventory', 'is_displayable'] as $field) {
            $data[$field] = $this->boolean($field);
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
            'equivalent_value.numeric' => __('products.validation.equivalent_value_numeric'),
            'equivalent_value.gt' => __('products.validation.equivalent_value_gt_zero'),
            'equivalent_unit_doc_num.exists' => __('products.validation.equivalent_unit_exists'),
            'components.*.quantity.numeric' => __('products.components.quantity_gt_zero'),
            'components.*.quantity.gt' => __('products.components.quantity_gt_zero'),
            'components.*.quantity.regex' => __('products.components.quantity_precision'),
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

    protected function productRecordMatchesContext(): bool
    {
        $record = $this->productRecord();

        if (! $record instanceof Product) {
            return false;
        }

        return $this->productContext() === Product::ContextRawMaterials
            ? $record->isRawMaterial()
            : ! $record->isRawMaterial();
    }

    private function productContext(): string
    {
        $routeName = (string) ($this->route()?->getName() ?? '');

        return str_starts_with($routeName, 'admin.raw-materials.')
            ? Product::ContextRawMaterials
            : Product::ContextProducts;
    }

    private function documentNumberKey(): string
    {
        return $this->productContext() === Product::ContextRawMaterials
            ? ProductDocumentNumberSettingsService::RawMaterialsKey
            : ProductDocumentNumberSettingsService::ProductsKey;
    }

    private function permissionPrefix(): string
    {
        return $this->productContext() === Product::ContextRawMaterials
            ? 'raw_materials'
            : 'products';
    }

    /**
     * @return list<string>
     */
    private function allowedClassificationsForContext(): array
    {
        return $this->productContext() === Product::ContextRawMaterials
            ? [Product::ClassificationRawMaterial]
            : Product::itemClassifications();
    }

    private function applyDocumentNumberContext(QueryBuilder $query): QueryBuilder
    {
        if ($this->productContext() === Product::ContextRawMaterials) {
            return $query->where('item_classification', Product::ClassificationRawMaterial);
        }

        return $query->where(function (QueryBuilder $query): void {
            $query
                ->whereNull('item_classification')
                ->orWhere('item_classification', '<>', Product::ClassificationRawMaterial);
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

            $normalized[] = [
                'public_id' => $this->blankToNull($component['public_id'] ?? null),
                'component_product_id' => $componentProduct?->getKey(),
                'unit_id' => $unit?->getKey(),
                'quantity' => $delete ? null : $this->normalizeNullableComponentQuantity($component['quantity'] ?? null),
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
            ->rawMaterials()
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

    private function componentRecord(Product $product, string $publicId): ?ProductComponent
    {
        return ProductComponent::query()
            ->forCompany($this->companyId())
            ->where('product_id', $product->getKey())
            ->where('public_id', $publicId)
            ->first();
    }

    private function componentDuplicateExists(Product $product, Product $componentProduct, ?string $publicId): bool
    {
        $query = ProductComponent::query()
            ->where('product_id', $product->getKey())
            ->where('component_product_id', $componentProduct->getKey());

        if ($publicId !== null) {
            $component = $this->componentRecord($product, $publicId);

            if ($component instanceof ProductComponent) {
                $query->whereKeyNot($component->getKey());
            }
        }

        return $query->exists();
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function emptyNewComponentRow(array $component): bool
    {
        return blank($component['public_id'] ?? null)
            && blank($component['component_product_doc_num'] ?? null)
            && blank($component['unit_doc_num'] ?? null)
            && blank($component['quantity'] ?? null)
            && blank($component['notes'] ?? null)
            && ! filter_var($component['_delete'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    private function normalizeNullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function normalizeNullableComponentQuantity(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    private function normalizeNullableEquivalenceDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 6, '.', '');
    }

    private function activeLookupExistsRule(string $table, int $companyId): Exists
    {
        return Rule::exists($table, 'doc_num')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'active');
    }
}

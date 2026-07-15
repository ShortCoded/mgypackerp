<?php

namespace Modules\Core\Services;

use Closure;
use DomainException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

class ProductService
{
    /**
     * @var list<string>
     */
    private array $fillableFields = [
        'name',
        'image_path',
        'barcode',
        'item_classification',
        'reorder_point',
        'item_unit_id',
        'equivalent_value',
        'equivalent_unit_id',
        'item_size_id',
        'item_color_id',
        'item_decal_id',
        'item_model_id',
        'item_origin_country_id',
        'item_category_id',
        'item_group_id',
        'cost_as_inventory',
        'is_displayable',
        'status',
        'notes',
    ];

    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly FilePickerService $filePicker,
        private readonly ArchiveFileUsageService $fileUsages,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: Product}
     */
    public function create(array $data, ?Product $cloneSource = null): array
    {
        return DB::transaction(function () use ($data, $cloneSource): array {
            $companyId = $this->companyContext->requireCompanyId();
            $documentKey = $this->documentNumberKeyForData($data);
            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber((int) $data['doc_number'], $documentKey)
                : $this->documentNumberService->nextForCompany($documentKey, Product::class, $companyId, $this->documentNumberScope($documentKey));

            $values = $this->normalizedValues($data);
            $selectedImageFile = null;

            if (! empty($data['image_archive_file_doc_num'])) {
                $selectedImageFile = $this->selectedArchiveImageFile((string) $data['image_archive_file_doc_num'], $companyId);
                $values['image_path'] = (string) $selectedImageFile->path;
            }

            $record = Product::query()->create([
                'company_id' => $companyId,
                ...$values,
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => auth()->id(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($record);

            if (array_key_exists('components', $data)) {
                $this->syncComponents($record, $data);
            } elseif ($cloneSource instanceof Product && ! $record->isRawMaterial()) {
                $this->cloneComponents($record, $cloneSource);
            }

            if ($selectedImageFile instanceof ArchiveFile) {
                $this->fileUsages->replaceFileForRecord($selectedImageFile, $record, Product::ImageCollection, Product::MainImageRole);
            }

            return ['record' => $record->refresh()->load(['equivalentUnit', 'components', 'mainImageUsage.file'])];
        });
    }

    private function cloneComponents(Product $record, Product $cloneSource): void
    {
        $this->assertRecordBelongsToCurrentCompany($cloneSource);

        ProductComponent::query()
            ->where('company_id', $cloneSource->company_id)
            ->where('product_id', $cloneSource->getKey())
            ->orderBy('created_at')
            ->get([
                'component_product_id',
                'unit_id',
                'quantity',
                'notes',
            ])
            ->each(function (ProductComponent $component) use ($record): void {
                $created = ProductComponent::query()->create([
                    'company_id' => $record->company_id,
                    'product_id' => $record->getKey(),
                    'component_product_id' => $component->component_product_id,
                    'unit_id' => $component->unit_id,
                    'quantity' => $this->normalizeNullableComponentQuantity($component->quantity) ?? '0.00000000',
                    'notes' => $this->normalizeNullableString($component->notes),
                    'created_by' => auth()->id(),
                ]);

                $this->crudAudit->clearCreationUpdateAudit($created);
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: Product, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null}
     */
    public function update(Product $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $this->assertRecordBelongsToCurrentCompany($record);
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $newValues = $this->normalizedValues($data);
            $selectedImageFile = null;
            $documentKey = $this->documentNumberKeyForRecord($record);

            if (array_key_exists('doc_number', $data)) {
                $newValues['doc_number'] = (int) $data['doc_number'];
                $newValues['doc_num'] = $this->documentNumberService->format($documentKey, (int) $data['doc_number']);
            }

            if (! empty($data['image_archive_file_doc_num'])) {
                $selectedImageFile = $this->selectedArchiveImageFile((string) $data['image_archive_file_doc_num'], (int) $record->company_id);
                $newValues['image_path'] = (string) $selectedImageFile->path;
            } elseif (($data['remove_image'] ?? false) === true) {
                $newValues['image_path'] = null;
            }

            $changes = $this->changedValues($record, $newValues);
            $usageChangeNeeded = $selectedImageFile instanceof ArchiveFile
                && ! $this->fileUsages->recordUsesFile($record, $selectedImageFile, Product::ImageCollection, Product::MainImageRole);

            if ($usageChangeNeeded && ! array_key_exists('image_path', $changes)) {
                $changes['image_path'] = [
                    'old' => $record->image_path ? 'image_present' : null,
                    'new' => 'image_updated',
                ];
            }

            $componentChanges = $this->componentChanges($record, $data);

            if ($componentChanges !== null) {
                $changes['components'] = $componentChanges;
            }

            $changedFields = collect(array_keys($changes))
                ->map(fn (string $field): string => $field === 'doc_num' ? 'doc_number' : $field)
                ->unique()
                ->values()
                ->all();

            if ($changedFields === []) {
                return [
                    'record' => $record->refresh()->load(['equivalentUnit', 'components', 'mainImageUsage.file']),
                    'changed' => false,
                    'changed_fields' => [],
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                ];
            }

            $this->crudAudit->saveUpdate($record, $newValues);
            $this->syncComponents($record, $data);

            if ($selectedImageFile instanceof ArchiveFile) {
                $this->fileUsages->replaceFileForRecord($selectedImageFile, $record, Product::ImageCollection, Product::MainImageRole);
            } elseif (($data['remove_image'] ?? false) === true) {
                $this->fileUsages->detachUsage($record, Product::ImageCollection, Product::MainImageRole);
            }

            return [
                'record' => $record->refresh()->load(['equivalentUnit', 'components', 'mainImageUsage.file']),
                'changed' => true,
                'changed_fields' => $changedFields,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(Product $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->assertRecordBelongsToCurrentCompany($record);
            $this->crudAudit->softDelete($record);
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums, string $context = Product::ContextProducts): int
    {
        return DB::transaction(function () use ($docNums, $context): int {
            $records = Product::query()
                ->forCompany($this->companyContext->requireCompanyId())
                ->forProductContext($context)
                ->whereIn('doc_num', $docNums)
                ->get();
            $deleted = 0;

            foreach ($records as $record) {
                $this->crudAudit->softDelete($record);
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(Product $record): Product
    {
        return DB::transaction(function () use ($record): Product {
            $record = Product::withTrashed()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRecordBelongsToCurrentCompany($record);

            if (! $record->trashed()) {
                throw new DomainException(__('products.messages.restore_not_allowed'));
            }

            if (Product::query()
                ->forCompany((int) $record->company_id)
                ->where('doc_num', $record->doc_num)
                ->whereKeyNot($record->getKey())
                ->exists()
                || $this->sameClassificationDocNumberExists($record)
                || ($record->barcode !== null && Product::query()
                    ->forCompany((int) $record->company_id)
                    ->where('barcode', $record->barcode)
                    ->whereKeyNot($record->getKey())
                    ->exists())) {
                throw new DomainException(__('products.messages.restore_conflict'));
            }

            $this->crudAudit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    private function sameClassificationDocNumberExists(Product $record): bool
    {
        $query = Product::query()
            ->forCompany((int) $record->company_id)
            ->where('doc_number', $record->doc_number)
            ->whereKeyNot($record->getKey());

        if ($record->isRawMaterial()) {
            $query->rawMaterials();
        } else {
            $query->withoutRawMaterials();
        }

        return $query->exists();
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function manualDocumentNumber(int $docNumber, string $documentKey): array
    {
        return [
            'doc_number' => $docNumber,
            'doc_num' => $this->documentNumberService->format($documentKey, $docNumber),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function documentNumberKeyForData(array $data): string
    {
        return $this->documentNumberKeyForContext(Product::contextForClassification((string) ($data['item_classification'] ?? '')));
    }

    private function documentNumberKeyForRecord(Product $record): string
    {
        return $this->documentNumberKeyForContext(Product::contextForClassification($record->item_classification));
    }

    private function documentNumberKeyForContext(string $context): string
    {
        return $context === Product::ContextRawMaterials
            ? ProductDocumentNumberSettingsService::RawMaterialsKey
            : ProductDocumentNumberSettingsService::ProductsKey;
    }

    /**
     * @return Closure(QueryBuilder): void
     */
    private function documentNumberScope(string $documentKey): Closure
    {
        return function (QueryBuilder $query) use ($documentKey): void {
            if ($documentKey === ProductDocumentNumberSettingsService::RawMaterialsKey) {
                $query->where('item_classification', Product::ClassificationRawMaterial);

                return;
            }

            $query->where(function (QueryBuilder $query): void {
                $query
                    ->whereNull('item_classification')
                    ->orWhere('item_classification', '<>', Product::ClassificationRawMaterial);
            });
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedValues(array $data): array
    {
        $values = [];

        foreach ($this->fillableFields as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = $this->normalizeValue($field, $data[$field]);
            }
        }

        return $values;
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'name', 'status' => trim((string) $value),
            'barcode' => $this->normalizeNullableString($value),
            'item_classification' => trim((string) ($value ?: Product::ClassificationFinishedProduct)),
            'reorder_point' => $this->normalizeNullableDecimal($value),
            'equivalent_value' => $this->normalizeNullableEquivalenceDecimal($value),
            'cost_as_inventory', 'is_displayable' => (bool) $value,
            'item_unit_id', 'equivalent_unit_id', 'item_size_id', 'item_color_id', 'item_decal_id', 'item_model_id', 'item_origin_country_id', 'item_category_id', 'item_group_id' => $value === null ? null : (int) $value,
            'image_path' => $value === null ? null : trim((string) $value),
            default => $this->normalizeNullableString($value),
        };
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(Product $record, array $newValues): array
    {
        $changes = [];

        foreach ($newValues as $field => $value) {
            $current = $record->{$field};
            $rawCurrent = $current;
            $rawValue = $value;

            if ($this->isLookupField($field)) {
                $currentId = $rawCurrent === null ? null : (int) $rawCurrent;
                $newId = $rawValue === null ? null : (int) $rawValue;

                if ($currentId !== $newId) {
                    $changes[$this->lookupChangeField($field)] = [
                        'old' => $this->lookupChangeValue($field, $currentId),
                        'new' => $this->lookupChangeValue($field, $newId),
                    ];
                }

                continue;
            }

            if ($current instanceof \DateTimeInterface) {
                $current = $current->format('Y-m-d H:i:s');
            }

            if ($current instanceof ItemLookup) {
                $current = $current->getKey();
            }

            if ($field === 'image_path') {
                if ((string) ($rawCurrent ?? '') !== (string) ($rawValue ?? '')) {
                    $changes[$field] = [
                        'old' => $rawCurrent ? 'image_present' : null,
                        'new' => $rawValue ? 'image_updated' : null,
                    ];
                }

                continue;
            }

            if ($field === 'reorder_point') {
                $currentDecimal = $this->normalizeNullableDecimal($current);
                $newDecimal = $this->normalizeNullableDecimal($value);

                if ($currentDecimal !== $newDecimal) {
                    $changes[$field] = [
                        'old' => $currentDecimal,
                        'new' => $newDecimal,
                    ];
                }

                continue;
            }

            if ($field === 'equivalent_value') {
                $currentDecimal = $this->normalizeNullableEquivalenceDecimal($current);
                $newDecimal = $this->normalizeNullableEquivalenceDecimal($value);

                if ($currentDecimal !== $newDecimal) {
                    $changes[$field] = [
                        'old' => $currentDecimal,
                        'new' => $newDecimal,
                    ];
                }

                continue;
            }

            if ($field === 'item_classification') {
                $currentLabel = $this->classificationChangeValue($current);
                $newLabel = $this->classificationChangeValue($value);

                if ($currentLabel !== $newLabel) {
                    $changes[$field] = [
                        'old' => $currentLabel,
                        'new' => $newLabel,
                    ];
                }

                continue;
            }

            if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                $changes[$field] = [
                    'old' => $current,
                    'new' => $value,
                ];
            }
        }

        return $changes;
    }

    private function isLookupField(string $field): bool
    {
        return array_key_exists($field, $this->lookupFieldModels());
    }

    private function lookupChangeValue(string $field, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $modelClass = $this->lookupFieldModels()[$field] ?? null;

        if ($modelClass === null) {
            return null;
        }

        /** @var ItemLookup|null $record */
        $record = $modelClass::withTrashed()->find($id);

        return $record ? trim(implode(' / ', array_filter([$record->doc_num, $record->name]))) : null;
    }

    private function lookupChangeField(string $field): string
    {
        return match ($field) {
            'item_unit_id' => 'unit',
            'equivalent_unit_id' => 'equivalent_unit',
            'item_size_id' => 'size',
            'item_color_id' => 'color',
            'item_decal_id' => 'decal',
            'item_model_id' => 'model',
            'item_origin_country_id' => 'origin_country',
            'item_category_id' => 'category',
            'item_group_id' => 'group',
            default => $field,
        };
    }

    /**
     * @return array<string, class-string<ItemLookup>>
     */
    private function lookupFieldModels(): array
    {
        return [
            'item_unit_id' => ItemUnit::class,
            'equivalent_unit_id' => ItemUnit::class,
            'item_size_id' => ItemSize::class,
            'item_color_id' => ItemColor::class,
            'item_decal_id' => ItemDecal::class,
            'item_model_id' => ItemModel::class,
            'item_origin_country_id' => ItemOriginCountry::class,
            'item_category_id' => ItemCategory::class,
            'item_group_id' => ItemGroup::class,
        ];
    }

    private function classificationChangeValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return (string) __("products.classifications.{$value}");
    }

    private function normalizeDecimal(mixed $value): string
    {
        return $this->normalizeNullableComponentQuantity($value) ?? '0.00000000';
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

    /**
     * @param  array<string, mixed>  $data
     * @return array{old: list<array<string, mixed>>, new: list<array<string, mixed>>}|null
     */
    private function componentChanges(Product $record, array $data): ?array
    {
        if (! array_key_exists('components', $data)) {
            return null;
        }

        $old = $this->currentComponents($record);
        $new = $this->submittedComponents($data);

        if ($old === $new) {
            return null;
        }

        return [
            'old' => $old,
            'new' => $new,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncComponents(Product $record, array $data): void
    {
        if (! array_key_exists('components', $data) || ! is_array($data['components'])) {
            return;
        }

        foreach ($data['components'] as $componentData) {
            if (! is_array($componentData)) {
                continue;
            }

            $publicId = $this->normalizeNullableString($componentData['public_id'] ?? null);
            $delete = (bool) ($componentData['_delete'] ?? false);
            $component = $publicId === null ? null : $this->componentByPublicId($record, $publicId);

            if ($delete) {
                if ($component instanceof ProductComponent && ! $component->trashed()) {
                    $this->crudAudit->softDelete($component);
                }

                continue;
            }

            $values = [
                'component_product_id' => (int) $componentData['component_product_id'],
                'unit_id' => $componentData['unit_id'] === null ? null : (int) $componentData['unit_id'],
                'quantity' => $this->normalizeDecimal($componentData['quantity'] ?? null),
                'notes' => $this->normalizeNullableString($componentData['notes'] ?? null),
            ];

            if ($component instanceof ProductComponent) {
                $this->crudAudit->saveUpdate($component, $values);

                continue;
            }

            $created = ProductComponent::query()->create([
                'company_id' => $record->company_id,
                'product_id' => $record->getKey(),
                ...$values,
                'created_by' => auth()->id(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($created);
        }
    }

    private function componentByPublicId(Product $record, string $publicId): ?ProductComponent
    {
        return ProductComponent::query()
            ->where('company_id', $record->company_id)
            ->where('product_id', $record->getKey())
            ->where('public_id', $publicId)
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currentComponents(Product $record): array
    {
        return ProductComponent::query()
            ->where('company_id', $record->company_id)
            ->where('product_id', $record->getKey())
            ->with(['componentProduct', 'unit'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (ProductComponent $component): array => [
                'public_id' => $component->public_id,
                'raw_material' => $this->productChangeLabel($component->componentProduct),
                'unit' => $this->unitChangeLabel($component->unit),
                'quantity' => $this->normalizeDecimal($component->quantity),
                'notes' => $component->notes,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function submittedComponents(array $data): array
    {
        $components = $data['components'] ?? [];

        if (! is_array($components)) {
            return [];
        }

        return collect($components)
            ->filter(fn (mixed $component): bool => is_array($component) && ! (bool) ($component['_delete'] ?? false))
            ->map(function (array $component): array {
                $componentProduct = isset($component['component_product_id'])
                    ? Product::withTrashed()->find($component['component_product_id'])
                    : null;
                $unit = isset($component['unit_id'])
                    ? ItemUnit::withTrashed()->find($component['unit_id'])
                    : null;

                return [
                    'public_id' => $this->normalizeNullableString($component['public_id'] ?? null),
                    'raw_material' => $this->productChangeLabel($componentProduct),
                    'unit' => $this->unitChangeLabel($unit),
                    'quantity' => $this->normalizeDecimal($component['quantity'] ?? null),
                    'notes' => $this->normalizeNullableString($component['notes'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    private function productChangeLabel(?Product $product): ?string
    {
        return $product ? trim(implode(' / ', array_filter([$product->doc_num, $product->name]))) : null;
    }

    private function unitChangeLabel(?ItemUnit $unit): ?string
    {
        return $unit ? trim(implode(' / ', array_filter([$unit->doc_num, $unit->name]))) : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function selectedArchiveImageFile(string $publicId, int $companyId): ArchiveFile
    {
        $file = $this->filePicker->selectableFileByPublicId($publicId, $companyId, FilePickerService::AcceptImage);

        if (! $file instanceof ArchiveFile) {
            throw ValidationException::withMessages([
                'image' => __('products.validation.selected_file_unavailable'),
            ]);
        }

        return $file;
    }

    private function assertRecordBelongsToCurrentCompany(Product $record): void
    {
        abort_unless((int) $record->company_id === $this->companyContext->requireCompanyId(), 404);
    }
}

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
        'tracks_expiry',
        'default_shelf_life_days',
        'status',
        'notes',
    ];

    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly FilePickerService $filePicker,
        private readonly ArchiveFileUsageService $fileUsages,
        private readonly NumericFormatService $numbers,
        private readonly ProductBomService $bom,
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

            if ($cloneSource instanceof Product && ! array_key_exists('cost_as_inventory', $data)) {
                $values['cost_as_inventory'] = (bool) $cloneSource->cost_as_inventory;
            }

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
            } elseif ($cloneSource instanceof Product && ! $record->isMaterial()) {
                $this->cloneComponents($record, $cloneSource);
            }

            if ($record->isPackagingMaterial()) {
                if (array_key_exists('related_finished_product_ids', $data)) {
                    $this->syncRelatedFinishedProducts($record, $data);
                } elseif ($cloneSource instanceof Product) {
                    $this->cloneRelatedFinishedProducts($record, $cloneSource);
                }
            }

            if ($selectedImageFile instanceof ArchiveFile) {
                $this->fileUsages->replaceFileForRecord($selectedImageFile, $record, Product::ImageCollection, Product::MainImageRole);
            }

            return ['record' => $record->refresh()->load(['equivalentUnit', 'components', 'mainImageUsage.file', 'relatedFinishedProducts'])];
        });
    }

    private function cloneComponents(Product $record, Product $cloneSource): void
    {
        $this->assertRecordBelongsToCurrentCompany($cloneSource);
        $this->bom->clone($record, $cloneSource);
    }

    private function cloneRelatedFinishedProducts(Product $record, Product $cloneSource): void
    {
        $this->assertRecordBelongsToCurrentCompany($cloneSource);

        $record->relatedFinishedProducts()->sync(
            $cloneSource->relatedFinishedProducts()
                ->withTrashed()
                ->forCompany((int) $record->company_id)
                ->pluck('products.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
        );
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

            if (isset($data['components']) && is_array($data['components'])) {
                $oldComponents = $this->currentComponents($record);
                $this->syncComponents($record, $data);
                $newComponents = $this->currentComponents($record);

                if ($oldComponents !== $newComponents) {
                    $changes['components'] = [
                        'old' => $oldComponents,
                        'new' => $newComponents,
                    ];
                }
            }

            if ($record->isPackagingMaterial() && array_key_exists('related_finished_product_ids', $data)) {
                $oldRelatedFinishedProducts = $this->currentRelatedFinishedProducts($record);
                $this->syncRelatedFinishedProducts($record, $data);
                $newRelatedFinishedProducts = $this->currentRelatedFinishedProducts($record);

                if ($oldRelatedFinishedProducts !== $newRelatedFinishedProducts) {
                    $changes['related_finished_products'] = [
                        'old' => $oldRelatedFinishedProducts,
                        'new' => $newRelatedFinishedProducts,
                    ];
                }
            }

            $changedFields = collect(array_keys($changes))
                ->map(fn (string $field): string => $field === 'doc_num' ? 'doc_number' : $field)
                ->unique()
                ->values()
                ->all();

            if ($changedFields === []) {
                return [
                    'record' => $record->refresh()->load(['equivalentUnit', 'components', 'mainImageUsage.file', 'relatedFinishedProducts']),
                    'changed' => false,
                    'changed_fields' => [],
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                ];
            }

            $this->crudAudit->saveUpdate($record, $newValues);

            if ($selectedImageFile instanceof ArchiveFile) {
                $this->fileUsages->replaceFileForRecord($selectedImageFile, $record, Product::ImageCollection, Product::MainImageRole);
            } elseif (($data['remove_image'] ?? false) === true) {
                $this->fileUsages->detachUsage($record, Product::ImageCollection, Product::MainImageRole);
            }

            return [
                'record' => $record->refresh()->load(['equivalentUnit', 'components', 'mainImageUsage.file', 'relatedFinishedProducts']),
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
            $this->assertNotUsedByActiveSalesDocuments($record);
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
                $this->assertNotUsedByActiveSalesDocuments($record);
                $this->crudAudit->softDelete($record);
                $deleted++;
            }

            return $deleted;
        });
    }

    private function assertNotUsedByActiveSalesDocuments(Product $record): void
    {
        $quotation = DB::table('quotation_revision_lines as lines')
            ->join('quotation_revisions as revisions', 'revisions.id', '=', 'lines.quotation_revision_id')
            ->join('quotations', 'quotations.id', '=', 'revisions.quotation_id')
            ->where('lines.product_id', $record->getKey())
            ->whereNull('quotations.deleted_at')
            ->whereIn('quotations.status', ['draft', 'sent', 'under_review', 'accepted'])
            ->value('quotations.doc_num');

        $salesRequest = DB::table('sales_request_lines as lines')
            ->join('sales_requests as documents', 'documents.id', '=', 'lines.sales_request_id')
            ->where('lines.product_id', $record->getKey())
            ->whereNull('documents.deleted_at')
            ->whereIn('documents.status', ['draft', 'submitted', 'approved', 'partially_converted'])
            ->value('documents.doc_num');

        $salesOrder = DB::table('sales_order_lines as lines')
            ->join('sales_orders as documents', 'documents.id', '=', 'lines.sales_order_id')
            ->where('lines.product_id', $record->getKey())
            ->whereNull('documents.deleted_at')
            ->whereNotIn('documents.status', ['cancelled', 'closed'])
            ->value('documents.doc_num');

        $document = $quotation ?? $salesRequest ?? $salesOrder;

        if ($document !== null) {
            throw new DomainException(__('products.messages.active_document_delete_blocked', ['document' => $document]));
        }
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

        match (Product::contextForClassification($record->item_classification)) {
            Product::ContextRawMaterials => $query->rawMaterials(),
            Product::ContextPackagingMaterials => $query->packagingMaterials(),
            default => $query->productItems(),
        };

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
        return match ($context) {
            Product::ContextRawMaterials => ProductDocumentNumberSettingsService::RawMaterialsKey,
            Product::ContextPackagingMaterials => ProductDocumentNumberSettingsService::PackagingMaterialsKey,
            default => ProductDocumentNumberSettingsService::ProductsKey,
        };
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

            if ($documentKey === ProductDocumentNumberSettingsService::PackagingMaterialsKey) {
                $query->where('item_classification', Product::ClassificationPackaging);

                return;
            }

            $query->where(function (QueryBuilder $query): void {
                $query
                    ->whereNull('item_classification')
                    ->orWhereNotIn('item_classification', Product::materialClassifications());
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
            'cost_as_inventory', 'is_displayable', 'tracks_expiry' => (bool) $value,
            'default_shelf_life_days' => $value === null || $value === '' ? null : (int) $value,
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
        return $this->numbers->normalizeToScale($value, 4);
    }

    private function normalizeNullableComponentQuantity(mixed $value): ?string
    {
        return $this->numbers->normalizeToScale($value, 8);
    }

    private function normalizeNullableEquivalenceDecimal(mixed $value): ?string
    {
        return $this->numbers->normalizeToScale($value, 6);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncComponents(Product $record, array $data): void
    {
        if (! array_key_exists('components', $data) || ! is_array($data['components'])) {
            return;
        }
        $this->bom->sync($record, $data['components']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncRelatedFinishedProducts(Product $record, array $data): void
    {
        $ids = collect($data['related_finished_product_ids'] ?? [])
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $record->relatedFinishedProducts()->sync($ids);
    }

    /**
     * @return list<string>
     */
    private function currentRelatedFinishedProducts(Product $record): array
    {
        return $record->relatedFinishedProducts()
            ->withTrashed()
            ->forCompany((int) $record->company_id)
            ->orderBy('products.doc_number')
            ->orderBy('products.id')
            ->get(['products.doc_num', 'products.name'])
            ->map(fn (Product $product): string => $this->relatedFinishedProductChangeLabel($product))
            ->values()
            ->all();
    }

    private function relatedFinishedProductChangeLabel(Product $product): string
    {
        return trim(implode(' — ', array_filter([$product->doc_num, $product->name])));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currentComponents(Product $record): array
    {
        return ProductComponent::query()
            ->where('company_id', $record->company_id)
            ->where('product_id', $record->getKey())
            ->with(['componentProduct', 'unit', 'referenceComponent'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductComponent $component): array => [
                'public_id' => $component->public_id,
                'raw_material' => $this->productChangeLabel($component->componentProduct),
                'unit' => $this->unitChangeLabel($component->unit),
                'calculation_method' => $component->calculation_method,
                'quantity' => $this->normalizeDecimal($component->quantity),
                'percentage' => $component->percentage === null
                    ? null
                    : $this->normalizeDecimal($component->percentage),
                'reference_component' => $component->referenceComponent?->public_id,
                'notes' => $component->notes,
            ])
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

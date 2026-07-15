<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;

class ProductComponentService
{
    /**
     * @var list<string>
     */
    private array $fillableFields = [
        'component_product_id',
        'unit_id',
        'quantity',
        'notes',
    ];

    public function __construct(
        private readonly CrudAuditService $crudAudit,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Product $product, array $data): ProductComponent
    {
        return DB::transaction(function () use ($product, $data): ProductComponent {
            $this->assertProductBelongsToCurrentCompany($product);
            $values = $this->normalizedValues($data);

            /** @var ProductComponent $component */
            $component = ProductComponent::query()->create([
                'company_id' => $product->company_id,
                'product_id' => $product->getKey(),
                ...$values,
                'created_by' => auth()->id(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($component);

            return $component->refresh()->loadMissing(['componentProduct.unit', 'componentProduct.equivalentUnit', 'unit']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: ProductComponent, changed: bool, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function update(Product $product, ProductComponent $component, array $data): array
    {
        return DB::transaction(function () use ($product, $component, $data): array {
            $this->assertProductBelongsToCurrentCompany($product);
            $this->assertComponentBelongsToProduct($product, $component);

            $newValues = $this->normalizedValues($data);
            $changes = $this->changedValues($component, $newValues);

            if ($changes === []) {
                return [
                    'record' => $component->refresh()->loadMissing(['componentProduct.unit', 'componentProduct.equivalentUnit', 'unit']),
                    'changed' => false,
                    'changes' => [],
                ];
            }

            $this->crudAudit->saveUpdate($component, $newValues);

            return [
                'record' => $component->refresh()->loadMissing(['componentProduct.unit', 'componentProduct.equivalentUnit', 'unit']),
                'changed' => true,
                'changes' => $changes,
            ];
        });
    }

    public function delete(Product $product, ProductComponent $component): void
    {
        DB::transaction(function () use ($product, $component): void {
            $this->assertProductBelongsToCurrentCompany($product);
            $this->assertComponentBelongsToProduct($product, $component);

            $this->crudAudit->softDelete($component);
        });
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
            'component_product_id', 'unit_id' => $value === null ? null : (int) $value,
            'quantity' => number_format((float) $value, 8, '.', ''),
            default => $this->normalizeNullableString($value),
        };
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(ProductComponent $component, array $newValues): array
    {
        $changes = [];

        foreach ($newValues as $field => $value) {
            if ($field === 'quantity') {
                $current = number_format((float) $component->{$field}, 8, '.', '');
                $new = number_format((float) $value, 8, '.', '');

                if ($current !== $new) {
                    $changes[$field] = ['old' => $current, 'new' => $new];
                }

                continue;
            }

            if ($field === 'component_product_id') {
                $currentId = $component->{$field} === null ? null : (int) $component->{$field};
                $newId = $value === null ? null : (int) $value;

                if ($currentId !== $newId) {
                    $changes['raw_material'] = [
                        'old' => $this->productLabel($currentId),
                        'new' => $this->productLabel($newId),
                    ];
                }

                continue;
            }

            if ($field === 'unit_id') {
                $currentId = $component->{$field} === null ? null : (int) $component->{$field};
                $newId = $value === null ? null : (int) $value;

                if ($currentId !== $newId) {
                    $changes['unit'] = [
                        'old' => $this->unitLabel($currentId),
                        'new' => $this->unitLabel($newId),
                    ];
                }

                continue;
            }

            if ((string) ($component->{$field} ?? '') !== (string) ($value ?? '')) {
                $changes[$field] = [
                    'old' => $component->{$field},
                    'new' => $value,
                ];
            }
        }

        return $changes;
    }

    private function productLabel(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $product = Product::withTrashed()->find($id);

        return $product ? trim(implode(' / ', array_filter([$product->doc_num, $product->name]))) : null;
    }

    private function unitLabel(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $unit = ItemUnit::withTrashed()->find($id);

        return $unit ? trim(implode(' / ', array_filter([$unit->doc_num, $unit->name]))) : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function assertProductBelongsToCurrentCompany(Product $product): void
    {
        abort_unless((int) $product->company_id === $this->companyContext->requireCompanyId(), 404);
    }

    private function assertComponentBelongsToProduct(Product $product, ProductComponent $component): void
    {
        abort_unless(
            (int) $component->company_id === (int) $product->company_id
            && (int) $component->product_id === (int) $product->getKey(),
            404,
        );
    }
}

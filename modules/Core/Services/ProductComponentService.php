<?php

namespace Modules\Core\Services;

use Illuminate\Validation\ValidationException;
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
        'calculation_method',
        'quantity',
        'percentage',
        'reference_component_key',
        'input_source',
        'notes',
    ];

    public function __construct(
        private readonly OperatingCompanyContextService $companyContext,
        private readonly NumericFormatService $numbers,
        private readonly ProductBomService $bom,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Product $product, array $data): ProductComponent
    {
        $this->assertProductBelongsToCurrentCompany($product);

        try {
            return $this->bom
                ->createComponent($product, $this->normalizedValues($data))
                ->loadMissing(['componentProduct.unit', 'componentProduct.equivalentUnit', 'unit', 'referenceComponent']);
        } catch (ValidationException $exception) {
            throw $this->standaloneValidationException($product, $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: ProductComponent, changed: bool, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function update(Product $product, ProductComponent $component, array $data): array
    {
        $this->assertProductBelongsToCurrentCompany($product);
        $this->assertComponentBelongsToProduct($product, $component);

        $original = $component->replicate();

        try {
            $record = $this->bom->updateComponent($product, $component, $this->normalizedValues($data));
        } catch (ValidationException $exception) {
            throw $this->standaloneValidationException($product, $exception, $component);
        }

        $changes = $this->changedValues($original, $this->semanticValues($record));

        return [
            'record' => $record->loadMissing(['componentProduct.unit', 'componentProduct.equivalentUnit', 'unit', 'referenceComponent']),
            'changed' => $changes !== [],
            'changes' => $changes,
        ];
    }

    public function delete(Product $product, ProductComponent $component): void
    {
        $this->assertProductBelongsToCurrentCompany($product);
        $this->assertComponentBelongsToProduct($product, $component);

        try {
            $this->bom->deleteComponent($product, $component);
        } catch (ValidationException $exception) {
            throw $this->standaloneValidationException($product, $exception, $component);
        }
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
            'quantity', 'percentage' => $this->numbers->normalizeToScale($value, 8),
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
            if (in_array($field, ['quantity', 'percentage'], true)) {
                $current = $this->numbers->normalizeToScale($component->{$field}, 8);
                $new = $this->numbers->normalizeToScale($value, 8);

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

    /**
     * @return array<string, mixed>
     */
    private function semanticValues(ProductComponent $component): array
    {
        return [
            'component_product_id' => $component->component_product_id,
            'unit_id' => $component->unit_id,
            'calculation_method' => $component->calculation_method,
            'quantity' => $component->quantity,
            'percentage' => $component->percentage,
            'reference_component_id' => $component->reference_component_id,
            'notes' => $component->notes,
        ];
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

    private function standaloneValidationException(
        Product $product,
        ValidationException $exception,
        ?ProductComponent $target = null,
    ): ValidationException {
        $payload = $this->bom->currentPayload($product);
        $targetIndex = $target instanceof ProductComponent
            ? collect($payload)->search(
                fn (array $row): bool => $row['public_id'] === $target->public_id,
            )
            : count($payload);
        $mapped = [];

        foreach ($exception->errors() as $field => $messages) {
            if (preg_match('/^components\.(\d+)\.([^.]+)$/', $field, $matches) === 1
                && $targetIndex !== false
                && (int) $matches[1] === (int) $targetIndex
            ) {
                $field = in_array($matches[2], ['_delete', 'client_key', 'public_id'], true)
                    ? 'component'
                    : $matches[2];
            }

            foreach ($messages as $message) {
                $mapped[$field][] = $message;
            }
        }

        return ValidationException::withMessages($mapped);
    }
}

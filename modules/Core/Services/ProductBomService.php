<?php

namespace Modules\Core\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;

final class ProductBomService
{
    public function __construct(
        private readonly ProductBomWeightResolver $resolver,
        private readonly CrudAuditService $crudAudit,
    ) {}

    /**
     * @param  array<int|string, array<string, mixed>>  $submittedRows
     * @return list<ProductComponent>
     */
    public function sync(Product $product, array $submittedRows): array
    {
        return DB::transaction(function () use ($product, $submittedRows): array {
            $this->lockProducts($product);

            return $this->syncLocked($product, $submittedRows);
        });
    }

    /**
     * @return list<ProductComponent>
     */
    public function clone(Product $target, Product $source): array
    {
        return DB::transaction(function () use ($target, $source): array {
            if ((int) $target->company_id !== (int) $source->company_id) {
                throw ValidationException::withMessages([
                    'clone_source_token' => __('products.components.not_available'),
                ]);
            }

            $this->lockProducts($target, $source);
            $sourceRows = $this->lockedComponents($source);
            $targetRows = $this->lockedComponents($target);
            $clientKeys = $sourceRows
                ->mapWithKeys(fn (ProductComponent $component): array => [
                    (string) $component->public_id => (string) Str::uuid(),
                ]);
            $payload = $sourceRows
                ->map(fn (ProductComponent $component): array => [
                    'client_key' => $clientKeys[(string) $component->public_id],
                    'public_id' => null,
                    'component_product_id' => $component->component_product_id,
                    'unit_id' => $component->unit_id,
                    'production_stage_id' => $component->production_stage_id,
                    'calculation_method' => $component->calculation_method,
                    'quantity' => (string) $component->quantity,
                    'percentage' => $component->percentage === null ? null : (string) $component->percentage,
                    'reference_component_key' => $component->referenceComponent instanceof ProductComponent
                        ? $clientKeys[(string) $component->referenceComponent->public_id]
                        : null,
                    'input_source' => $component->calculation_method === ProductComponent::CalculationPercentage
                        ? ProductComponent::InputPercentage
                        : ProductComponent::InputWeight,
                    'notes' => $component->notes,
                    '_delete' => false,
                ])
                ->all();

            return $this->syncLocked($target, $payload, $targetRows);
        });
    }

    public function createComponent(Product $product, array $data): ProductComponent
    {
        return DB::transaction(function () use ($product, $data): ProductComponent {
            $this->lockProducts($product);
            $currentRows = $this->lockedComponents($product);
            $clientKey = (string) Str::uuid();
            $rows = [
                ...$this->payloadFromComponents($currentRows),
                [
                    ...$data,
                    'client_key' => $clientKey,
                    'public_id' => null,
                    '_delete' => false,
                ],
            ];
            $resolved = $this->syncLocked($product, $rows, $currentRows);
            $component = end($resolved);

            if (! $component instanceof ProductComponent) {
                throw ValidationException::withMessages([
                    'component_product_doc_num' => __('products.components.not_available'),
                ]);
            }

            return $component;
        });
    }

    public function updateComponent(
        Product $product,
        ProductComponent $component,
        array $data,
    ): ProductComponent {
        return DB::transaction(function () use ($product, $component, $data): ProductComponent {
            $this->lockProducts($product);
            $currentRows = $this->lockedComponents($product);
            $currentComponent = $currentRows->firstWhere('public_id', $component->public_id);

            if (! $currentComponent instanceof ProductComponent) {
                throw ValidationException::withMessages([
                    'component_product_doc_num' => __('products.components.not_available'),
                ]);
            }

            $rows = collect($this->payloadFromComponents($currentRows))
                ->map(fn (array $row): array => $row['public_id'] === $component->public_id
                    ? [
                        ...$row,
                        ...$data,
                        'client_key' => $row['client_key'],
                        'public_id' => $row['public_id'],
                    ]
                    : $row)
                ->all();

            $this->syncLocked($product, $rows, $currentRows);

            return $currentComponent->refresh();
        });
    }

    public function deleteComponent(Product $product, ProductComponent $component): void
    {
        DB::transaction(function () use ($product, $component): void {
            $this->lockProducts($product);
            $currentRows = $this->lockedComponents($product);

            if (! $currentRows->contains('public_id', $component->public_id)) {
                throw ValidationException::withMessages([
                    'component_product_doc_num' => __('products.components.not_available'),
                ]);
            }

            $rows = collect($this->payloadFromComponents($currentRows))
                ->map(function (array $row) use ($component): array {
                    if ($row['public_id'] === $component->public_id) {
                        $row['_delete'] = true;
                    }

                    return $row;
                })
                ->all();

            $this->syncLocked($product, $rows, $currentRows);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function currentPayload(Product $product): array
    {
        $components = ProductComponent::query()
            ->forCompany((int) $product->company_id)
            ->where('product_id', $product->getKey())
            ->with('referenceComponent')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $this->payloadFromComponents($components);
    }

    /**
     * @param  Collection<int, ProductComponent>  $components
     * @return list<array<string, mixed>>
     */
    private function payloadFromComponents(Collection $components): array
    {
        return $components
            ->map(fn (ProductComponent $component): array => [
                'client_key' => (string) $component->public_id,
                'public_id' => (string) $component->public_id,
                'component_product_id' => $component->component_product_id,
                'unit_id' => $component->unit_id,
                'production_stage_id' => $component->production_stage_id,
                'calculation_method' => $component->calculation_method,
                'quantity' => (string) $component->quantity,
                'percentage' => $component->percentage === null ? null : (string) $component->percentage,
                'reference_component_key' => $component->referenceComponent?->public_id,
                'input_source' => $component->calculation_method === ProductComponent::CalculationPercentage
                    ? ProductComponent::InputPercentage
                    : ProductComponent::InputWeight,
                'notes' => $component->notes,
                '_delete' => false,
            ])
            ->all();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $submittedRows
     * @param  Collection<int, ProductComponent>|null  $lockedRows
     * @return list<ProductComponent>
     */
    private function syncLocked(
        Product $product,
        array $submittedRows,
        ?Collection $lockedRows = null,
    ): array {
        $currentRows = ($lockedRows ?? $this->lockedComponents($product))->keyBy('public_id');
        $preparedRows = $this->prepareRows($submittedRows, $currentRows);
        $resolvedRows = $this->resolver->resolve($product, $preparedRows);
        $modelsByClientKey = [];
        $newClientKeys = [];
        $survivingPublicIds = [];
        $existingIdsByClientKey = collect($resolvedRows)
            ->mapWithKeys(function (array $row) use ($currentRows): array {
                $component = is_string($row['public_id'])
                    ? $currentRows->get($row['public_id'])
                    : null;

                return $component instanceof ProductComponent
                    ? [$row['client_key'] => (int) $component->getKey()]
                    : [];
            });

        foreach ($resolvedRows as $row) {
            $publicId = $row['public_id'];
            $component = is_string($publicId) ? $currentRows->get($publicId) : null;
            $referenceKey = $row['reference_component_key'];
            $values = [
                'component_product_id' => (int) $row['component_product_id'],
                'unit_id' => $row['unit_id'] === null ? null : (int) $row['unit_id'],
                'production_stage_id' => isset($row['production_stage_id']) ? (int) $row['production_stage_id'] : null,
                'calculation_method' => $row['calculation_method'],
                'quantity' => $row['quantity'],
                'percentage' => $row['percentage'],
                'reference_component_id' => is_string($referenceKey)
                    ? $existingIdsByClientKey->get($referenceKey)
                    : null,
                'notes' => $row['notes'],
            ];

            if ($component instanceof ProductComponent) {
                $this->saveIfChanged($component, $values);
            } else {
                $component = ProductComponent::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'company_id' => $product->company_id,
                    'product_id' => $product->getKey(),
                    ...$values,
                    'created_by' => auth()->id(),
                ]);
                $this->crudAudit->clearCreationUpdateAudit($component);
                $newClientKeys[$row['client_key']] = true;
            }

            $modelsByClientKey[$row['client_key']] = $component;
            $survivingPublicIds[] = (string) $component->public_id;
        }

        foreach ($resolvedRows as $row) {
            $component = $modelsByClientKey[$row['client_key']];
            $referenceKey = $row['reference_component_key'];
            $referenceId = is_string($referenceKey)
                ? $modelsByClientKey[$referenceKey]->getKey()
                : null;

            if ((int) ($component->reference_component_id ?? 0) !== (int) ($referenceId ?? 0)) {
                if (isset($newClientKeys[$row['client_key']])) {
                    $component->forceFill(['reference_component_id' => $referenceId])->save();
                    $this->crudAudit->clearCreationUpdateAudit($component);
                } else {
                    $this->saveIfChanged($component, [
                        'reference_component_id' => $referenceId,
                    ]);
                }
            }
        }

        $toDelete = $currentRows
            ->filter(fn (ProductComponent $component): bool => ! in_array((string) $component->public_id, $survivingPublicIds, true));

        foreach ($toDelete as $component) {
            $this->crudAudit->softDelete($component);
        }

        return collect($resolvedRows)
            ->map(fn (array $row): ProductComponent => $modelsByClientKey[$row['client_key']]->refresh())
            ->all();
    }

    /**
     * @return Collection<int, ProductComponent>
     */
    private function lockedComponents(Product $product): Collection
    {
        return ProductComponent::query()
            ->forCompany((int) $product->company_id)
            ->where('product_id', $product->getKey())
            ->with('referenceComponent')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function lockProducts(Product ...$products): void
    {
        collect($products)
            ->unique(fn (Product $product): string => $product->company_id.':'.$product->getKey())
            ->sortBy(fn (Product $product): int => (int) $product->getKey())
            ->each(function (Product $product): void {
                Product::query()
                    ->forCompany((int) $product->company_id)
                    ->whereKey($product->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
            });
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $submittedRows
     * @param  Collection<string, ProductComponent>  $currentRows
     * @return list<array<string, mixed>>
     */
    private function prepareRows(array $submittedRows, $currentRows): array
    {
        $prepared = [];

        foreach ($submittedRows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $publicId = trim((string) ($row['public_id'] ?? '')) ?: null;
            $clientKey = trim((string) ($row['client_key'] ?? ''))
                ?: $publicId
                ?: (string) Str::uuid();

            if ($publicId !== null && ! $currentRows->has($publicId)) {
                throw ValidationException::withMessages([
                    "components.{$index}.public_id" => __('products.components.not_available'),
                ]);
            }

            $prepared[] = [
                ...$row,
                'client_key' => $clientKey,
                'public_id' => $publicId,
            ];
        }

        return $prepared;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function saveIfChanged(ProductComponent $component, array $values): void
    {
        $component->forceFill($values);

        if ($component->isDirty()) {
            $this->crudAudit->saveUpdate($component);
        }
    }
}

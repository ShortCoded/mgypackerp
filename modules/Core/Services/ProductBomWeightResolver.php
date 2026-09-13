<?php

namespace Modules\Core\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;

final class ProductBomWeightResolver
{
    public const WeightScale = 8;

    public const PercentageScale = 8;

    public function __construct(
        private readonly ProductComponentUnitConversionService $conversions,
        private readonly ProductComponentUnitOptionsService $unitOptions,
    ) {}

    /**
     * @param  array<int|string, array<string, mixed>>  $submittedRows
     * @return list<array<string, mixed>>
     */
    public function resolve(Product $product, array $submittedRows): array
    {
        $errors = [];
        $rows = [];
        $deletedKeys = [];
        $submittedPublicIds = [];
        $persisted = ProductComponent::query()
            ->forCompany((int) $product->company_id)
            ->where('product_id', $product->getKey())
            ->get()
            ->keyBy('public_id');

        foreach (array_values($submittedRows) as $index => $submittedRow) {
            if (! is_array($submittedRow)) {
                continue;
            }

            $row = $this->normalizeRow($submittedRow, $index);
            $clientKey = $row['client_key'];

            if (! Str::isUuid($clientKey)) {
                $errors["components.{$index}.client_key"][] = __('products.components.invalid_client_key');
            } elseif (isset($rows[$clientKey]) || isset($deletedKeys[$clientKey])) {
                $errors["components.{$index}.client_key"][] = __('products.components.duplicate_client_key');
            }

            if ($row['public_id'] !== null && ! $persisted->has($row['public_id'])) {
                $errors["components.{$index}.public_id"][] = __('products.components.not_available');
            } elseif ($row['public_id'] !== null && isset($submittedPublicIds[$row['public_id']])) {
                $errors["components.{$index}.public_id"][] = __('products.components.duplicate_public_id');
            } elseif ($row['public_id'] !== null) {
                $submittedPublicIds[$row['public_id']] = true;
            }

            if ($row['_delete']) {
                $deletedKeys[$clientKey] = $row;

                continue;
            }

            $row['component_product'] = $this->componentProduct(
                $product,
                $row['component_product_id'],
                $index,
                $errors,
            );
            $row['unit'] = $this->componentUnit(
                $product,
                $row['component_product'],
                $row['unit_id'],
                $row['calculation_method'],
                $index,
                $errors,
            );
            $rows[$clientKey] = $row;
        }

        $this->validateReferences($rows, $deletedKeys, $errors);
        $this->validateCycles($rows, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var array<string, array<string, mixed>> $resolved */
        $resolved = [];

        foreach (array_keys($rows) as $clientKey) {
            $this->resolveRow($clientKey, $rows, $resolved, $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return collect($submittedRows)
            ->map(function (mixed $submittedRow) use ($resolved): ?array {
                if (! is_array($submittedRow)) {
                    return null;
                }

                $clientKey = trim((string) ($submittedRow['client_key'] ?? $submittedRow['public_id'] ?? ''));

                return $resolved[$clientKey] ?? null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $submittedRow
     * @return array<string, mixed>
     */
    private function normalizeRow(array $submittedRow, int $index): array
    {
        $publicId = $this->nullableString($submittedRow['public_id'] ?? null);
        $clientKey = $this->nullableString($submittedRow['client_key'] ?? null)
            ?? $publicId
            ?? (string) Str::uuid();

        return [
            ...$submittedRow,
            '_index' => $index,
            'client_key' => $clientKey,
            'public_id' => $publicId,
            '_delete' => filter_var($submittedRow['_delete'] ?? false, FILTER_VALIDATE_BOOL),
            'component_product_id' => $this->nullableInteger($submittedRow['component_product_id'] ?? null),
            'unit_id' => $this->nullableInteger($submittedRow['unit_id'] ?? null),
            'calculation_method' => trim((string) ($submittedRow['calculation_method'] ?? ProductComponent::CalculationDirect)),
            'quantity' => $this->nullableString($submittedRow['quantity'] ?? null),
            'percentage' => $this->nullableString($submittedRow['percentage'] ?? null),
            'reference_component_key' => $this->nullableString($submittedRow['reference_component_key'] ?? null),
            'input_source' => $this->nullableString($submittedRow['input_source'] ?? null),
            'notes' => $this->nullableString($submittedRow['notes'] ?? null),
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function componentProduct(
        Product $product,
        ?int $componentProductId,
        int $index,
        array &$errors,
    ): ?Product {
        if ($componentProductId === null) {
            $errors["components.{$index}.component_product_doc_num"][] = __('products.components.component_item_required');

            return null;
        }

        $componentProduct = Product::query()
            ->forCompany((int) $product->company_id)
            ->active()
            ->componentItems()
            ->with(['unit', 'equivalentUnit'])
            ->find($componentProductId);

        if (! $componentProduct instanceof Product || $componentProduct->getKey() === $product->getKey()) {
            $errors["components.{$index}.component_product_doc_num"][] = __('products.components.not_available');

            return null;
        }

        return $componentProduct;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function componentUnit(
        Product $product,
        ?Product $componentProduct,
        ?int $unitId,
        string $calculationMethod,
        int $index,
        array &$errors,
    ): ?ItemUnit {
        if (! $componentProduct instanceof Product) {
            return null;
        }

        if ($unitId === null) {
            $fallbackUnit = $componentProduct->unit;

            if ($fallbackUnit instanceof ItemUnit
                && ! $fallbackUnit->trashed()
                && (int) $fallbackUnit->company_id === (int) $product->company_id
            ) {
                return $fallbackUnit;
            }

            if ($calculationMethod !== ProductComponent::CalculationPercentage) {
                return null;
            }

            $errors["components.{$index}.unit_doc_num"][] = __('products.components.unit_required');

            return null;
        }

        if (! in_array($unitId, $this->unitOptions->validUnitIds($componentProduct), true)) {
            $errors["components.{$index}.unit_doc_num"][] = __('products.components.invalid_unit');

            return null;
        }

        $unit = ItemUnit::query()
            ->forCompany((int) $product->company_id)
            ->find($unitId);

        if (! $unit instanceof ItemUnit) {
            $errors["components.{$index}.unit_doc_num"][] = __('products.components.invalid_unit');
        }

        return $unit;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $deletedKeys
     * @param  array<string, list<string>>  $errors
     */
    private function validateReferences(array &$rows, array $deletedKeys, array &$errors): void
    {
        foreach ($rows as $clientKey => $row) {
            $index = $row['_index'];
            $method = $row['calculation_method'];

            if (! in_array($method, ProductComponent::calculationMethods(), true)) {
                $errors["components.{$index}.calculation_method"][] = __('products.components.invalid_calculation_method');

                continue;
            }

            if ($method !== ProductComponent::CalculationPercentage) {
                continue;
            }

            $referenceKey = $row['reference_component_key'];

            if ($referenceKey === null) {
                $errors["components.{$index}.reference_component_key"][] = __('products.components.reference_required');
            } elseif ($referenceKey === $clientKey) {
                $errors["components.{$index}.reference_component_key"][] = __('products.components.line_self_reference');
            } elseif (! isset($rows[$referenceKey])) {
                $errors["components.{$index}.reference_component_key"][] = __('products.components.reference_not_available');

                if (isset($deletedKeys[$referenceKey])) {
                    $deletedIndex = $deletedKeys[$referenceKey]['_index'];
                    $errors["components.{$deletedIndex}._delete"][] = __(
                        'products.components.referenced_delete_blocked',
                        ['line' => $index + 1],
                    );
                }
            }

            $source = $row['input_source'];
            $hasWeight = $row['quantity'] !== null;
            $hasPercentage = $row['percentage'] !== null;

            if ($source === null && ($hasWeight xor $hasPercentage)) {
                $source = $hasPercentage ? ProductComponent::InputPercentage : ProductComponent::InputWeight;
                $rows[$clientKey]['input_source'] = $source;
            }

            if (! in_array($source, ProductComponent::inputSources(), true)) {
                $errors["components.{$index}.input_source"][] = __('products.components.input_source_required');
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, list<string>>  $errors
     */
    private function validateCycles(array $rows, array &$errors): void
    {
        $states = [];
        $stack = [];

        $visit = function (string $clientKey) use (&$visit, &$states, &$stack, $rows, &$errors): void {
            $state = $states[$clientKey] ?? 0;

            if ($state === 2) {
                return;
            }

            if ($state === 1) {
                $cycleStart = array_search($clientKey, $stack, true);
                $cycleKeys = $cycleStart === false ? [$clientKey] : array_slice($stack, $cycleStart);

                foreach ($cycleKeys as $cycleKey) {
                    $index = $rows[$cycleKey]['_index'];
                    $errors["components.{$index}.reference_component_key"][] = __('products.components.circular_reference');
                }

                return;
            }

            $states[$clientKey] = 1;
            $stack[] = $clientKey;
            $referenceKey = $rows[$clientKey]['calculation_method'] === ProductComponent::CalculationPercentage
                ? $rows[$clientKey]['reference_component_key']
                : null;

            if ($referenceKey !== null && isset($rows[$referenceKey])) {
                $visit($referenceKey);
            }

            array_pop($stack);
            $states[$clientKey] = 2;
        };

        foreach (array_keys($rows) as $clientKey) {
            $visit($clientKey);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $resolved
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>|null
     */
    private function resolveRow(
        string $clientKey,
        array $rows,
        array &$resolved,
        array &$errors,
    ): ?array {
        if (isset($resolved[$clientKey])) {
            return $resolved[$clientKey];
        }

        $row = $rows[$clientKey];
        $index = $row['_index'];

        if ($row['calculation_method'] !== ProductComponent::CalculationPercentage) {
            $valueType = match ($row['calculation_method']) {
                ProductComponent::CalculationQuantity => 'quantity',
                ProductComponent::CalculationCount => 'count',
                default => 'weight',
            };
            $scale = $row['calculation_method'] === ProductComponent::CalculationCount
                ? 0
                : self::WeightScale;
            $quantity = $this->positiveScaledDecimal(
                $row['quantity'],
                $scale,
                "components.{$index}.quantity",
                __("products.components.{$valueType}_gt_zero"),
                $errors,
                $valueType === 'count'
                    ? __('products.components.count_integer')
                    : __("products.components.{$valueType}_precision"),
            );

            if ($quantity === null) {
                return null;
            }

            return $resolved[$clientKey] = [
                ...$row,
                'quantity' => $quantity,
                'percentage' => null,
                'reference_component_key' => null,
                'input_source' => ProductComponent::InputWeight,
            ];
        }

        $referenceKey = $row['reference_component_key'];

        if (! is_string($referenceKey) || ! isset($rows[$referenceKey])) {
            return null;
        }

        $reference = $this->resolveRow($referenceKey, $rows, $resolved, $errors);

        if ($reference === null
            || ! $row['component_product'] instanceof Product
            || ! $reference['component_product'] instanceof Product
        ) {
            return null;
        }

        if (! $row['unit'] instanceof ItemUnit || ! $reference['unit'] instanceof ItemUnit) {
            $errors["components.{$index}.reference_component_key"][] = __('products.components.incompatible_units');

            return null;
        }

        try {
            $referenceDecimal = BigDecimal::of($reference['quantity']);

            if (! $referenceDecimal->isGreaterThan(0)) {
                $errors["components.{$index}.reference_component_key"][] = __('products.components.reference_weight_gt_zero');

                return null;
            }

            if ($row['input_source'] === ProductComponent::InputPercentage) {
                $percentage = $this->positiveScaledDecimal(
                    $row['percentage'],
                    self::PercentageScale,
                    "components.{$index}.percentage",
                    __('products.components.percentage_gt_zero'),
                    $errors,
                    __('products.components.percentage_precision'),
                );

                if ($percentage === null) {
                    return null;
                }

                $referenceUnitWeight = (string) $referenceDecimal
                    ->multipliedBy($percentage)
                    ->dividedBy('100', ProductComponentUnitConversionService::WorkScale, RoundingMode::HalfUp);
                $weight = $this->conversions->convert(
                    $referenceUnitWeight,
                    $reference['component_product'],
                    $reference['unit'],
                    $row['component_product'],
                    $row['unit'],
                    self::WeightScale,
                );

                if ($weight === null) {
                    $errors["components.{$index}.reference_component_key"][] = __('products.components.incompatible_units');

                    return null;
                }
            } else {
                $submittedWeight = $this->positiveScaledDecimal(
                    $row['quantity'],
                    self::WeightScale,
                    "components.{$index}.quantity",
                    __('products.components.weight_gt_zero'),
                    $errors,
                    __('products.components.weight_precision'),
                );

                if ($submittedWeight === null) {
                    return null;
                }

                $convertedReference = $this->conversions->convert(
                    $reference['quantity'],
                    $reference['component_product'],
                    $reference['unit'],
                    $row['component_product'],
                    $row['unit'],
                );

                if ($convertedReference === null) {
                    $errors["components.{$index}.reference_component_key"][] = __('products.components.incompatible_units');

                    return null;
                }

                $convertedReferenceDecimal = BigDecimal::of($convertedReference);

                $percentage = (string) BigDecimal::of($submittedWeight)
                    ->multipliedBy('100')
                    ->dividedBy($convertedReferenceDecimal, self::PercentageScale, RoundingMode::HalfUp);

                if (! $this->fitsStorage($percentage)) {
                    $errors["components.{$index}.percentage"][] = __('products.components.decimal_out_of_range');

                    return null;
                }

                $weight = (string) $convertedReferenceDecimal
                    ->multipliedBy($percentage)
                    ->dividedBy('100', self::WeightScale, RoundingMode::HalfUp);
            }

            if (! BigDecimal::of($weight)->isGreaterThan(0)) {
                $errors["components.{$index}.quantity"][] = __('products.components.resolved_weight_gt_zero');

                return null;
            }

            if (! $this->fitsStorage($weight)) {
                $errors["components.{$index}.quantity"][] = __('products.components.decimal_out_of_range');

                return null;
            }
        } catch (MathException) {
            $errors["components.{$index}.quantity"][] = __('products.components.invalid_calculation');

            return null;
        }

        return $resolved[$clientKey] = [
            ...$row,
            'quantity' => $weight,
            'percentage' => $percentage,
            'input_source' => ProductComponent::InputPercentage,
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function positiveScaledDecimal(
        mixed $value,
        int $scale,
        string $field,
        string $message,
        array &$errors,
        ?string $precisionMessage = null,
    ): ?string {
        try {
            $decimal = BigDecimal::of((string) $value);

            if (! $decimal->isGreaterThan(0)) {
                $errors[$field][] = $message;

                return null;
            }

            if ($decimal->strippedOfTrailingZeros()->getScale() > $scale) {
                $errors[$field][] = $precisionMessage ?? $message;

                return null;
            }

            $scaled = $decimal->toScale($scale);

            if (! $this->fitsStorage((string) $scaled)) {
                $errors[$field][] = __('products.components.decimal_out_of_range');

                return null;
            }

            return (string) $scaled;
        } catch (MathException) {
            $errors[$field][] = $message;

            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function fitsStorage(string $value): bool
    {
        return strlen(ltrim(explode('.', $value, 2)[0], '-')) <= 10;
    }
}

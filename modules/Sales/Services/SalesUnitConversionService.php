<?php

namespace Modules\Sales\Services;

use DomainException;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\ProductComponentUnitConversionService;
use Modules\Core\Services\ProductComponentUnitOptionsService;

class SalesUnitConversionService
{
    public function __construct(
        private readonly ProductComponentUnitConversionService $conversions,
        private readonly ProductComponentUnitOptionsService $unitOptions,
    ) {}

    /**
     * @return array{unit_id: int, base_unit_id: int, conversion_factor: string, base_quantity: string}
     */
    public function snapshot(Product $product, mixed $unitId, mixed $quantity): array
    {
        $product->loadMissing(['unit', 'equivalentUnit']);
        $selectedUnitId = (int) ($unitId ?: $product->item_unit_id);

        if (! in_array($selectedUnitId, $this->unitOptions->validUnitIds($product), true)) {
            throw new DomainException(__('The selected unit is not configured for this product.'));
        }

        $selectedUnit = ItemUnit::query()
            ->forCompany((int) $product->company_id)
            ->active()
            ->find($selectedUnitId);
        $baseUnit = $product->unit;

        if (! $selectedUnit instanceof ItemUnit || ! $baseUnit instanceof ItemUnit) {
            throw new DomainException(__('The product requires an active base unit.'));
        }

        $factor = $this->conversions->convert('1', $product, $selectedUnit, $product, $baseUnit, 8);
        $baseQuantity = $this->conversions->convert((string) $quantity, $product, $selectedUnit, $product, $baseUnit, 8);

        if ($factor === null || $baseQuantity === null || bccomp($factor, '0', 8) <= 0) {
            throw new DomainException(__('The selected product unit has no unambiguous conversion to the base unit.'));
        }

        return [
            'unit_id' => $selectedUnit->getKey(),
            'base_unit_id' => $baseUnit->getKey(),
            'conversion_factor' => $factor,
            'base_quantity' => $baseQuantity,
        ];
    }

    public function toBase(mixed $quantity, mixed $conversionFactor): string
    {
        return bcmul((string) $quantity, (string) $conversionFactor, 8);
    }
}

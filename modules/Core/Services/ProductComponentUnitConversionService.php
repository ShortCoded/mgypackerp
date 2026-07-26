<?php

namespace Modules\Core\Services;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;

final class ProductComponentUnitConversionService
{
    public const WorkScale = 24;

    private const MaximumStoredWeight = '9999999999.99999999';

    private const HalfStoredWeightQuantum = '0.000000005';

    /**
     * Convert a reference-line weight into the dependent line's selected unit.
     */
    public function convert(
        mixed $amount,
        Product $referenceProduct,
        ItemUnit $referenceUnit,
        Product $dependentProduct,
        ItemUnit $dependentUnit,
        int $scale = self::WorkScale,
    ): ?string {
        $factor = $this->factor(
            $referenceProduct,
            $referenceUnit,
            $dependentProduct,
            $dependentUnit,
        );

        if ($factor === null) {
            return null;
        }

        try {
            return (string) BigDecimal::of((string) $amount)
                ->multipliedBy($factor)
                ->toScale($scale, RoundingMode::HalfUp);
        } catch (MathException) {
            return null;
        }
    }

    /**
     * @return list<array{from: string, to: string, factor: string}>
     */
    public function globalEdgesForCompany(int $companyId): array
    {
        return ItemUnit::query()
            ->forCompany($companyId)
            ->whereNotNull('equivalent_value')
            ->whereNotNull('equivalent_unit_id')
            ->with('equivalentUnit')
            ->get()
            ->flatMap(fn (ItemUnit $unit): array => $this->unitEdges($unit))
            ->values()
            ->all();
    }

    /**
     * @return list<array{from: string, to: string, factor: string}>
     */
    public function productEdges(Product $product): array
    {
        $product->loadMissing(['unit', 'equivalentUnit']);

        if (! $product->unit instanceof ItemUnit
            || ! $product->equivalentUnit instanceof ItemUnit
            || $product->unit->trashed()
            || $product->equivalentUnit->trashed()
            || (int) $product->unit->company_id !== (int) $product->company_id
            || (int) $product->equivalentUnit->company_id !== (int) $product->company_id
            || ! $this->positiveDecimal($product->equivalent_value)
        ) {
            return [];
        }

        return $this->conversionEdge(
            $product->unit,
            $product->equivalentUnit,
            (string) $product->equivalent_value,
        );
    }

    private function factor(
        Product $referenceProduct,
        ItemUnit $referenceUnit,
        Product $dependentProduct,
        ItemUnit $dependentUnit,
    ): ?string {
        if ((int) $referenceUnit->getKey() === (int) $dependentUnit->getKey()) {
            return '1';
        }

        if ((int) $referenceProduct->company_id !== (int) $dependentProduct->company_id
            || (int) $referenceUnit->company_id !== (int) $referenceProduct->company_id
            || (int) $dependentUnit->company_id !== (int) $referenceProduct->company_id
        ) {
            return null;
        }

        $edges = [
            ...$this->productIdEdges($referenceProduct),
            ...$this->productIdEdges($dependentProduct),
            ...$this->globalIdEdgesForCompany((int) $referenceProduct->company_id),
        ];

        return $this->resolveFactor(
            (int) $referenceUnit->getKey(),
            (int) $dependentUnit->getKey(),
            $edges,
        );
    }

    /**
     * @return list<array{from_id: int, to_id: int, factor: string, inverse: bool}>
     */
    private function productIdEdges(Product $product): array
    {
        $product->loadMissing(['unit', 'equivalentUnit']);
        $unit = $product->unit;
        $equivalentUnit = $product->equivalentUnit;

        if (! $unit instanceof ItemUnit
            || ! $equivalentUnit instanceof ItemUnit
            || $unit->trashed()
            || $equivalentUnit->trashed()
            || (int) $unit->company_id !== (int) $product->company_id
            || (int) $equivalentUnit->company_id !== (int) $product->company_id
            || ! $this->positiveDecimal($product->equivalent_value)
        ) {
            return [];
        }

        return $this->bidirectionalIdEdges(
            $unit,
            $equivalentUnit,
            (string) $product->equivalent_value,
        );
    }

    /**
     * @return list<array{from_id: int, to_id: int, factor: string, inverse: bool}>
     */
    private function globalIdEdgesForCompany(int $companyId): array
    {
        return ItemUnit::query()
            ->forCompany($companyId)
            ->whereNotNull('equivalent_value')
            ->whereNotNull('equivalent_unit_id')
            ->with('equivalentUnit')
            ->get()
            ->flatMap(fn (ItemUnit $unit): array => $this->unitIdEdges($unit, $companyId))
            ->values()
            ->all();
    }

    /**
     * @param  list<array{from_id: int, to_id: int, factor: string, inverse: bool}>  $edges
     */
    private function resolveFactor(int $fromUnitId, int $toUnitId, array $edges): ?string
    {
        /** @var array<int, list<array{to_id: int, factor: string, inverse: bool}>> $adjacency */
        $adjacency = [];

        foreach ($edges as $edge) {
            $adjacency[$edge['from_id']][] = [
                'to_id' => $edge['to_id'],
                'factor' => $edge['factor'],
                'inverse' => $edge['inverse'],
            ];
        }

        /** @var array<int, BigRational> $factors */
        $factors = [$fromUnitId => BigRational::one()];
        $queue = [$fromUnitId];

        try {
            while ($queue !== []) {
                $unitId = array_shift($queue);
                $currentFactor = $factors[$unitId];

                foreach ($adjacency[$unitId] ?? [] as $edge) {
                    $edgeFactor = BigRational::of($edge['factor']);
                    $nextFactor = $currentFactor->multipliedBy(
                        $edge['inverse']
                            ? BigRational::one()->dividedBy($edgeFactor)
                            : $edgeFactor,
                    );
                    $nextUnitId = $edge['to_id'];

                    if (! isset($factors[$nextUnitId])) {
                        $factors[$nextUnitId] = $nextFactor;
                        $queue[] = $nextUnitId;

                        continue;
                    }

                    if (! $this->factorsAreOutputEquivalent($factors[$nextUnitId], $nextFactor)) {
                        return null;
                    }
                }
            }
        } catch (MathException) {
            return null;
        }

        return isset($factors[$toUnitId])
            ? (string) $factors[$toUnitId]->toScale(self::WorkScale, RoundingMode::HalfUp)
            : null;
    }

    /**
     * Allow only relative path variance that cannot move the largest storable weight
     * by half of the quantity column's smallest storage unit.
     */
    private function factorsAreOutputEquivalent(BigRational $knownFactor, BigRational $candidateFactor): bool
    {
        if ($knownFactor->isEqualTo($candidateFactor)) {
            return true;
        }

        $largestFactor = BigRational::max($knownFactor->abs(), $candidateFactor->abs());

        if ($largestFactor->isZero()) {
            return false;
        }

        $maximumStoredDifference = $knownFactor
            ->minus($candidateFactor)
            ->abs()
            ->multipliedBy(self::MaximumStoredWeight);
        $maximumToleratedDifference = $largestFactor
            ->multipliedBy(self::HalfStoredWeightQuantum);

        return $maximumStoredDifference->isLessThanOrEqualTo($maximumToleratedDifference);
    }

    /**
     * @return list<array{from: string, to: string, factor: string}>
     */
    private function unitEdges(ItemUnit $unit): array
    {
        $equivalentUnit = $unit->equivalentUnit;

        if (! $equivalentUnit instanceof ItemUnit
            || $unit->trashed()
            || $equivalentUnit->trashed()
            || (int) $unit->company_id !== (int) $equivalentUnit->company_id
            || ! $this->positiveDecimal($unit->equivalent_value)
        ) {
            return [];
        }

        return $this->conversionEdge($unit, $equivalentUnit, (string) $unit->equivalent_value);
    }

    /**
     * @return list<array{from: string, to: string, factor: string}>
     */
    private function conversionEdge(ItemUnit $from, ItemUnit $to, string $factor): array
    {
        return [
            [
                'from' => (string) $from->doc_num,
                'to' => (string) $to->doc_num,
                'factor' => $this->canonicalDecimal($factor),
            ],
        ];
    }

    /**
     * @return list<array{from_id: int, to_id: int, factor: string, inverse: bool}>
     */
    private function unitIdEdges(ItemUnit $unit, int $companyId): array
    {
        $equivalentUnit = $unit->equivalentUnit;

        if (! $equivalentUnit instanceof ItemUnit
            || $unit->trashed()
            || $equivalentUnit->trashed()
            || (int) $unit->company_id !== $companyId
            || (int) $equivalentUnit->company_id !== $companyId
            || ! $this->positiveDecimal($unit->equivalent_value)
        ) {
            return [];
        }

        return $this->bidirectionalIdEdges(
            $unit,
            $equivalentUnit,
            (string) $unit->equivalent_value,
        );
    }

    /**
     * @return list<array{from_id: int, to_id: int, factor: string, inverse: bool}>
     */
    private function bidirectionalIdEdges(ItemUnit $from, ItemUnit $to, string $factor): array
    {
        $factor = $this->canonicalDecimal($factor);

        return [
            [
                'from_id' => (int) $from->getKey(),
                'to_id' => (int) $to->getKey(),
                'factor' => $factor,
                'inverse' => false,
            ],
            [
                'from_id' => (int) $to->getKey(),
                'to_id' => (int) $from->getKey(),
                'factor' => $factor,
                'inverse' => true,
            ],
        ];
    }

    private function positiveDecimal(mixed $value): bool
    {
        try {
            return $value !== null && BigDecimal::of((string) $value)->isGreaterThan(0);
        } catch (MathException) {
            return false;
        }
    }

    private function canonicalDecimal(mixed $value): string
    {
        $decimal = (string) $value;

        if (! str_contains($decimal, '.')) {
            return $decimal;
        }

        return rtrim(rtrim($decimal, '0'), '.');
    }
}

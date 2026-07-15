<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Carbon;
use Modules\FixedAssets\Models\FixedAsset;

class FixedAssetDepreciationCalculator
{
    private const DaysInYear = 365;

    public function theoreticalAnnualDepreciation(FixedAsset $asset): ?float
    {
        if (! $this->assetHasDepreciableBase($asset)) {
            return null;
        }

        $purchaseValue = (float) $asset->purchase_value;
        $salvageValue = (float) ($asset->salvage_value ?? 0);
        $previousDepreciation = (float) ($asset->previous_depreciation ?? 0);
        $depreciableBase = max(0.0, $purchaseValue - $salvageValue);
        $remainingDepreciableAmount = max(0.0, $depreciableBase - $previousDepreciation);

        if ($remainingDepreciableAmount <= 0) {
            return null;
        }

        $amount = match ($asset->depreciation_method) {
            FixedAsset::DepreciationMethodStraightLine => $this->straightLineAnnualAmount($asset, $depreciableBase),
            FixedAsset::DepreciationMethodDecliningBalance => $this->decliningBalanceAnnualAmount($asset, $purchaseValue, $salvageValue, $previousDepreciation),
            FixedAsset::DepreciationMethodDoubleDecliningBalance => $this->doubleDecliningBalanceAnnualAmount($asset, $purchaseValue, $salvageValue, $previousDepreciation),
            FixedAsset::DepreciationMethodSumOfYearsDigits => $this->sumOfYearsDigitsFirstYearAmount($asset, $depreciableBase),
            default => null,
        };

        return $amount === null ? null : round(min($amount, $remainingDepreciableAmount), 4);
    }

    public function depreciationPerUsageUnit(FixedAsset $asset): ?float
    {
        if (! $this->assetHasDepreciableBase($asset)
            || $asset->depreciation_method !== FixedAsset::DepreciationMethodUnitsOfProduction
            || $asset->expected_usage_units === null
            || (float) $asset->expected_usage_units <= 0
        ) {
            return null;
        }

        $purchaseValue = (float) $asset->purchase_value;
        $salvageValue = (float) ($asset->salvage_value ?? 0);

        return round(max(0.0, $purchaseValue - $salvageValue) / (float) $asset->expected_usage_units, 4);
    }

    /**
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    public function normalizedMethodInputs(string $method, ?float $usefulLife, ?float $annualDepreciationRate, ?float $expectedUsageUnits): array
    {
        return match ($method) {
            FixedAsset::DepreciationMethodStraightLine => $this->straightLineInputs($usefulLife, $annualDepreciationRate),
            FixedAsset::DepreciationMethodDecliningBalance => [null, $annualDepreciationRate, null],
            FixedAsset::DepreciationMethodDoubleDecliningBalance => [
                $usefulLife,
                $usefulLife !== null && $usefulLife > 0 ? 200 / $usefulLife : null,
                null,
            ],
            FixedAsset::DepreciationMethodSumOfYearsDigits => [$usefulLife, null, null],
            FixedAsset::DepreciationMethodUnitsOfProduction => [null, null, $expectedUsageUnits],
            default => [null, null, null],
        };
    }

    public function calculateForPeriod(FixedAsset $asset, Carbon $from, Carbon $to): float
    {
        if ($to->lt($from) || ! $this->canDepreciate($asset)) {
            return 0.0;
        }

        $startDate = Carbon::parse($asset->depreciation_start_date)->startOfDay();
        $periodStart = $from->copy()->startOfDay()->max($startDate);
        $periodEnd = $to->copy()->startOfDay();

        if ($periodEnd->lt($periodStart)) {
            return 0.0;
        }

        $purchaseValue = (float) $asset->purchase_value;
        $salvageValue = (float) ($asset->salvage_value ?? 0);
        $previousDepreciation = (float) ($asset->previous_depreciation ?? 0);
        $usefulLife = (float) $asset->useful_life;
        $depreciableBase = max(0.0, $purchaseValue - $salvageValue);
        $remainingDepreciableAmount = max(0.0, $depreciableBase - $previousDepreciation);

        if ($depreciableBase <= 0 || $remainingDepreciableAmount <= 0 || $usefulLife <= 0) {
            return 0.0;
        }

        // Fixed Assets uses a 365-day convention for daily pro-rata straight-line depreciation.
        $days = $periodStart->diffInDays($periodEnd) + 1;
        $annualDepreciation = $depreciableBase / $usefulLife;
        $periodDepreciation = ($annualDepreciation / self::DaysInYear) * $days;

        return round(min($periodDepreciation, $remainingDepreciableAmount), 4);
    }

    private function canDepreciate(FixedAsset $asset): bool
    {
        return (bool) $asset->is_depreciable
            && $asset->depreciation_method === FixedAsset::DepreciationMethodStraightLine
            && $asset->depreciation_start_date !== null
            && $asset->purchase_value !== null
            && $asset->useful_life !== null;
    }

    private function assetHasDepreciableBase(FixedAsset $asset): bool
    {
        if (! (bool) $asset->is_depreciable || $asset->purchase_value === null) {
            return false;
        }

        $purchaseValue = (float) $asset->purchase_value;
        $salvageValue = (float) ($asset->salvage_value ?? 0);

        return $purchaseValue > 0 && $purchaseValue > $salvageValue;
    }

    private function straightLineAnnualAmount(FixedAsset $asset, float $depreciableBase): ?float
    {
        $usefulLife = $asset->useful_life === null ? null : (float) $asset->useful_life;

        return $usefulLife !== null && $usefulLife > 0 ? $depreciableBase / $usefulLife : null;
    }

    private function decliningBalanceAnnualAmount(FixedAsset $asset, float $purchaseValue, float $salvageValue, float $previousDepreciation): ?float
    {
        if ($asset->annual_depreciation_rate === null || (float) $asset->annual_depreciation_rate <= 0) {
            return null;
        }

        $bookValue = max(0.0, $purchaseValue - $previousDepreciation);
        $amountAboveSalvage = max(0.0, $bookValue - $salvageValue);

        return min($bookValue * ((float) $asset->annual_depreciation_rate / 100), $amountAboveSalvage);
    }

    private function doubleDecliningBalanceAnnualAmount(FixedAsset $asset, float $purchaseValue, float $salvageValue, float $previousDepreciation): ?float
    {
        if ($asset->useful_life === null || (float) $asset->useful_life <= 0) {
            return null;
        }

        $bookValue = max(0.0, $purchaseValue - $previousDepreciation);
        $amountAboveSalvage = max(0.0, $bookValue - $salvageValue);

        return min($bookValue * (2 / (float) $asset->useful_life), $amountAboveSalvage);
    }

    private function sumOfYearsDigitsFirstYearAmount(FixedAsset $asset, float $depreciableBase): ?float
    {
        if ($asset->useful_life === null || (float) $asset->useful_life <= 0) {
            return null;
        }

        $usefulLife = (float) $asset->useful_life;
        $denominator = ($usefulLife * ($usefulLife + 1)) / 2;

        return $denominator > 0 ? $depreciableBase * ($usefulLife / $denominator) : null;
    }

    /**
     * @return array{0: float|null, 1: float|null, 2: null}
     */
    private function straightLineInputs(?float $usefulLife, ?float $annualDepreciationRate): array
    {
        if ($usefulLife === null && $annualDepreciationRate !== null && $annualDepreciationRate > 0) {
            $usefulLife = 100 / $annualDepreciationRate;
        }

        if ($annualDepreciationRate === null && $usefulLife !== null && $usefulLife > 0) {
            $annualDepreciationRate = 100 / $usefulLife;
        }

        return [$usefulLife, $annualDepreciationRate, null];
    }
}

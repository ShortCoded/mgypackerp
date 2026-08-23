<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Carbon;
use Modules\FixedAssets\Models\FixedAsset;

class FixedAssetDepreciationCalculator
{
    private const DefaultDaysInYear = 365;

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

        $eligibleRange = $this->eligibleDateRange($asset, $from, $to);

        if ($eligibleRange === null) {
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

        $annualDepreciation = $depreciableBase / $usefulLife;
        $periodDepreciation = ($annualDepreciation / $this->dayBasis()) * $eligibleRange['days'];

        return round(min($periodDepreciation, $remainingDepreciableAmount), 4);
    }

    /**
     * @param  array<string, string>  $position
     * @return array<string, string>|null
     */
    public function snapshot(FixedAsset $asset, Carbon $from, Carbon $to, array $position, ?string $usageUnits = null): ?array
    {
        if ($to->lt($from) || ! $this->canCalculateSnapshot($asset, $usageUnits) || bccomp($position['remaining_depreciable_amount'], '0', 4) <= 0) {
            return null;
        }

        $eligibleRange = $this->eligibleDateRange($asset, $from, $to);

        if ($eligibleRange === null) {
            return null;
        }

        $periodStart = $eligibleRange['start'];
        $periodEnd = $eligibleRange['end'];
        $dayBasis = (string) $this->dayBasis();
        $days = (string) $eligibleRange['days'];
        $annualDepreciation = $this->annualAmountForSnapshot($asset, $position, $periodStart);
        $periodDepreciation = $asset->depreciation_method === FixedAsset::DepreciationMethodUnitsOfProduction
            ? bcmul(bcdiv($position['depreciation_base'], number_format((float) $asset->expected_usage_units, 4, '.', ''), 8), (string) $usageUnits, 4)
            : bcmul(bcdiv($annualDepreciation, $dayBasis, 8), $days, 4);
        $periodDepreciation = bccomp($periodDepreciation, $position['remaining_depreciable_amount'], 4) > 0
            ? $position['remaining_depreciable_amount']
            : $periodDepreciation;

        if (bccomp($periodDepreciation, '0', 4) <= 0) {
            return null;
        }

        $rate = number_format((float) ($asset->exchange_rate ?: 1), 6, '.', '');
        $basePeriodDepreciation = bcmul($periodDepreciation, $rate, 4);
        $basePeriodDepreciation = bccomp($basePeriodDepreciation, $position['base_remaining_depreciable_amount'], 4) > 0
            ? $position['base_remaining_depreciable_amount']
            : $basePeriodDepreciation;
        $accumulatedAfter = bcadd($position['accumulated_depreciation'], $periodDepreciation, 4);
        $baseAccumulatedAfter = bcadd($position['base_accumulated_depreciation'], $basePeriodDepreciation, 4);

        return [
            ...$position,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'period_depreciation' => $periodDepreciation,
            'base_period_depreciation' => $basePeriodDepreciation,
            'usage_units' => $usageUnits,
            'accumulated_before' => $position['accumulated_depreciation'],
            'base_accumulated_before' => $position['base_accumulated_depreciation'],
            'accumulated_after' => $accumulatedAfter,
            'base_accumulated_after' => $baseAccumulatedAfter,
            'closing_net_book_value' => bcsub($position['acquisition_cost'], $accumulatedAfter, 4),
            'base_closing_net_book_value' => bcsub($position['base_acquisition_cost'], $baseAccumulatedAfter, 4),
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon, days: int}|null
     */
    public function eligibleDateRange(FixedAsset $asset, Carbon $from, Carbon $to): ?array
    {
        if ($asset->depreciation_start_date === null || $to->lt($from)) {
            return null;
        }

        $serviceDate = Carbon::parse($asset->depreciation_start_date)->startOfDay();
        if (! (bool) config('fixed_assets.activation_date_inclusive', true)) {
            $serviceDate->addDay();
        }

        $periodStart = $from->copy()->startOfDay()->max($serviceDate);
        if ($asset->previous_depreciation_until_date !== null) {
            $periodStart = $periodStart->max($asset->previous_depreciation_until_date->copy()->addDay()->startOfDay());
        }

        $periodEnd = $to->copy()->startOfDay();
        if ($asset->disposed_at !== null) {
            $periodEnd = $periodEnd->min($this->disposalCutoffDate($asset->disposed_at));
        }

        if ($periodEnd->lt($periodStart)) {
            return null;
        }

        return [
            'start' => $periodStart,
            'end' => $periodEnd,
            'days' => (int) $periodStart->diffInDays($periodEnd) + 1,
        ];
    }

    public function dayBasis(): int
    {
        return max(1, (int) config('fixed_assets.day_basis', self::DefaultDaysInYear));
    }

    public function disposalCutoffDate(Carbon $disposalDate): Carbon
    {
        return match ((string) config('fixed_assets.disposal_cutoff_policy', 'start_of_disposal_month')) {
            'through_disposal_date' => $disposalDate->copy()->startOfDay(),
            'day_before_disposal' => $disposalDate->copy()->subDay()->startOfDay(),
            default => $disposalDate->copy()->startOfMonth()->subDay()->startOfDay(),
        };
    }

    private function canCalculateSnapshot(FixedAsset $asset, ?string $usageUnits): bool
    {
        if (! (bool) $asset->is_depreciable
            || $asset->depreciation_start_date === null
            || $asset->purchase_value === null
            || ! in_array($asset->depreciation_method, FixedAsset::depreciationMethods(), true)
        ) {
            return false;
        }

        if ($asset->depreciation_method === FixedAsset::DepreciationMethodUnitsOfProduction) {
            return $asset->expected_usage_units !== null
                && (float) $asset->expected_usage_units > 0
                && $usageUnits !== null
                && is_numeric($usageUnits)
                && bccomp($usageUnits, '0', 4) > 0;
        }

        if (in_array($asset->depreciation_method, [FixedAsset::DepreciationMethodStraightLine, FixedAsset::DepreciationMethodDoubleDecliningBalance, FixedAsset::DepreciationMethodSumOfYearsDigits], true)) {
            return $asset->useful_life !== null && (float) $asset->useful_life > 0;
        }

        return $asset->annual_depreciation_rate !== null && (float) $asset->annual_depreciation_rate > 0;
    }

    /**
     * @param  array<string, string>  $position
     */
    private function annualAmountForSnapshot(FixedAsset $asset, array $position, Carbon $periodStart): string
    {
        $usefulLife = number_format((float) ($asset->useful_life ?: 1), 4, '.', '');
        $bookValue = $position['net_book_value'];

        return match ($asset->depreciation_method) {
            FixedAsset::DepreciationMethodStraightLine => bcdiv($position['depreciation_base'], $usefulLife, 8),
            FixedAsset::DepreciationMethodDecliningBalance => bcmul($bookValue, bcdiv(number_format((float) $asset->annual_depreciation_rate, 4, '.', ''), '100', 8), 8),
            FixedAsset::DepreciationMethodDoubleDecliningBalance => bcmul($bookValue, bcdiv('2', $usefulLife, 8), 8),
            FixedAsset::DepreciationMethodSumOfYearsDigits => $this->sumOfYearsDigitsAnnualAmount($asset, $position['depreciation_base'], $periodStart),
            default => '0.00000000',
        };
    }

    private function sumOfYearsDigitsAnnualAmount(FixedAsset $asset, string $depreciationBase, Carbon $periodStart): string
    {
        $life = max(1, (int) ceil((float) $asset->useful_life));
        $serviceDate = Carbon::parse($asset->depreciation_start_date);
        $yearIndex = min($life - 1, max(0, $serviceDate->diffInYears($periodStart)));
        $remainingYears = (string) ($life - $yearIndex);
        $denominator = (string) (($life * ($life + 1)) / 2);

        return bcmul($depreciationBase, bcdiv($remainingYears, $denominator, 8), 8);
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

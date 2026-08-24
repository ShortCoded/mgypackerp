<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Carbon;
use Modules\Core\Services\NumericFormatService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetDepreciation;

class FixedAssetBookValueService
{
    public function __construct(private readonly NumericFormatService $numbers) {}

    /**
     * @return array{
     *     acquisition_cost: string,
     *     base_acquisition_cost: string,
     *     residual_value: string,
     *     base_residual_value: string,
     *     depreciation_base: string,
     *     base_depreciation_base: string,
     *     accumulated_depreciation: string,
     *     base_accumulated_depreciation: string,
     *     net_book_value: string,
     *     base_net_book_value: string,
     *     remaining_depreciable_amount: string,
     *     base_remaining_depreciable_amount: string
     * }
     */
    public function position(FixedAsset $asset, ?Carbon $through = null): array
    {
        $cost = $this->scale($asset->purchase_value);
        $rate = $this->rate($asset->exchange_rate);
        $baseCost = $asset->base_acquisition_value === null
            ? bcmul($cost, $rate, 4)
            : $this->scale($asset->base_acquisition_value);
        $residual = $this->scale($asset->salvage_value);
        $baseResidual = bcmul($residual, $rate, 4);
        $openingAccumulated = $this->scale($asset->previous_depreciation);
        $baseOpeningAccumulated = bcmul($openingAccumulated, $rate, 4);
        [$periodAccumulated, $basePeriodAccumulated] = $this->postedDepreciationTotals($asset, $through);
        $accumulated = bcadd($openingAccumulated, $periodAccumulated, 4);
        $baseAccumulated = bcadd($baseOpeningAccumulated, $basePeriodAccumulated, 4);
        $depreciationBase = $this->nonNegative(bcsub($cost, $residual, 4));
        $baseDepreciationBase = $this->nonNegative(bcsub($baseCost, $baseResidual, 4));
        $accumulated = $this->minimum($accumulated, $depreciationBase);
        $baseAccumulated = $this->minimum($baseAccumulated, $baseDepreciationBase);

        return [
            'acquisition_cost' => $cost,
            'base_acquisition_cost' => $baseCost,
            'residual_value' => $residual,
            'base_residual_value' => $baseResidual,
            'depreciation_base' => $depreciationBase,
            'base_depreciation_base' => $baseDepreciationBase,
            'accumulated_depreciation' => $accumulated,
            'base_accumulated_depreciation' => $baseAccumulated,
            'net_book_value' => $this->maximum(bcsub($cost, $accumulated, 4), $residual),
            'base_net_book_value' => $this->maximum(bcsub($baseCost, $baseAccumulated, 4), $baseResidual),
            'remaining_depreciable_amount' => $this->nonNegative(bcsub($depreciationBase, $accumulated, 4)),
            'base_remaining_depreciable_amount' => $this->nonNegative(bcsub($baseDepreciationBase, $baseAccumulated, 4)),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function postedDepreciationTotals(FixedAsset $asset, ?Carbon $through): array
    {
        if ($through === null && $asset->relationLoaded('postedDepreciations')) {
            return [
                $asset->postedDepreciations->reduce(fn (string $total, FixedAssetDepreciation $row): string => bcadd($total, $this->scale($row->period_depreciation), 4), '0.0000'),
                $asset->postedDepreciations->reduce(fn (string $total, FixedAssetDepreciation $row): string => bcadd($total, $this->scale($row->base_period_depreciation), 4), '0.0000'),
            ];
        }

        $query = $asset->postedDepreciations();

        if ($through !== null) {
            $query->whereDate('period_end', '<=', $through->toDateString());
        }

        $totals = $query->reorder()
            ->selectRaw('COALESCE(SUM(period_depreciation), 0) as amount, COALESCE(SUM(base_period_depreciation), 0) as base_amount')
            ->first();

        return [$this->scale($totals?->amount), $this->scale($totals?->base_amount)];
    }

    private function scale(mixed $value): string
    {
        return $this->numbers->normalizeToScale($value, 4) ?? '0.0000';
    }

    private function rate(mixed $value): string
    {
        return $this->numbers->normalizeToScale($value, 6) ?? '1.000000';
    }

    private function nonNegative(string $value): string
    {
        return bccomp($value, '0', 4) >= 0 ? $value : '0.0000';
    }

    private function minimum(string $left, string $right): string
    {
        return bccomp($left, $right, 4) <= 0 ? $left : $right;
    }

    private function maximum(string $left, string $right): string
    {
        return bccomp($left, $right, 4) >= 0 ? $left : $right;
    }
}

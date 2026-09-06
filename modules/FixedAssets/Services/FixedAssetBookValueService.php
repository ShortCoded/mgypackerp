<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Carbon;
use Modules\Core\Services\NumericFormatService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetDepreciation;
use Modules\FixedAssets\Models\FixedAssetMovement;

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
        $through ??= now()->endOfDay();
        $movements = $asset->relationLoaded('costMovements')
            ? $asset->costMovements->filter(fn ($row): bool => $row->status === 'posted' && $row->movement_date->lte($through))
            : $asset->costMovements()->where('status', 'posted')->whereDate('movement_date', '<=', $through)->get();
        $recognition = $movements->first(fn (FixedAssetMovement $row): bool => in_array($row->movement_type, [FixedAssetMovement::TypeCapitalization, FixedAssetMovement::TypeOpening], true));
        $legacy = $asset->hasLegacyRecognition() ? $asset->legacy_recognition : null;
        $hasRecognition = $asset->hasPostedRecognition() && ! $legacy;
        $rate = $this->rate($asset->exchange_rate);
        $beforeEntry = $through->lt($asset->asset_date);
        $cost = $hasRecognition || $beforeEntry ? '0.0000' : $this->scale($legacy['purchase_value'] ?? $asset->purchase_value);
        $baseCost = $hasRecognition || $beforeEntry ? '0.0000' : ($asset->base_acquisition_value === null ? bcmul($cost, $rate, 4) : $this->scale($asset->base_acquisition_value));
        if ($recognition) {
            $cost = $movements->reduce(fn (string $sum, FixedAssetMovement $row): string => bcadd($sum, (string) $row->amount, 4), '0.0000');
            $baseCost = $movements->reduce(fn (string $sum, FixedAssetMovement $row): string => bcadd($sum, (string) $row->base_amount, 4), '0.0000');
        }
        if ($legacy && ! $beforeEntry) {
            $cost = bcadd($cost, $movements->reduce(fn (string $sum, FixedAssetMovement $row): string => bcadd($sum, (string) $row->amount, 4), '0.0000'), 4);
            $baseCost = bcadd($baseCost, $movements->reduce(fn (string $sum, FixedAssetMovement $row): string => bcadd($sum, (string) $row->base_amount, 4), '0.0000'), 4);
        }
        $residual = $this->scale($recognition ? data_get($recognition->snapshot, 'residual_value') : $asset->salvage_value);
        foreach ($movements as $movement) {
            if ($movement->revised_residual_value !== null) {
                $residual = (string) $movement->revised_residual_value;
            }
        }
        if (bccomp($cost, '0', 4) === 0) {
            $residual = '0.0000';
        }
        $baseResidual = bcmul($residual, $rate, 4);
        $openingAccumulated = $recognition ? (string) $recognition->opening_accumulated : ($hasRecognition || $beforeEntry ? '0.0000' : $this->scale($asset->previous_depreciation));
        $baseOpeningAccumulated = $recognition ? (string) $recognition->base_opening_accumulated : bcmul($openingAccumulated, $rate, 4);
        [$periodAccumulated, $basePeriodAccumulated] = $this->postedDepreciationTotals($asset, $through);
        $accumulated = bcadd($openingAccumulated, $periodAccumulated, 4);
        $baseAccumulated = bcadd($baseOpeningAccumulated, $basePeriodAccumulated, 4);
        $disposals = $asset->relationLoaded('disposals') ? $asset->disposals->filter(fn ($row): bool => $row->status === 'posted' && $row->disposal_date->lte($through)) : $asset->disposals()->where('status', 'posted')->whereDate('disposal_date', '<=', $through)->get();
        foreach ($disposals as $disposal) {
            $cost = bcsub($cost, (string) $disposal->original_cost, 4);
            $baseCost = bcsub($baseCost, (string) $disposal->base_original_cost, 4);
            $accumulated = bcsub($accumulated, (string) $disposal->accumulated_depreciation, 4);
            $baseAccumulated = bcsub($baseAccumulated, (string) $disposal->base_accumulated_depreciation, 4);
            $residual = '0.0000';
            $baseResidual = '0.0000';
        }
        $depreciationBase = $this->nonNegative(bcsub($cost, $residual, 4));
        $baseDepreciationBase = $this->nonNegative(bcsub($baseCost, $baseResidual, 4));

        return [
            'recognized' => $recognition !== null || ($legacy && ! $beforeEntry),
            'original_cost' => $recognition ? (string) $recognition->amount : $this->scale($legacy['purchase_value'] ?? $asset->purchase_value),
            'additions' => $movements->where('movement_type', FixedAssetMovement::TypeAddition)->reduce(fn (string $total, FixedAssetMovement $row): string => bcadd($total, (string) $row->amount, 4), '0.0000'),
            'acquisition_cost' => $cost,
            'base_acquisition_cost' => $baseCost,
            'residual_value' => $residual,
            'base_residual_value' => $baseResidual,
            'depreciation_base' => $depreciationBase,
            'base_depreciation_base' => $baseDepreciationBase,
            'accumulated_depreciation' => $accumulated,
            'base_accumulated_depreciation' => $baseAccumulated,
            'net_book_value' => bcsub($cost, $accumulated, 4),
            'base_net_book_value' => bcsub($baseCost, $baseAccumulated, 4),
            'remaining_depreciable_amount' => $this->nonNegative(bcsub($depreciationBase, $accumulated, 4)),
            'base_remaining_depreciable_amount' => $this->nonNegative(bcsub($baseDepreciationBase, $baseAccumulated, 4)),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function postedDepreciationTotals(FixedAsset $asset, ?Carbon $through): array
    {
        if ($asset->relationLoaded('postedDepreciations')) {
            $rows = $asset->postedDepreciations->filter(fn ($row): bool => $through === null || $row->period_end->lte($through));

            return [
                $rows->reduce(fn (string $total, FixedAssetDepreciation $row): string => bcadd($total, $this->scale($row->period_depreciation), 4), '0.0000'),
                $rows->reduce(fn (string $total, FixedAssetDepreciation $row): string => bcadd($total, $this->scale($row->base_period_depreciation), 4), '0.0000'),
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

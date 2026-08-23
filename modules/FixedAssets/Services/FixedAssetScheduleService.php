<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Carbon;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetDepreciation;

class FixedAssetScheduleService
{
    public function __construct(
        private readonly FixedAssetBookValueService $bookValues,
        private readonly FixedAssetDepreciationCalculator $calculator,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, projection_available: bool}
     */
    public function schedule(FixedAsset $asset, int $maximumPeriods = 600): array
    {
        $asset->loadMissing('postedDepreciations');
        $rows = $asset->postedDepreciations->map(fn (FixedAssetDepreciation $row): array => [
            'period_start' => $row->period_start,
            'period_end' => $row->period_end,
            'opening_net_book_value' => bcadd((string) $row->closing_net_book_value, (string) $row->period_depreciation, 4),
            'period_depreciation' => $row->period_depreciation,
            'accumulated_depreciation' => $row->accumulated_after,
            'closing_net_book_value' => $row->closing_net_book_value,
            'status' => 'posted',
            'journal_entry_id' => $row->journal_entry_id,
        ])->all();
        $position = $this->bookValues->position($asset);
        $projectionAvailable = (bool) $asset->is_depreciable
            && $asset->depreciation_start_date !== null
            && $asset->depreciation_method !== FixedAsset::DepreciationMethodUnitsOfProduction
            && ! $asset->isDisposed()
            && in_array($asset->status, [FixedAsset::StatusActive, FixedAsset::StatusFullyDepreciated], true)
            && bccomp($position['remaining_depreciable_amount'], '0', 4) > 0;

        if (! $projectionAvailable) {
            return ['rows' => $rows, 'projection_available' => false];
        }

        $lastPosted = $asset->postedDepreciations->last()?->period_end;
        $cursor = $lastPosted
            ? $lastPosted->copy()->addDay()->startOfMonth()
            : Carbon::parse($asset->depreciation_start_date)->startOfMonth();

        for ($period = 0; $period < $maximumPeriods && bccomp($position['remaining_depreciable_amount'], '0', 4) > 0; $period++) {
            $periodStart = $cursor->copy()->max(Carbon::parse($asset->depreciation_start_date));
            $periodEnd = $cursor->copy()->endOfMonth();
            $snapshot = $this->calculator->snapshot($asset, $periodStart, $periodEnd, $position);

            if ($snapshot === null) {
                break;
            }

            $rows[] = [
                'period_start' => Carbon::parse($snapshot['period_start']),
                'period_end' => Carbon::parse($snapshot['period_end']),
                'opening_net_book_value' => $position['net_book_value'],
                'period_depreciation' => $snapshot['period_depreciation'],
                'accumulated_depreciation' => $snapshot['accumulated_after'],
                'closing_net_book_value' => $snapshot['closing_net_book_value'],
                'status' => 'projected',
                'journal_entry_id' => null,
            ];
            $position = [
                ...$position,
                'accumulated_depreciation' => $snapshot['accumulated_after'],
                'base_accumulated_depreciation' => $snapshot['base_accumulated_after'],
                'net_book_value' => $snapshot['closing_net_book_value'],
                'base_net_book_value' => $snapshot['base_closing_net_book_value'],
                'remaining_depreciable_amount' => bcsub($position['remaining_depreciable_amount'], $snapshot['period_depreciation'], 4),
                'base_remaining_depreciable_amount' => bcsub($position['base_remaining_depreciable_amount'], $snapshot['base_period_depreciation'], 4),
            ];
            $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return ['rows' => $rows, 'projection_available' => true];
    }
}

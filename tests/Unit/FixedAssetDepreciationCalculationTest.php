<?php

use Illuminate\Support\Carbon;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Services\FixedAssetDepreciationCalculator;
use Tests\TestCase;

uses(TestCase::class);

function depreciationCalculationAsset(array $overrides = []): FixedAsset
{
    return new FixedAsset([
        'asset_name' => 'Depreciation Calculation Sample',
        'is_depreciable' => true,
        'purchase_value' => '120000.0000',
        'salvage_value' => '20000.0000',
        'previous_depreciation' => '0.0000',
        'useful_life' => '5.00',
        'annual_depreciation_rate' => null,
        'expected_usage_units' => null,
        'operation_date' => '2026-01-01',
        'depreciation_start_date' => '2026-01-01',
        ...$overrides,
    ]);
}

function assertDepreciationCalculationEquals(float $expected, ?float $actual): void
{
    test()->assertNotNull($actual);
    test()->assertEqualsWithDelta($expected, (float) $actual, 0.01);
}

test('DepreciationCalculation verifies first year sample amounts for all supported methods', function (): void {
    $calculator = new FixedAssetDepreciationCalculator;
    $usedUnits = 4000;

    $samples = [
        FixedAsset::DepreciationMethodStraightLine => [
            'asset' => depreciationCalculationAsset([
                'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
            ]),
            'expected_depreciation' => 20000.00,
            'expected_ending_book_value' => 100000.00,
        ],
        FixedAsset::DepreciationMethodDecliningBalance => [
            'asset' => depreciationCalculationAsset([
                'depreciation_method' => FixedAsset::DepreciationMethodDecliningBalance,
                'annual_depreciation_rate' => '20.0000',
            ]),
            'expected_depreciation' => 24000.00,
            'expected_ending_book_value' => 96000.00,
        ],
        FixedAsset::DepreciationMethodDoubleDecliningBalance => [
            'asset' => depreciationCalculationAsset([
                'depreciation_method' => FixedAsset::DepreciationMethodDoubleDecliningBalance,
            ]),
            'expected_depreciation' => 48000.00,
            'expected_ending_book_value' => 72000.00,
        ],
        FixedAsset::DepreciationMethodSumOfYearsDigits => [
            'asset' => depreciationCalculationAsset([
                'depreciation_method' => FixedAsset::DepreciationMethodSumOfYearsDigits,
            ]),
            'expected_depreciation' => 33333.33,
            'expected_ending_book_value' => 86666.67,
        ],
    ];

    foreach ($samples as $sample) {
        $asset = $sample['asset'];
        $actualDepreciation = $calculator->theoreticalAnnualDepreciation($asset);
        $endingBookValue = (float) $asset->purchase_value - (float) $actualDepreciation;

        assertDepreciationCalculationEquals($sample['expected_depreciation'], $actualDepreciation);
        $this->assertEqualsWithDelta($sample['expected_ending_book_value'], $endingBookValue, 0.01);
    }

    $unitsAsset = depreciationCalculationAsset([
        'depreciation_method' => FixedAsset::DepreciationMethodUnitsOfProduction,
        'expected_usage_units' => '50000.0000',
        'useful_life' => null,
    ]);
    $actualDepreciationPerUnit = $calculator->depreciationPerUsageUnit($unitsAsset);
    $actualPeriodDepreciation = $actualDepreciationPerUnit === null ? null : $actualDepreciationPerUnit * $usedUnits;
    $endingBookValue = (float) $unitsAsset->purchase_value - (float) $actualPeriodDepreciation;

    assertDepreciationCalculationEquals(2.00, $actualDepreciationPerUnit);
    assertDepreciationCalculationEquals(8000.00, $actualPeriodDepreciation);
    $this->assertEqualsWithDelta(112000.00, $endingBookValue, 0.01);
});

test('DepreciationCalculation verifies salvage floor previous depreciation and invalid setup conventions', function (): void {
    $calculator = new FixedAssetDepreciationCalculator;

    $decliningFloorAsset = depreciationCalculationAsset([
        'depreciation_method' => FixedAsset::DepreciationMethodDecliningBalance,
        'annual_depreciation_rate' => '20.0000',
        'previous_depreciation' => '99000.0000',
    ]);
    assertDepreciationCalculationEquals(1000.00, $calculator->theoreticalAnnualDepreciation($decliningFloorAsset));

    $doubleDecliningFloorAsset = depreciationCalculationAsset([
        'depreciation_method' => FixedAsset::DepreciationMethodDoubleDecliningBalance,
        'previous_depreciation' => '99000.0000',
    ]);
    assertDepreciationCalculationEquals(1000.00, $calculator->theoreticalAnnualDepreciation($doubleDecliningFloorAsset));

    $previousDepreciationAsset = depreciationCalculationAsset([
        'depreciation_method' => FixedAsset::DepreciationMethodDecliningBalance,
        'annual_depreciation_rate' => '20.0000',
        'previous_depreciation' => '30000.0000',
    ]);
    assertDepreciationCalculationEquals(18000.00, $calculator->theoreticalAnnualDepreciation($previousDepreciationAsset));

    $nonDepreciableAsset = depreciationCalculationAsset([
        'is_depreciable' => false,
        'depreciation_method' => null,
    ]);
    expect($calculator->theoreticalAnnualDepreciation($nonDepreciableAsset))->toBeNull()
        ->and($calculator->depreciationPerUsageUnit($nonDepreciableAsset))->toBeNull()
        ->and($calculator->calculateForPeriod($nonDepreciableAsset, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')))->toBe(0.0);

    $missingExpectedUnitsAsset = depreciationCalculationAsset([
        'depreciation_method' => FixedAsset::DepreciationMethodUnitsOfProduction,
        'expected_usage_units' => null,
    ]);
    expect($calculator->depreciationPerUsageUnit($missingExpectedUnitsAsset))->toBeNull();
});

test('daily depreciation policy has explicit activation disposal leap year opening and residual boundaries', function (): void {
    config()->set('fixed_assets.day_basis', 365);
    config()->set('fixed_assets.activation_date_inclusive', true);
    config()->set('fixed_assets.disposal_cutoff_policy', 'start_of_disposal_month');

    $calculator = new FixedAssetDepreciationCalculator;
    $fullMonth = depreciationCalculationAsset([
        'purchase_value' => '36500.0000',
        'salvage_value' => '0.0000',
        'useful_life' => '1.00',
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'depreciation_start_date' => '2024-01-01',
    ]);
    $midMonthActivation = depreciationCalculationAsset([
        'purchase_value' => '36500.0000',
        'salvage_value' => '0.0000',
        'useful_life' => '1.00',
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'depreciation_start_date' => '2026-08-20',
    ]);
    $midMonthDisposal = depreciationCalculationAsset([
        'purchase_value' => '36500.0000',
        'salvage_value' => '0.0000',
        'useful_life' => '1.00',
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'depreciation_start_date' => '2026-01-01',
        'disposed_at' => '2026-08-18',
    ]);
    $sameMonthLifecycle = depreciationCalculationAsset([
        'purchase_value' => '36500.0000',
        'salvage_value' => '0.0000',
        'useful_life' => '1.00',
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'depreciation_start_date' => '2026-08-05',
        'disposed_at' => '2026-08-20',
    ]);
    $openingBoundary = depreciationCalculationAsset([
        'purchase_value' => '36500.0000',
        'salvage_value' => '0.0000',
        'useful_life' => '1.00',
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'depreciation_start_date' => '2026-01-01',
        'previous_depreciation_until_date' => '2026-06-30',
    ]);
    $residualCap = depreciationCalculationAsset([
        'purchase_value' => '100000.0000',
        'salvage_value' => '10000.0000',
        'previous_depreciation' => '89999.0000',
        'useful_life' => '5.00',
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'depreciation_start_date' => '2026-01-01',
    ]);

    $activationRange = $calculator->eligibleDateRange($midMonthActivation, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
    $openingRange = $calculator->eligibleDateRange($openingBoundary, Carbon::parse('2026-06-01'), Carbon::parse('2026-07-31'));

    expect($calculator->dayBasis())->toBe(365)
        ->and($calculator->calculateForPeriod($fullMonth, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')))->toBe(3100.0)
        ->and($calculator->calculateForPeriod($fullMonth, Carbon::parse('2024-02-01'), Carbon::parse('2024-02-29')))->toBe(2900.0)
        ->and($activationRange)->not->toBeNull()
        ->and($activationRange['start']->toDateString())->toBe('2026-08-20')
        ->and($activationRange['end']->toDateString())->toBe('2026-08-31')
        ->and($activationRange['days'])->toBe(12)
        ->and($calculator->calculateForPeriod($midMonthActivation, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')))->toBe(1200.0)
        ->and($calculator->calculateForPeriod($midMonthDisposal, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')))->toBe(0.0)
        ->and($calculator->disposalCutoffDate(Carbon::parse('2026-08-18'))->toDateString())->toBe('2026-07-31')
        ->and($calculator->calculateForPeriod($sameMonthLifecycle, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')))->toBe(0.0)
        ->and($openingRange)->not->toBeNull()
        ->and($openingRange['start']->toDateString())->toBe('2026-07-01')
        ->and($openingRange['days'])->toBe(31)
        ->and($calculator->calculateForPeriod($openingBoundary, Carbon::parse('2026-06-01'), Carbon::parse('2026-07-31')))->toBe(3100.0)
        ->and($calculator->calculateForPeriod($residualCap, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')))->toBe(1.0);
});

test('monthly posted snapshots match annual method amounts instead of compounding monthly by accident', function (string $method, float $expected): void {
    $calculator = new FixedAssetDepreciationCalculator;
    $asset = depreciationCalculationAsset(['depreciation_method' => $method, 'annual_depreciation_rate' => '20', 'expected_usage_units' => '48000']);
    $position = ['acquisition_cost' => '120000.0000', 'base_acquisition_cost' => '120000.0000', 'residual_value' => '20000.0000', 'base_residual_value' => '20000.0000', 'depreciation_base' => '100000.0000', 'base_depreciation_base' => '100000.0000', 'accumulated_depreciation' => '0.0000', 'base_accumulated_depreciation' => '0.0000', 'net_book_value' => '120000.0000', 'base_net_book_value' => '120000.0000', 'remaining_depreciable_amount' => '100000.0000', 'base_remaining_depreciable_amount' => '100000.0000'];
    $total = '0.0000';
    for ($month = 1; $month <= 12; $month++) {
        $from = Carbon::create(2026, $month, 1);
        $row = $calculator->snapshot($asset, $from, $from->copy()->endOfMonth(), $position, '400');
        expect($row)->not->toBeNull();
        $total = bcadd($total, $row['period_depreciation'], 4);
        $position = [...$row, 'accumulated_depreciation' => $row['accumulated_after'], 'base_accumulated_depreciation' => $row['base_accumulated_after'], 'net_book_value' => $row['closing_net_book_value'], 'base_net_book_value' => $row['base_closing_net_book_value'], 'remaining_depreciable_amount' => bcsub($row['closing_net_book_value'], '20000', 4), 'base_remaining_depreciable_amount' => bcsub($row['base_closing_net_book_value'], '20000', 4)];
    }
    expect((float) $total)->toEqualWithDelta($expected, 0.01);
})->with([
    [FixedAsset::DepreciationMethodStraightLine, 20000],
    [FixedAsset::DepreciationMethodDecliningBalance, 24000],
    [FixedAsset::DepreciationMethodDoubleDecliningBalance, 48000],
    [FixedAsset::DepreciationMethodSumOfYearsDigits, 100000 * 5 / 15],
    [FixedAsset::DepreciationMethodUnitsOfProduction, 10000],
]);

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

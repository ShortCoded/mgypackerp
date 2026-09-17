<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Exports\ReconciliationCenterExport;
use Modules\Accounting\Services\ReconciliationComparisonService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Product;
use Modules\Inventory\Exports\InventoryValuationComparisonExport;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Services\InventoryValuationService;

test('valuation export preserves every comparison method and column', function (): void {
    $comparison = app(InventoryValuationService::class)->compareMovements([
        ['quantity_in' => '10', 'quantity_out' => '0', 'unit_cost' => '10'],
        ['quantity_in' => '0', 'quantity_out' => '4'],
    ]);
    $export = new InventoryValuationComparisonExport($comparison);

    expect($export->headings())->toHaveCount(6)
        ->and($export->array())->toHaveCount(4)
        ->and($export->array()[0])->toHaveCount(6);
});

test('reconciliation export preserves unavailable and detailed controls without inventing matches', function (): void {
    $comparison = app(ReconciliationComparisonService::class);
    $report = [
        'results' => collect([
            $comparison->compare('customer', 'Customer', [[
                'key' => 'customer-one',
                'source_opening' => '10',
                'gl_opening' => '10',
                'source_movement' => '5',
                'gl_movement' => '5',
                'source_ending' => '15',
                'gl_ending' => '15',
            ]]),
            $comparison->compare('statement', 'Statement', [], sourceAvailable: false),
        ]),
    ];
    $export = new ReconciliationCenterExport($report);

    expect($export->headings())->toHaveCount(13)
        ->and($export->array())->toHaveCount(2)
        ->and($export->array()[0])->toHaveCount(13)
        ->and($export->array()[1])->toHaveCount(13)
        ->and($export->array()[1][2])->toBe(ReconciliationComparisonService::InsufficientData);
});

test('historical valuation is unchanged after a later inventory movement is added', function (): void {
    $companyId = DB::table('companies')->insertGetId([
        'name' => 'Valuation historical acceptance company',
        'status' => 'active',
        'is_main' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $branchId = DB::table('branches')->insertGetId([
        'company_id' => $companyId,
        'name' => 'Valuation historical acceptance branch',
        'type' => Branch::TypeWarehouse,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $periodId = DB::table('financial_periods')->insertGetId([
        'company_id' => $companyId,
        'name' => 'Valuation historical acceptance period',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $storeId = DB::table('branch_stores')->insertGetId([
        'branch_id' => $branchId,
        'name' => 'Valuation historical acceptance store',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $productId = DB::table('products')->insertGetId([
        'company_id' => $companyId,
        'doc_num' => 'VALUATION-HISTORY-'.Str::uuid(),
        'name' => 'Valuation historical acceptance fixture',
        'item_classification' => Product::stockableItemClassifications()[0],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $openingDate = Carbon::parse('2026-01-01');
    $cutoff = $openingDate->copy()->addDay();
    $laterDate = Carbon::parse('2026-12-31');
    $base = [
        'company_id' => $companyId,
        'financial_period_id' => $periodId,
        'branch_id' => $branchId,
        'branch_store_id' => $storeId,
        'transaction_type' => InventoryDocument::TypeReceipt,
        'product_id' => $productId,
        'quantity_out' => 0,
        'source_type' => 'valuation_acceptance_test',
        'source_id' => 1,
        'stock_status' => 'available',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('inventory_transactions')->insert([
        ...$base,
        'posting_key' => 'valuation-history-opening-'.Str::uuid(),
        'transaction_date' => $openingDate->toDateString(),
        'quantity_in' => 10,
        'source_doc_num' => 'VALUATION-HISTORY-OPENING',
        'unit_cost' => 10,
        'total_cost' => 100,
    ]);

    $service = app(InventoryValuationService::class);
    $beforeLaterMovement = $service->comparisonForStockPosition(
        $companyId,
        $periodId,
        $branchId,
        $storeId,
        (int) $productId,
        $cutoff->toDateString(),
    );

    DB::table('inventory_transactions')->insert([
        ...$base,
        'posting_key' => 'valuation-history-later-'.Str::uuid(),
        'transaction_date' => $laterDate->toDateString(),
        'quantity_in' => 10,
        'source_doc_num' => 'VALUATION-HISTORY-LATER',
        'unit_cost' => 20,
        'total_cost' => 200,
    ]);

    $sameHistoricalCutoff = $service->comparisonForStockPosition(
        $companyId,
        $periodId,
        $branchId,
        $storeId,
        (int) $productId,
        $cutoff->toDateString(),
    );
    $laterCutoff = $service->comparisonForStockPosition(
        $companyId,
        $periodId,
        $branchId,
        $storeId,
        (int) $productId,
        $laterDate->toDateString(),
    );

    expect($sameHistoricalCutoff)->toBe($beforeLaterMovement)
        ->and($sameHistoricalCutoff['source_count'])->toBe(1)
        ->and($sameHistoricalCutoff['available_cost'])->toBe('100.00000000')
        ->and($laterCutoff['source_count'])->toBe(2)
        ->and($laterCutoff['available_cost'])->toBe('300.00000000');
});

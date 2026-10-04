<?php

use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryReportService;

require_once __DIR__.'/ProcurementSupport.php';

test('inventory summary retains zero quantity value and unvalued exceptions with exact scope', function (string $value, string $unvalued): void {
    $f = procurementFixture(true);
    $f['company']->update(['name' => 'SYNTHETIC balance exceptions '.$f['company']->id]);
    $transaction = InventoryTransaction::query()->create([
        'posting_key' => 'SYNTHETIC-BALANCE-EXCEPTION-'.$f['company']->id,
        'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
        'branch_id' => $f['branch']->id, 'branch_store_id' => $f['store']->id,
        'transaction_date' => '2026-09-28', 'transaction_type' => 'value_adjustment',
        'product_id' => $f['raw']->id, 'unit_id' => $f['unit']->id,
        'quantity_in' => '0', 'quantity_out' => '0', 'unit_cost' => '0', 'total_cost' => '0',
        'value_delta' => $value, 'unvalued_quantity_delta' => $unvalued,
        'source_type' => 'synthetic_acceptance', 'source_id' => 1,
        'source_doc_num' => 'SYNTHETIC-BALANCE-EXCEPTION', 'stock_status' => InventoryTransaction::StatusAvailable,
    ]);
    $original = $transaction->refresh()->getAttributes();
    $service = app(InventoryReportService::class);
    $filters = ['as_of' => '2026-09-28', 'branch_store_id' => $f['store']->id];
    $balances = $service->balances($f['company']->id, $filters);
    $report = $service->report($f['company']->id, $f['period']->id, $f['branch']->id, $filters);

    expect($balances)->toHaveCount(1)
        ->and($balances->sole()->on_hand)->toBe('0.00000000')
        ->and($balances->sole()->inventory_value)->toBe($value)
        ->and($balances->sole()->unvalued_receipt_quantity)->toBe($unvalued)
        ->and($balances->sole()->unvalued_row_count)->toBe($unvalued === '0.00000000' ? 0 : 1)
        ->and($report['balances'])->toHaveCount(1)
        ->and($report['reportTotals']['on_hand'])->toBe('0.00000000')
        ->and($report['reportTotals']['inventory_value'])->toBe($value)
        ->and($report['reportTotals']['unvalued_receipt_quantity'])->toBe($unvalued)
        ->and($service->balances($f['company']->id, ['as_of' => '2026-09-27']))->toBeEmpty()
        ->and($service->balances($f['company']->id, ['product_id' => $f['finished']->id]))->toBeEmpty()
        ->and($service->balances($f['company']->id + 1000000, $filters))->toBeEmpty()
        ->and($service->balances($f['company']->id, [...$filters, 'branch_id' => $f['branch']->id + 1000000]))->toBeEmpty()
        ->and($service->balances($f['company']->id, [...$filters, 'financial_period_id' => $f['period']->id + 1000000]))->toBeEmpty()
        ->and($transaction->fresh()->getAttributes())->toBe($original);
})->with([
    'quantity neutral cost difference' => ['-50.00000000', '0.00000000'],
    'minimum legal stock value' => ['0.00000001', '0.00000000'],
    'unvalued legacy exception' => ['0.00000000', '3.00000000'],
]);

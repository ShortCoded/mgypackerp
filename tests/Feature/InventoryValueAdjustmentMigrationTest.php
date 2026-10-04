<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptCostProposal;

require_once __DIR__.'/../InventoryValueAdjustmentSupport.php';

test('value adjustment migration preserves preexisting ledger columns and their values on rollback', function (): void {
    $fixture = receiptCompletionFixture();
    $transaction = costTransitionTransaction($fixture, ['quantity_in' => '1', 'unit_cost' => '3', 'total_cost' => '3', 'value_delta' => '2']);
    DB::table('inventory_value_adjustment_column_ownership')->where('column_name', 'value_delta')->update(['created_by_migration' => false]);
    $migration = require base_path('modules/Inventory/Database/Migrations/2026_10_03_120400_add_inventory_value_adjustment_ledger.php');
    $migration->down();
    expect(Schema::hasColumn('inventory_transactions', 'value_delta'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_transactions', 'unvalued_quantity_delta'))->toBeFalse()
        ->and(DB::table('inventory_transactions')->where('id', $transaction->id)->value('value_delta'))->toBe(2)
        ->and(Schema::hasTable('inventory_value_adjustments'))->toBeFalse();
    $migration->up();
    expect((bool) DB::table('inventory_value_adjustment_column_ownership')->where('column_name', 'value_delta')->value('created_by_migration'))->toBeFalse()
        ->and((bool) DB::table('inventory_value_adjustment_column_ownership')->where('column_name', 'unvalued_quantity_delta')->value('created_by_migration'))->toBeTrue();
});

test('value adjustment migration refuses rollback when its own ledger evidence exists', function (): void {
    $fixture = receiptCompletionFixture();
    costTransitionTransaction($fixture, ['quantity_in' => '1', 'unit_cost' => '3', 'total_cost' => '3', 'value_delta' => '0']);
    $migration = require base_path('modules/Inventory/Database/Migrations/2026_10_03_120400_add_inventory_value_adjustment_ledger.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(Schema::hasTable('inventory_value_adjustments'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_transactions', 'value_delta'))->toBeTrue();
});

test('pending historical cost approvals remain recoverable and prevent destructive schema rollback', function (): void {
    $fixture = receiptCompletionFixture();
    $day = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $day, InventoryDocument::TypeReceipt, '2');
    $proposal = receiptCompletionPrepare($fixture, $receipt, '3', $day);
    $migration = require base_path('modules/Inventory/Database/Migrations/2026_10_03_120400_add_inventory_value_adjustment_ledger.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and($proposal->fresh()->impact_sha256)->toBe($proposal->impact_sha256)
        ->and($proposal->fresh()->status)->toBe(InventoryReceiptCostProposal::StatusPending);
});

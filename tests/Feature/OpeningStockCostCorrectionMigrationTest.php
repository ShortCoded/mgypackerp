<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\OpeningStockCostCorrection;
use Modules\Inventory\Services\OpeningStockCostCorrectionService;

require_once __DIR__.'/../OpeningStockCostCorrectionSupport.php';

test('opening correction schema can roll back while empty and refuses to destroy pending or approved evidence', function (): void {
    $migration = require base_path('modules/Inventory/Database/Migrations/2026_10_03_152544_create_inventory_opening_stock_cost_corrections_table.php');
    expect(OpeningStockCostCorrection::query()->count())->toBe(0);
    $migration->down();
    expect(Schema::hasTable('inventory_opening_stock_cost_corrections'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('inventory_opening_stock_cost_corrections'))->toBeTrue();
    $fixture = openingCorrectionFixture();
    $proposal = prepareOpeningCorrection($fixture, '8', ($fixture['day'])(3));
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and($proposal->fresh()->status)->toBe(OpeningStockCostCorrection::StatusPending)
        ->and($fixture['opening']->fresh()->lines->sole()->quantity)->toBe('10.0000');
    $this->actingAs($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    app(OpeningStockCostCorrectionService::class)->approve(request(), $fixture['opening'], $proposal, 'SYNTHETIC migration preservation review');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and($proposal->fresh()->status)->toBe(OpeningStockCostCorrection::StatusApproved)
        ->and($proposal->fresh()->valueAdjustment->journalEntry->status)->toBe('posted');
});

<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockCostCorrection;
use Modules\Inventory\Services\OpeningStockCostCorrectionService;

require_once __DIR__.'/OpeningStockCostCorrectionSupport.php';

test('inventory movement numbering remains unique across financial periods of one company', function (): void {
    $fixture = openingCorrectionFixture();
    $first = costTransitionMovement($fixture, ($fixture['day'])(2), InventoryDocument::TypeIssue, '1');
    $fixture['period']->update(['to_date' => '2026-06-30']);
    $later = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->id, 'doc_number' => 998703,
        'doc_num' => 'SYNTHETIC-MOVEMENT-NUMBER-PERIOD', 'name' => 'SYNTHETIC movement numbering period',
        'from_date' => '2026-07-01', 'to_date' => '2026-12-31', 'is_closed' => false,
    ]);
    $fixture['period'] = $later;
    session(costTransitionSession($fixture));
    $second = costTransitionMovement($fixture, '2026-07-01', InventoryDocument::TypeIssue, '1');
    expect($second->doc_number)->toBe($first->doc_number + 1)->and($second->doc_num)->not->toBe($first->doc_num)
        ->and($second->financial_period_id)->toBe($later->id)
        ->and($first->fresh()->transactions->sole()->completedTotalCost())->toBe('5.00000000')
        ->and($second->transactions->sole()->completedTotalCost())->toBe('5.00000000');
});

test('independent approval posts a causal correction without rewriting original opening or pricing evidence', function (): void {
    $fixture = openingCorrectionFixture();
    $day = $fixture['day'];
    $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '4');
    $root = InventoryTransaction::query()->where('source_type', OpeningStock::class)->where('source_id', $fixture['opening']->id)->sole();
    $original = [$root->quantity_in, $root->unit_cost, $root->total_cost, $issue->transactions->sole()->total_cost,
        $fixture['pricing']->updated_at->toISOString()];
    $correction = prepareOpeningCorrection($fixture, '8', $day(3));
    expect($correction->status)->toBe(OpeningStockCostCorrection::StatusPending)
        ->and($correction->plan['source_total'])->toBe('30.00000000')
        ->and($correction->plan['source_period_closed'])->toBeFalse()
        ->and($correction->source_snapshot['lines'][0]['root_unit_cost'])->toBe('5.00000000')
        ->and($correction->source_snapshot['lines'][0]['pricing_documents'][0]['pricing_doc_num'])->toBe($fixture['pricing']->doc_num)
        ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(0);
    $fixture['preparer']->givePermissionTo('inventory.opening_stock_cost_corrections.approve');
    expect(fn () => app(OpeningStockCostCorrectionService::class)->approve(request(), $fixture['opening'], $correction, 'SYNTHETIC self approval'))
        ->toThrow(DomainException::class);

    test()->actingAs($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    $approved = app(OpeningStockCostCorrectionService::class)->approve(
        request(), $fixture['opening'], $correction, 'SYNTHETIC independent approval REF-001',
    );
    $adjustment = $approved->adjustment()->with('lines', 'journalEntry.lines')->sole();
    $root->refresh();
    $issueTransaction = $issue->transactions->sole()->fresh();
    expect($approved->inventory_value_adjustment_id)->toBe($adjustment->id)
        ->and($root->quantity_in)->toBe($original[0])->and($root->unit_cost)->toBe($original[1])->and($root->total_cost)->toBe($original[2])
        ->and($issueTransaction->total_cost)->toBe($original[3])
        ->and($root->completedTotalCost())->toBe('80.00000000')
        ->and($issueTransaction->completedTotalCost())->toBe('32.00000000')
        ->and($fixture['pricing']->fresh()->updated_at->toISOString())->toBe($original[4])
        ->and($adjustment->lines->where('effect', 'expense')->sole()->amount)->toBe('12.00000000')
        ->and($adjustment->journalEntry->lines->sum('debit_amount'))->toBe(30.0)
        ->and($adjustment->journalEntry->lines->sum('credit_amount'))->toBe(30.0);
    expect(InventoryTransaction::query()->where('source_type', InventoryValueAdjustment::class)->where('source_id', $adjustment->id)->get()
        ->every(fn (InventoryTransaction $row): bool => bccomp((string) $row->quantity_in, '0', 8) === 0
            && bccomp((string) $row->quantity_out, '0', 8) === 0))->toBeTrue();
    $again = app(OpeningStockCostCorrectionService::class)->approve(
        request(), $fixture['opening'], $approved, 'SYNTHETIC independent approval REF-001',
    );
    expect($again->inventory_value_adjustment_id)->toBe($adjustment->id)
        ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_type', InventoryValueAdjustment::class)->where('source_id', $adjustment->id)->count())->toBe(1);
});

test('permissions, scope, rejection, and stale lineage are enforced without partial posting', function (): void {
    $fixture = openingCorrectionFixture();
    $day = $fixture['day'];
    $fixture['preparer']->revokePermissionTo('inventory.opening_stock_cost_corrections.prepare');
    expect(fn () => prepareOpeningCorrection($fixture, '7', $day(4)))->toThrow(AuthorizationException::class);
    $fixture['preparer']->givePermissionTo('inventory.opening_stock_cost_corrections.prepare');
    $correction = prepareOpeningCorrection($fixture, '7', $day(4));
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '1');
    test()->actingAs($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    expect(fn () => app(OpeningStockCostCorrectionService::class)->approve(request(), $fixture['opening'], $correction, 'SYNTHETIC stale approval'))
        ->toThrow(DomainException::class);
    expect($correction->fresh()->status)->toBe(OpeningStockCostCorrection::StatusPending)
        ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(0);
    expect(app(OpeningStockCostCorrectionService::class)->reject(
        request(), $fixture['opening'], $correction, 'SYNTHETIC stale lineage rejected',
    )->status)->toBe(OpeningStockCostCorrection::StatusRejected);

    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    request()->session()->put(OperatingContextService::BranchIdKey, 0);
    expect(fn () => prepareOpeningCorrection($fixture, '7', $day(4)))->toThrow(DomainException::class);
});

test('closed source remains immutable while correction posts to the current open period', function (): void {
    $fixture = openingCorrectionFixture();
    $sourcePeriod = $fixture['period'];
    $sourcePeriod->update(['is_closed' => true]);
    $target = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->id, 'doc_number' => 998702,
        'doc_num' => 'SYNTHETIC-OPENING-CORRECTION-PERIOD', 'name' => 'SYNTHETIC opening correction posting period',
        'from_date' => $sourcePeriod->to_date->copy()->addDay(), 'to_date' => $sourcePeriod->to_date->copy()->addMonth(), 'is_closed' => false,
    ]);
    request()->session()->put([
        OperatingContextService::FinancialPeriodIdKey => $target->id,
        OperatingContextService::FinancialPeriodDocNumKey => $target->doc_num,
    ]);
    $correction = prepareOpeningCorrection($fixture, '6', $target->from_date->toDateString());
    expect($correction->financial_period_id)->toBe($sourcePeriod->id)
        ->and($correction->posting_period_id)->toBe($target->id)
        ->and($correction->plan['source_period_closed'])->toBeTrue();
    test()->actingAs($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    $approved = app(OpeningStockCostCorrectionService::class)->approve(
        request(), $fixture['opening'], $correction, 'SYNTHETIC later-period approval',
    );
    expect($approved->adjustment->financial_period_id)->toBe($target->id)
        ->and($approved->adjustment->journalEntry->financial_period_id)->toBe($target->id)
        ->and($fixture['opening']->fresh()->financial_period_id)->toBe($sourcePeriod->id)
        ->and($fixture['opening']->fresh()->lines->sole()->quantity)->toBe('10.0000')
        ->and($sourcePeriod->fresh()->is_closed)->toBeTrue();

    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $target->update(['is_closed' => true]);
    expect(fn () => prepareOpeningCorrection($fixture, '7', $target->from_date->copy()->addDay()->toDateString()))
        ->toThrow(DomainException::class);
});

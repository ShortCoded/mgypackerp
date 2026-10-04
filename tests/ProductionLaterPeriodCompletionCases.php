<?php

use App\Services\PostingAccountResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionRunCorrectionService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/InventoryStandardCostSupport.php';
require_once __DIR__.'/InventoryValueAdjustmentSupport.php';

test('two fractional receipt cost revisions preserve booked waste and WIP reconciliation across source and later correction periods', function (): void {
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    $fixture = standardCostFixture(true);
    $fixture['product'] = $fixture['raw'];
    $receipt = costTransitionMovement($fixture, '2026-09-30', InventoryDocument::TypeReceipt, '20', '2');
    $run = standardCostCompletedRun($fixture);
    /** Explicit synthetic legacy fixture with missing original receipt valuation, never a migration or customer-data repair. */
    $legacyJournal = $receipt->journalEntry;
    $receipt->forceFill(['journal_entry_id' => null])->save();
    $receipt->lines()->update(['unit_cost' => null, 'total_cost' => null]);
    $receipt->transactions()->update(['unit_cost' => null, 'total_cost' => null]);
    InventoryReceiptLayer::query()->where('receipt_transaction_id', $receipt->transactions->sole()->id)->update(['unit_cost' => null]);
    DB::table('journal_entry_lines')->where('journal_entry_id', $legacyJournal->id)->delete();
    DB::table('journal_entries')->where('id', $legacyJournal->id)->delete();
    foreach (['inventory.documents.propose_receipt_cost', 'inventory.documents.approve_receipt_cost',
        'production.runs.correct', 'production.runs.correct_approve', 'production.runs.correct_later_period'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['preparer']->givePermissionTo(['inventory.documents.propose_receipt_cost', 'production.runs.correct', 'production.runs.correct_later_period']);
    $fixture['approver']->givePermissionTo(['inventory.documents.approve_receipt_cost', 'production.runs.correct_approve', 'production.runs.correct_later_period']);
    $fixture['counterpart'] = $fixture['clearing_account'];
    foreach (['3.00004900', '3.00008900'] as $cost) {
        standardCostActor($fixture['preparer']);
        receiptCompletionApprove($fixture, $receipt->fresh(), receiptCompletionPrepare($fixture, $receipt->fresh(), $cost, '2026-09-30'));
    }
    $waste = $run->inventoryDocuments()->where('document_type', InventoryDocument::TypeProductionWaste)->sole();
    $wasteTransaction = $waste->transactions()->where('is_reversal', false)->sole();
    expect($wasteTransaction->completedTotalCost())->toBe('3.00008900')
        ->and(InventoryValueAdjustment::query()->whereHas('lines', fn ($query) => $query->where('source_transaction_id', $wasteTransaction->id))->count())->toBe(2)
        ->and(app(ProductionCostService::class)->runPosition($run->fresh())['wip'])->toBe('0.00000000');
    foreach ([null, $fixture['branch']->id] as $branch) {
        $rows = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $branch))->keyBy('key');
        expect($rows['production_waste']['difference'])->toBe('0.0000', json_encode($rows['production_waste'], JSON_THROW_ON_ERROR))
            ->and($rows['wip']['difference'])->toBe('0.0000');
    }
    $fixture['period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
    $target = FinancialPeriod::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 99779,
        'doc_num' => 'SYNTHETIC-FRACTIONAL-OCT', 'name' => 'SYNTHETIC fractional later production',
        'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false]);
    $expenseAccount = app(PostingAccountResolver::class)->resolve($fixture['company']->id, PostingAccountResolver::AbnormalWasteLoss, 'SYNTHETIC reconciliation proof')->id;
    $sourceJournal = InventoryValueAdjustment::query()->whereHas('lines', fn ($query) => $query->where('source_transaction_id', $wasteTransaction->id))
        ->orderBy('id')->firstOrFail()->journalEntry;
    expect($sourceJournal->lines()->where('account_id', $expenseAccount)->sole()->debit_amount)->toBe('1.0000');
    $assertWasteDifference = function (int $period, string $expected) use ($fixture): void {
        foreach ([null, $fixture['branch']->id] as $branch) {
            $rows = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $period, $branch))->keyBy('key');
            expect($rows['production_waste']['difference'])->toBe($expected, json_encode($rows['production_waste'], JSON_THROW_ON_ERROR));
        }
    };
    $sourceJournal->update(['financial_period_id' => $target->id]);
    $assertWasteDifference($fixture['period']->id, '1.0000');
    $assertWasteDifference($target->id, '-1.0000');
    $sourceJournal->update(['financial_period_id' => $fixture['period']->id, 'is_posted' => false]);
    $assertWasteDifference($fixture['period']->id, '1.0000');
    $sourceJournal->update(['is_posted' => true]);
    $expenseLine = $sourceJournal->lines()->where('account_id', $expenseAccount)->sole();
    $originalDebit = $expenseLine->debit_amount;
    $expenseLine->update(['debit_amount' => bcadd($originalDebit, '0.0001', 4)]);
    $assertWasteDifference($fixture['period']->id, '-0.0001');
    $expenseLine->update(['debit_amount' => $originalDebit]);
    $this->travelTo(Carbon::parse('2026-10-04 12:00:00'));
    request()->session()->put([OperatingContextService::FinancialPeriodIdKey => $target->id, OperatingContextService::FinancialPeriodDocNumKey => $target->doc_num]);
    standardCostActor($fixture['preparer']);
    $service = app(ProductionRunCorrectionService::class);
    $proposal = $service->propose($run->fresh(), ['good_base_quantity' => '9', 'rejected_base_quantity' => '1', 'rework_base_quantity' => '0', 'scrap_base_quantity' => '0'],
        'SYNTHETIC fractional later correction', $service->preview($run->fresh())['fingerprint'], '2026-10-04', mode: 'later_period');
    standardCostActor($fixture['approver']);
    $service->approve($run->fresh(), $proposal->id);
    expect(app(ProductionCostService::class)->runPosition($run->fresh())['wip'])->toBe('60.00178000');
    foreach ([null, $fixture['branch']->id] as $branch) {
        foreach ([$fixture['period']->id => '3.0000', $target->id => '-3.0000'] as $period => $expected) {
            $rows = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $period, $branch))->keyBy('key');
            expect($rows['production_waste']['subledger'])->toBe($expected)->and($rows['production_waste']['difference'])->toBe('0.0000')
                ->and($rows['wip']['difference'])->toBe('0.0000');
        }
    }
    $inverse = JournalEntry::query()->where('company_id', $fixture['company']->id)
        ->where('source_type', 'inventory_document_cost_completion_reversal')->where('source_id', $waste->id)->sole();
    $inverse->update(['is_posted' => false]);
    $assertWasteDifference($target->id, '-1.0000');
    $inverse->update(['is_posted' => true, 'financial_period_id' => $fixture['period']->id]);
    $assertWasteDifference($target->id, '-1.0000');
    $assertWasteDifference($fixture['period']->id, '1.0000');
    $inverse->update(['financial_period_id' => $target->id]);
    $assertWasteDifference($target->id, '0.0000');
});

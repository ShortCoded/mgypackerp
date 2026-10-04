<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionRunCorrectionService;

require_once __DIR__.'/ProductionLaterPeriodCorrectionSupport.php';

test('completed production from a closed period reverses and recompletes through real routes in a frozen later period with original history and reconciled stock GL', function (): void {
    $fixture = productionLaterPeriodFixture();
    $run = $fixture['run'];
    $ended = $run->actual_end_at->toDateTimeString();
    $sourceDocuments = $run->inventoryDocuments()->where('status', InventoryDocument::StatusPosted)->get();
    $originalLines = DB::table('inventory_document_lines')->whereIn('inventory_document_id', $sourceDocuments->modelKeys())->orderBy('id')->get()->toJson();
    $originalTransactions = DB::table('inventory_transactions')->where('production_run_id', $run->id)->where('is_reversal', false)->orderBy('id')->get()->toJson();
    $originalProgress = $run->progressEntries()->orderBy('id')->get()->map->getRawOriginal()->all();
    $originalJournalLines = DB::table('journal_entry_lines')->whereIn('journal_entry_id', $sourceDocuments->pluck('journal_entry_id')->filter())->orderBy('id')->get()->toJson();
    $url = route('admin.production.runs.corrections.index', $run);
    $this->get($url)->assertOk()->assertSee('later_period')->assertSee($fixture['target']->name);
    $this->get(route('admin.production.runs.show', $run))->assertOk();
    $this->postJson(route('admin.production.runs.corrections.store', $run), [...$fixture['payload'], 'output' => ['good_base_quantity' => []]])
        ->assertUnprocessable()->assertJsonValidationErrors('output.good_base_quantity');
    $id = $this->postJson(route('admin.production.runs.corrections.store', $run), $fixture['payload'])->assertOk()->json('data.correction_id');
    $this->postJson(route('admin.production.runs.corrections.approve', [$run, $id]))->assertUnprocessable();
    $this->actingAs($fixture['approver'])->withSession($fixture['session'])
        ->postJson(route('admin.production.runs.corrections.approve', [$run, $id]))->assertOk();
    $run->refresh();
    expect($run->financial_period_id)->toBe($fixture['period']->id)->and($fixture['period']->fresh()->is_closed)->toBeTrue()
        ->and($run->actual_end_at->toDateTimeString())->toBe($ended)
        ->and($run->correction_posting_financial_period_id)->toBe($fixture['target']->id)
        ->and($run->active_correction_id)->toBe($id)->and($run->status)->toBe(ProductionRun::StatusRunning)
        ->and($fixture['issue']->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('40.00000000');
    $targetPosition = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['target']->id))->keyBy('key');
    expect($targetPosition['wip']['subledger'])->toBe('40.0000')->and($targetPosition['wip']['difference'])->toBe('0.0000')
        ->and($targetPosition['production_waste']['subledger'])->toBe('-2.0000')->and($targetPosition['production_waste']['difference'])->toBe('0.0000');
    expect(DB::table('inventory_document_lines')->whereIn('inventory_document_id', $sourceDocuments->modelKeys())->orderBy('id')->get()->toJson())->toBe($originalLines)
        ->and(DB::table('inventory_transactions')->where('production_run_id', $run->id)->where('is_reversal', false)->orderBy('id')->get()->toJson())->toBe($originalTransactions)
        ->and(DB::table('journal_entry_lines')->whereIn('journal_entry_id', $sourceDocuments->pluck('journal_entry_id')->filter())->orderBy('id')->get()->toJson())->toBe($originalJournalLines);
    foreach (InventoryTransaction::query()->where('production_run_id', $run->id)->where('is_reversal', true)->get() as $transaction) {
        expect($transaction->financial_period_id)->toBe($fixture['target']->id)->and($transaction->transaction_date->toDateString())->toBe('2026-10-04');
    }
    foreach ($sourceDocuments->whereIn('document_type', [InventoryDocument::TypeProductionReceipt, InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste]) as $document) {
        $document->refresh()->load('journalEntry', 'reversalJournalEntry');
        expect($document->financial_period_id)->toBe($fixture['period']->id)->and($document->document_date->toDateString())->toBe('2026-09-30');
        if ($document->journal_entry_id !== null) {
            expect($document->journalEntry->financial_period_id)->toBe($fixture['period']->id)
                ->and($document->reversalJournalEntry->financial_period_id)->toBe($fixture['target']->id)
                ->and($document->reversalJournalEntry->entry_date->toDateString())->toBe('2026-10-04');
        } else {
            expect($document->reversal_journal_entry_id)->toBeNull();
        }
    }
    $transactionCount = InventoryTransaction::query()->count();
    $journalCount = JournalEntry::query()->count();
    $fixture['target']->update(['is_closed' => true]);
    $this->postJson(route('admin.production.runs.corrections.approve', [$run, $id]))->assertOk();
    $this->postJson(route('admin.production.runs.account', $run), ['branch_store_id' => $fixture['store']->id,
        'lines' => [['requirement_id' => $fixture['requirement']->id, 'consumed_quantity' => '18', 'waste_quantity' => '2']]])->assertUnprocessable();
    expect(InventoryTransaction::query()->count())->toBe($transactionCount)->and(JournalEntry::query()->count())->toBe($journalCount);
    $fixture['target']->update(['is_closed' => false]);
    $migration = require base_path('database/migrations/2026_10_03_175146_add_posting_period_to_production_run_corrections.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)->and($run->fresh()->active_correction_id)->toBe($id);
    $this->postJson(route('admin.production.runs.account', $run), ['branch_store_id' => $fixture['store']->id,
        'lines' => [['requirement_id' => $fixture['requirement']->id, 'consumed_quantity' => '18', 'waste_quantity' => '2']]])->assertOk();
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(route('admin.production.runs.labor', $run), [
        'actual_labor_count' => 1, 'labor_details' => [['employee_id' => $fixture['employee']->id, 'actual_hours' => '1', 'piece_quantity' => '9']],
    ])->assertOk();
    $inspection = $fixture['cycle']->recordInspection($run->fresh(), ['result' => 'passed', 'disposition' => 'release']);
    $fixture['cycle']->reviewInspection($inspection, true);
    expect($inspection->financial_period_id)->toBe($fixture['target']->id)->and($inspection->inspection_date->toDateString())->toBe('2026-10-04')
        ->and($inspection->correction_sequence)->toBe(1);
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(route('admin.production.runs.receive', $run), [
        'branch_store_id' => $fixture['store']->id, 'base_quantity' => '9',
    ])->assertOk();
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(route('admin.production.runs.complete', $run))->assertOk();
    $run->refresh();
    $replacement = $run->inventoryDocuments()->where('status', InventoryDocument::StatusPosted)->where('document_type', InventoryDocument::TypeProductionReceipt)->sole();
    expect($run->financial_period_id)->toBe($fixture['period']->id)->and($run->actual_end_at->toDateTimeString())->toBe($ended)
        ->and($run->status)->toBe(ProductionRun::StatusCompleted)->and($run->received_base_quantity)->toBe('9.00000000')
        ->and($replacement->financial_period_id)->toBe($fixture['target']->id)->and($replacement->document_date->toDateString())->toBe('2026-10-04')
        ->and($replacement->lines->sole()->total_cost)->toBe('36.00000000')
        ->and($replacement->lines->sole()->manufacture_date->toDateString())->toBe('2026-09-30')
        ->and($replacement->lines->sole()->expiry_date->toDateString())->toBe($fixture['receipt']->lines->sole()->expiry_date->toDateString())
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('0.00000000');
    foreach ($run->progressEntries()->orderBy('id')->limit(count($originalProgress))->get() as $index => $progress) {
        expect($progress->getRawOriginal())->toBe($originalProgress[$index]);
    }
    foreach ([$fixture['period'], $fixture['target']] as $period) {
        $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $period->id))->keyBy('key');
        expect($reconciliation['wip']['difference'])->toBe('0.0000')->and($reconciliation['finished_goods']['difference'])->toBe('0.0000');
        expect($reconciliation['production_waste']['subledger'])->toBe('2.0000')->and($reconciliation['production_waste']['difference'])->toBe('0.0000');
    }
    $second = app(ProductionRunCorrectionService::class)->preview($run);
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(route('admin.production.runs.corrections.store', $run), [
        ...$fixture['payload'], 'fingerprint' => $second['fingerprint'], 'reason' => 'SYNTHETIC next correction review',
    ])->assertForbidden();
    $this->actingAs($fixture['user'])->withSession($fixture['session'])->postJson(route('admin.production.runs.corrections.store', $run), [
        ...$fixture['payload'], 'fingerprint' => $second['fingerprint'], 'reason' => 'SYNTHETIC next correction review',
    ])->assertOk();
});

test('later production approval refuses target closure context change or revoked later privilege without changing original effects', function (string $failure): void {
    $fixture = productionLaterPeriodFixture();
    $run = $fixture['run'];
    $id = $this->postJson(route('admin.production.runs.corrections.store', $run), $fixture['payload'])->assertOk()->json('data.correction_id');
    $original = $run->fresh()->getRawOriginal();
    $transactions = InventoryTransaction::query()->count();
    $journals = JournalEntry::query()->count();
    $session = $fixture['session'];
    if ($failure === 'closed') {
        $fixture['target']->update(['is_closed' => true]);
    } elseif ($failure === 'context') {
        $session = manufacturingIntegritySession($fixture);
    } else {
        $fixture['approver']->revokePermissionTo('production.runs.correct_later_period');
    }
    $response = $this->actingAs($fixture['approver'])->withSession($session)->postJson(route('admin.production.runs.corrections.approve', [$run, $id]));
    $failure === 'permission' ? $response->assertForbidden() : $response->assertUnprocessable();
    expect($run->fresh()->getRawOriginal())->toBe($original)
        ->and(DB::table('production_run_corrections')->where('id', $id)->value('status'))->toBe('prepared')
        ->and(InventoryTransaction::query()->count())->toBe($transactions)->and(JournalEntry::query()->count())->toBe($journals)
        ->and($fixture['receipt']->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and(DB::table('production_piece_approvals')->where('production_run_id', $run->id)->whereNotNull('revoked_at')->count())->toBe(0);
})->with(['closed', 'context', 'permission']);

test('later production correction refuses corrupt original journal headers or balanced altered source amounts atomically', function (string $tampering): void {
    $fixture = productionLaterPeriodFixture();
    $receipt = $fixture['receipt'];
    if ($tampering === 'period') {
        DB::table('journal_entries')->where('id', $receipt->journal_entry_id)->update(['financial_period_id' => $fixture['target']->id]);
    } elseif ($tampering === 'source') {
        DB::table('journal_entries')->where('id', $receipt->journal_entry_id)->update(['source_type' => 'SYNTHETIC-unrelated-owner']);
    } else {
        DB::table('journal_entry_lines')->where('journal_entry_id', $receipt->journal_entry_id)->where('debit_amount', '>', 0)->increment('debit_amount', 1);
        DB::table('journal_entry_lines')->where('journal_entry_id', $receipt->journal_entry_id)->where('credit_amount', '>', 0)->increment('credit_amount', 1);
    }
    $fixture['payload']['fingerprint'] = app(ProductionRunCorrectionService::class)->preview($fixture['run'])['fingerprint'];
    $id = $this->postJson(route('admin.production.runs.corrections.store', $fixture['run']), $fixture['payload'])->assertOk()->json('data.correction_id');
    $before = $fixture['run']->fresh()->getRawOriginal();
    $transactions = InventoryTransaction::query()->count();
    $journals = JournalEntry::query()->count();
    $this->actingAs($fixture['approver'])->withSession($fixture['session'])
        ->postJson(route('admin.production.runs.corrections.approve', [$fixture['run'], $id]))->assertUnprocessable();
    expect($fixture['run']->fresh()->getRawOriginal())->toBe($before)->and($receipt->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and(DB::table('production_run_corrections')->where('id', $id)->value('status'))->toBe('prepared')
        ->and(InventoryTransaction::query()->count())->toBe($transactions)->and(JournalEntry::query()->count())->toBe($journals)
        ->and($fixture['period']->fresh()->is_closed)->toBeTrue();
})->with(['period', 'source', 'amount']);

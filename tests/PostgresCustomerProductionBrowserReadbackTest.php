<?php

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionQualityQuantityService;
use Modules\Production\Services\ProductionShiftEvidenceService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBeIn(['mgypack_production_evidence_pg_20261005', 'mgypack_production_fullcycle_pg_20261005'])
        ->and($identity->host)->toBe('127.0.0.1')->and((int) $identity->port)->toBe(5432);
    DB::beginTransaction();
    DB::statement('SET TRANSACTION READ ONLY');
});

afterEach(function (): void {
    DB::rollBack();
});

test('saved native browser factory cycle reconciles quantity quality staging cost and balanced original journals', function (int $runId): void {
    $target = DB::connection()->getDatabaseName();
    $full = $target === 'mgypack_production_fullcycle_pg_20261005';
    $quantity = $runId === 13 ? '28.00000000' : ($full ? '80.00000000' : '75.00000000');
    $run = ProductionRun::query()->with('requirements')->where('company_id', 1)->findOrFail($runId);
    expect($run->good_base_quantity)->toBe($quantity)->and($run->received_base_quantity)->toBe($quantity)
        ->and($run->status)->toBe($runId === 13 || $full ? 'completed' : 'running')
        ->and($run->progressEntries()->count())->toBe($full ? 2 : 1)
        ->and(app(ProductionQualityQuantityService::class)->availableQuantity($run))->toBe('0.00000000');
    $position = app(ProductionCostService::class)->runPosition($run);
    $staging = InventoryTransaction::query()->where('company_id', 1)->where('branch_id', $run->branch_id)
        ->where('production_run_id', $runId)->where('stock_status', InventoryTransaction::StatusProductionStaging)
        ->selectRaw('coalesce(sum(quantity_in-quantity_out),0) as quantity')->first();
    expect(bccomp($position['wip'], '0', 8))->toBe($runId === 13 || $full ? 0 : 1)
        ->and(bccomp((string) $staging->quantity, '0', 8))->toBe($runId === 13 || $full ? 0 : 1);
    if ($full) {
        expect(bccomp($position['waste'], '0', 8))->toBe(1);
        expect($run->requirements->first()->waste_quantity)->toBe('1.00000000');
    }
    $documents = $run->inventoryDocuments()->where('status', InventoryDocument::StatusPosted)->with('journalEntry')->get();
    $journals = [];
    foreach ($documents as $document) {
        if ($document->document_type === InventoryDocument::TypeMaterialConsumption) {
            expect($document->journalEntry)->toBeNull();

            continue;
        }
        expect($document->journalEntry)->not->toBeNull();
        $totals = $document->journalEntry->lines()->reorder()->selectRaw('coalesce(sum(debit_amount),0) as debit, coalesce(sum(credit_amount),0) as credit')->first();
        expect(bccomp((string) $totals->debit, (string) $totals->credit, 4))->toBe(0);
        $journals[] = ['document' => $document->doc_num, 'type' => $document->document_type, 'journal_id' => $document->journalEntry->id,
            'debit' => (string) $totals->debit, 'credit' => (string) $totals->credit];
    }
    $shift = app(ProductionShiftEvidenceService::class)->report($run)->sole();
    expect($shift->ended_at)->not->toBeNull()->and($shift->good_base_quantity)->toBe($quantity)->and(count($shift->crew_snapshot))->toBe(2);
    if ($full) {
        expect($shift->sheet_fields['crew_source'])->toBe('override')->and(bccomp((string) $shift->downtime_minutes, '0', 4))->toBe(1);
    }
    $proof = ['at_utc' => gmdate(DATE_ATOM), 'target' => $target, 'readonly_readback' => true, 'run_id' => $runId,
        'run_number' => $run->run_number, 'good' => $run->good_base_quantity, 'received' => $run->received_base_quantity,
        'status' => $run->status, 'progress_count' => $run->progressEntries()->count(), 'stock_staging' => (string) $staging->quantity,
        'cost_position' => $position, 'journals' => $journals, 'shift_entry' => $shift->id, 'crew_source' => $shift->sheet_fields['crew_source'],
        'crew' => $shift->crew_snapshot, 'downtime_minutes' => (string) $shift->downtime_minutes,
        'synthetic_approval_only' => true, 'full_synthetic_replenishment_and_loss_mapping' => $full, 'exit_gate' => 'passed'];
    file_put_contents(storage_path('app/test-artifacts/mgypack-browser-readback-'.$target.'-run-'.$runId.'.json'), json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
})->with(['injection' => [13], 'cover' => [18]]);

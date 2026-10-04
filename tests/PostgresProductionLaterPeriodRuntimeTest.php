<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\FinancialPeriod;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionRunCorrectionService;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/ProductionLaterPeriodCorrectionSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('actual PostgreSQL later production duplicate approvals and replacement receipts serialize without duplicated effects', function (): void {
    if (getenv('MGYPACK_PRODUCTION_LATER_RUNTIME_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed isolated synthetic contention fixture.');
    }
    $fixture = productionLaterPeriodFixture(true, '2026-10-03');
    $run = $fixture['run'];
    $service = app(ProductionRunCorrectionService::class);
    $proposal = $service->propose($run, $fixture['payload']['output'], $fixture['payload']['reason'], $fixture['payload']['fingerprint'], '2026-10-03', mode: 'later_period');
    $sourceDocuments = $run->inventoryDocuments()->where('document_type', '!=', InventoryDocument::TypeMaterialIssue)->pluck('id');
    $originalCount = InventoryTransaction::query()->whereIn('source_id', $sourceDocuments)->where('source_type', InventoryDocument::class)->where('is_reversal', false)->count();
    $operation = ['operation' => 'production-correction', 'user' => $fixture['approver']->id, 'context' => $fixture['session'], 'run' => $run->id, 'correction' => $proposal->id];
    $results = closurePostgresRace([$operation, $operation], orderedCompanyLock: true);
    expect(collect($results)->pluck('result')->all())->toBe(['applied', 'applied'])
        ->and(collect($results)->pluck('id')->unique()->all())->toBe([$proposal->id])
        ->and(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
        ->and(InventoryTransaction::query()->whereIn('source_id', $sourceDocuments)->where('source_type', InventoryDocument::class)->where('is_reversal', true)->count())->toBe($originalCount)
        ->and($run->fresh()->correction_sequence)->toBe(1);
    $fixture['cycle']->accountMaterials($run->fresh(), $fixture['store']->id, [$fixture['requirement']->id => ['consumed_quantity' => '18', 'waste_quantity' => '2']]);
    $fixture['cycle']->recordLabor($run->fresh(), ['actual_labor_count' => 1,
        'labor_details' => [['employee_id' => $fixture['employee']->id, 'actual_hours' => '1', 'piece_quantity' => '9']]]);
    $inspection = $fixture['cycle']->recordInspection($run->fresh(), ['result' => 'passed', 'disposition' => 'release']);
    $fixture['cycle']->reviewInspection($inspection, true);
    $operation = ['operation' => 'production-receipt', 'user' => $fixture['approver']->id, 'context' => $fixture['session'], 'run' => $run->id, 'store' => $fixture['store']->id, 'quantity' => '9'];
    $results = closurePostgresRace([$operation, $operation], orderedCompanyLock: true);
    expect(collect($results)->pluck('result')->all())->toBe(['applied', 'blocked'])
        ->and(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
        ->and($run->fresh()->received_base_quantity)->toBe('9.00000000');
    $receipt = $run->inventoryDocuments()->where('document_type', InventoryDocument::TypeProductionReceipt)->where('status', InventoryDocument::StatusPosted)->sole();
    expect($receipt->financial_period_id)->toBe($fixture['target']->id)->and($receipt->transactions()->where('is_reversal', false)->count())->toBe(1)
        ->and($receipt->lines->sole()->total_cost)->toBe('36.00000000');
    $fixture['cycle']->completeRun($run->fresh());
    expect(app(ProductionCostService::class)->runPosition($run->fresh())['wip'])->toBe('0.00000000');
    foreach ([$fixture['period']->id, $fixture['target']->id] as $period) {
        $rows = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $period))->keyBy('key');
        foreach (['wip', 'finished_goods', 'production_waste'] as $key) {
            expect($rows[$key]['difference'])->toBe('0.0000', $key);
        }
    }
});

test('actual PostgreSQL output issue and later production correction preserve valid ordered intermediate states', function (): void {
    if (getenv('MGYPACK_PRODUCTION_LATER_RUNTIME_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed isolated synthetic contention fixture.');
    }
    foreach ([false, true] as $correctionFirst) {
        $fixture = productionLaterPeriodFixture(true, '2026-10-03');
        $run = $fixture['run'];
        $proposal = app(ProductionRunCorrectionService::class)->propose($run, $fixture['payload']['output'], $fixture['payload']['reason'], $fixture['payload']['fingerprint'], '2026-10-03', mode: 'later_period');
        $correction = ['operation' => 'production-correction', 'user' => $fixture['approver']->id, 'context' => $fixture['session'], 'run' => $run->id, 'correction' => $proposal->id];
        $issue = ['operation' => 'movement', 'user' => $fixture['user']->id, 'context' => $fixture['session'],
            'header' => ['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
                'financial_period_id' => $fixture['target']->id, 'document_date' => '2026-10-03', 'document_type' => InventoryDocument::TypeIssue,
                'notes' => 'SYNTHETIC ordered finished-output issue'],
            'lines' => [['product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '1',
                'batch_lot' => $fixture['receipt']->lines->sole()->batch_lot]]];
        $results = closurePostgresRace($correctionFirst ? [$correction, $issue] : [$issue, $correction], orderedCompanyLock: true);
        expect(collect($results)->pluck('result')->all())->toBe(['applied', 'blocked'], json_encode($results, JSON_THROW_ON_ERROR))
            ->and(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
            ->and(DB::table('production_run_corrections')->where('id', $proposal->id)->value('status'))->toBe($correctionFirst ? 'approved' : 'prepared')
            ->and($run->fresh()->status)->toBe($correctionFirst ? ProductionRun::StatusRunning : ProductionRun::StatusCompleted)
            ->and($fixture['receipt']->fresh()->status)->toBe($correctionFirst ? InventoryDocument::StatusReversed : InventoryDocument::StatusPosted);
        $position = app(InventoryAvailabilityService::class)->forProduct($fixture['company']->id, $fixture['store']->id, $fixture['finished']->id);
        expect($position['available'])->toBe($correctionFirst ? '0.00000000' : '9.00000000')
            ->and(app(ProductionCostService::class)->runPosition($run->fresh())['wip'])->toBe($correctionFirst ? '40.00000000' : '0.00000000');
    }
});

test('persist isolated synthetic production source and later target for ordinary browser correction approval', function (): void {
    if (getenv('MGYPACK_PRODUCTION_LATER_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic browser fixture only.');
    }
    $path = '/tmp/mgypack-production-later-period-runtime-20261003.json';
    if (is_file($path)) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $run = ProductionRun::query()->where('company_id', $manifest['company_id'])->findOrFail($manifest['run_id']);
        $manifest['run_uuid'] = $run->public_id;
        $manifest['run_doc_num'] = $run->run_number;
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        expect($run->public_id)->not->toBeNull();

        return;
    }
    DB::transaction(function () use ($path): void {
        $fixture = productionLaterPeriodFixture(true, '2026-10-03');
        foreach ([$fixture['user'], $fixture['approver']] as $user) {
            $user->update(['locale' => 'ar']);
            app(DefaultLoginContextService::class)->update($user, ['company_doc_num' => $fixture['company']->doc_num,
                'branch_doc_num' => $fixture['branch']->doc_num, 'financial_period_doc_num' => $fixture['target']->doc_num]);
        }
        $run = $fixture['run'];
        $documents = $run->inventoryDocuments()->orderBy('id')->get();
        $manifest = ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003', 'company_id' => $fixture['company']->id,
            'company_doc_num' => $fixture['company']->doc_num, 'branch_id' => $fixture['branch']->id, 'source_period_id' => $fixture['period']->id,
            'target_period_id' => $fixture['target']->id, 'session' => $fixture['session'], 'run_id' => $run->id, 'run_uuid' => $run->public_id,
            'run_doc_num' => $run->run_number, 'requirement_id' => $fixture['requirement']->id, 'store_id' => $fixture['store']->id,
            'employee_id' => $fixture['employee']->id, 'preparer_id' => $fixture['user']->id, 'approver_id' => $fixture['approver']->id,
            'preparer' => $fixture['user']->username, 'approver' => $fixture['approver']->username,
            'posting_date' => '2026-10-03', 'payload' => $fixture['payload'], 'phase' => 'source',
            'source_lines' => DB::table('inventory_document_lines')->whereIn('inventory_document_id', $documents->modelKeys())->orderBy('id')->get()->toArray(),
            'source_transactions' => DB::table('inventory_transactions')->where('production_run_id', $run->id)->orderBy('id')->get()->toArray(),
            'source_journal_lines' => DB::table('journal_entry_lines')->whereIn('journal_entry_id', $documents->pluck('journal_entry_id')->filter())->orderBy('id')->get()->toArray()];
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
    });
});

test('verify ordinary browser later production correction and complete replacement through actual authorized controllers', function (): void {
    if (getenv('MGYPACK_PRODUCTION_LATER_RUNTIME_FINISH') !== '1') {
        $this->markTestSkipped('Explicit persisted ordinary browser verification only.');
    }
    $path = '/tmp/mgypack-production-later-period-runtime-20261003.json';
    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $run = ProductionRun::query()->where('company_id', $manifest['company_id'])->findOrFail($manifest['run_id']);
    $proposal = DB::table('production_run_corrections')->where('id', $run->active_correction_id)->firstOrFail();
    expect($proposal->status)->toBe('approved')->and((int) $proposal->prepared_by)->toBe($manifest['preparer_id'])
        ->and((int) $proposal->approved_by)->toBe($manifest['approver_id'])->and((int) $proposal->posting_financial_period_id)->toBe($manifest['target_period_id']);
    $this->actingAs(User::query()->findOrFail($manifest['approver_id']))->withSession($manifest['session']);
    if ($manifest['phase'] !== 'finished') {
        $this->postJson(route('admin.production.runs.account', $run), ['branch_store_id' => $manifest['store_id'],
            'lines' => [['requirement_id' => $manifest['requirement_id'], 'consumed_quantity' => '18', 'waste_quantity' => '2']]])->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(route('admin.production.runs.labor', $run), ['actual_labor_count' => 1,
            'labor_details' => [['employee_id' => $manifest['employee_id'], 'actual_hours' => '1', 'piece_quantity' => '9']]])->assertOk();
        $cycle = app(ProductionCycleService::class);
        $inspection = $cycle->recordInspection($run->fresh(), ['result' => 'passed', 'disposition' => 'release']);
        $cycle->reviewInspection($inspection, true);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(route('admin.production.runs.receive', $run), ['branch_store_id' => $manifest['store_id'], 'base_quantity' => '9'])->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(route('admin.production.runs.complete', $run))->assertOk();
        $manifest['phase'] = 'finished';
        $manifest['proposal_id'] = (int) $proposal->id;
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    $run->refresh();
    expect($run->status)->toBe(ProductionRun::StatusCompleted)->and($run->received_base_quantity)->toBe('9.00000000')
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('0.00000000')
        ->and(FinancialPeriod::query()->findOrFail($manifest['source_period_id'])->is_closed)->toBeTrue();
    foreach (['inventory_document_lines' => ['inventory_document_id', collect($manifest['source_lines'])->pluck('inventory_document_id')->unique()->all(), 'source_lines'],
        'inventory_transactions' => ['id', collect($manifest['source_transactions'])->pluck('id')->all(), 'source_transactions'],
        'journal_entry_lines' => ['id', collect($manifest['source_journal_lines'])->pluck('id')->all(), 'source_journal_lines']] as $table => [$key, $ids, $snapshot]) {
        expect(DB::table($table)->whereIn($key, $ids)->orderBy('id')->get()->toJson())->toBe(json_encode($manifest[$snapshot], JSON_THROW_ON_ERROR));
    }
    $receipt = $run->inventoryDocuments()->where('document_type', InventoryDocument::TypeProductionReceipt)->where('status', InventoryDocument::StatusPosted)->sole();
    expect($receipt->financial_period_id)->toBe($manifest['target_period_id'])->and($receipt->lines->sole()->total_cost)->toBe('36.00000000')
        ->and($receipt->lines->sole()->manufacture_date->toDateString())->toBe('2026-09-30');
    foreach ([$manifest['source_period_id'], $manifest['target_period_id']] as $period) {
        $rows = collect(app(InventoryGlReconciliationService::class)->reconcile($manifest['company_id'], $period))->keyBy('key');
        foreach (['wip', 'finished_goods', 'production_waste'] as $key) {
            expect($rows[$key]['difference'])->toBe('0.0000');
        }
    }
});

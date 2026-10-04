<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Auth\Services\UserPresenceService;
use Modules\Inventory\Exports\InventoryPeriodicCostCloseExport;
use Modules\Inventory\Models\InventoryCostStandard;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryStandardCostSettlement;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryStandardCostService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/InventoryStandardCostSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('create isolated synthetic standard run sources for actual browser preparation and independent approval', function (): void {
    if (getenv('MGYPACK_STANDARD_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic browser fixture only.');
    }
    $path = '/tmp/mgypack-standard-cost-runtime-20261003.json';
    if (file_exists($path)) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['synthetic'])->toBeTrue()->and(ProductionRun::findOrFail($manifest['run_id'])->branch_id)->toBe($manifest['branch_id']);

        return;
    }
    $manifest = DB::transaction(function (): array {
        $fixture = standardCostFixture();
        $fixture['product'] = $fixture['raw'];
        costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '2');
        $run = standardCostCompletedRun($fixture);
        $manifest = ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003', 'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id, 'store_id' => $fixture['store']->id, 'product_id' => $fixture['finished']->id,
            'product_code' => $fixture['finished']->doc_num, 'run_id' => $run->id, 'run_uuid' => $run->public_id, 'run_number' => $run->run_number,
            'from_date' => now()->toDateString(), 'to_date' => $fixture['period']->to_date->toDateString(),
            'preparer_id' => $fixture['preparer']->id, 'preparer' => $fixture['preparer']->username,
            'approver_id' => $fixture['approver']->id, 'approver' => $fixture['approver']->username, 'accounts' => []];
        foreach (['materials', 'labor', 'overhead', 'clearing'] as $component) {
            $manifest['accounts'][$component] = ['id' => $fixture[$component.'_account']->id, 'code' => $fixture[$component.'_account']->account_code];
        }
        foreach ([$fixture['preparer'], $fixture['approver']] as $user) {
            foreach (['export', 'print'] as $action) {
                $user->givePermissionTo('inventory.cost_policies.standard.'.$action);
            }
            app(DefaultLoginContextService::class)->update($user, ['company_doc_num' => $fixture['company']->doc_num,
                'branch_doc_num' => $fixture['branch']->doc_num, 'financial_period_doc_num' => $fixture['period']->doc_num]);
        }

        return $manifest;
    });
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

test('actual browser standard version and settlement approval preserves actual cost and reconciles stock and all component accounts', function (): void {
    if (getenv('MGYPACK_STANDARD_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Verify the real independent browser approvals.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-standard-cost-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    $standard = InventoryCostStandard::where('branch_id', $manifest['branch_id'])->where('status', 'approved')->sole();
    $settlement = InventoryStandardCostSettlement::where('production_run_id', $manifest['run_id'])->where('status', 'finalized')->sole();
    expect($standard->prepared_by)->toBe($manifest['preparer_id'])->and($standard->approved_by)->toBe($manifest['approver_id'])
        ->and($settlement->prepared_by)->toBe($manifest['preparer_id'])->and($settlement->approved_by)->toBe($manifest['approver_id']);
    $position = app(ProductionCostService::class)->runPosition(ProductionRun::findOrFail($manifest['run_id']));
    expect($position['capitalizable'])->toBe('38.00000000')->and($position['finished_goods'])->toBe('35.00000000')
        ->and($position['standard_variance'])->toBe('3.00000000')->and($position['wip'])->toBe('0.00000000');
    foreach (['materials' => '6.0000', 'labor' => '-1.0000', 'overhead' => '-2.0000'] as $part => $expected) {
        $net = $settlement->valueAdjustment->journalEntry->lines->where('account_id', $manifest['accounts'][$part]['id'])
            ->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4), 4), '0.0000');
        expect($net)->toBe($expected);
    }
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($manifest['company_id'], $settlement->financial_period_id, $manifest['branch_id']));
    foreach (['raw_materials', 'wip', 'finished_goods', 'production_waste'] as $key) {
        expect($reconciliation->firstWhere('key', $key)['difference'])->toBe('0.0000');
    }
});

test('release an aborted session belonging only to the named synthetic standard browser preparer', function (): void {
    if (getenv('MGYPACK_STANDARD_RUNTIME_RELEASE_ABORTED_SESSION') !== '1') {
        $this->markTestSkipped('Explicit synthetic browser session recovery only.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-standard-cost-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    $user = User::findOrFail($manifest['preparer_id']);
    expect($manifest['synthetic'])->toBeTrue()->and($user->username)->toBe($manifest['preparer'])->toStartWith('synthetic-closure-');
    $presence = app(UserPresenceService::class);
    $fingerprints = $presence->activeSessionsForUser($user)->pluck('session_fingerprint')->all();
    $presence->markFingerprintsOffline($fingerprints);
    expect($presence->hasActiveSession($user))->toBeFalse();
});

test('simultaneous standard settlement approval posts one component variance journal and one stock value adjustment', function (): void {
    if (getenv('MGYPACK_STANDARD_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed isolated synthetic concurrency acceptance only.');
    }
    $fixture = standardCostFixture();
    $fixture['product'] = $fixture['raw'];
    costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '2');
    $run = standardCostCompletedRun($fixture);
    $service = app(InventoryStandardCostService::class);
    $standard = prepareSyntheticStandard($fixture);
    standardCostActor($fixture['approver']);
    $standard = $service->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC independently approved race version');
    standardCostActor($fixture['preparer']);
    $settlement = $service->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC concurrent finalization', $fixture['preparer']->id);
    $quantity = InventoryTransaction::where('production_run_id', $run->id)->sum(DB::raw('quantity_in - quantity_out'));
    $results = closurePostgresRace([
        ['operation' => 'standard-cost-settlement-approve', 'settlement' => $settlement->id, 'user' => $fixture['approver']->id],
        ['operation' => 'standard-cost-settlement-approve', 'settlement' => $settlement->id, 'user' => $fixture['approver']->id],
    ]);
    $settlement->refresh();
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['applied', 'blocked'])
        ->and($settlement->status)->toBe(InventoryStandardCostSettlement::StatusFinalized)
        ->and(InventoryValueAdjustment::where('source_type', InventoryStandardCostSettlement::class)->where('source_id', $settlement->id)->count())->toBe(1)
        ->and($settlement->valueAdjustment->journalEntry->status)->toBe(JournalEntry::StatusPosted)
        ->and(InventoryTransaction::where('production_run_id', $run->id)->sum(DB::raw('quantity_in - quantity_out')))->toBe($quantity);
    $position = app(ProductionCostService::class)->runPosition($run->fresh());
    expect($position['finished_goods'])->toBe('35.00000000')->and($position['standard_variance'])->toBe('3.00000000')->and($position['wip'])->toBe('0.00000000');
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    expect($reconciliation->every(fn (array $row): bool => bccomp((string) $row['difference'], '0', 4) === 0))->toBeTrue($reconciliation->toJson());
});

test('actual browser downloaded Excel CSV and Arabic English PDF match the observed frozen settlement tables', function (): void {
    if (getenv('MGYPACK_STANDARD_BROWSER_EXPORT_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit verification of real browser downloads only.');
    }
    $sections = json_decode(file_get_contents('/tmp/mgypack-standard-browser-sections-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    $rows = (new InventoryPeriodicCostCloseExport($sections))->array();
    $workbook = IOFactory::load('/home/mohab/Downloads/SCV-00001.xlsx');
    $actual = $workbook->getActiveSheet()->toArray(null, false, false, false);
    $csv = fopen('/home/mohab/Downloads/SCV-00001.csv', 'r');
    try {
        foreach ($rows as $index => $row) {
            $csvRow = fgetcsv($csv, separator: ',', enclosure: '"', escape: '');
            foreach ($row as $column => $expected) {
                expect($actual[$index][$column] ?? '')->toBe($expected)->and($csvRow[$column] ?? '')->toBe($expected);
            }
        }
    } finally {
        fclose($csv);
        $workbook->disconnectWorksheets();
    }
    foreach (['/home/mohab/Downloads/SCV-00001.pdf', '/home/mohab/Downloads/SCV-00001 (1).pdf'] as $path) {
        $extract = new Process(['pdftotext', '-layout', $path, '-']);
        $extract->mustRun();
        expect($extract->getOutput())->toContain('SCV-00001', 'STD-00001', 'JE-00083', '3.20000000', '38.00000000', '6.00000000');
    }
});

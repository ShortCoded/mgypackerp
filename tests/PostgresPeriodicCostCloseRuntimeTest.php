<?php

use Illuminate\Support\Facades\DB;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryPeriodicCostClose;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/InventoryPeriodicCostCloseSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_acceptance_closure_20261003')->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)
        ->and(DB::transactionLevel())->toBe(0);
});

test('persist explicitly synthetic periodic close inputs for preparation and independent browser approval', function (): void {
    if (getenv('MGYPACK_PERIODIC_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic browser fixture creation only.');
    }
    $path = '/tmp/mgypack-periodic-cost-runtime-20261003.json';
    if (file_exists($path)) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['synthetic'])->toBeTrue()->and($manifest['database'])->toBe('mgypack_acceptance_closure_20261003')
            ->and(InventoryDocument::query()->findOrFail($manifest['issue_id'])->company_id)->toBe($manifest['company_id']);

        return;
    }
    DB::transaction(function () use ($path): void {
        $fixture = periodicCostFixture(isolatedCompany: true);
        $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
        costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10.12345678');
        $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '8');
        costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '20.12345678');
        foreach ([$fixture['preparer'], $fixture['approver']] as $user) {
            foreach (['journal_entries.view', 'inventory.cost_policies.periodic.export', 'inventory.cost_policies.periodic.print'] as $permission) {
                Permission::findOrCreate($permission, 'web');
                $user->givePermissionTo($permission);
            }
            app(DefaultLoginContextService::class)->update($user, ['company_doc_num' => $fixture['company']->doc_num,
                'branch_doc_num' => $fixture['branch']->doc_num, 'financial_period_doc_num' => $fixture['period']->doc_num]);
        }
        $manifest = ['database' => 'mgypack_acceptance_closure_20261003', 'synthetic' => true,
            'preparer' => $fixture['preparer']->username, 'approver' => $fixture['approver']->username,
            'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'store_id' => $fixture['store']->id,
            'branch_doc_num' => $fixture['branch']->doc_num, 'store_uuid' => $fixture['store']->public_uuid,
            'product_id' => $fixture['product']->id, 'issue_id' => $issue->id, 'counterpart_id' => $fixture['clearing']->id,
            'counterpart_code' => $fixture['clearing']->account_code, 'from_date' => $day(0), 'to_date' => $day(4), 'posting_date' => now()->toDateString()];
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    });
});

test('persisted independent browser periodic approval preserves original costs and exact stock and GL differences', function (): void {
    if (getenv('MGYPACK_PERIODIC_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic browser fixture verification only.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-periodic-cost-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    $close = InventoryPeriodicCostClose::query()->where('company_id', $manifest['company_id'])->where('status', InventoryPeriodicCostClose::StatusFinalized)->where('doc_num', 'PWA-00001')->sole();
    $precision = InventoryPeriodicCostClose::query()->where('company_id', $manifest['company_id'])->where('doc_num', 'PWA-00002')->where('status', InventoryPeriodicCostClose::StatusFinalized)->sole();
    $issue = InventoryDocument::query()->findOrFail($manifest['issue_id']);
    expect($close->prepared_by)->not->toBe($close->approved_by)
        ->and($issue->transactions->sole()->total_cost)->toBe('80.98765424')
        ->and($issue->transactions->sole()->completedTotalCost())->toBe('120.98765424')
        ->and($close->impact_snapshot['period_inputs'][0]['average'])->toBe('15.12345678')
        ->and($close->valueAdjustment->lines->where('effect', 'expense')->sole()->amount)->toBe('40.00000000');
    $balance = InventoryTransaction::query()->where('branch_store_id', $manifest['store_id'])->where('product_id', $manifest['product_id'])
        ->selectRaw('sum(quantity_in-quantity_out) as quantity, sum('.InventoryTransaction::signedValueSql().') as value')->first();
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($manifest['company_id'], $close->posting_period_id, $manifest['branch_id']));
    expect(bccomp((string) $balance->quantity, '12', 8))->toBe(0)->and(bccomp((string) $balance->value, '181.48148136', 8))->toBe(0)
        ->and($close->valueAdjustment->journalEntry->lines->sum('debit_amount'))->toBe(40.0)
        ->and($close->valueAdjustment->journalEntry->lines->sum('credit_amount'))->toBe(40.0)
        ->and($precision->prepared_by)->not->toBe($precision->approved_by)
        ->and($precision->valueAdjustment->lines->where('effect', 'gl_precision'))->toHaveCount(6)
        ->and($precision->valueAdjustment->lines->where('effect', 'gl_precision')->every(fn ($line): bool => $line->inventory_transaction_id === null))->toBeTrue()
        ->and(InventoryTransaction::query()->where('source_type', InventoryValueAdjustment::class)->where('source_id', $precision->valueAdjustment->id)->count())->toBe(0)
        ->and(bcadd((string) $precision->valueAdjustment->journalEntry->lines->sum('debit_amount'), '0', 4))->toBe('0.0002')
        ->and(bcadd((string) $precision->valueAdjustment->journalEntry->lines->sum('credit_amount'), '0', 4))->toBe('0.0002')
        ->and($reconciliation->every(fn ($row): bool => bccomp((string) $row['difference'], '0', 4) === 0))->toBeTrue($reconciliation->toJson());
});

test('simultaneous independent periodic approvals post exactly one adjustment and journal', function (): void {
    if (getenv('MGYPACK_PERIODIC_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed isolated synthetic concurrency acceptance only.');
    }
    $fixture = periodicCostFixture(isolatedCompany: true);
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $first = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10.12345678');
    $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '8');
    $last = costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '20.12345678');
    foreach ([$first, $issue, $last] as $source) {
        periodicSyntheticLegacyJournal($source);
    }
    $close = preparePeriodicCost($fixture);
    $results = closurePostgresRace([
        ['operation' => 'periodic-cost-close-approve', 'close' => $close->id, 'user' => $fixture['approver']->id],
        ['operation' => 'periodic-cost-close-approve', 'close' => $close->id, 'user' => $fixture['approver']->id],
    ]);
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['applied', 'blocked'])
        ->and($close->fresh()->status)->toBe(InventoryPeriodicCostClose::StatusFinalized)
        ->and(InventoryValueAdjustment::query()->where('source_id', $close->id)->where('source_type', InventoryPeriodicCostClose::class)->count())->toBe(1)
        ->and(bcadd((string) $close->valueAdjustment->journalEntry->lines->sum('debit_amount'), '0', 4))->toBe('40.0001')
        ->and(bcadd((string) $close->valueAdjustment->journalEntry->lines->sum('credit_amount'), '0', 4))->toBe('40.0001')
        ->and($close->valueAdjustment->lines->where('effect', 'gl_precision'))->toHaveCount(6);
});

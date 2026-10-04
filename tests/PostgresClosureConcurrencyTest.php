<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryCostPolicyTransition;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryCostPolicyTransitionService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionRunBatch;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Models\ProductProductionStage;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionRunCorrectionService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/InventoryCostTransitionSupport.php';
require_once __DIR__.'/ManufacturingInventorySupport.php';

beforeEach(function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_acceptance_closure_20261003')->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432);
    expect(DB::transactionLevel())->toBe(0);
});

require_once __DIR__.'/ClosurePostgresRaceSupport.php';

test('real PostgreSQL transition activation serializes with cancellation and backdated posting', function (): void {
    $fixture = costTransitionFixture('-SYNTHETIC-RACE-'.Str::random(8));
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeAdjustmentIn, '3', '10');
    $service = app(InventoryCostPolicyTransitionService::class);
    $transition = $service->prepare($fixture['company']->getKey(), ['branch_store_id' => $fixture['store']->getKey(), 'effective_from' => $day(2), 'reason' => 'SYNTHETIC concurrent transition'], $fixture['preparer']->getKey());
    auth()->login($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    $transition = $service->approve($transition, $fixture['approver']->getKey());
    $before = InventoryTransaction::query()->where('company_id', $fixture['company']->getKey())->count();
    $results = closurePostgresRace([
        ['operation' => 'activate', 'transition' => $transition->id, 'user' => $fixture['approver']->id],
        ['operation' => 'cancel', 'transition' => $transition->id, 'user' => $fixture['approver']->id],
    ]);
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['applied', 'blocked'])
        ->and($transition->refresh()->status)->toBeIn([InventoryCostPolicyTransition::StatusActivated, InventoryCostPolicyTransition::StatusCancelled])
        ->and(InventoryTransaction::query()->where('company_id', $fixture['company']->getKey())->count())->toBe($before)
        ->and(InventoryCostPolicy::query()->where('branch_store_id', $fixture['store']->id)->count())->toBe($transition->status === InventoryCostPolicyTransition::StatusActivated ? 1 : 0);

    $otherStore = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC concurrent source store']);
    $other = [...$fixture, 'store' => $otherStore];
    auth()->login($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    costTransitionMovement($other, $day(1), InventoryDocument::TypeAdjustmentIn, '3', '10');
    $second = $service->prepare($fixture['company']->id, ['branch_store_id' => $otherStore->id, 'effective_from' => $day(2), 'reason' => 'SYNTHETIC transition vs source'], $fixture['preparer']->id);
    auth()->login($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    $second = $service->approve($second, $fixture['approver']->id);
    $results = closurePostgresRace([
        ['operation' => 'activate', 'transition' => $second->id, 'user' => $fixture['approver']->id],
        ['operation' => 'movement', 'user' => $fixture['preparer']->id, 'header' => [
            'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'financial_period_id' => $fixture['period']->id,
            'branch_store_id' => $otherStore->id, 'document_date' => $day(1), 'document_type' => InventoryDocument::TypeAdjustmentIn,
        ], 'lines' => [['product_id' => $fixture['product']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '1', 'unit_cost' => '20']]],
    ]);
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['applied', 'blocked']);
    $posted = collect($results)->firstWhere('operation', 'movement')['result'] === 'applied';
    $net = InventoryTransaction::query()->where('branch_store_id', $otherStore->id)->selectRaw('sum(quantity_in - quantity_out) as quantity, sum(case when quantity_in > 0 then total_cost else -total_cost end) as value')->first();
    expect(bccomp((string) $net->quantity, $posted ? '4' : '3', 8))->toBe(0)
        ->and(bccomp((string) $net->value, $posted ? '50' : '30', 8))->toBe(0)
        ->and($second->refresh()->status)->toBe($posted ? InventoryCostPolicyTransition::StatusApproved : InventoryCostPolicyTransition::StatusActivated);
});

test('real PostgreSQL batch create and next stage start serialize atomically with completed run correction', function (): void {
    $fixture = manufacturingInventoryFixture('-SYNTHETIC-STAGE-RACE-'.Str::random(8));
    $fixture['branch']->update(['type' => Branch::TypeFactory]);
    $stages = collect([1, 2])->map(function (int $sequence) use ($fixture) {
        $stage = ProductionStage::query()->create([
            'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'code' => 'SYNTHETIC-RACE-'.Str::random(8),
            'name' => 'Synthetic stage '.$sequence, 'display_order' => $sequence, 'status' => 'active',
        ]);

        return ProductProductionStage::query()->create([
            'company_id' => $fixture['company']->id, 'product_id' => $fixture['finished']->id, 'production_stage_id' => $stage->id,
            'sequence' => $sequence, 'status' => 'active',
        ]);
    });
    $cycle = app(ProductionCycleService::class);
    $order = $cycle->createMakeToStockOrder(['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'financial_period_id' => $fixture['period']->id], [[
        'product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '10', 'stage_public_ids' => $stages->pluck('public_id')->all(),
    ]]);
    $order = $cycle->releaseOrder($order);
    $line = $order->lines->sole();
    $snapshots = $cycle->stagesForLine($order, $line)->values();
    expect($snapshots)->toHaveCount(2);
    $runData = ['planned_quantity' => '10', 'planned_start_at' => now()->addHour()->toDateTimeString(), 'planned_end_at' => now()->addHours(2)->toDateTimeString(),
        'production_machine_id' => $fixture['machine']->id, 'production_mold_id' => $fixture['mold']->id, 'batch_lot' => 'SYNTHETIC-STAGE-RACE'];
    $run = $cycle->createRun($line, [...$runData, 'production_order_stage_snapshot_id' => $snapshots[0]->id]);
    $cycle->reserveRun($run, $fixture['store']->id);
    $cycle->issueMaterials($run, $fixture['store']->id);
    $run = $cycle->startRun($cycle->completeSetup($cycle->startSetup($run)));
    $cycle->recordProgress($run, ['good_base_quantity' => '10']);
    $requirement = $run->requirements()->sole();
    $cycle->accountMaterials($run->fresh(), $fixture['store']->id, [$requirement->id => ['consumed_quantity' => '20', 'waste_quantity' => '0']]);
    $run = $cycle->completeRun($run->fresh());
    foreach (['production.runs.correct', 'production.runs.correct_approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $approver = closureSyntheticUser();
    $approver->givePermissionTo('production.runs.correct_approve');
    $service = app(ProductionRunCorrectionService::class);
    $preview = $service->preview($run);
    $proposal = $service->propose($run, ['good_base_quantity' => '9', 'rejected_base_quantity' => '1', 'rework_base_quantity' => '0', 'scrap_base_quantity' => '0'],
        'SYNTHETIC correction versus downstream stage', $preview['fingerprint'], $run->actual_end_at->toDateString());
    $beforeTransactions = InventoryTransaction::query()->where('production_run_id', $run->id)->count();
    $results = closurePostgresRace([
        ['operation' => 'production-correction', 'user' => $approver->id, 'run' => $run->id, 'correction' => $proposal->id],
        ['operation' => 'batch-start', 'user' => $fixture['user']->id, 'order' => $order->id, 'batch' => [
            ...collect($runData)->except('planned_quantity')->all(),
            'lines' => [['production_order_line_id' => $line->id, 'production_order_stage_snapshot_id' => $snapshots[1]->id, 'planned_quantity' => '10']],
        ]],
    ]);
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['applied', 'blocked']);
    $corrected = collect($results)->firstWhere('operation', 'production-correction')['result'] === 'applied';
    expect($run->fresh()->status)->toBe($corrected ? 'running' : 'completed')
        ->and(ProductionRunBatch::query()->where('production_order_id', $order->id)->count())->toBe($corrected ? 0 : 1)
        ->and(ProductionRun::query()->where('production_order_stage_snapshot_id', $snapshots[1]->id)->count())->toBe($corrected ? 0 : 1)
        ->and(DB::table('production_run_corrections')->where('id', $proposal->id)->value('status'))->toBe($corrected ? 'approved' : 'prepared')
        ->and(InventoryTransaction::query()->where('production_run_id', $run->id)->count())->toBe($corrected ? $beforeTransactions + 1 : $beforeTransactions);
});

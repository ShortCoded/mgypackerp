<?php

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrShift;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionDailyReportService;
use Modules\Production\Services\ProductionHandoverService;
use Modules\Production\Services\ProductionWarehouseReceiptCorrectionService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (getenv('MGYPACK_HANDOVER_RACE') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit backed-up local synthetic handover PostgreSQL race only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_handover_pg_20261006')->and($identity->host)->toBe('127.0.0.1')
        ->and((int) $identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

/** @return array<string, mixed> */
function handoverRaceContext(): array
{
    $f = json_decode(file_get_contents(storage_path('app/test-artifacts/mgypack-production-handover-20261006/browser-fixture.json')), true, flags: JSON_THROW_ON_ERROR);
    $company = Company::findOrFail($f['company_id']);
    $branch = Branch::findOrFail($f['branch_id']);
    $period = FinancialPeriod::findOrFail($f['period_id']);
    expect(str_starts_with($company->name, 'SYNTHETIC'))->toBeTrue();
    $session = [OperatingContextService::CompanyIdKey => $company->id, OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->id, OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->id, OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num];

    return ['fixture' => $f, 'session' => $session];
}

/** @param array<string, mixed> $context */
function handoverRaceActor(array $context, string $kind): void
{
    $actor = User::findOrFail($context['fixture']['actors'][$kind]['id']);
    expect(str_ends_with($actor->email, '@example.test'))->toBeTrue();
    test()->actingAs($actor)->withSession($context['session']);
    request()->attributes->replace([]);
    request()->setUserResolver(fn () => $actor);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put($context['session']);
}

/** @return array<string, mixed> */
function handoverRaceSource(): array
{
    $context = handoverRaceContext();
    $f = $context['fixture'];
    handoverRaceActor($context, 'operator');
    $original = ProductionRun::findOrFail($f['runs'][0]['id']);
    Carbon::setTestNow(Carbon::now()->startOfDay());
    try {
        $cycle = app(ProductionCycleService::class);
        $order = $cycle->releaseOrder($cycle->createMakeToStockOrder(['company_id' => $f['company_id'], 'branch_id' => $f['branch_id'], 'financial_period_id' => $f['period_id']],
            [['product_id' => $original->product_id, 'unit_id' => $original->unit_id, 'quantity' => '10']]));
        $suffix = Str::random(8);
        $shift = HrShift::query()->create(['doc_number' => (int) HrShift::query()->max('doc_number') + 1,
            'doc_num' => 'SYNTHETIC-RACE-SHIFT-'.$suffix, 'name' => 'SYNTHETIC race night shift '.$suffix,
            'start_time' => '00:00:00', 'end_time' => '08:00:00', 'break_minutes' => 0,
            'crosses_midnight' => false, 'status' => 'active', 'created_by' => auth()->id()]);
        $machine = ProductionMachine::query()->create(['company_id' => $f['company_id'], 'branch_id' => $f['branch_id'], 'code' => 'SYNTHETIC-RACE-M-'.$suffix, 'name' => 'SYNTHETIC race machine']);
        $mold = ProductionMold::query()->create(['company_id' => $f['company_id'], 'branch_id' => $f['branch_id'], 'code' => 'SYNTHETIC-RACE-MOLD-'.$suffix, 'name' => 'SYNTHETIC race mold']);
        $machine->molds()->attach($mold);
        $mold->products()->attach($original->product_id);
        $run = $cycle->createRun($order->lines->sole(), ['planned_quantity' => '10', 'planned_start_at' => now()->addHour(),
            'planned_end_at' => now()->addHours(2), 'production_machine_id' => $machine->id,
            'production_mold_id' => $mold->id, 'batch_lot' => 'SYNTHETIC-RACE-'.Str::random(6)]);
        $cycle->reserveRun($run, $f['store_id']);
        $cycle->issueMaterials($run->fresh(), $f['store_id']);
        $run = $cycle->enableOutputEvidence($run->fresh(), [['requirement_public_id' => $run->requirements->sole()->public_id, 'basis' => 'measured_material']], 'physical_route');
        $run = $cycle->startRun($cycle->completeSetup($cycle->startSetup($run)));
        Carbon::setTestNow(now()->setTime(2, 0));
        $entry = app(ProductionDailyReportService::class)->record($run, ['sheet_kind' => 'injection', 'hr_shift_id' => $shift->id,
            'work_date' => now()->toDateString(), 'working_hours' => '1', 'lines' => [['run_public_id' => $run->public_id, 'quantity' => '10']]])->sole();
        $cycle->accountMaterials($run->fresh(), $f['store_id'], [$run->requirements->sole()->id => ['consumed_quantity' => '20', 'waste_quantity' => '0']], dailyProgress: $entry);
        $cycle->recordInspection($run->fresh(), ['quality_inspection_type_id' => $f['quality_type_id'], 'result' => 'passed',
            'disposition' => 'release', 'affected_base_quantity' => '10', 'accepted_base_quantity' => '10']);
        $service = app(ProductionHandoverService::class);
        $handover = $service->approveHandover($service->createHandover($run->fresh(), $f['store_id'], now()->toDateString(),
            [['run_public_id' => $run->public_id, 'quantity' => '10']]));
    } finally {
        Carbon::setTestNow();
    }
    handoverRaceActor($context, 'warehouse');

    return [...$context, 'run' => $run->fresh(), 'handover' => $handover, 'actor_id' => $f['actors']['warehouse']['id']];
}

/**
 * @param  array<string, mixed>  $context
 * @param  list<int>  $documentIds
 * @return list<array<string, mixed>>
 */
function runHandoverWarehouseRace(array $context, array $documentIds, string $operation, ?int $proposalId = null): array
{
    $directory = storage_path('app/test-artifacts/mgypack-production-handover-20261006/race-'.Str::uuid());
    mkdir($directory, 0700);
    $config = DB::connection()->getConfig();
    $worker = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$identity = Illuminate\Support\Facades\DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port, pg_backend_pid() as pid');
if ($identity->db !== 'mgypack_handover_pg_20261006' || $identity->host !== '127.0.0.1' || (int) $identity->port !== 5432) { throw new RuntimeException('Owned local race clone guard'); }
$user = App\Models\User::query()->findOrFail($payload['user_id']);
$receipt = Modules\Inventory\Models\InventoryDocument::query()->where('company_id', $payload['company_id'])->findOrFail($payload['document_id']);
$company = Modules\Core\Models\Company::query()->findOrFail($receipt->company_id);
if (!str_ends_with($user->email, '@example.test') || !str_starts_with($company->name, 'SYNTHETIC')) { throw new RuntimeException('Synthetic actor/company guard'); }
auth()->setUser($user);
$request = Illuminate\Http\Request::create('/synthetic-local-handover-race', 'POST');
$request->setUserResolver(fn () => $user);
$request->setLaravelSession(app('session.store'));
$request->session()->put($payload['session']);
$app->instance('request', $request);
Illuminate\Support\Facades\Request::clearResolvedInstance('request');
file_put_contents($payload['ready'], json_encode(['pid' => (int) $identity->pid]));
$deadline = microtime(true) + 25;
while (!is_file($payload['go'])) { if (microtime(true) > $deadline) { throw new RuntimeException('Worker barrier timeout'); } usleep(10000); }
$result = ['operation' => $payload['operation'], 'document_id' => $receipt->id, 'backend_pid' => (int) $identity->pid];
try {
    if ($payload['operation'] === 'receipt_approve') {
        app(Modules\Production\Services\ProductionHandoverService::class)->approveWarehouseReceipt($receipt);
    } else {
        app(Modules\Production\Services\ProductionWarehouseReceiptCorrectionService::class)->approve($receipt, $payload['proposal_id']);
    }
    $result['outcome'] = 'completed';
} catch (DomainException $exception) {
    $result['outcome'] = 'blocked';
    $result['reason'] = $exception->getMessage();
}
$result['status'] = $receipt->fresh()->status;
$result['transaction_level_after'] = Illuminate\Support\Facades\DB::transactionLevel();
echo json_encode($result, JSON_THROW_ON_ERROR);
WORKER;
    $processes = [];
    $pids = [];
    try {
        DB::beginTransaction();
        Company::query()->whereKey($context['fixture']['company_id'])->lockForUpdate()->firstOrFail();
        foreach ($documentIds as $index => $documentId) {
            $path = $directory.'/'.$index.'.json';
            file_put_contents($path, json_encode(['user_id' => $context['actor_id'],
                'company_id' => $context['fixture']['company_id'], 'document_id' => $documentId, 'proposal_id' => $proposalId, 'operation' => $operation,
                'session' => $context['session'], 'ready' => $directory.'/'.$index.'.ready', 'go' => $directory.'/go'], JSON_THROW_ON_ERROR));
            $process = new Process(['php', '-r', $worker, $path], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
                'DB_DATABASE' => 'mgypack_handover_pg_20261006', 'DB_URL' => '', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_ROUTES_CACHE' => $directory.'/routes.php',
                'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync',
                'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
            ]);
            $process->setTimeout(65)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 25;
        while (count($pids) !== 2) {
            foreach ($processes as $index => $process) {
                if (is_file($directory.'/'.$index.'.ready')) {
                    $pids[$index] = json_decode(file_get_contents($directory.'/'.$index.'.ready'), true, flags: JSON_THROW_ON_ERROR)['pid'];
                } elseif (! $process->isRunning()) {
                    throw new RuntimeException('Worker initialization failed: '.$process->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Parent barrier timeout');
            }
            usleep(10000);
        }
        expect(count(array_unique($pids)))->toBe(2);
        file_put_contents($directory.'/go', 'go');
        $deadline = microtime(true) + 20;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->whereIn('pid', array_values($pids))->where('wait_event_type', 'Lock')->pluck('pid')->all();
            if (count($waiting) === 2) {
                break;
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Both owner workers did not contend on native database locks.');
            }
            usleep(10000);
        } while (true);
        DB::commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($result['transaction_level_after'])->toBe(0);
            $results[] = $result;
        }
        file_put_contents($directory.'/results.json', json_encode(['observed_simultaneous_lock_waits' => $waiting,
            'document_ids' => $documentIds, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $results;
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
}

test('competing actual warehouse receipts cannot exceed the approved handover quantity under native locks', function (): void {
    $context = handoverRaceSource();
    $service = app(ProductionHandoverService::class);
    $input = [['line_public_id' => $context['handover']->lines->sole()->public_id, 'quantity' => '6']];
    $first = $service->createWarehouseReceipt($context['handover'], now()->toDateString(), $input);
    $second = $service->createWarehouseReceipt($context['handover'], now()->toDateString(), $input);
    $before = [InventoryTransaction::where('company_id', $first->company_id)->count(), JournalEntry::where('company_id', $first->company_id)->count()];
    $results = runHandoverWarehouseRace($context, [$first->id, $second->id], 'receipt_approve');
    expect(array_column($results, 'outcome'))->toContain('completed')->toContain('blocked')
        ->and($context['run']->fresh()->received_base_quantity)->toBe('6.00000000')
        ->and(InventoryDocument::whereIn('id', [$first->id, $second->id])->where('status', 'posted')->count())->toBe(1)
        ->and(InventoryTransaction::where('company_id', $first->company_id)->count())->toBe($before[0] + 1)
        ->and(JournalEntry::where('company_id', $first->company_id)->count())->toBe($before[1] + 1);
    foreach (app(InventoryGlReconciliationService::class)->reconcile($first->company_id, $context['fixture']['period_id'], $first->branch_id) as $row) {
        expect($row['difference'])->toBe('0.0000');
    }
});

test('simultaneous warehouse approval retries post the same actual receipt once', function (): void {
    $context = handoverRaceSource();
    $service = app(ProductionHandoverService::class);
    $receipt = $service->createWarehouseReceipt($context['handover'], now()->toDateString(),
        [['line_public_id' => $context['handover']->lines->sole()->public_id, 'quantity' => '4']]);
    $before = [InventoryTransaction::where('company_id', $receipt->company_id)->count(), JournalEntry::where('company_id', $receipt->company_id)->count()];
    $results = runHandoverWarehouseRace($context, [$receipt->id, $receipt->id], 'receipt_approve');
    expect(array_column($results, 'outcome'))->toBe(['completed', 'completed'])
        ->and($context['run']->fresh()->received_base_quantity)->toBe('4.00000000')
        ->and(InventoryTransaction::where('company_id', $receipt->company_id)->count())->toBe($before[0] + 1)
        ->and(JournalEntry::where('company_id', $receipt->company_id)->count())->toBe($before[1] + 1)
        ->and(DB::table('activity_log')->where('event', 'production.warehouse_receipt.approved')->where('subject_id', $receipt->id)->count())->toBe(1);
});

test('simultaneous independent warehouse correction approvals produce one native inventory and GL inverse', function (): void {
    $context = handoverRaceSource();
    $service = app(ProductionHandoverService::class);
    $receipt = $service->approveWarehouseReceipt($service->createWarehouseReceipt($context['handover'], now()->toDateString(),
        [['line_public_id' => $context['handover']->lines->sole()->public_id, 'quantity' => '4']]));
    $corrections = app(ProductionWarehouseReceiptCorrectionService::class);
    $preview = $corrections->preview($receipt);
    $proposal = $corrections->prepare($receipt, 'SYNTHETIC duplicate reviewed inverse race', $preview['fingerprint'], now()->toDateString());
    handoverRaceActor($context, 'reviewer');
    $context['actor_id'] = $context['fixture']['actors']['reviewer']['id'];
    $before = [InventoryTransaction::where('company_id', $receipt->company_id)->count(), JournalEntry::where('company_id', $receipt->company_id)->count()];
    $results = runHandoverWarehouseRace($context, [$receipt->id, $receipt->id], 'correction_approve', $proposal->id);
    expect(array_column($results, 'outcome'))->toBe(['completed', 'completed'])
        ->and($receipt->fresh()->status)->toBe('reversed')->and($proposal->fresh()->status)->toBe('approved')
        ->and($context['run']->fresh()->received_base_quantity)->toBe('0.00000000')
        ->and(InventoryTransaction::where('company_id', $receipt->company_id)->count())->toBe($before[0] + 1)
        ->and(JournalEntry::where('company_id', $receipt->company_id)->count())->toBe($before[1] + 1)
        ->and(DB::table('activity_log')->where('event', 'production.warehouse_receipt_correction.approved')->where('subject_id', $receipt->id)->count())->toBe(1);
    foreach (app(InventoryGlReconciliationService::class)->reconcile($receipt->company_id, $context['fixture']['period_id'], $receipt->branch_id) as $row) {
        expect($row['difference'])->toBe('0.0000');
    }
});

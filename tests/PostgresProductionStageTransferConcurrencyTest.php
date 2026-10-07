<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Company;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionStageTransfer;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionStageTransferService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/ProductionStageTransferSupport.php';

uses(TestCase::class);

beforeEach(function (): void {
    if (getenv('MGYPACK_STAGE_RACE') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit backed-up local synthetic stage transfer PostgreSQL race only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_stage_transfer_pg_20261006')->and($identity->host)->toBe('127.0.0.1')
        ->and((int) $identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
    Carbon::setTestNow();
});

function stageRaceSource(): array
{
    $f = physicalStageFixture('10', Carbon::now()->startOfDay()->addHours(8)->toDateTimeString());
    physicalStageProgress($f, 0, '6', '12');
    expect(str_starts_with($f['company']->name, 'SYNTHETIC'))->toBeTrue();

    return [...$f, 'fixture' => ['company_id' => $f['company']->id], 'session' => manufacturingIntegritySession($f),
        'actor_id' => $f['approver']->id, 'operator_id' => $f['user']->id];
}

/** @param array<string, mixed> $context @param list<int> $documentIds @param list<string> $operations @return list<array<string, mixed>> */
function runStageOwnerRace(array $context, array $documentIds, array $operations): array
{
    $directory = storage_path('app/test-artifacts/mgypack-physical-stage-transfer-20261006/race-'.Str::uuid());
    mkdir($directory, 0700);
    $config = DB::connection()->getConfig();
    $worker = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$identity = Illuminate\Support\Facades\DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port, pg_backend_pid() as pid');
if ($identity->db !== 'mgypack_stage_transfer_pg_20261006' || $identity->host !== '127.0.0.1' || (int) $identity->port !== 5432) { throw new RuntimeException('Owned local race clone guard'); }
$user = App\Models\User::query()->findOrFail($payload['user_id']);
$receipt = Modules\Production\Models\ProductionStageTransfer::query()->where('company_id', $payload['company_id'])->findOrFail($payload['document_id']);
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
    if ($payload['operation'] === 'approve') {
        app(Modules\Production\Services\ProductionStageTransferService::class)->approve($receipt);
    } elseif ($payload['operation'] === 'reverse') {
        app(Modules\Production\Services\ProductionStageTransferService::class)->reverse($receipt, 'SYNTHETIC concurrent unused reversal');
    } else {
        $run = $receipt->targetRun;
        app(Modules\Production\Services\ProductionCycleService::class)->recordProgress($run, ['good_base_quantity' => '1', 'stage_input_base_quantity' => '1',
            'material_evidence' => [['requirement_public_id' => $run->requirements->sole()->public_id, 'measured_quantity' => '1']]]);
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
            file_put_contents($path, json_encode(['user_id' => $operations[$index] === 'progress' ? $context['operator_id'] : $context['actor_id'],
                'company_id' => $context['fixture']['company_id'], 'document_id' => $documentId, 'operation' => $operations[$index],
                'session' => $context['session'], 'ready' => $directory.'/'.$index.'.ready', 'go' => $directory.'/go'], JSON_THROW_ON_ERROR));
            $process = new Process(['php', '-r', $worker, $path], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
                'DB_DATABASE' => 'mgypack_stage_transfer_pg_20261006', 'DB_URL' => '', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
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

test('simultaneous independent approvals post one stage transfer journal and quantity once', function (): void {
    $f = stageRaceSource();
    $owner = physicalStagePrepare($f, 0, '4');
    $stock = InventoryTransaction::query()->count();
    $results = runStageOwnerRace($f, [$owner->id, $owner->id], ['approve', 'approve']);
    expect(collect($results)->pluck('outcome')->all())->toBe(['completed', 'completed'])
        ->and(JournalEntry::query()->where('source_type', 'production_stage_transfer')->where('source_id', $owner->id)->count())->toBe(1)
        ->and(app(ProductionStageTransferService::class)->position($f['runs'][1]->fresh())['incoming_quantity'])->toBe('4.00000000')
        ->and(InventoryTransaction::query()->count())->toBe($stock);
});

test('competing partial stage transfers cannot claim the same six released units twice', function (): void {
    $f = stageRaceSource();
    $first = physicalStagePrepare($f, 0, '4');
    $second = physicalStagePrepare($f, 0, '4');
    $results = runStageOwnerRace($f, [$first->id, $second->id], ['approve', 'approve']);
    expect(collect($results)->where('outcome', 'completed'))->toHaveCount(1)
        ->and(collect($results)->where('outcome', 'blocked'))->toHaveCount(1)
        ->and(ProductionStageTransfer::query()->whereIn('id', [$first->id, $second->id])->where('status', 'posted')->count())->toBe(1)
        ->and(app(ProductionCostService::class)->runPosition($f['runs'][0]->fresh())['wip'])->toBe('120.00000000')
        ->and(app(ProductionCostService::class)->runPosition($f['runs'][1]->fresh())['wip'])->toBe('130.00000000');
});

test('stage input processing and native reversal serialize without orphaned WIP or invented output', function (): void {
    $f = stageRaceSource();
    $owner = physicalStagePost($f, 0, '4');
    physicalStageActor($f);
    $f['cycle']->startRun($f['runs'][1]->fresh());
    $results = runStageOwnerRace($f, [$owner->id, $owner->id], ['progress', 'reverse']);
    expect(collect($results)->where('outcome', 'completed'))->toHaveCount(1)
        ->and(collect($results)->where('outcome', 'blocked'))->toHaveCount(1);
    $position = app(ProductionStageTransferService::class)->position($f['runs'][1]->fresh());
    if ($owner->fresh()->status === 'posted') {
        expect($position['used_quantity'])->toBe('1.00000000')->and($f['runs'][1]->fresh()->good_base_quantity)->toBe('1.00000000')
            ->and($owner->fresh()->reversal_journal_entry_id)->toBeNull();
    } else {
        expect($owner->fresh()->status)->toBe('reversed')->and($position['incoming_quantity'])->toBe('0.00000000')
            ->and($f['runs'][1]->fresh()->good_base_quantity)->toBe('0.00000000')
            ->and(DB::table('production_stage_input_consumptions')->where('production_stage_transfer_id', $owner->id)->count())->toBe(0);
    }
    $positions = $f['runs']->map(fn ($run): array => app(ProductionCostService::class)->runPosition($run->fresh()));
    expect($positions->reduce(fn (string $sum, array $p): string => bcadd($sum, $p['wip'], 8), '0.00000000'))->toBe('270.00000000');
});

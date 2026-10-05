<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Company;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionQualityQuantityService;
use Modules\Production\Services\ProductionQualityWorkflowService;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/ProductionPartialOutputEvidenceSupport.php';

beforeEach(function (): void {
    if (getenv('MGYPACK_PRODUCTION_RACE') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit isolated local PostgreSQL production race only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_production_race_pg_20261005')
        ->and($identity->host)->toBe('127.0.0.1')->and((int) $identity->port)->toBe(5432)
        ->and(DB::transactionLevel())->toBe(0);
});

/** @return array<string, mixed> */
function productionRaceFixture(): array
{
    $fixture = partialOutputFixture();
    foreach (['production.runs.complete', 'production.quality.review'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    expect($fixture['company']->name)->toStartWith('SYNTHETIC')
        ->and($fixture['user']->email)->toEndWith('@example.test')
        ->and($fixture['run']->getConnection()->getDatabaseName())->toBe('mgypack_production_race_pg_20261005');

    return $fixture;
}

/** @param array<string, mixed> $fixture @param list<array<string, mixed>> $actions @return list<array<string, mixed>> */
function productionRaceActions(array $fixture, array $actions): array
{
    $directory = storage_path('app/test-artifacts/production-race-'.Str::uuid());
    mkdir($directory, 0700);
    $config = DB::connection()->getConfig();
    $worker = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$identity = Illuminate\Support\Facades\DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port, pg_backend_pid() as pid');
if ($identity->db !== 'mgypack_production_race_pg_20261005' || $identity->host !== '127.0.0.1' || (int) $identity->port !== 5432) { throw new RuntimeException('Isolated race database guard'); }
Illuminate\Support\Carbon::setTestNow('2026-09-29 12:00:00');
$user = App\Models\User::query()->findOrFail($payload['user_id']);
$run = Modules\Production\Models\ProductionRun::query()->findOrFail($payload['run_id']);
$company = Modules\Core\Models\Company::query()->findOrFail($run->company_id);
if (!str_ends_with($user->email, '@example.test') || !str_starts_with($company->name, 'SYNTHETIC')) { throw new RuntimeException('Synthetic actor/company guard'); }
auth()->login($user);
$action = $payload['action'];
$kind = $action['kind'];
$operation = $kind === 'output' ? 'progress' : ($kind === 'receipt' ? 'receive' : 'complete');
$routeName = $kind === 'quality' ? 'admin.production.quality.approve' : 'admin.production.runs.'.$operation;
Illuminate\Support\Facades\Gate::authorize($kind === 'quality' ? 'production.quality.review' : 'production.runs.'.$operation);
$input = ['_submission_token' => $action['token']];
if ($kind === 'output') { $input['good_base_quantity'] = $action['quantity']; }
if ($kind === 'receipt') { $input += ['base_quantity' => $action['quantity'], 'branch_store_id' => $payload['store_id']]; }
$path = $kind === 'quality' ? '/admin/production/quality/'.$action['id'].'/approve' : '/admin/production/runs/'.$run->public_id.'/'.$operation;
$request = Illuminate\Http\Request::create($path, 'POST', $input);
$request->headers->set('Accept', 'application/json');
$request->setUserResolver(fn() => $user);
$request->setLaravelSession(app('session.store'));
$request->session()->put($payload['session']);
$route = app('router')->getRoutes()->getByName($routeName);
$request->setRouteResolver(fn() => $route);
$app->instance('request', $request);
Illuminate\Support\Facades\Request::clearResolvedInstance('request');
file_put_contents($payload['ready'], json_encode(['pid' => (int)$identity->pid]));
$deadline = microtime(true)+20;
while (!is_file($payload['go'])) { if (microtime(true)>$deadline) { throw new RuntimeException('Worker barrier timeout'); } usleep(10000); }
try {
    $response = app(App\Http\Middleware\IdempotentDocumentSubmission::class)->handle($request, function () use ($kind, $run, $action, $payload) {
        $cycle = app(Modules\Production\Services\ProductionCycleService::class);
        $record = match ($kind) {
            'output' => $cycle->recordProgress($run, ['good_base_quantity' => $action['quantity']]),
            'receipt' => $cycle->receiveFinishedGoods($run, $payload['store_id'], $action['quantity']),
            'quality' => app(Modules\Production\Services\ProductionQualityWorkflowService::class)->review(Modules\Production\Models\ProductionQualityInspection::query()->findOrFail($action['id']), true),
            'close' => $cycle->completeRun($run),
        };
        return response()->json(['id' => $record->id, 'doc_num' => $record->doc_num, 'status' => $record->status]);
    }, 'required');
    $result = ['ok' => $response->getStatusCode() === 200, 'kind' => $kind, 'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)];
} catch (Throwable $exception) {
    $result = ['ok' => false, 'kind' => $kind, 'exception' => get_class($exception), 'message' => $exception->getMessage(), 'status' => method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : null];
}
$result += ['backend_pid' => (int)$identity->pid, 'transaction_level_after' => Illuminate\Support\Facades\DB::transactionLevel()];
echo json_encode($result, JSON_THROW_ON_ERROR);
WORKER;
    $processes = [];
    $pids = [];
    try {
        DB::beginTransaction();
        Company::query()->whereKey($fixture['company']->id)->lockForUpdate()->firstOrFail();
        foreach ($actions as $index => $action) {
            $path = $directory.'/'.$index.'.json';
            file_put_contents($path, json_encode(['action' => $action, 'user_id' => $fixture['user']->id,
                'run_id' => $fixture['run']->id, 'store_id' => $fixture['store']->id, 'session' => manufacturingIntegritySession($fixture),
                'ready' => $directory.'/'.$index.'.ready', 'go' => $directory.'/go'], JSON_THROW_ON_ERROR));
            $process = new Process(['php', '-r', $worker, $path], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
                'DB_DATABASE' => 'mgypack_production_race_pg_20261005', 'DB_URL' => '', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'APP_CONFIG_CACHE' => '/tmp/mgypack-production-race-worker-config.php', 'APP_ROUTES_CACHE' => '/tmp/mgypack-production-race-worker-routes.php',
                'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync',
                'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
            ]);
            $process->setTimeout(50)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 20;
        while (count($pids) !== count($processes)) {
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
        expect(count(array_unique($pids)))->toBe(count($processes));
        file_put_contents($directory.'/go', 'go');
        $deadline = microtime(true) + 15;
        $waiting = [];
        while (count($actions) > 1) {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->whereIn('pid', array_values($pids))->where('wait_event_type', 'Lock')->pluck('pid')->all();
            if (count($waiting) === count($processes)) {
                break;
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers did not both contend on native database locks.');
            }
            usleep(10000);
        }
        DB::commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($result['transaction_level_after'])->toBe(0);
            if (! $result['ok']) {
                expect($result['exception'])->toBeIn([DomainException::class, HttpException::class]);
            }
            $results[] = $result;
        }
        file_put_contents($directory.'/results.json', json_encode(['observed_simultaneous_lock_waits' => $waiting, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

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

/** @param array<string, mixed> $fixture @return array<string, mixed> */
function productionRacePosition(array $fixture, string $good, string $received): array
{
    $run = $fixture['run']->fresh();
    expect($run->good_base_quantity)->toBe(bcadd($good, '0', 8))
        ->and($run->received_base_quantity)->toBe(bcadd($received, '0', 8))
        ->and($fixture['requirement']->fresh()->consumed_quantity)->toBe(bcmul($good, '2', 8))
        ->and(bccomp(app(ProductionQualityQuantityService::class)->availableQuantity($run), '0', 8))->not->toBe(-1);
    $staging = InventoryTransaction::query()->where('company_id', $run->company_id)->where('production_run_id', $run->id)
        ->where('stock_status', InventoryTransaction::StatusProductionStaging)->selectRaw('coalesce(sum(quantity_in-quantity_out),0) as quantity')->first();
    expect(bcadd((string) $staging->quantity, '0', 8))->toBe(bcsub('20', bcmul($good, '2', 8), 8));
    $position = app(ProductionCostService::class)->runPosition($run);
    expect($position['issued'])->toBe('200.00000000')->and($position['waste'])->toBe('0.00000000')
        ->and($position['finished_goods'])->toBe(bcmul($received, '20', 8))
        ->and($position['wip'])->toBe(bcsub('200', bcmul($received, '20', 8), 8));
    $receipt = InventoryTransaction::query()->where('company_id', $run->company_id)->where('production_run_id', $run->id)
        ->where('stock_status', InventoryTransaction::StatusAvailable)->where('product_id', $run->product_id)
        ->selectRaw('coalesce(sum(quantity_in-quantity_out),0) as quantity, coalesce(sum(total_cost),0) as cost')->first();
    expect(bcadd((string) $receipt->quantity, '0', 8))->toBe(bcadd($received, '0', 8))
        ->and(bcadd((string) $receipt->cost, '0', 8))->toBe(bcmul($received, '20', 8));
    $documents = $run->inventoryDocuments()->where('status', 'posted')->with('journalEntry')->get();
    foreach ($documents as $document) {
        if ($document->document_type === InventoryDocument::TypeMaterialConsumption) {
            expect($document->journalEntry)->toBeNull();

            continue;
        }
        expect($document->journalEntry)->not->toBeNull();
        $totals = $document->journalEntry->lines()->reorder()->selectRaw('sum(debit_amount) as debit, sum(credit_amount) as credit')->first();
        expect(bccomp((string) $totals->debit, (string) $totals->credit, 8))->toBe(0);
    }

    return ['run_id' => $run->id, 'good' => $good, 'received' => $received, 'staging' => (string) $staging->quantity,
        'position' => $position, 'documents' => $documents->pluck('doc_num')->all(), 'status' => $run->status];
}

/** @param array<string, mixed> $fixture @param list<array<string, mixed>> $results */
function productionRaceProof(string $name, array $fixture, array $results, string $good, string $received): void
{
    $position = productionRacePosition($fixture, $good, $received);
    file_put_contents(storage_path('app/test-artifacts/mgypack-production-race-'.$name.'-20261005.json'), json_encode([
        'at_utc' => gmdate(DATE_ATOM), 'database' => DB::connection()->getDatabaseName(),
        'fixture' => 'Synthetic isolated company with native valued issue, output, QA and receipts; customer source untouched',
        'results' => $results, 'final' => $position, 'transaction_level' => DB::transactionLevel(), 'exit_gate' => 'passed',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
}

test('independent duplicate output submissions consume material exactly once and reject payload drift', function (): void {
    $f = productionRaceFixture();
    $action = ['kind' => 'output', 'quantity' => '2', 'token' => (string) Str::uuid()];
    $results = productionRaceActions($f, [$action, $action]);
    expect(array_column($results, 'ok'))->toBe([true, true])->and($results[0]['body'])->toBe($results[1]['body'])
        ->and($f['run']->progressEntries()->count())->toBe(1);
    $drift = productionRaceActions($f, [[...$action, 'quantity' => '3']]);
    expect($drift[0]['ok'])->toBeFalse()->and($drift[0]['status'])->toBe(409);
    productionRaceProof('duplicate-output', $f, [...$results, ...$drift], '2', '0');
});

test('independent output entries cannot overdraw the same native material capacity', function (): void {
    $f = productionRaceFixture();
    $results = productionRaceActions($f, [['kind' => 'output', 'quantity' => '6', 'token' => (string) Str::uuid()], ['kind' => 'output', 'quantity' => '6', 'token' => (string) Str::uuid()]]);
    expect(array_sum(array_column($results, 'ok')))->toBe(1)->and($f['run']->progressEntries()->count())->toBe(1)
        ->and(DB::table('document_submissions')->where('company_id', $f['company']->id)->count())->toBe(1);
    productionRaceProof('competing-output', $f, $results, '6', '0');
});

test('independent quality approval and receipt never release unapproved or duplicate quantity', function (): void {
    $f = productionRaceFixture();
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '2']);
    $quality = app(ProductionQualityWorkflowService::class);
    $inspection = $quality->start($quality->receive($quality->create($f['run']->fresh(), ['quality_inspection_type_id' => $f['qualityType']->id, 'affected_base_quantity' => '2'])));
    $f['user']->revokePermissionTo('production.quality.release_normal');
    $inspection = $quality->submit($inspection, ['result' => 'passed', 'disposition' => 'release', 'affected_base_quantity' => '2', 'accepted_base_quantity' => '2']);
    expect($inspection->status)->toBe(ProductionQualityInspection::StatusSubmitted);
    $f['user']->givePermissionTo('production.quality.release_normal');
    $receipt = ['kind' => 'receipt', 'quantity' => '1', 'token' => (string) Str::uuid()];
    $results = productionRaceActions($f, [['kind' => 'quality', 'id' => $inspection->id, 'token' => (string) Str::uuid()], $receipt]);
    expect($results[0]['ok'])->toBeTrue()->and($inspection->fresh()->status)->toBe(ProductionQualityInspection::StatusApproved);
    $retry = productionRaceActions($f, [$receipt]);
    $replay = productionRaceActions($f, [$receipt]);
    expect($retry[0]['ok'])->toBeTrue()->and($replay[0]['body'])->toBe($retry[0]['body'])
        ->and(app(ProductionQualityQuantityService::class)->availableQuantity($f['run']->fresh()))->toBe('1.00000000');
    productionRaceProof('quality-receipt', $f, [...$results, ...$retry, ...$replay], '2', '1');
});

test('independent competing partial receipts cannot allocate one approved output twice', function (): void {
    $f = productionRaceFixture();
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '2']);
    partialOutputApprove($f, '2');
    $results = productionRaceActions($f, [['kind' => 'receipt', 'quantity' => '2', 'token' => (string) Str::uuid()], ['kind' => 'receipt', 'quantity' => '2', 'token' => (string) Str::uuid()]]);
    expect(array_sum(array_column($results, 'ok')))->toBe(1)
        ->and($f['run']->inventoryDocuments()->where('document_type', InventoryDocument::TypeProductionReceipt)->count())->toBe(1)
        ->and(DB::table('production_quality_receipt_allocations')->whereIn('inventory_document_id', $f['run']->inventoryDocuments()->where('document_type', InventoryDocument::TypeProductionReceipt)->select('id'))->sum('base_quantity'))->toEqual(2)
        ->and(DB::table('document_submissions')->where('company_id', $f['company']->id)->count())->toBe(1);
    productionRaceProof('competing-receipts', $f, $results, '2', '2');
});

test('independent duplicate partial receipts replay one valued native receipt', function (): void {
    $f = productionRaceFixture();
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '2']);
    partialOutputApprove($f, '2');
    $action = ['kind' => 'receipt', 'quantity' => '1', 'token' => (string) Str::uuid()];
    $results = productionRaceActions($f, [$action, $action]);
    expect(array_column($results, 'ok'))->toBe([true, true])->and($results[0]['body'])->toBe($results[1]['body'])
        ->and($f['run']->inventoryDocuments()->where('document_type', InventoryDocument::TypeProductionReceipt)->count())->toBe(1);
    productionRaceProof('duplicate-receipt', $f, $results, '2', '1');
});

test('independent final receipt and run close preserve exact final stock and zero WIP', function (): void {
    $f = productionRaceFixture();
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '10']);
    partialOutputApprove($f, '10');
    $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '9');
    $close = ['kind' => 'close', 'token' => (string) Str::uuid()];
    $results = productionRaceActions($f, [['kind' => 'receipt', 'quantity' => '1', 'token' => (string) Str::uuid()], $close]);
    expect($results[0]['ok'])->toBeTrue();
    $retry = productionRaceActions($f, [$close]);
    expect($retry[0]['ok'])->toBeTrue()->and($f['run']->fresh()->status)->toBe('completed')
        ->and($f['run']->inventoryDocuments()->where('document_type', InventoryDocument::TypeProductionReceipt)->count())->toBe(2);
    $rejected = productionRaceActions($f, [['kind' => 'receipt', 'quantity' => '1', 'token' => (string) Str::uuid()]]);
    expect($rejected[0]['ok'])->toBeFalse();
    productionRaceProof('receipt-close', $f, [...$results, ...$retry, ...$rejected], '10', '10');
});

test('independent output and run close cannot mutate terminal valued output', function (): void {
    $f = productionRaceFixture();
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '10']);
    partialOutputApprove($f, '10');
    $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '10');
    $results = productionRaceActions($f, [['kind' => 'output', 'quantity' => '1', 'token' => (string) Str::uuid()], ['kind' => 'close', 'token' => (string) Str::uuid()]]);
    expect($results[0]['ok'])->toBeFalse()->and($results[1]['ok'])->toBeTrue()
        ->and($f['run']->fresh()->status)->toBe('completed')->and($f['run']->progressEntries()->count())->toBe(1);
    productionRaceProof('output-close', $f, $results, '10', '10');
});

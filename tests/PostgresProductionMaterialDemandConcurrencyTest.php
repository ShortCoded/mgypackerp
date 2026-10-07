<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Company;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Services\ProductionMaterialDemandService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/ProductionAnyStageMaterialSupport.php';

beforeEach(function (): void {
    if (getenv('MGYPACK_MATERIAL_DEMAND_RACE') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit isolated local PostgreSQL material demand race only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_material_demand_race_20261005')
        ->and($identity->host)->toBe('127.0.0.1')->and((int) $identity->port)->toBe(5432)
        ->and(DB::transactionLevel())->toBe(0);
});

/** @param array<string, mixed> $fixture @param list<array<string, mixed>> $actions @return list<array<string, mixed>> */
function materialDemandRaceActions(array $fixture, array $actions): array
{
    $directory = storage_path('app/test-artifacts/material-demand-race-'.Str::uuid());
    mkdir($directory, 0700);
    $config = DB::connection()->getConfig();
    $worker = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$identity = Illuminate\Support\Facades\DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port, pg_backend_pid() as pid');
if ($identity->db !== 'mgypack_material_demand_race_20261005' || $identity->host !== '127.0.0.1' || (int) $identity->port !== 5432) { throw new RuntimeException('Isolated race database guard'); }
Illuminate\Support\Carbon::setTestNow('2026-09-29 12:00:00');
$user = App\Models\User::query()->findOrFail($payload['user_id']);
$run = Modules\Production\Models\ProductionRun::query()->findOrFail($payload['run_id']);
$company = Modules\Core\Models\Company::query()->findOrFail($run->company_id);
if (!str_ends_with($user->email, '@example.test') || !str_starts_with($company->name, 'SYNTHETIC')) { throw new RuntimeException('Synthetic actor/company guard'); }
auth()->login($user);
$action = $payload['action'];
$kind = $action['kind'];
$routeName = 'admin.production.material-requests.store';
Illuminate\Support\Facades\Gate::authorize('production.material_requests.create');
$input = ['_submission_token' => $action['token']];
$path = '/admin/production/material-requests';
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
        $record = app(Modules\Production\Services\ProductionMaterialRequestService::class)->create($run, $payload['store_id'], quantitiesByComponentId: [$action['component_id'] => $action['quantity']]);
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
                'run_id' => $action['run_id'], 'store_id' => $fixture['store']->id, 'session' => manufacturingIntegritySession($fixture),
                'ready' => $directory.'/'.$index.'.ready', 'go' => $directory.'/go'], JSON_THROW_ON_ERROR));
            $process = new Process(['php', '-r', $worker, $path], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
                'DB_DATABASE' => 'mgypack_material_demand_race_20261005', 'DB_URL' => '', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'APP_CONFIG_CACHE' => '/tmp/mgypack-material-demand-race-worker-config.php', 'APP_ROUTES_CACHE' => '/tmp/mgypack-material-demand-race-worker-routes.php',
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

test('simultaneous stage requests serialize against one immutable BOM demand', function (): void {
    $f = anyStageMaterialFixture();
    $actions = $f['runs']->slice(1)->map(fn ($run): array => ['kind' => 'request', 'run_id' => $run->id, 'component_id' => $f['componentId'], 'quantity' => '150', 'token' => (string) Str::uuid()])->values()->all();
    $results = materialDemandRaceActions($f, $actions);
    expect(collect($results)->where('ok', true))->toHaveCount(1);
    expect(ProductionMaterialRequest::query()->where('production_order_id', $f['order']->id)->count())->toBe(1);
    expect(app(ProductionMaterialDemandService::class)->remaining($f['runs'][0]->requirements->sole()))->toBe('50.00000000');
});

test('simultaneous retries of one material request create one demand commitment', function (): void {
    $f = anyStageMaterialFixture();
    $action = ['kind' => 'request', 'run_id' => $f['runs'][1]->id, 'component_id' => $f['componentId'], 'quantity' => '20', 'token' => (string) Str::uuid()];
    $results = materialDemandRaceActions($f, [$action, $action]);
    expect(collect($results)->where('ok', true))->toHaveCount(2)
        ->and($results[0]['body']['id'])->toBe($results[1]['body']['id']);
    expect(ProductionMaterialRequest::query()->where('production_order_id', $f['order']->id)->count())->toBe(1);
    expect(app(ProductionMaterialDemandService::class)->remaining($f['runs'][0]->requirements->sole()))->toBe('180.00000000');
});

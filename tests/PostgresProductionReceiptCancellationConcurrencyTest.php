<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionReceiptCancellationService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/ProductionReceiptCancellationSupport.php';

beforeEach(function (): void {
    if (getenv('MGYPACK_RECEIPT_CANCELLATION_RACE') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit backed-up local synthetic receipt cancellation PostgreSQL race only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_receipt_cancellation_pg_20261005')
        ->and($identity->host)->toBe('127.0.0.1')->and((int) $identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('simultaneous independent approval retries create exactly one selected receipt stock and GL inverse', function (): void {
    $data = json_decode(file_get_contents(storage_path('app/test-artifacts/mgypack-receipt-cancellation-20261005/browser-fixture.json')), true, flags: JSON_THROW_ON_ERROR);
    $f = ['company' => Company::query()->findOrFail($data['company_id']),
        'branch' => Branch::query()->findOrFail($data['branch_id']),
        'period' => FinancialPeriod::query()->findOrFail($data['period_id']),
        'run' => ProductionRun::query()->where('company_id', $data['company_id'])->findOrFail($data['run']['id']),
        'user' => User::query()->findOrFail($data['actors']['operator']['id'])];
    expect(str_starts_with($f['company']->name, 'SYNTHETIC'))->toBeTrue()
        ->and(str_ends_with($f['user']->email, '@example.test'))->toBeTrue()->and($f['run']->status)->toBe(ProductionRun::StatusCompleted);
    $this->actingAs($f['user'])->withSession(manufacturingIntegritySession($f));
    request()->setUserResolver(fn () => $f['user']);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(manufacturingIntegritySession($f));
    $receipt = $f['run']->inventoryDocuments()->where('document_type', InventoryDocument::TypeProductionReceipt)
        ->where('status', InventoryDocument::StatusPosted)->orderByDesc('id')->firstOrFail();
    $beforeReceived = (string) $f['run']->received_base_quantity;
    $quantity = bcadd((string) $receipt->lines()->sum('quantity'), '0', 8);
    $preview = app(ProductionReceiptCancellationService::class)->preview($f['run'], $receipt->doc_num);
    expect($preview['blockers'])->toBeEmpty();
    $proposal = app(ProductionReceiptCancellationService::class)->prepare($f['run'], $receipt->doc_num,
        'SYNTHETIC concurrent independent receipt approval acceptance', $preview['fingerprint'], now()->toDateString(), 'original_period');
    $directory = storage_path('app/test-artifacts/receipt-cancellation-race-'.Str::uuid());
    mkdir($directory, 0700);
    $config = DB::connection()->getConfig();
    $worker = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$identity = Illuminate\Support\Facades\DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port, pg_backend_pid() as pid');
if ($identity->db !== 'mgypack_receipt_cancellation_pg_20261005' || $identity->host !== '127.0.0.1' || (int)$identity->port !== 5432) { throw new RuntimeException('Owned local race clone guard'); }
$user = App\Models\User::query()->findOrFail($payload['user_id']);
$run = Modules\Production\Models\ProductionRun::query()->findOrFail($payload['run_id']);
if (!str_ends_with($user->email, '@example.test') || !str_starts_with($run->order->company->name, 'SYNTHETIC')) { throw new RuntimeException('Synthetic actor/company guard'); }
auth()->setUser($user);
$request = Illuminate\Http\Request::create('/synthetic-local-receipt-approval', 'POST');
$request->setUserResolver(fn() => $user);
$request->setLaravelSession(app('session.store'));
$request->session()->put($payload['session']);
$app->instance('request', $request);
Illuminate\Support\Facades\Request::clearResolvedInstance('request');
Illuminate\Support\Facades\Gate::authorize('production.runs.correct_approve');
file_put_contents($payload['ready'], json_encode(['pid' => (int)$identity->pid]));
$deadline = microtime(true)+20;
while (!is_file($payload['go'])) { if (microtime(true)>$deadline) { throw new RuntimeException('Worker barrier timeout'); } usleep(10000); }
$proposal = app(Modules\Production\Services\ProductionReceiptCancellationService::class)->approve($run, $payload['proposal_id'], $payload['receipt_number']);
echo json_encode(['id' => $proposal->id, 'status' => $proposal->status, 'backend_pid' => (int)$identity->pid, 'transaction_level_after' => Illuminate\Support\Facades\DB::transactionLevel()], JSON_THROW_ON_ERROR);
WORKER;
    $processes = [];
    $pids = [];
    try {
        DB::beginTransaction();
        Company::query()->whereKey($f['company']->id)->lockForUpdate()->firstOrFail();
        foreach ([0, 1] as $index) {
            $path = $directory.'/'.$index.'.json';
            file_put_contents($path, json_encode(['user_id' => $data['actors']['approver']['id'], 'run_id' => $f['run']->id,
                'proposal_id' => (int) $proposal->id, 'receipt_number' => $receipt->doc_num, 'session' => manufacturingIntegritySession($f),
                'ready' => $directory.'/'.$index.'.ready', 'go' => $directory.'/go'], JSON_THROW_ON_ERROR));
            $process = new Process(['php', '-r', $worker, $path], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
                'DB_DATABASE' => 'mgypack_receipt_cancellation_pg_20261005', 'DB_URL' => '', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'APP_CONFIG_CACHE' => '/tmp/mgypack-receipt-race-worker-config.php', 'APP_ROUTES_CACHE' => '/tmp/mgypack-receipt-race-worker-routes.php',
                'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync',
                'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
            ]);
            $process->setTimeout(50)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 20;
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
        $deadline = microtime(true) + 15;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->whereIn('pid', array_values($pids))->where('wait_event_type', 'Lock')->pluck('pid')->all();
            if (count($waiting) === 2) {
                break;
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Both approval workers did not contend on native database locks.');
            }
            usleep(10000);
        } while (true);
        DB::commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect((int) $result['id'])->toBe((int) $proposal->id)->and($result['status'])->toBe('approved')->and($result['transaction_level_after'])->toBe(0);
            $results[] = $result;
        }
        expect($receipt->transactions()->where('is_reversal', true)->count())->toBe(1)
            ->and($f['run']->fresh()->received_base_quantity)->toBe(bcsub($beforeReceived, $quantity, 8));
        $this->actingAs($f['user'])->withSession(manufacturingIntegritySession($f));
        $cycle = app(ProductionCycleService::class);
        $cycle->receiveFinishedGoods($f['run']->fresh(), $data['run']['finished_store'], $quantity);
        $cycle->completeRun($f['run']->fresh());
        expect($f['run']->fresh()->status)->toBe(ProductionRun::StatusCompleted)->and($f['run']->fresh()->received_base_quantity)->toBe($beforeReceived);
        foreach (app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id, $f['branch']->id) as $row) {
            expect($row['difference'])->toBe('0.0000');
        }
        file_put_contents($directory.'/results.json', json_encode(['observed_simultaneous_lock_waits' => $waiting,
            'results' => $results, 'receipt_id' => $receipt->id, 'proposal_id' => $proposal->id, 'restored_native_completion' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
});

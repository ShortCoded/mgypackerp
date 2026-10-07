<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\SupplierSelection;
use Modules\Purchases\Services\ProcurementSourcingService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (getenv('MGYPACK_OWNER_CANCELLATION_RACE') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit backed-up local synthetic sourcing PostgreSQL race only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_owner_cancellation_pg_20261006')
        ->and($identity->host)->toBe('127.0.0.1')->and((int) $identity->port)->toBe(5432)
        ->and(DB::transactionLevel())->toBe(0);
});

/** @return array{fixture: array<string, mixed>, selection: SupplierSelection, session: array<string, int|string>} */
function sourcingRaceContext(): array
{
    $fixture = json_decode(file_get_contents(storage_path('app/test-artifacts/mgypack-local-owner-cancellation-20261006/browser-fixture.json')), true, flags: JSON_THROW_ON_ERROR);
    $company = Company::query()->findOrFail($fixture['company_id']);
    $branch = Branch::query()->findOrFail($fixture['branch_id']);
    $period = FinancialPeriod::query()->findOrFail($fixture['period_id']);
    $user = User::query()->findOrFail($fixture['actors']['operator']['id']);
    expect(str_starts_with($company->name, 'SYNTHETIC'))->toBeTrue()
        ->and(str_ends_with($user->email, '@example.test'))->toBeTrue();
    $session = [OperatingContextService::CompanyIdKey => $company->id, OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->id, OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->id, OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num];
    test()->actingAs($user)->withSession($session);
    request()->setUserResolver(fn () => $user);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put($session);

    return ['fixture' => $fixture, 'session' => $session,
        'selection' => SupplierSelection::query()->where('company_id', $company->id)->findOrFail($fixture['documents']['concurrency']['selection_id'])];
}

/**
 * @param  array<string, mixed>  $context
 * @param  list<string>  $operations
 * @return list<array<string, mixed>>
 */
function runSourcingOwnerRace(array $context, SupplierSelection $selection, array $operations): array
{
    $directory = storage_path('app/test-artifacts/mgypack-local-owner-cancellation-20261006/race-'.Str::uuid());
    mkdir($directory, 0700);
    $config = DB::connection()->getConfig();
    $worker = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$identity = Illuminate\Support\Facades\DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port, pg_backend_pid() as pid');
if ($identity->db !== 'mgypack_owner_cancellation_pg_20261006' || $identity->host !== '127.0.0.1' || (int) $identity->port !== 5432) { throw new RuntimeException('Owned local race clone guard'); }
$user = App\Models\User::query()->findOrFail($payload['user_id']);
$selection = Modules\Purchases\Models\SupplierSelection::query()->where('company_id', $payload['company_id'])->findOrFail($payload['selection_id']);
$company = Modules\Core\Models\Company::query()->findOrFail($selection->company_id);
if (!str_ends_with($user->email, '@example.test') || !str_starts_with($company->name, 'SYNTHETIC')) { throw new RuntimeException('Synthetic actor/company guard'); }
auth()->setUser($user);
$request = Illuminate\Http\Request::create('/synthetic-local-sourcing-race', 'POST');
$request->setUserResolver(fn () => $user);
$request->setLaravelSession(app('session.store'));
$request->session()->put($payload['session']);
$app->instance('request', $request);
Illuminate\Support\Facades\Request::clearResolvedInstance('request');
Illuminate\Support\Facades\Gate::authorize('purchases.supplier_selection.'.$payload['operation']);
file_put_contents($payload['ready'], json_encode(['pid' => (int) $identity->pid]));
$deadline = microtime(true) + 25;
while (!is_file($payload['go'])) { if (microtime(true) > $deadline) { throw new RuntimeException('Worker barrier timeout'); } usleep(10000); }
$result = ['operation' => $payload['operation'], 'backend_pid' => (int) $identity->pid];
try {
    $service = app(Modules\Purchases\Services\ProcurementSourcingService::class);
    if ($payload['operation'] === 'approve') {
        $result['purchase_order_ids'] = $service->approveSelection($selection)->pluck('id')->all();
    } else {
        $service->cancelSourcingDocument($selection, 'SYNTHETIC simultaneous owner cancellation');
    }
    $result['outcome'] = 'completed';
} catch (DomainException $exception) {
    $result['outcome'] = 'blocked';
    $result['reason'] = $exception->getMessage();
}
$result['status'] = $selection->fresh()->status;
$result['transaction_level_after'] = Illuminate\Support\Facades\DB::transactionLevel();
echo json_encode($result, JSON_THROW_ON_ERROR);
WORKER;
    $processes = [];
    $pids = [];
    try {
        DB::beginTransaction();
        SupplierSelection::query()->whereKey($selection->id)->lockForUpdate()->firstOrFail();
        foreach ($operations as $index => $operation) {
            $path = $directory.'/'.$index.'.json';
            file_put_contents($path, json_encode(['user_id' => $context['fixture']['actors']['operator']['id'],
                'company_id' => $selection->company_id, 'selection_id' => $selection->id, 'operation' => $operation,
                'session' => $context['session'], 'ready' => $directory.'/'.$index.'.ready', 'go' => $directory.'/go'], JSON_THROW_ON_ERROR));
            $process = new Process(['php', '-r', $worker, $path], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
                'DB_DATABASE' => 'mgypack_owner_cancellation_pg_20261006', 'DB_URL' => '', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
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
            'selection_id' => $selection->id, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

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

test('simultaneous sourcing cancellation retries retain one native audit and release capacity once', function (): void {
    $context = sourcingRaceContext();
    $selection = $context['selection'];
    expect($selection->status)->toBe('draft');
    $before = $selection->lines->map->getRawOriginal()->all();
    $results = runSourcingOwnerRace($context, $selection, ['cancel', 'cancel']);
    expect(array_column($results, 'outcome'))->toBe(['completed', 'completed'])
        ->and($selection->fresh()->status)->toBe('cancelled')
        ->and($selection->fresh()->lines->map->getRawOriginal()->all())->toBe($before)
        ->and(DB::table('activity_log')->where('event', 'sourcing_document.cancelled')->where('subject_type', SupplierSelection::class)->where('subject_id', $selection->id)->count())->toBe(1)
        ->and(PurchaseOrder::query()->where('supplier_selection_id', $selection->id)->count())->toBe(0)
        ->and(JournalEntry::query()->where('company_id', $selection->company_id)->count())->toBe(0)
        ->and(InventoryTransaction::query()->where('company_id', $selection->company_id)->count())->toBe(0);
});

test('simultaneous selection approval and cancellation cannot leave a cancelled award with an active order', function (): void {
    $context = sourcingRaceContext();
    $source = $context['selection'];
    expect($source->status)->toBe('cancelled');
    $selection = app(ProcurementSourcingService::class)->createSupplierSelection($source->requestForQuotation, [
        'selection_date' => now()->toDateString(), 'selection_reason' => 'SYNTHETIC approval versus cancellation race',
        'lines' => [['quotation_line_public_id' => $source->lines->sole()->quotationLine->public_id, 'selected_quantity' => '10']],
    ]);
    $results = runSourcingOwnerRace($context, $selection, ['approve', 'cancel']);
    expect(array_column($results, 'outcome'))->toContain('completed')->toContain('blocked');
    $selection->refresh();
    $orders = PurchaseOrder::query()->where('supplier_selection_id', $selection->id);
    expect($selection->status)->toBeIn(['approved', 'cancelled'])
        ->and($orders->count())->toBe($selection->status === 'approved' ? 1 : 0)
        ->and($orders->where('status', PurchaseOrder::StatusCancelled)->count())->toBe(0)
        ->and(DB::table('activity_log')->where('event', 'sourcing_document.cancelled')->where('subject_type', SupplierSelection::class)->where('subject_id', $selection->id)->count())->toBe($selection->status === 'cancelled' ? 1 : 0)
        ->and(JournalEntry::query()->where('company_id', $selection->company_id)->count())->toBe(0)
        ->and(InventoryTransaction::query()->where('company_id', $selection->company_id)->count())->toBe(0);
});

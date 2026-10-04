<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** @param list<array<string, mixed>> $operations @return array<string, mixed> */
function localOpeningOrderedRace(array $operations): array
{
    $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$identity = Illuminate\Support\Facades\DB::selectOne('select current_database() as db, host(inet_server_addr()) as host, inet_server_port() as port');
if ($identity->db !== 'mgypack_legacy_repair_candidate_20261004' || $identity->host !== '127.0.0.1' || (int)$identity->port !== 5432 || !$app->environment('testing')) { exit(90); }
config(['mail.default' => 'array', 'broadcasting.default' => 'null']);
Illuminate\Support\Facades\Mail::fake();
Illuminate\Support\Facades\Notification::fake();
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Http::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();
$app->instance(Modules\Core\Services\OperationalNotificationService::class, Mockery::mock(Modules\Core\Services\OperationalNotificationService::class)->shouldReceive('send')->zeroOrMoreTimes()->andReturnNull()->getMock());
$input = json_decode(getenv('MGYPACK_LOCAL_OPENING_WORKER'), true, flags: JSON_THROW_ON_ERROR);
$user = App\Models\User::findOrFail($input['user']);
auth()->login($user);
request()->setUserResolver(fn () => $user);
request()->setLaravelSession(app('session.store'));
session($input['context']);
$backend = Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() as pid');
file_put_contents($input['ready'], json_encode(['pid' => (int)$backend->pid]));
$deadline = microtime(true) + 15;
while (!file_exists($input['go'])) { if (microtime(true) > $deadline) { exit(91); } usleep(10000); }
$paused = false;
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\QueryExecuted::class, function ($event) use ($input, &$paused): void {
    if (!$input['pause'] || $paused || !str_contains($event->sql, '"financial_periods"') || !str_contains(strtolower($event->sql), 'for update')) { return; }
    $paused = true;
    file_put_contents($input['locked'], 'locked');
    $deadline = microtime(true) + 15;
    while (!file_exists($input['release'])) { if (microtime(true) > $deadline) { throw new RuntimeException('Synthetic local period checkpoint timeout'); } usleep(10000); }
});
try {
    $record = match ($input['operation']) {
        'approve' => app(Modules\Finance\Services\OpeningBalanceApprovalService::class)->approve(Modules\Finance\Models\OpeningBalance::findOrFail($input['id'])),
        'source-approve' => app(Modules\Inventory\Services\OpeningStockService::class)->approve(Modules\Inventory\Models\OpeningStock::findOrFail($input['id'])),
    };
    echo json_encode(['result' => 'applied', 'operation' => $input['operation'], 'id' => $record->id]);
} catch (DomainException $error) {
    echo json_encode(['result' => 'blocked', 'operation' => $input['operation'], 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage()); exit(1);
}
WORKER;
    $directory = sys_get_temp_dir().'/mgypack-local-opening-race-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $processes = [];
    try {
        foreach ($operations as $index => $operation) {
            $input = [...$operation, 'context' => session()->all(), 'user' => auth()->id(),
                'go' => $directory.'/go-'.$index, 'ready' => $directory.'/ready-'.$index,
                'pause' => $index === 0, 'locked' => $directory.'/locked', 'release' => $directory.'/release'];
            $process = new Process([PHP_BINARY, '-r', $worker], base_path(), ['MGYPACK_LOCAL_OPENING_WORKER' => json_encode($input, JSON_THROW_ON_ERROR),
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => LocalOpeningAcceptanceDatabase,
                'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432', 'SESSION_DRIVER' => 'array']);
            $process->setTimeout(45)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (count(glob($directory.'/ready-*')) !== count($operations) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(count(glob($directory.'/ready-*')))->toBe(count($operations));
        $pids = array_map(fn ($index): int => json_decode(file_get_contents($directory.'/ready-'.$index), true)['pid'], array_keys($operations));
        expect(count(array_unique($pids)))->toBe(count($operations));
        file_put_contents($directory.'/go-0', 'go');
        $deadline = microtime(true) + 15;
        while (! file_exists($directory.'/locked') && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($directory.'/locked'))->toBeTrue();
        file_put_contents($directory.'/go-1', 'go');
        $deadline = microtime(true) + 10;
        do {
            $waiting = DB::selectOne('select wait_event_type, wait_event from pg_stat_activity where pid = ?', [$pids[1]]);
            if ($waiting?->wait_event_type === 'Lock') {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect($waiting?->wait_event_type)->toBe('Lock');
        file_put_contents($directory.'/release', 'release');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $proof = ['distinct_backend_pids' => $pids, 'loser_wait_event_type' => $waiting->wait_event_type,
            'loser_wait_event' => $waiting->wait_event, 'first_holds_period_lock' => true, 'results' => $results];
        file_put_contents($directory.'/proof.json', json_encode($proof, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return [...$proof, 'artifact' => $directory.'/proof.json'];
    } finally {
        file_put_contents($directory.'/release', 'release');
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
}

<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\CustomerWithholdingSettlement;
use Modules\Sales\Services\CustomerWithholdingSettlementService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/CustomerWithholdingCollectionSupport.php';

beforeEach(function (): void {
    if (getenv('MGYPACK_WHT_RACE') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit synthetic concurrency on named isolated PostgreSQL race database only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_wht_race_pg_20261005')->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

/** @param array<string,mixed> $fixture @param list<array<string,mixed>> $actions @return list<array<string,mixed>> */
function withholdingParallelActions(array $fixture, array $actions): array
{
    $directory = storage_path('app/test-artifacts/wht-race-'.Str::uuid());
    mkdir($directory, 0700);
    $config = DB::connection()->getConfig();
    $worker = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$identity = Illuminate\Support\Facades\DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
if ($identity->db !== 'mgypack_wht_race_pg_20261005' || $identity->host !== '127.0.0.1' || (int)$identity->port !== 5432) { throw new RuntimeException('Race database guard'); }
$payload = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$user = App\Models\User::query()->findOrFail($payload['user_id']);
if (!str_contains($user->email, 'example.test') && !str_contains($user->email, 'example.com') && !str_contains($user->email, 'synthetic')) { throw new RuntimeException('Synthetic actor guard'); }
auth()->login($user);
request()->setUserResolver(fn() => $user);
request()->setLaravelSession(app('session.store'));
request()->session()->put($payload['session']);
if (!str_starts_with($payload['filesystem_root'], storage_path('framework/testing/disks/customer-withholding-'))) { throw new RuntimeException('Synthetic storage guard'); }
config(['filesystems.disks.local.root' => $payload['filesystem_root']]);
Illuminate\Support\Facades\Storage::forgetDisk('local');
file_put_contents($payload['ready'], 'ready');
$deadline = microtime(true)+15;
while (!is_file($payload['go'])) { if (microtime(true)>$deadline) { throw new RuntimeException('Race barrier timeout'); } usleep(10000); }
try {
    $invoice = Modules\Sales\Models\CustomerInvoice::query()->findOrFail($payload['invoice_id']);
    if ($payload['action']['kind'] === 'approve') {
        $record = app(Modules\Sales\Services\CustomerWithholdingSettlementService::class)->approve($invoice, $payload['action']['id'], 'SYNTHETIC concurrent approval');
        $result = ['ok'=>true, 'kind'=>'approve', 'id'=>$record->id, 'journal_id'=>$record->journal_entry_id];
    } else {
        $receipt = app(Modules\Sales\Services\CustomerReceiptService::class)->createAndApprove($payload['receipt'], [['customer_invoice_payment_schedule_id'=>$payload['schedule_id'], 'amount'=>'10']]);
        $result = ['ok'=>true, 'kind'=>'cash', 'id'=>$receipt->id, 'journal_id'=>$receipt->journal_entry_id];
    }
} catch (Throwable $exception) {
    $result = ['ok'=>false, 'kind'=>$payload['action']['kind'], 'exception'=>get_class($exception), 'message'=>$exception->getMessage()];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
WORKER;
    $processes = [];
    foreach ($actions as $index => $action) {
        $path = $directory.'/'.$index.'.json';
        file_put_contents($path, json_encode(['action' => $action, 'user_id' => $fixture['approver']->id,
            'invoice_id' => $fixture['invoice']->id, 'schedule_id' => $fixture['schedule']->id, 'session' => salesCycleSession($fixture),
            'filesystem_root' => Storage::disk('local')->path(''), 'ready' => $directory.'/'.$index.'.ready', 'go' => $directory.'/go',
            'receipt' => ['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'financial_period_id' => $fixture['period']->id,
                'customer_id' => $fixture['customer']->id, 'currency_id' => $fixture['currency']->id, 'exchange_rate' => '1',
                'receipt_date' => now()->toDateString(), 'receipt_type' => CustomerReceipt::TypeCollection,
                'payment_method' => 'bank', 'bank_account_id' => $fixture['bankAccount']->id, 'amount' => '10']], JSON_THROW_ON_ERROR));
        $process = new Process(['php', '-r', $worker, $path], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432', 'DB_DATABASE' => 'mgypack_wht_race_pg_20261005', 'DB_URL' => '',
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array']);
        $process->setTimeout(45)->start();
        $processes[] = $process;
    }
    try {
        $deadline = microtime(true) + 15;
        while (! is_file($directory.'/0.ready') || ! is_file($directory.'/1.ready')) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Race worker initialization failed: '.$process->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Parent race barrier timeout');
            }
            usleep(10000);
        }
        file_put_contents($directory.'/go', 'go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        file_put_contents($directory.'/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
}

test('concurrent independent approvals replay one immutable certificate journal', function (): void {
    $f = withholdingFixture();
    $record = app(CustomerWithholdingSettlementService::class)->prepare($f['invoice'], withholdingPayload($f));
    $count = DB::table('journal_entries')->count();
    $results = withholdingParallelActions($f, [['kind' => 'approve', 'id' => $record->id], ['kind' => 'approve', 'id' => $record->id]]);
    expect(array_column($results, 'ok'))->toBe([true, true])->and($results[0]['journal_id'])->toBe($results[1]['journal_id'])
        ->and(DB::table('journal_entries')->count())->toBe($count + 1)->and($f['invoice']->fresh()->actual_withholding_amount)->toBe('10.0000')
        ->and($f['invoice']->fresh()->remaining_amount)->toBe('0.0000');
    app(CustomerWithholdingSettlementService::class)->assertEvidence($f['company']->id);
});

test('concurrent different certificates cannot settle the same outstanding capacity twice', function (): void {
    $f = withholdingFixture();
    $service = app(CustomerWithholdingSettlementService::class);
    $payload = withholdingPayload($f);
    $first = $service->prepare($f['invoice'], $payload);
    $second = $service->prepare($f['invoice'], [...$payload, 'certificate_reference' => 'SYNTHETIC-CERT-2']);
    $count = DB::table('journal_entries')->count();
    $results = withholdingParallelActions($f, [['kind' => 'approve', 'id' => $first->id], ['kind' => 'approve', 'id' => $second->id]]);
    expect(array_sum(array_column($results, 'ok')))->toBe(1)->and(DB::table('journal_entries')->count())->toBe($count + 1)
        ->and($f['invoice']->fresh()->actual_withholding_amount)->toBe('10.0000')->and($f['invoice']->fresh()->remaining_amount)->toBe('0.0000')
        ->and(CustomerWithholdingSettlement::query()->where('company_id', $f['company']->id)->where('status', 'approved')->count())->toBe(1);
    $service->assertEvidence($f['company']->id);
});

test('concurrent cash and actual tax settle only the remaining native receivable', function (): void {
    $f = withholdingFixture();
    $record = app(CustomerWithholdingSettlementService::class)->prepare($f['invoice'], withholdingPayload($f));
    $count = DB::table('journal_entries')->count();
    $results = withholdingParallelActions($f, [['kind' => 'approve', 'id' => $record->id], ['kind' => 'cash']]);
    expect(array_sum(array_column($results, 'ok')))->toBe(1)->and(DB::table('journal_entries')->count())->toBe($count + 1)
        ->and($f['invoice']->fresh()->remaining_amount)->toBe('0.0000')
        ->and(bcadd($f['invoice']->fresh()->paid_amount, $f['invoice']->fresh()->actual_withholding_amount, 4))->toBe('1140.0000');
    app(CustomerWithholdingSettlementService::class)->assertEvidence($f['company']->id);
});

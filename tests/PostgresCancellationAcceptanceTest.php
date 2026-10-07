<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Services\ProductionMaterialRequestService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Services\CustomerInvoiceService;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/CancellationRecoverySupport.php';

beforeEach(function (): void {
    if (getenv('MGYPACK_CANCEL_ACCEPTANCE') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit synthetic local cancellation acceptance only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_cancel_acceptance_20261007')->and($identity->host)->toBe('127.0.0.1')->and((int) $identity->port)->toBe(5432);
});

test('PostgreSQL original source draft restoration preserves exact ownership and ledger totals', function (): void {
    foreach (['order', 'request'] as $type) {
        $f = cancellationRestoreFixture($type);
        $service = app(CustomerInvoiceService::class);
        $rows = $f['invoice']->lines->map->getRawOriginal()->all();
        $before = [JournalEntry::count(), InventoryTransaction::count()];
        $service->deleteDraft($f['invoice']);
        $restored = $service->restoreArchived($f['invoice']->fresh());
        expect($restored->lines->map->getRawOriginal()->all())->toBe($rows)->and($restored->trashed())->toBeFalse()
            ->and([JournalEntry::count(), InventoryTransaction::count()])->toBe($before);
        $field = $type === 'order' ? 'invoiced_quantity' : 'converted_quantity';
        expect($f['source']->fresh()->lines->first()->{$field})->toBe($type === 'order' ? '100.00000000' : '1.00000000');
    }
});

test('concurrent PostgreSQL restores cannot reserve the same source capacity twice', function (): void {
    $f = cancellationRestoreFixture();
    $service = app(CustomerInvoiceService::class);
    $service->deleteDraft($f['invoice']);
    $second = $service->createFromOrder($f['source']->fresh(), [['sales_order_line_id' => $f['source']->lines->first()->id, 'quantity' => '100']],
        [['amount' => '1000', 'due_date' => now()->toDateString()]]);
    $service->deleteDraft($second);
    $dir = storage_path('app/test-artifacts/mgypack-cancel-20261007/restore-race');
    mkdir($dir, 0700);
    $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$p = json_decode(getenv('MGYPACK_CANCEL_WORKER'), true, flags: JSON_THROW_ON_ERROR);
$db = Illuminate\Support\Facades\DB::selectOne('select current_database() as db, inet_server_addr() as host');
if ($db->db !== 'mgypack_cancel_acceptance_20261007' || $db->host !== '127.0.0.1') { throw new RuntimeException('Owned acceptance DB guard'); }
$user = App\Models\User::findOrFail($p['user']);
if (!str_starts_with($user->username, 'synthetic-closure-') || !str_ends_with($user->email, '@example.test')) { throw new RuntimeException('Synthetic actor guard'); }
auth()->login($user); request()->setUserResolver(fn() => $user); request()->setLaravelSession(app('session.store')); session($p['context']);
file_put_contents($p['ready'], 'ready');
$deadline = microtime(true)+15;
while (!is_file($p['go'])) { if(microtime(true)>$deadline) { throw new RuntimeException('Race barrier timeout'); } usleep(10000); }
try {
 app(Modules\Sales\Services\CustomerInvoiceService::class)->restoreArchived(Modules\Sales\Models\CustomerInvoice::onlyTrashed()->findOrFail($p['invoice']));
 echo json_encode(['outcome'=>'restored','invoice'=>$p['invoice']]);
} catch (DomainException $e) { echo json_encode(['outcome'=>'blocked','invoice'=>$p['invoice'],'message'=>$e->getMessage()]); }
PHP;
    $processes = [];
    try {
        foreach ([$f['invoice']->id, $second->id] as $index => $invoiceId) {
            $payload = ['user' => $f['user']->id, 'context' => salesCycleSession($f), 'invoice' => $invoiceId,
                'ready' => $dir.'/ready-'.$index, 'go' => $dir.'/go'];
            $process = new Process([PHP_BINARY, '-r', $worker], base_path(), ['MGYPACK_CANCEL_WORKER' => json_encode($payload), 'SESSION_DRIVER' => 'array']);
            $process->setTimeout(35)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (count(glob($dir.'/ready-*')) < 2 && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(count(glob($dir.'/ready-*')))->toBe(2);
        touch($dir.'/go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        expect(array_column($results, 'outcome'))->toContain('restored', 'blocked')
            ->and($f['source']->fresh()->lines->first()->invoiced_quantity)->toBe('100.00000000')
            ->and(CustomerInvoice::query()->whereIn('id', [$f['invoice']->id, $second->id])->count())->toBe(1);
        file_put_contents($dir.'/result.json', json_encode($results, JSON_PRETTY_PRINT));
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
});

test('prepare persistent only synthetic native cancellation browser documents in the owned database', function (): void {
    Carbon::setTestNow('2026-09-29 12:00:00');
    $path = storage_path('app/test-artifacts/mgypack-cancel-20261007/browser-fixture.json');
    expect(file_exists($path))->toBeFalse();
    $invoices = app(CustomerInvoiceService::class);
    $sales = [];
    foreach (['order', 'request'] as $type) {
        $f = cancellationRestoreFixture($type);
        foreach (['customer_invoices.cancel', 'customer_invoices.post', 'customer_invoices.reopen'] as $ability) {
            $f['user']->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }
        $invoices->deleteDraft($f['invoice']);
        $sales[$type] = ['actor' => ['id' => $f['user']->id, 'username' => $f['user']->username],
            'context' => ['company_doc_num' => $f['company']->doc_num, 'branch_doc_num' => $f['branch']->doc_num, 'financial_period_doc_num' => $f['period']->doc_num],
            'invoice_id' => $f['invoice']->id, 'invoice' => $f['invoice']->doc_num, 'source_id' => $f['source']->id,
            'source_line_id' => $f['source']->fresh()->lines->first()->id, 'restore_url' => route('admin.sales.sales-invoices.restore', $f['invoice']->doc_num, false)];
        if ($type === 'request') {
            $direct = $invoices->post($invoices->createDirect($f['invoice_payload']));
            $reopened = $invoices->reopen($direct, 'SYNTHETIC correction of original posted service invoice');
            $sales[$type]['reopened_invoice_id'] = $reopened->id;
            $sales[$type]['reopened_path'] = route('admin.sales.sales-invoices.show', $reopened, false);
        }
    }
    $f = cancellationExpenseFixture();
    $f['period']->update(['to_date' => '2026-12-31']);
    foreach (['production.material_requests.cancel', 'production.material_requests.view', 'production.runs.issue',
        'production.runs.cancel', 'production.runs.correct', 'production.runs.correct_approve'] as $ability) {
        $f['user']->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $materials = app(ProductionMaterialRequestService::class);
    $unissued = $materials->create($f['runs'][1], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '20']);
    $issued = $materials->approve($materials->create($f['runs'][0], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '20']));
    $materials->issue($issued, [$issued->lines->sole()->id => '1']);
    $fixture = ['database' => 'mgypack_cancel_acceptance_20261007', 'sales' => $sales,
        'production' => ['actor' => ['id' => $f['user']->id, 'username' => $f['user']->username],
            'context' => ['company_doc_num' => $f['company']->doc_num, 'branch_doc_num' => $f['branch']->doc_num, 'financial_period_doc_num' => $f['period']->doc_num],
            'expense_id' => $f['expense']->id, 'expense_path' => route('admin.production.expenses.show', $f['expense'], false),
            'unissued_id' => $unissued->id, 'unissued_path' => route('admin.production.material-requests.show', $unissued, false),
            'issued_id' => $issued->id, 'issued_path' => route('admin.production.material-requests.show', $issued, false),
            'run_path' => route('admin.production.runs.show', $f['runs'][0], false),
            'owner_path' => route('admin.production.runs.cancellation-owner', $f['runs'][0], false),
            'return_path' => route('admin.production.runs.operation', [$f['runs'][0], 'return'], false)],
        'default_database_written' => false, 'synthetic_actors_only' => true];
    file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

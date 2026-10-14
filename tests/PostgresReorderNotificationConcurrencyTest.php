<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Models\UserNotification;
use Modules\Core\Services\NotificationAccessService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\RequestMemo;
use Modules\Inventory\Models\InventoryReorderAlert;
use Modules\Inventory\Services\InventoryReorderNotificationService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/InventoryReorderSupport.php';

beforeEach(function (): void {
    if (getenv('MGYPACK_REORDER_PG') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit isolated PostgreSQL reorder rehearsal only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_reorder_notifications_20261007')->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432);
    Queue::fake();
    Notification::fake();
    config()->set('webpush.vapid.public_key', null);
    config()->set('webpush.vapid.private_key', null);
});

test('simultaneous PostgreSQL reorder checks create one shortage episode and one notification then permit recovery and rebreach', function (): void {
    $fixture = DB::transaction(fn (): array => reorderFixture());
    $token = sys_get_temp_dir().'/mgypack-reorder-'.bin2hex(random_bytes(12));
    $workers = [];
    config()->set('database.connections.reorder_lock', config('database.connections.pgsql'));
    $lock = DB::connection('reorder_lock');
    $lock->beginTransaction();
    $lock->table('companies')->where('id', $fixture['company']->id)->lockForUpdate()->first();
    try {
        foreach ([1, 2] as $worker) {
            $ready = var_export($token.'.ready.'.$worker, true);
            $companyId = $fixture['company']->id;
            $code = 'require '.var_export(base_path('vendor/autoload.php'), true).'; $app=require '.var_export(base_path('bootstrap/app.php'), true).';'
                .'$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
                .'$i=Illuminate\\Support\\Facades\\DB::selectOne("select current_database() as db, inet_server_addr() as host");'
                .'if($i->db!=="mgypack_reorder_notifications_20261007"||$i->host!=="127.0.0.1"){throw new RuntimeException("Isolated guard");}'
                .'Illuminate\\Support\\Facades\\Queue::fake(); Illuminate\\Support\\Facades\\Notification::fake();'
                .'config(["webpush.vapid.public_key"=>null,"webpush.vapid.private_key"=>null]);'
                .'file_put_contents('.$ready.',"ready");'
                .'echo json_encode(["created"=>app(Modules\\Inventory\\Services\\InventoryReorderNotificationService::class)->monitor('.$companyId.')]);';
            $workers[$worker] = new Process(['php', '-r', $code], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'mgypack_reorder_notifications_20261007',
                'DB_URL' => '', 'APP_CONFIG_CACHE' => '/tmp/mgypack-reorder-pg-config.php', 'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'stderr',
                'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
            ]);
            $workers[$worker]->setTimeout(30)->start();
        }
        $deadline = microtime(true) + 15;
        while ((! is_file($token.'.ready.1') || ! is_file($token.'.ready.2')) && microtime(true) < $deadline) {
            usleep(20000);
        }
        expect(is_file($token.'.ready.1'))->toBeTrue()->and(is_file($token.'.ready.2'))->toBeTrue();
        $lock->commit();
        $created = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $created[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['created'];
        }
        sort($created);
        expect($created)->toBe([0, 1]);
        $states = InventoryReorderAlert::query()->where('company_id', $companyId);
        $notifications = UserNotification::query()->where('company_id', $companyId);
        expect($states->count())->toBe(1)->and($states->sole()->episode)->toBe(1)->and($notifications->count())->toBe(1);
        $service = app(InventoryReorderNotificationService::class);
        expect($service->monitor($companyId))->toBe(0);
        reorderMovement($fixture, '3');
        expect($service->monitor($companyId))->toBe(0)->and($states->sole()->is_low)->toBeFalse();
        reorderMovement($fixture, '0', '1');
        expect($service->monitor($companyId))->toBe(1)->and($notifications->count())->toBe(2)->and($states->sole()->episode)->toBe(2);
        $restricted = reorderRestrictedRecipient($fixture);
        expect($service->monitor($companyId))->toBe(1);
        $notification = UserNotification::query()->where('user_id', $restricted->id)->sole();
        DB::table('role_financial_period_access')->where('role_id', $restricted->roles->sole()->id)->delete();
        app()->instance(RequestMemo::class, new RequestMemo);
        app()->forgetInstance(OperatingScopeAccessService::class);
        app()->forgetInstance(NotificationAccessService::class);
        expect(app(NotificationAccessService::class)->allows($restricted->fresh(), $notification))->toBeFalse();
        $evidence = ['database' => 'mgypack_reorder_notifications_20261007', 'company_id' => $companyId,
            'simultaneous_created' => $created, 'episode_after_rebreach' => $states->sole()->episode,
            'notifications_after_rebreach' => 2, 'period_revocation_enforced' => true, 'real_outbound' => 0];
        file_put_contents(storage_path('app/test-artifacts/reorder-notifications-20261007/postgres-concurrency.json'), json_encode($evidence, JSON_PRETTY_PRINT));
    } finally {
        if ($lock->transactionLevel() > 0) {
            $lock->rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        foreach ([1, 2] as $worker) {
            if (is_file($token.'.ready.'.$worker)) {
                unlink($token.'.ready.'.$worker);
            }
        }
        DB::disconnect('reorder_lock');
    }
});

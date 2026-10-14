<?php

use App\Jobs\DeliverWebPushNotification;
use App\Services\EffectivePermissionResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Product;
use Modules\Core\Models\UserNotification;
use Modules\Core\Services\NotificationAccessService;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\PwaSettingsService;
use Modules\Core\Services\RequestMemo;
use Modules\Core\Services\SettingService;
use Modules\Inventory\Models\InventoryReorderAlert;
use Modules\Inventory\Services\InventoryReorderNotificationService;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Purchases\Models\PurchaseRequisition;

require_once __DIR__.'/../InventoryReorderSupport.php';

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
    config()->set('webpush.vapid.public_key', null);
    config()->set('webpush.vapid.private_key', null);
});

test('reorder monitoring uses report available stock minus active reservations and alerts once per shortage episode', function (): void {
    $fixture = reorderFixture();
    $service = app(InventoryReorderNotificationService::class);
    expect($service->monitor($fixture['company']->id))->toBe(1)
        ->and($service->monitor($fixture['company']->id))->toBe(0);
    $notification = UserNotification::query()->sole();
    $report = app(InventoryReportService::class)->report($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id, []);
    expect($notification->metadata['available_quantity'])->toBe('7.00000000')
        ->and($notification->metadata['available_quantity'])->toBe($report['reorder']->sole()->available)
        ->and($notification->metadata['reorder_point'])->toBe($report['reorder']->sole()->reorder_point)
        ->and($notification->company_id)->toBe($fixture['company']->id)
        ->and($notification->branch_id)->toBe($fixture['branch']->id)
        ->and($notification->required_permission)->toBe(InventoryReorderAlert::Permission)
        ->and($notification->url)->toContain('/admin/inventory/reports/operations', 'branch_store_id='.$fixture['store']->id, 'product_id='.$fixture['product']->id)
        ->and(InventoryReorderAlert::query()->sole()->episode)->toBe(1)
        ->and(PurchaseRequisition::query()->count())->toBe(0);
    reorderMovement($fixture, '3');
    expect($service->monitor($fixture['company']->id))->toBe(0)
        ->and(InventoryReorderAlert::query()->sole()->is_low)->toBeFalse();
    reorderMovement($fixture, '0', '1');
    expect($service->monitor($fixture['company']->id))->toBe(1)
        ->and($service->monitor($fixture['company']->id))->toBe(0)
        ->and(UserNotification::query()->count())->toBe(2)
        ->and(InventoryReorderAlert::query()->sole()->episode)->toBe(2)
        ->and(UserNotification::query()->latest('id')->first()->metadata['available_quantity'])->toBe('9.00000000');
    Notification::assertNothingSent();
});

test('reorder alerts honor permission company branch financial period and active recipient boundaries', function (): void {
    $fixture = reorderFixture();
    $allowed = reorderRestrictedRecipient($fixture);
    foreach (['company', 'branch', 'financial_period'] as $scope) {
        reorderRestrictedRecipient($fixture, $scope);
    }
    $inactive = reorderRestrictedRecipient($fixture);
    $inactive->update(['status' => 'inactive']);
    $deleted = reorderRestrictedRecipient($fixture);
    $deleted->delete();
    expect(app(InventoryReorderNotificationService::class)->monitor($fixture['company']->id))->toBe(2)
        ->and(UserNotification::query()->pluck('user_id')->sort()->values()->all())->toBe(collect([$fixture['user']->id, $allowed->id])->sort()->values()->all());
});

test('reorder shortages do not fall back to administrators lacking report permission and can notify a newly eligible recipient once', function (): void {
    $fixture = reorderFixture();
    $fixture['user']->revokePermissionTo(InventoryReorderAlert::Permission);
    $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'web', 'doc_number' => 99100, 'doc_num' => 'REORDER-ADMIN']);
    $fixture['user']->assignRole($role);
    $service = app(InventoryReorderNotificationService::class);
    expect($service->monitor($fixture['company']->id))->toBe(0)->and(UserNotification::query()->count())->toBe(0);
    $fixture['user']->givePermissionTo(InventoryReorderAlert::Permission);
    expect($service->monitor($fixture['company']->id))->toBe(1)
        ->and($service->monitor($fixture['company']->id))->toBe(0)
        ->and(InventoryReorderAlert::query()->sole()->episode)->toBe(1);
});

test('disabling a reorder candidate resolves its episode without creating notifications', function (string $change): void {
    $fixture = reorderFixture();
    $service = app(InventoryReorderNotificationService::class);
    expect($service->monitor($fixture['company']->id))->toBe(1);
    match ($change) {
        'zero' => $fixture['product']->update(['reorder_point' => 0]),
        'null' => $fixture['product']->update(['reorder_point' => null]),
        'service' => $fixture['product']->update(['item_classification' => Product::ClassificationService]),
        'inactive-product' => $fixture['product']->update(['status' => 'inactive']),
        'deleted-product' => $fixture['product']->delete(),
        'deleted-store' => $fixture['store']->delete(),
        'inactive-branch' => $fixture['branch']->update(['status' => 'inactive']),
        'deleted-branch' => $fixture['branch']->delete(),
        'inactive-company' => $fixture['company']->update(['status' => 'inactive']),
        'deleted-company' => $fixture['company']->delete(),
        'closed-period' => $fixture['period']->update(['is_closed' => true]),
        'deleted-period' => $fixture['period']->delete(),
    };
    expect($service->monitor($fixture['company']->id))->toBe(0)
        ->and(InventoryReorderAlert::query()->sole()->is_low)->toBeFalse()
        ->and(UserNotification::query()->count())->toBe(1);
})->with(['zero', 'null', 'service', 'inactive-product', 'deleted-product', 'deleted-store', 'inactive-branch', 'deleted-branch', 'inactive-company', 'deleted-company', 'closed-period', 'deleted-period']);

test('zero thresholds do not create states and reactivation starts a new shortage episode', function (): void {
    $fixture = reorderFixture();
    $fixture['product']->update(['reorder_point' => 0]);
    $service = app(InventoryReorderNotificationService::class);
    expect($service->monitor($fixture['company']->id))->toBe(0)->and(InventoryReorderAlert::query()->count())->toBe(0);
    $fixture['product']->update(['reorder_point' => 10]);
    expect($service->monitor($fixture['company']->id))->toBe(1);
    $fixture['product']->update(['reorder_point' => 0]);
    $service->monitor($fixture['company']->id);
    $fixture['product']->update(['reorder_point' => 10]);
    expect($service->monitor($fixture['company']->id))->toBe(1)->and(InventoryReorderAlert::query()->sole()->episode)->toBe(2);
});

test('a failed notification transaction rolls back the shortage transition and retry creates one durable alert', function (): void {
    $fixture = reorderFixture();
    $this->partialMock(NotificationService::class)->shouldReceive('createImmediate')->once()->andThrow(new RuntimeException('Synthetic persistence failure'));
    expect(fn () => app(InventoryReorderNotificationService::class)->monitor($fixture['company']->id))->toThrow(RuntimeException::class)
        ->and(InventoryReorderAlert::query()->count())->toBe(0)->and(UserNotification::query()->count())->toBe(0);
    app()->forgetInstance(NotificationService::class);
    expect(app(InventoryReorderNotificationService::class)->monitor($fixture['company']->id))->toBe(1)
        ->and(UserNotification::query()->count())->toBe(1)->and(InventoryReorderAlert::query()->sole()->episode)->toBe(1);
});

test('reorder notifications render Arabic and English in the native center and open the filtered permitted report', function (): void {
    $fixture = reorderFixture();
    app(InventoryReorderNotificationService::class)->monitor($fixture['company']->id);
    $notification = UserNotification::query()->sole();
    $original = $notification->getRawOriginal();
    $this->withoutExceptionHandling()->actingAs($fixture['user']);
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $message = app(NotificationService::class)->reorderPresentation($notification->metadata);
        $this->withSession(['locale' => $locale])->getJson(route('admin.notifications.poll'))
            ->assertOk()->assertJsonPath('data.notifications.0.title', $message['title'])->assertJsonPath('data.notifications.0.body', $message['body']);
        $this->get(route('admin.notifications.index'))->assertOk()->assertSee($message['title'])->assertSee($message['body']);
    }
    expect($notification->fresh()->getRawOriginal())->toBe($original);
    $this->get(route('admin.notifications.open', $notification))->assertRedirect($notification->url);
    $this->get($notification->url)->assertOk()->assertSee($fixture['product']->name);
});

test('reorder push uses the existing after commit queue once and revoked permissions block native delivery', function (): void {
    config()->set('webpush.vapid.public_key', 'synthetic-public-key');
    config()->set('webpush.vapid.private_key', 'synthetic-private-key');
    config()->set('webpush.vapid.subject', 'mailto:synthetic@example.test');
    $fixture = reorderFixture();
    $service = app(InventoryReorderNotificationService::class);
    $service->monitor($fixture['company']->id);
    $service->monitor($fixture['company']->id);
    $notification = UserNotification::query()->sole();
    Queue::assertPushed(DeliverWebPushNotification::class, 1);
    Queue::assertPushed(fn (DeliverWebPushNotification $job): bool => $job->notificationId === $notification->id && $job->afterCommit === true);
    app(SettingService::class)->set(PwaSettingsService::EnabledKey, '1');
    app(SettingService::class)->set(PwaSettingsService::ServiceWorkerEnabledKey, '1');
    $fixture['user']->revokePermissionTo(InventoryReorderAlert::Permission);
    app(EffectivePermissionResolver::class)->flush();
    expect(app(NotificationAccessService::class)->allows($fixture['user']->fresh(), $notification))->toBeFalse();
    (new DeliverWebPushNotification($notification->id))->handle(app(PwaSettingsService::class));
    expect($notification->fresh()->push_status)->toBe('revoked');
    Notification::assertNothingSent();
});

test('financial period access revocation hides an existing reorder alert', function (): void {
    $fixture = reorderFixture();
    $user = reorderRestrictedRecipient($fixture);
    app(InventoryReorderNotificationService::class)->monitor($fixture['company']->id);
    $notification = UserNotification::query()->where('user_id', $user->id)->sole();
    DB::table('role_financial_period_access')->where('role_id', $user->roles->sole()->id)->delete();
    app()->instance(RequestMemo::class, new RequestMemo);
    app()->forgetInstance(OperatingScopeAccessService::class);
    app()->forgetInstance(NotificationAccessService::class);
    expect(app(NotificationAccessService::class)->allows($user->fresh(), $notification))->toBeFalse();
    $this->actingAs($user)->getJson(route('admin.notifications.poll'))->assertOk()->assertJsonCount(0, 'data.notifications');
});

test('reorder monitoring is registered every five minutes without overlapping and its command accepts a bounded company', function (): void {
    $fixture = reorderFixture();
    $this->artisan('inventory:notify-reorder-shortages', ['--company' => $fixture['company']->id])->expectsOutput('Created 1 reorder notifications.')->assertSuccessful();
    $this->artisan('inventory:notify-reorder-shortages', ['--company' => 'invalid'])->assertExitCode(2);
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains($event->command ?? '', 'inventory:notify-reorder-shortages'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

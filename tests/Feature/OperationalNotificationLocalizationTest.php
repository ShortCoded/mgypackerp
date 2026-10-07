<?php

use App\Models\User;
use Modules\Core\Models\UserNotification;
use Modules\Core\Services\NotificationService;

function storedOperationalNotification(User $user, bool $unassigned = false): UserNotification
{
    return UserNotification::query()->create(['user_id' => $user->id, 'type' => 'production.completed', 'category' => 'production', 'module' => 'production',
        'title' => $unassigned ? 'Operational responsibility is not assigned' : 'Production — Completed',
        'body' => $unassigned ? 'An operational event has no eligible recipient. Review responsibility and access settings.' : 'Document PROD-TEST-001 changed state and is in your work scope.',
        'metadata' => ['document_number' => 'PROD-TEST-001', 'status' => 'completed', 'subject_type' => 'Modules\\Production\\Models\\ProductionOrder'],
        'delivered_at' => now()]);
}

test('stored English operational notifications render in Arabic and English without rewriting their history', function (): void {
    $user = User::factory()->create();
    $notification = storedOperationalNotification($user);
    $original = $notification->fresh()->getRawOriginal();
    $this->withoutExceptionHandling()->actingAs($user);
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $title = __('notifications.operational.title', ['module' => __('notifications.modules.production'), 'status' => __('notifications.statuses.completed')]);
        $this->withSession(['locale' => $locale])->getJson(route('admin.notifications.poll'))->assertOk()->assertJsonPath('data.notifications.0.title', $title);
        $this->get(route('admin.notifications.index'))->assertOk()->assertSee($title);
    }
    expect($notification->fresh()->getRawOriginal())->toBe($original);
});

test('unassigned responsibility and custom notification wording retain their meaning when locale changes', function (): void {
    $user = User::factory()->create();
    $notification = storedOperationalNotification($user, true);
    app()->setLocale('ar');
    expect(app(NotificationService::class)->presentation($notification))->toBe([
        'title' => __('notifications.operational.unassigned_title'), 'body' => __('notifications.operational.unassigned_body')]);
    $notification->title = 'Custom customer instruction';
    expect(app(NotificationService::class)->presentation($notification)['title'])->toBe('Custom customer instruction');
    $notification->metadata = null;
    expect(app(NotificationService::class)->presentation($notification)['body'])->toBe($notification->body);
});

test('operational presentation does not expose another user notification through polling', function (): void {
    $user = User::factory()->create();
    storedOperationalNotification(User::factory()->create());
    $this->actingAs($user)->getJson(route('admin.notifications.poll'))->assertOk()->assertJsonCount(0, 'data.notifications');
});

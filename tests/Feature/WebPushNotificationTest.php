<?php

use App\Jobs\DeliverWebPushNotification;
use App\Models\User;
use App\Notifications\UserNotificationWebPush;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use Modules\Core\Models\UserNotification;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\PwaSettingsService;
use Modules\Core\Services\SettingService;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushMessage;

beforeEach(function (): void {
    config()->set('webpush.vapid.subject', 'mailto:qa@example.test');
    config()->set('webpush.vapid.public_key', 'qa-public-key');
    config()->set('webpush.vapid.private_key', 'qa-private-key');
});

function webPushSubscriptionPayload(string $endpoint = 'https://push.example.test/subscription/device-1'): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => [
            'p256dh' => 'public-browser-key',
            'auth' => 'browser-auth-token',
        ],
        'content_encoding' => 'aes128gcm',
    ];
}

test('push subscription endpoints require authentication', function () {
    $this->postJson(route('admin.notifications.push-subscriptions.store'), webPushSubscriptionPayload())
        ->assertUnauthorized();

    $this->deleteJson(route('admin.notifications.push-subscriptions.destroy'), [
        'endpoint' => webPushSubscriptionPayload()['endpoint'],
    ])->assertUnauthorized();
});

test('authenticated user can subscribe update and unsubscribe the current browser', function () {
    $user = User::factory()->create();
    $payload = webPushSubscriptionPayload();

    $this->actingAs($user)
        ->postJson(route('admin.notifications.push-subscriptions.store'), $payload)
        ->assertOk()
        ->assertJsonPath('success', true);

    $subscription = PushSubscription::query()->sole();

    expect($user->ownsPushSubscription($subscription))->toBeTrue()
        ->and($subscription->endpoint)->toBe($payload['endpoint'])
        ->and($subscription->public_key)->toBe($payload['keys']['p256dh'])
        ->and($subscription->auth_token)->toBe($payload['keys']['auth']);

    $payload['keys']['auth'] = 'rotated-auth-token';

    $this->postJson(route('admin.notifications.push-subscriptions.store'), $payload)
        ->assertOk();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and($subscription->refresh()->auth_token)->toBe('rotated-auth-token');

    $this->deleteJson(route('admin.notifications.push-subscriptions.destroy'), [
        'endpoint' => $payload['endpoint'],
    ])->assertOk();

    expect(PushSubscription::query()->count())->toBe(0);
});

test('one user cannot remove another users push subscription', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $endpoint = webPushSubscriptionPayload()['endpoint'];

    $owner->updatePushSubscription($endpoint, 'owner-key', 'owner-token', 'aes128gcm');

    $this->actingAs($other)
        ->deleteJson(route('admin.notifications.push-subscriptions.destroy'), ['endpoint' => $endpoint])
        ->assertOk();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and($owner->fresh()->pushSubscriptions)->toHaveCount(1);
});

test('durable notification queues one after commit push delivery per deduplicated event', function () {
    Queue::fake();
    $recipient = User::factory()->create();

    $first = app(NotificationService::class)->createImmediate(
        $recipient,
        'task.assigned',
        'Task assigned',
        'A task was assigned.',
        '/admin/tools/my-board',
        dedupeKey: 'task.assigned:44:'.$recipient->getKey(),
    );
    $second = app(NotificationService::class)->createImmediate(
        $recipient,
        'task.assigned',
        'Task assigned',
        'A task was assigned.',
        '/admin/tools/my-board',
        dedupeKey: 'task.assigned:44:'.$recipient->getKey(),
    );

    expect($first->is($second))->toBeTrue()
        ->and(UserNotification::query()->count())->toBe(1);

    Queue::assertPushed(DeliverWebPushNotification::class, 1);
    Queue::assertPushed(fn (DeliverWebPushNotification $job): bool => $job->notificationId === $first->getKey());
});

test('push delivery complements the existing database notification', function () {
    Notification::fake();
    app(SettingService::class)->set(PwaSettingsService::EnabledKey, '1');
    app(SettingService::class)->set(PwaSettingsService::ServiceWorkerEnabledKey, '1');

    $recipient = User::factory()->create();
    $recipient->updatePushSubscription(
        'https://push.example.test/subscription/delivery',
        'browser-public-key',
        'browser-auth-token',
        'aes128gcm',
    );
    $notification = UserNotification::query()->create([
        'user_id' => $recipient->getKey(),
        'type' => 'calendar.event_reminder',
        'category' => 'calendar',
        'title' => 'Calendar reminder',
        'body' => 'Production review starts soon.',
        'url' => '/admin/calendar',
        'delivered_at' => now(),
        'dedupe_key' => 'calendar.event_reminder:81:'.$recipient->getKey(),
    ]);

    (new DeliverWebPushNotification((int) $notification->getKey()))
        ->handle(app(PwaSettingsService::class));

    Notification::assertSentTo(
        $recipient,
        UserNotificationWebPush::class,
        function (UserNotificationWebPush $push): bool {
            $payload = $push->toWebPush(new stdClass)->toArray();

            return $payload['title'] === 'Calendar reminder'
                && $payload['body'] === 'Production review starts soon.'
                && $payload['tag'] !== ''
                && $payload['data']['url'] === '/admin/calendar'
                && filled($payload['data']['occurred_at']);
        },
    );

    expect($notification->fresh())->not->toBeNull()
        ->and($notification->delivered_at)->not->toBeNull();
});

test('expired or permanently missing push endpoint is removed from the owning user', function (int $status) {
    $user = User::factory()->create();
    $endpoint = "https://push.example.test/subscription/expired-{$status}";
    $subscription = $user->updatePushSubscription($endpoint, 'expired-key', 'expired-token', 'aes128gcm');
    $report = new MessageSentReport(
        new Request('POST', $endpoint),
        new Response($status),
        false,
        'Push service rejected the subscription.',
    );

    app(ReportHandler::class)->handleReport($report, $subscription, new WebPushMessage);

    expect(PushSubscription::query()->whereKey($subscription->getKey())->exists())->toBeFalse();
})->with([404, 410]);

test('web push payload contains no private VAPID key or financial metadata', function () {
    $notification = new UserNotification([
        'public_uuid' => '8e6f0472-c9ec-45f1-9b38-bc3066f92b36',
        'title' => 'Sales approval',
        'body' => 'A sales document needs attention.',
        'url' => '/admin/sales/quotations/Q-100',
        'dedupe_key' => 'sales.approval:Q-100',
        'delivered_at' => now(),
    ]);
    $payload = (new UserNotificationWebPush($notification))->toWebPush(new stdClass)->toArray();
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('qa-private-key')
        ->not->toContain('amount')
        ->not->toContain('total');
});

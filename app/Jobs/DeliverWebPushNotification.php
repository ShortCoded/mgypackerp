<?php

namespace App\Jobs;

use App\Notifications\UserNotificationWebPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Core\Models\UserNotification;
use Modules\Core\Services\NotificationAccessService;
use Modules\Core\Services\PwaSettingsService;
use Throwable;

class DeliverWebPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $notificationId)
    {
        $this->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(PwaSettingsService $pwaSettings, ?NotificationAccessService $access = null): void
    {
        $access ??= app(NotificationAccessService::class);

        if (! $this->configured()) {
            $this->updateStatus('not_configured');

            return;
        }

        $settings = $pwaSettings->settings();

        if (! $settings['enabled'] || ! $settings['service_worker_enabled']) {
            $this->updateStatus('disabled');

            return;
        }

        $notification = UserNotification::query()
            ->with('user.pushSubscriptions')
            ->find($this->notificationId);

        if (! $notification instanceof UserNotification || $notification->delivered_at === null || ! $notification->user) {
            return;
        }

        if (! $access->allows($notification->user, $notification)) {
            $notification->forceFill([
                'push_status' => 'revoked',
                'push_last_attempt_at' => now(),
                'push_error_code' => null,
            ])->save();

            return;
        }

        if ($notification->user->pushSubscriptions->isEmpty()) {
            $notification->forceFill([
                'push_status' => 'no_subscription',
                'push_last_attempt_at' => now(),
                'push_error_code' => null,
            ])->save();

            return;
        }

        try {
            $notification->forceFill([
                'push_status' => 'sending',
                'push_attempts' => (int) $notification->push_attempts + 1,
                'push_last_attempt_at' => now(),
                'push_error_code' => null,
            ])->save();

            $notification->user->notify(new UserNotificationWebPush(
                $notification,
                $settings['icon_192_url'],
            ));

            $notification->forceFill(['push_status' => 'accepted'])->save();
        } catch (Throwable $exception) {
            $notification->forceFill([
                'push_status' => 'failed',
                'push_error_code' => class_basename($exception),
            ])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        UserNotification::query()
            ->whereKey($this->notificationId)
            ->update([
                'push_status' => 'failed',
                'push_error_code' => $exception ? class_basename($exception) : 'UnknownFailure',
                'push_last_attempt_at' => now(),
            ]);
    }

    private function updateStatus(string $status): void
    {
        UserNotification::query()
            ->whereKey($this->notificationId)
            ->update([
                'push_status' => $status,
                'push_last_attempt_at' => now(),
            ]);
    }

    private function configured(): bool
    {
        return filled(config('webpush.vapid.subject'))
            && filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }
}

<?php

namespace App\Jobs;

use App\Notifications\UserNotificationWebPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Core\Models\UserNotification;
use Modules\Core\Services\PwaSettingsService;
use Throwable;

class DeliverWebPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $notificationId)
    {
        $this->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(PwaSettingsService $pwaSettings): void
    {
        if (! $this->configured()) {
            return;
        }

        $settings = $pwaSettings->settings();

        if (! $settings['enabled'] || ! $settings['service_worker_enabled']) {
            return;
        }

        $notification = UserNotification::query()
            ->with('user.pushSubscriptions')
            ->find($this->notificationId);

        if (! $notification instanceof UserNotification || $notification->delivered_at === null || $notification->user?->pushSubscriptions->isEmpty()) {
            return;
        }

        try {
            $notification->user->notify(new UserNotificationWebPush(
                $notification,
                $settings['icon_192_url'],
                $settings['icon_192_url'],
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function configured(): bool
    {
        return filled(config('webpush.vapid.subject'))
            && filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }
}

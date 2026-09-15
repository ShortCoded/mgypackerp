<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Core\Models\UserNotification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class UserNotificationWebPush extends Notification
{
    use Queueable;

    public function __construct(
        public readonly UserNotification $userNotification,
        public readonly ?string $icon = null,
        public readonly ?string $badge = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $message = (new WebPushMessage)
            ->title($this->userNotification->external_title ?: $this->userNotification->title)
            ->tag($this->userNotification->dedupe_key ?: $this->userNotification->public_uuid)
            ->lang(app()->getLocale())
            ->data([
                'id' => $this->userNotification->public_uuid,
                'event_id' => $this->userNotification->event_uuid,
                'severity' => $this->userNotification->severity,
                'url' => route('admin.notifications.open', $this->userNotification, false),
                'occurred_at' => ($this->userNotification->delivered_at ?: $this->userNotification->created_at)?->toIso8601String(),
            ])
            ->options([
                'TTL' => 3600,
                'urgency' => $this->userNotification->severity === 'urgent' ? 'high' : 'normal',
            ]);

        $externalBody = $this->userNotification->external_body ?: $this->userNotification->body;

        if (filled($externalBody)) {
            $message->body((string) $externalBody);
        }

        if (filled($this->icon)) {
            $message->icon((string) $this->icon);
        }

        if (filled($this->badge)) {
            $message->badge((string) $this->badge);
        }

        return $message;
    }
}

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
            ->title($this->userNotification->title)
            ->tag($this->userNotification->dedupe_key ?: $this->userNotification->public_uuid)
            ->lang(app()->getLocale())
            ->data([
                'id' => $this->userNotification->public_uuid,
                'url' => $this->userNotification->url ?: route('dashboard', [], false),
                'occurred_at' => ($this->userNotification->delivered_at ?: $this->userNotification->created_at)?->toIso8601String(),
            ])
            ->options([
                'TTL' => 3600,
                'urgency' => 'normal',
            ]);

        if (filled($this->userNotification->body)) {
            $message->body((string) $this->userNotification->body);
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

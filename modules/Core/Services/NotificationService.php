<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\CalendarEvent;
use Modules\Core\Models\UserNotification;
use Modules\Core\Models\UserTask;

class NotificationService
{
    public function __construct(
        private readonly DateFormatService $dates,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createImmediate(
        User $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        array $metadata = [],
        ?string $dedupeKey = null,
    ): UserNotification {
        return $this->persist([
            'user_id' => $recipient->getKey(),
            'type' => $type,
            'category' => Str::before($type, '.'),
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'metadata' => $metadata,
            'scheduled_for' => null,
            'delivered_at' => now(),
            'dedupe_key' => $dedupeKey,
        ]);
    }

    public function notifyTaskAssigned(UserTask $task, iterable $recipients, User $actor): void
    {
        foreach ($this->recipientUsers($recipients) as $recipient) {
            if ((int) $recipient->getKey() === (int) $actor->getKey()) {
                continue;
            }

            $this->createImmediate(
                $recipient,
                'task.assigned',
                __('notifications.types.task_assigned'),
                __('notifications.messages.task_assigned', ['title' => $task->title]),
                route('admin.my-board.index', [], false),
                $this->taskMetadata($task, $actor),
                "task.assigned:{$task->getKey()}:{$recipient->getKey()}",
            );
        }
    }

    public function notifyTaskUpdated(UserTask $task, iterable $recipients, User $actor): void
    {
        foreach ($this->recipientUsers($recipients) as $recipient) {
            if ((int) $recipient->getKey() === (int) $actor->getKey()) {
                continue;
            }

            $timestamp = $task->updated_at?->format('YmdHis') ?: now()->format('YmdHis');

            $this->createImmediate(
                $recipient,
                'task.updated',
                __('notifications.types.task_updated'),
                __('notifications.messages.task_updated', ['title' => $task->title]),
                route('admin.my-board.index', [], false),
                $this->taskMetadata($task, $actor),
                "task.updated:{$task->getKey()}:{$recipient->getKey()}:{$timestamp}",
            );
        }
    }

    public function scheduleCalendarReminder(CalendarEvent $event): void
    {
        if (! ($event->reminder_at instanceof Carbon) || $event->trashed() || $event->status === CalendarEvent::StatusCancelled) {
            $this->cancelCalendarReminder($event);

            return;
        }

        $this->persist([
            'user_id' => $event->user_id,
            'type' => 'calendar.event_reminder',
            'category' => 'calendar',
            'title' => __('notifications.types.calendar_reminder'),
            'body' => __('notifications.messages.calendar_reminder', [
                'title' => $event->title,
                'time' => $this->dates->formatDateTime($event->starts_at, ''),
            ]),
            'url' => route('admin.calendar.index', [], false),
            'metadata' => [
                'event_uuid' => $event->public_uuid,
                'event_title' => $event->title,
                'starts_at' => $event->starts_at?->toJSON(),
                'location' => $event->location,
            ],
            'scheduled_for' => $event->reminder_at,
            'delivered_at' => null,
            'read_at' => null,
            'dedupe_key' => "calendar.event_reminder:{$event->getKey()}:{$event->user_id}",
        ], updateExisting: true);
    }

    public function cancelCalendarReminder(CalendarEvent $event): void
    {
        UserNotification::query()
            ->where('dedupe_key', "calendar.event_reminder:{$event->getKey()}:{$event->user_id}")
            ->whereNull('delivered_at')
            ->delete();
    }

    public function dispatchDue(int $limit = 200): int
    {
        return DB::transaction(function () use ($limit): int {
            $dispatched = $this->deliverScheduled($limit);
            $dispatched += $this->dispatchTaskDueSoon($limit);

            return $dispatched;
        });
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::query()
            ->forUser($user)
            ->delivered()
            ->whereNull('read_at')
            ->count();
    }

    /**
     * @return EloquentCollection<int, UserNotification>
     */
    public function latestFor(User $user, int $limit = 10): EloquentCollection
    {
        return UserNotification::query()
            ->forUser($user)
            ->delivered()
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->latest('delivered_at')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function persist(array $values, bool $updateExisting = false): UserNotification
    {
        $dedupeKey = $values['dedupe_key'] ?? null;

        if (is_string($dedupeKey) && $dedupeKey !== '') {
            if ($updateExisting) {
                return UserNotification::query()->updateOrCreate(
                    ['dedupe_key' => $dedupeKey],
                    $values,
                );
            }

            $existing = UserNotification::query()->where('dedupe_key', $dedupeKey)->first();

            if ($existing instanceof UserNotification) {
                return $existing;
            }
        }

        return UserNotification::query()->create($values);
    }

    private function deliverScheduled(int $limit): int
    {
        $now = now();
        $notifications = UserNotification::query()
            ->whereNull('delivered_at')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', $now)
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->lockForUpdate()
            ->get();

        foreach ($notifications as $notification) {
            $notification->forceFill(['delivered_at' => $now])->save();
        }

        return $notifications->count();
    }

    private function dispatchTaskDueSoon(int $limit): int
    {
        $now = now();
        $soon = $now->copy()->addMinutes(30);
        $created = 0;

        UserTask::query()
            ->with(['assignees:id,name,doc_num,email', 'assignedTo:id,name,doc_num,email', 'createdBy:id,name,doc_num'])
            ->where('type', UserTask::TypeTask)
            ->where('status', '!=', UserTask::StatusDone)
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$now, $soon])
            ->orderBy('due_at')
            ->limit($limit)
            ->get()
            ->each(function (UserTask $task) use (&$created): void {
                foreach ($this->taskRecipients($task) as $recipient) {
                    $notification = $this->createImmediate(
                        $recipient,
                        'task.due_soon',
                        __('notifications.types.task_due_soon'),
                        __('notifications.messages.task_due_soon', [
                            'title' => $task->title,
                            'time' => $this->dates->formatDateTime($task->due_at, ''),
                        ]),
                        route('admin.my-board.index', [], false),
                        $this->taskMetadata($task, $task->createdBy),
                        "task.due_soon:{$task->getKey()}:{$recipient->getKey()}",
                    );

                    if ($notification->wasRecentlyCreated) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    /**
     * @return Collection<int, User>
     */
    private function taskRecipients(UserTask $task): Collection
    {
        $recipients = $task->assignees;

        if ($recipients->isEmpty() && $task->assignedTo instanceof User) {
            $recipients = collect([$task->assignedTo]);
        }

        return $recipients->unique(fn (User $user): int => (int) $user->getKey())->values();
    }

    /**
     * @param  iterable<int, User>  $users
     * @return Collection<int, User>
     */
    private function recipientUsers(iterable $users): Collection
    {
        return collect($users)
            ->filter(fn (mixed $user): bool => $user instanceof User)
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function taskMetadata(UserTask $task, ?User $actor): array
    {
        return [
            'task_doc_num' => $task->doc_num,
            'task_title' => $task->title,
            'actor_doc_num' => $actor?->doc_num,
            'actor_name' => $actor?->name,
            'due_at' => $task->due_at?->toJSON(),
        ];
    }
}

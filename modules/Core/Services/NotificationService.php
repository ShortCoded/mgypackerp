<?php

namespace Modules\Core\Services;

use App\Jobs\DeliverWebPushNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\CalendarEvent;
use Modules\Core\Models\ChatConversation;
use Modules\Core\Models\UserNotification;
use Modules\Core\Models\UserTask;
use Ramsey\Uuid\Uuid;
use stdClass;

class NotificationService
{
    public function __construct(
        private readonly DateFormatService $dates,
        private readonly NotificationAccessService $access,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $delivery
     */
    public function createImmediate(
        User $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        array $metadata = [],
        ?string $dedupeKey = null,
        array $delivery = [],
    ): UserNotification {
        $notification = $this->persist([
            'event_uuid' => $delivery['event_uuid'] ?? (string) Str::uuid(),
            'user_id' => $recipient->getKey(),
            'type' => $type,
            'category' => Str::before($type, '.'),
            'module' => $delivery['module'] ?? Str::before($type, '.'),
            'severity' => $delivery['severity'] ?? 'information',
            'requires_action' => (bool) ($delivery['requires_action'] ?? false),
            'sound_key' => $delivery['sound_key'] ?? null,
            'suppress_in_app_alert' => (bool) ($delivery['suppress_in_app_alert'] ?? false),
            'title' => $title,
            'body' => $body,
            'external_title' => $delivery['external_title'] ?? $title,
            'external_body' => $delivery['external_body'] ?? $body,
            'url' => $url,
            'required_permission' => $delivery['required_permission'] ?? null,
            'company_id' => $delivery['company_id'] ?? null,
            'branch_id' => $delivery['branch_id'] ?? null,
            'conversation_id' => $delivery['conversation_id'] ?? null,
            'metadata' => $metadata,
            'scheduled_for' => null,
            'delivered_at' => now(),
            'dedupe_key' => $dedupeKey,
        ]);

        if ($notification->wasRecentlyCreated && $this->pushConfigured()) {
            $notification->forceFill(['push_status' => 'queued'])->save();
            DeliverWebPushNotification::dispatch((int) $notification->getKey())->afterCommit();
        }

        return $notification;
    }

    public function notifyTaskAssigned(UserTask $task, iterable $recipients, User $actor): void
    {
        $eventUuid = (string) Str::uuid();

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
                "task.assigned:{$task->getKey()}:{$eventUuid}:{$recipient->getKey()}",
                [
                    'event_uuid' => $eventUuid,
                    'severity' => 'action',
                    'requires_action' => true,
                    'sound_key' => 'action',
                ],
            );
        }
    }

    public function notifyTaskUpdated(UserTask $task, iterable $recipients, User $actor): void
    {
        $eventUuid = (string) Str::uuid();
        $recipients = $this->recipientUsers($recipients);

        if ($task->createdBy instanceof User) {
            $recipients->push($task->createdBy);
        }

        foreach ($recipients->unique(fn (User $user): int => (int) $user->getKey()) as $recipient) {
            if ((int) $recipient->getKey() === (int) $actor->getKey()) {
                continue;
            }

            $this->createImmediate(
                $recipient,
                'task.updated',
                __('notifications.types.task_updated'),
                __('notifications.messages.task_updated', ['title' => $task->title]),
                route('admin.my-board.index', [], false),
                $this->taskMetadata($task, $actor),
                "task.updated:{$task->getKey()}:{$eventUuid}:{$recipient->getKey()}",
                [
                    'event_uuid' => $eventUuid,
                    'severity' => 'action',
                    'requires_action' => true,
                    'sound_key' => 'action',
                ],
            );
        }
    }

    public function notifyTaskUnassigned(UserTask $task, iterable $recipients, User $actor): void
    {
        $eventUuid = (string) Str::uuid();

        foreach ($this->recipientUsers($recipients) as $recipient) {
            if ((int) $recipient->getKey() === (int) $actor->getKey()) {
                continue;
            }

            $this->createImmediate(
                $recipient,
                'task.unassigned',
                __('notifications.types.task_unassigned'),
                __('notifications.messages.task_unassigned', ['title' => $task->title]),
                route('admin.my-board.index', [], false),
                $this->taskMetadata($task, $actor),
                "task.unassigned:{$task->getKey()}:{$eventUuid}:{$recipient->getKey()}",
                ['event_uuid' => $eventUuid, 'severity' => 'information'],
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
            'module' => 'calendar',
            'event_uuid' => $event->public_uuid,
            'severity' => 'action',
            'requires_action' => true,
            'sound_key' => 'action',
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
            $dispatched += $this->dispatchTaskDue($limit);
            $dispatched += $this->dispatchTaskOverdue($limit);

            return $dispatched;
        });
    }

    public function unreadCount(User $user): int
    {
        return $this->access->queryFor($user)
            ->delivered()
            ->whereNull('read_at')
            ->count();
    }

    /**
     * @return array{
     *     unread_count: int,
     *     notifications: Collection<int, stdClass>
     * }
     */
    public function pollData(User $user, int $limit = 10): array
    {
        $unreadCount = $this->access->queryFor($user)
            ->delivered()
            ->whereNull('read_at')
            ->selectRaw('COUNT(*)');

        $notifications = $this->access->queryFor($user)
            ->delivered()
            ->select([
                'id',
                'public_uuid',
                'event_uuid',
                'type',
                'category',
                'module',
                'severity',
                'requires_action',
                'sound_key',
                'suppress_in_app_alert',
                'title',
                'body',
                'url',
                'read_at',
                'delivered_at',
                'created_at',
            ])
            ->selectSub(
                ChatConversation::query()
                    ->select('public_uuid')
                    ->whereColumn('chat_conversations.id', 'user_notifications.conversation_id'),
                'conversation_uuid',
            )
            ->selectSub($unreadCount, 'unread_count')
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->latest('delivered_at')
            ->latest('created_at')
            ->limit($limit)
            ->toBase()
            ->get();

        return [
            'unread_count' => (int) ($notifications->first()?->unread_count ?? 0),
            'notifications' => $notifications,
        ];
    }

    /**
     * @return EloquentCollection<int, UserNotification>
     */
    public function latestFor(User $user, int $limit = 10): EloquentCollection
    {
        return $this->access->queryFor($user)
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

            try {
                return UserNotification::query()->firstOrCreate(
                    ['dedupe_key' => $dedupeKey],
                    $values,
                );
            } catch (UniqueConstraintViolationException) {
                return UserNotification::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
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
            if ($this->pushConfigured()) {
                $notification->forceFill(['push_status' => 'queued'])->save();
                DeliverWebPushNotification::dispatch((int) $notification->getKey())->afterCommit();
            }
        }

        return $notifications->count();
    }

    private function dispatchTaskDueSoon(int $limit): int
    {
        $now = now();
        $soon = $now->copy()->addMinutes(30);

        return $this->dispatchTaskReminderQuery(
            UserTask::query()->whereBetween('due_at', [$now, $soon]),
            'task.due_soon',
            __('notifications.types.task_due_soon'),
            fn (UserTask $task): string => __('notifications.messages.task_due_soon', [
                'title' => $task->title,
                'time' => $this->dates->formatDateTime($task->due_at, ''),
            ]),
            fn (UserTask $task, User $recipient): string => "task.due_soon:{$task->getKey()}:{$recipient->getKey()}:{$task->due_at?->format('YmdHis')}",
            $limit,
        );
    }

    private function dispatchTaskDue(int $limit): int
    {
        $now = now();

        return $this->dispatchTaskReminderQuery(
            UserTask::query()
                ->where('due_at', '<=', $now),
            'task.due',
            __('notifications.types.task_due'),
            fn (UserTask $task): string => __('notifications.messages.task_due', ['title' => $task->title]),
            fn (UserTask $task, User $recipient): string => "task.due:{$task->getKey()}:{$recipient->getKey()}:{$task->due_at?->format('YmdHis')}",
            $limit,
        );
    }

    private function dispatchTaskOverdue(int $limit): int
    {
        $today = now()->toDateString();

        return $this->dispatchTaskReminderQuery(
            UserTask::query()->where('due_at', '<=', now()->subMinutes(10)),
            'task.overdue',
            __('notifications.types.task_overdue'),
            fn (UserTask $task): string => __('notifications.messages.task_overdue', ['title' => $task->title]),
            fn (UserTask $task, User $recipient): string => "task.overdue:{$task->getKey()}:{$recipient->getKey()}:{$task->due_at?->format('YmdHis')}:{$today}",
            $limit,
            'urgent',
        );
    }

    /**
     * @param  Builder<UserTask>  $query
     * @param  callable(UserTask): string  $body
     * @param  callable(UserTask, User): string  $dedupeKey
     */
    private function dispatchTaskReminderQuery(
        Builder $query,
        string $type,
        string $title,
        callable $body,
        callable $dedupeKey,
        int $limit,
        string $severity = 'action',
    ): int {
        if ($limit < 1) {
            return 0;
        }

        $created = 0;
        $chunkSize = min(max($limit, 50), 200);

        $query
            ->with(['assignees:id,name,doc_num,email', 'assignedTo:id,name,doc_num,email', 'createdBy:id,name,doc_num'])
            ->where('type', UserTask::TypeTask)
            ->where('status', '!=', UserTask::StatusDone)
            ->whereNotNull('due_at')
            ->orderBy('due_at')
            ->orderBy('id')
            ->chunk($chunkSize, function (EloquentCollection $tasks) use (&$created, $body, $dedupeKey, $limit, $severity, $title, $type): bool {
                foreach ($tasks as $task) {
                    if ($created >= $limit) {
                        return false;
                    }

                    $eventUuid = $this->stableEventUuid("{$type}:{$task->getKey()}:{$task->due_at?->format('YmdHis')}:".($type === 'task.overdue' ? now()->toDateString() : 'once'));

                    foreach ($this->taskRecipients($task) as $recipient) {
                        if ($created >= $limit) {
                            return false;
                        }

                        $notification = $this->createImmediate(
                            $recipient,
                            $type,
                            $title,
                            $body($task),
                            route('admin.my-board.index', [], false),
                            $this->taskMetadata($task, $task->createdBy),
                            $dedupeKey($task, $recipient),
                            [
                                'event_uuid' => $eventUuid,
                                'severity' => $severity,
                                'requires_action' => true,
                                'sound_key' => $severity === 'urgent' ? 'urgent' : 'action',
                            ],
                        );

                        if ($notification->wasRecentlyCreated) {
                            $created++;
                        }
                    }
                }

                return true;
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

    private function pushConfigured(): bool
    {
        return filled(config('webpush.vapid.subject'))
            && filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    private function stableEventUuid(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'mgy-pack-erp:'.$key)->toString();
    }
}

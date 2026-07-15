<?php

namespace Modules\Core\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\CalendarEvent;
use Throwable;

class CalendarEventService
{
    /**
     * @var list<string>
     */
    private array $managedFields = [
        'title',
        'description',
        'starts_at',
        'ends_at',
        'all_day',
        'status',
        'color',
        'location',
        'meeting_url',
        'reminder_at',
    ];

    public function __construct(
        private readonly CrudAuditService $crudAudit,
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return Collection<int, CalendarEvent>
     */
    public function eventsFor(User $user, mixed $start = null, mixed $end = null): Collection
    {
        $startDate = $this->normalizeNullableDate($start);
        $endDate = $this->normalizeNullableDate($end);

        return CalendarEvent::query()
            ->with(['user:id,name,doc_num'])
            ->ownedBy($user)
            ->when($startDate instanceof CarbonImmutable, function ($query) use ($startDate): void {
                $query->where(function ($query) use ($startDate): void {
                    $query->whereNull('ends_at')
                        ->orWhere('ends_at', '>=', $startDate);
                });
            })
            ->when($endDate instanceof CarbonImmutable, fn ($query) => $query->where('starts_at', '<=', $endDate))
            ->orderBy('starts_at')
            ->get();
    }

    public function initialDateFor(User $user): ?CarbonImmutable
    {
        $timezone = config('app.timezone', 'Africa/Cairo');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $upcoming = CalendarEvent::query()
            ->ownedBy($user)
            ->where('starts_at', '>=', $today)
            ->orderBy('starts_at')
            ->value('starts_at');

        if ($upcoming instanceof Carbon) {
            return $upcoming->toImmutable()->setTimezone($timezone);
        }

        if ($upcoming !== null) {
            return CarbonImmutable::parse($upcoming, $timezone)->setTimezone($timezone);
        }

        $latest = CalendarEvent::query()
            ->ownedBy($user)
            ->latest('starts_at')
            ->value('starts_at');

        if ($latest instanceof Carbon) {
            return $latest->toImmutable()->setTimezone($timezone);
        }

        return $latest === null ? null : CarbonImmutable::parse($latest, $timezone)->setTimezone($timezone);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Request $request, User $user, array $data): CalendarEvent
    {
        return DB::transaction(function () use ($request, $user, $data): CalendarEvent {
            $event = CalendarEvent::query()->create([
                ...$this->normalizedValues($data),
                'user_id' => $user->getKey(),
                'created_by' => $user->getKey(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($event);
            $event = $event->refresh();

            $this->logActivity($request, 'calendar_events.create', 'create', $event, meta: $this->eventMeta($event), user: $user);
            $this->notifications->scheduleCalendarReminder($event);

            return $event;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{event: CalendarEvent, changed: bool, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function update(Request $request, CalendarEvent $event, User $user, array $data): array
    {
        $this->ensureOwnedBy($event, $user);

        return DB::transaction(function () use ($request, $event, $user, $data): array {
            $event = $this->lockedOwnedEvent($event, $user);
            $values = $this->normalizedValues($data);
            $changes = $this->changedValues($event, $values);

            if ($changes === []) {
                return [
                    'event' => $event,
                    'changed' => false,
                    'changes' => [],
                ];
            }

            $this->crudAudit->saveUpdate($event, $values, (int) $user->getKey());
            $event = $event->refresh();

            $this->logActivity($request, 'calendar_events.update', 'update', $event, $changes, $this->eventMeta($event), $user);
            $this->notifications->scheduleCalendarReminder($event);

            return [
                'event' => $event,
                'changed' => true,
                'changes' => $changes,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{event: CalendarEvent, changed: bool, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function move(Request $request, CalendarEvent $event, User $user, array $data): array
    {
        $this->ensureOwnedBy($event, $user);

        return DB::transaction(function () use ($request, $event, $user, $data): array {
            $event = $this->lockedOwnedEvent($event, $user);
            $values = $this->normalizedMoveValues($data);
            $changes = $this->changedValues($event, $values);

            if ($changes === []) {
                return [
                    'event' => $event,
                    'changed' => false,
                    'changes' => [],
                ];
            }

            $this->crudAudit->saveUpdate($event, $values, (int) $user->getKey());
            $event = $event->refresh();

            $this->logActivity($request, 'calendar_events.move', 'move', $event, $changes, $this->eventMeta($event), $user);
            $this->notifications->scheduleCalendarReminder($event);

            return [
                'event' => $event,
                'changed' => true,
                'changes' => $changes,
            ];
        });
    }

    /**
     * @return array{event: CalendarEvent, changed: bool, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function updateStatus(Request $request, CalendarEvent $event, User $user, string $status): array
    {
        $this->ensureOwnedBy($event, $user);

        return DB::transaction(function () use ($request, $event, $user, $status): array {
            $event = $this->lockedOwnedEvent($event, $user);
            $values = ['status' => $status];
            $changes = $this->changedValues($event, $values);

            if ($changes === []) {
                return [
                    'event' => $event,
                    'changed' => false,
                    'changes' => [],
                ];
            }

            $this->crudAudit->saveUpdate($event, $values, (int) $user->getKey());
            $event = $event->refresh();

            $this->logActivity($request, 'calendar_events.status_change', 'status_change', $event, $changes, $this->eventMeta($event), $user);
            $this->notifications->scheduleCalendarReminder($event);

            return [
                'event' => $event,
                'changed' => true,
                'changes' => $changes,
            ];
        });
    }

    public function delete(Request $request, CalendarEvent $event, User $user): void
    {
        $this->ensureOwnedBy($event, $user);

        DB::transaction(function () use ($request, $event, $user): void {
            $event = $this->lockedOwnedEvent($event, $user);
            $properties = $this->activityProperties($event, 'delete', meta: $this->eventMeta($event), user: $user);

            $this->crudAudit->softDelete($event, (int) $user->getKey());
            $this->notifications->cancelCalendarReminder($event);
            $this->logActivity($request, 'calendar_events.delete', 'delete', $event, properties: $properties);
        });
    }

    public function ensureOwnedBy(CalendarEvent $event, User $user): void
    {
        abort_unless((int) $event->user_id === (int) $user->getKey(), 404);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedValues(array $data): array
    {
        $values = [];

        foreach ($this->managedFields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $values[$field] = match ($field) {
                'title' => $this->normalizeString($data[$field]),
                'description', 'color', 'location', 'meeting_url' => $this->normalizeNullableString($data[$field]),
                'starts_at' => $this->normalizeDate($data[$field]),
                'ends_at', 'reminder_at' => $this->normalizeNullableDate($data[$field]),
                'all_day' => (bool) $data[$field],
                'status' => $this->normalizeStatus($data[$field]),
                default => $data[$field],
            };
        }

        $values['status'] ??= CalendarEvent::StatusPending;
        $values['all_day'] ??= false;

        return $values;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedMoveValues(array $data): array
    {
        return [
            'starts_at' => $this->normalizeDate($data['starts_at']),
            'ends_at' => $this->normalizeNullableDate($data['ends_at'] ?? null),
            'all_day' => (bool) ($data['all_day'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(CalendarEvent $event, array $values): array
    {
        $changes = [];

        foreach ($values as $field => $newValue) {
            $oldValue = $event->getAttribute($field);

            if ($this->comparableValue($oldValue) === $this->comparableValue($newValue)) {
                continue;
            }

            $changes[$field] = [
                'old' => $this->activityValue($oldValue),
                'new' => $this->activityValue($newValue),
            ];
        }

        return $changes;
    }

    private function lockedOwnedEvent(CalendarEvent $event, User $user): CalendarEvent
    {
        return CalendarEvent::query()
            ->ownedBy($user)
            ->whereKey($event->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function normalizeString(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = trim((string) $value);

        return in_array($status, CalendarEvent::Statuses, true) ? $status : CalendarEvent::StatusPending;
    }

    private function normalizeDate(mixed $value): CarbonImmutable
    {
        $date = $this->normalizeNullableDate($value);

        abort_unless($date instanceof CarbonImmutable, 422);

        return $date;
    }

    private function normalizeNullableDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! is_numeric($value) && ! $value instanceof DateTimeInterface) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        $timezone = config('app.timezone', 'Africa/Cairo');

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone($timezone);
        }

        return CarbonImmutable::parse($value, $timezone)->setTimezone($timezone);
    }

    private function comparableValue(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->copy()->setTimezone(config('app.timezone', 'Africa/Cairo'))->format('Y-m-d H:i:s');
        }

        if ($value instanceof CarbonImmutable) {
            return $value->setTimezone(config('app.timezone', 'Africa/Cairo'))->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value === null ? null : (string) $value;
    }

    private function activityValue(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->copy()->toJSON();
        }

        if ($value instanceof CarbonImmutable) {
            return $value->toJSON();
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventMeta(CalendarEvent $event): array
    {
        return [
            'all_day' => (bool) $event->all_day,
            'status' => $event->status,
            'starts_at' => $event->starts_at?->toJSON(),
            'ends_at' => $event->ends_at?->toJSON(),
            'meeting_url' => $event->meeting_url,
        ];
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function activityProperties(CalendarEvent $event, string $actionType, array $changes = [], array $meta = [], ?User $user = null): array
    {
        return [
            'record' => [
                'type' => 'calendar_events',
                'label' => $event->title,
                'doc_num' => $event->public_uuid,
            ],
            'action' => [
                'type' => $actionType,
                'label_key' => "calendar.activity_actions.{$actionType}",
            ],
            'changes' => ActivityLogProperties::changes($changes),
            'meta' => collect($meta)
                ->reject(fn (mixed $value): bool => $value === null || $value === '' || $value === [])
                ->all(),
            'related' => [
                'user' => [
                    'type' => 'users',
                    'label' => $user?->name,
                    'doc_num' => $user?->doc_num,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $properties
     */
    private function logActivity(
        Request $request,
        string $action,
        string $actionType,
        CalendarEvent $event,
        array $changes = [],
        array $meta = [],
        ?User $user = null,
        ?array $properties = null,
    ): void {
        try {
            $this->activityLogger->log($request, 'core', $action, 'success', [
                'properties_only' => true,
                'properties' => $properties ?? $this->activityProperties($event, $actionType, $changes, $meta, $user),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

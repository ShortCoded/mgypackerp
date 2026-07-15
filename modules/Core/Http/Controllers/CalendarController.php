<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Http\Requests\Calendar\MoveCalendarEventRequest;
use Modules\Core\Http\Requests\Calendar\StoreCalendarEventRequest;
use Modules\Core\Http\Requests\Calendar\UpdateCalendarEventRequest;
use Modules\Core\Http\Requests\Calendar\UpdateCalendarEventStatusRequest;
use Modules\Core\Models\CalendarEvent;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CalendarEventService;
use Modules\Core\Services\DateFormatService;
use Throwable;

class CalendarController extends Controller
{
    public function __construct(
        private readonly CalendarEventService $events,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
        private readonly DateFormatService $dates,
    ) {}

    public function index(Request $request): View
    {
        $this->logView($request);

        /** @var User $user */
        $user = $request->user();

        return view('modules.core.calendar.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.calendar.index'),
            'canCreateCalendarEvent' => (bool) $request->user()?->can('calendar.create'),
            'canEditCalendarEvent' => (bool) $request->user()?->can('calendar.edit'),
            'canDeleteCalendarEvent' => (bool) $request->user()?->can('calendar.delete'),
            'initialCalendarDate' => $this->events->initialDateFor($user)?->toDateString(),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(
            $this->events
                ->eventsFor($user, $request->query('start'), $request->query('end'))
                ->map(fn (CalendarEvent $event): array => $this->eventPayload($event, $request))
                ->values()
        );
    }

    public function store(StoreCalendarEventRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $event = $this->events->create($request, $user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('calendar.messages.created'),
            'data' => [
                'event' => $this->eventPayload($event, $request),
            ],
        ], 201);
    }

    public function show(Request $request, CalendarEvent $event): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->events->ensureOwnedBy($event, $user);

        return response()->json([
            'success' => true,
            'data' => [
                'event' => $this->eventPayload($event, $request),
            ],
        ]);
    }

    public function update(UpdateCalendarEventRequest $request, CalendarEvent $event): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->events->update($request, $event, $user, $request->validated());

        return response()->json([
            'success' => $result['changed'],
            'type' => $result['changed'] ? 'updated' : 'no_changes',
            'message' => $result['changed'] ? __('calendar.messages.updated') : __('common.messages.no_changes'),
            'data' => [
                'event' => $this->eventPayload($result['event'], $request),
            ],
        ]);
    }

    public function move(MoveCalendarEventRequest $request, CalendarEvent $event): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->events->move($request, $event, $user, $request->validated());

        return response()->json([
            'success' => $result['changed'],
            'type' => $result['changed'] ? 'moved' : 'no_changes',
            'message' => $result['changed'] ? __('calendar.messages.moved') : __('common.messages.no_changes'),
            'data' => [
                'event' => $this->eventPayload($result['event'], $request),
            ],
        ]);
    }

    public function status(UpdateCalendarEventStatusRequest $request, CalendarEvent $event): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->events->updateStatus($request, $event, $user, (string) $request->validated('status'));

        return response()->json([
            'success' => $result['changed'],
            'type' => $result['changed'] ? 'status_changed' : 'no_changes',
            'message' => $result['changed'] ? __('calendar.messages.updated') : __('common.messages.no_changes'),
            'data' => [
                'event' => $this->eventPayload($result['event'], $request),
            ],
        ]);
    }

    public function destroy(Request $request, CalendarEvent $event): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('calendar.delete'), 403);

        /** @var User $user */
        $user = $request->user();
        $this->events->delete($request, $event, $user);

        return response()->json([
            'success' => true,
            'message' => __('calendar.messages.deleted'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(CalendarEvent $event, Request $request): array
    {
        $color = $event->color ?: 'primary';
        $status = $event->status ?: CalendarEvent::StatusPending;
        $isOwner = (int) $event->user_id === (int) $request->user()?->getKey();
        $canEdit = $isOwner && (bool) $request->user()?->can('calendar.edit');
        $canDelete = $isOwner && (bool) $request->user()?->can('calendar.delete');
        $canComplete = $isOwner && (bool) $request->user()?->can('calendar.complete');

        return [
            'id' => $event->public_uuid,
            'title' => $event->title,
            'start' => $event->starts_at?->toIso8601String(),
            'end' => $event->ends_at?->toIso8601String(),
            'allDay' => (bool) $event->all_day,
            'editable' => $canEdit,
            'startEditable' => $canEdit,
            'durationEditable' => $canEdit,
            'classNames' => ["bg-{$color}-subtle", "event-bg-{$color}-subtle"],
            'extendedProps' => [
                'description' => $event->description ?: '',
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'status_class' => $this->statusClass($status),
                'color' => $color,
                'location' => $event->location ?: '',
                'meeting_url' => $event->meeting_url ?: '',
                'reminder_at' => $event->reminder_at?->toIso8601String(),
                'created_at' => $event->created_at?->toIso8601String(),
                'updated_at' => $event->updated_at?->toIso8601String(),
                'formatted_start' => $this->formatEventDate($event, $event->starts_at),
                'formatted_end' => $this->formatEventEndDate($event),
                'formatted_range' => $this->formattedEventRange($event),
                'formatted_reminder_at' => $this->dates->formatDateTime($event->reminder_at, ''),
                'formatted_created_at' => $this->dates->formatDateTime($event->created_at, ''),
                'formatted_updated_at' => $this->dates->formatDateTime($event->updated_at, ''),
                'can_edit' => $canEdit,
                'can_delete' => $canDelete,
                'can_complete' => $canComplete,
            ],
        ];
    }

    private function formattedEventRange(CalendarEvent $event): string
    {
        return collect([
            $this->formatEventDate($event, $event->starts_at),
            $this->formatEventEndDate($event),
        ])->filter()->implode(' - ');
    }

    private function formatEventDate(CalendarEvent $event, mixed $date): string
    {
        return $event->all_day
            ? $this->dates->formatDate($date, '')
            : $this->dates->formatDateTime($date, '');
    }

    private function formatEventEndDate(CalendarEvent $event): string
    {
        if ($event->ends_at === null) {
            return '';
        }

        if (! $event->all_day) {
            return $this->dates->formatDateTime($event->ends_at, '');
        }

        $displayEnd = $event->ends_at->copy();

        if ($event->starts_at !== null && $displayEnd->gt($event->starts_at)) {
            $displayEnd = $displayEnd->subDay();
        }

        return $this->dates->formatDate($displayEnd, '');
    }

    private function statusLabel(string $status): string
    {
        $key = "calendar.statuses.{$status}";

        return __($key) === $key ? $status : __($key);
    }

    private function statusClass(string $status): string
    {
        return match ($status) {
            CalendarEvent::StatusConfirmed => 'success',
            CalendarEvent::StatusCancelled => 'secondary',
            default => 'warning',
        };
    }

    private function logView(Request $request): void
    {
        try {
            $this->activityLogger->log($request, 'core', 'calendar.view', 'success', [
                'properties_only' => true,
                'properties' => [
                    'area' => 'calendar',
                    'action' => [
                        'type' => 'view',
                        'label_key' => 'calendar.activity_actions.view',
                    ],
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

<?php

use App\Models\User;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\CalendarEvent;
use Modules\Core\Models\UserNotification;
use Modules\Core\Services\SettingService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function calendarActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function calendarPayload(array $overrides = []): array
{
    return [
        'title' => 'Planning Session',
        'description' => 'Quarterly planning',
        'starts_at' => now()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
        'ends_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'),
        'all_day' => false,
        'status' => CalendarEvent::StatusPending,
        'color' => 'primary',
        'location' => 'Meeting Room',
        'meeting_url' => 'https://meet.example.com/planning',
        'reminder_at' => now()->addDay()->setTime(8, 45)->format('Y-m-d H:i:s'),
        ...$overrides,
    ];
}

test('calendar permissions are discoverable and assigned to admin role', function () {
    $this->seed(PermissionSeeder::class);

    $adminRole = Role::query()
        ->where('name', 'admin')
        ->where('guard_name', 'web')
        ->firstOrFail();

    expect(Permission::query()->where('name', 'calendar.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'calendar.create')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'calendar.edit')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'calendar.delete')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'calendar.complete')->exists())->toBeTrue()
        ->and($adminRole->hasPermissionTo('calendar.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('calendar.delete'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('calendar.complete'))->toBeTrue();
});

test('unauthenticated users cannot view calendar page', function () {
    $this->get(route('admin.calendar.index'))->assertRedirect(route('login'));
});

test('authenticated user can view calendar page with permission', function () {
    $actor = calendarActor(['calendar.view', 'calendar.create', 'calendar.edit', 'calendar.delete']);

    CalendarEvent::factory()->for($actor, 'user')->create([
        'starts_at' => '2026-10-05 09:00:00',
        'ends_at' => '2026-10-05 10:00:00',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.calendar.index'))
        ->assertOk()
        ->assertSee(__('calendar.title'))
        ->assertSee('id="erpCalendar"', false)
        ->assertSee('assets/css/modules/Core/calendar.css', false)
        ->assertSee('vendors/fullcalendar/index.global.min.js', false)
        ->assertSee('assets/js/modules/Core/calendar.js', false)
        ->assertSee('"initialDate":"2026-10-05"', false)
        ->assertSee(str_replace('/', '\\/', route('admin.calendar.events')), false)
        ->assertSee('id="calendarEventForm" autocomplete="off" novalidate', false)
        ->assertSee('id="calendarEventDetailsModal"', false)
        ->assertSee(__('calendar.sections.basic_information'))
        ->assertSee(__('calendar.sections.schedule'))
        ->assertSee(__('calendar.sections.details'))
        ->assertSee('js-date-picker js-calendar-date-time', false)
        ->assertSee('data-shortcut-action="calendar.create"', false)
        ->assertSee('data-shortcut-action="calendar.today"', false)
        ->assertSee('data-shortcut-action="calendar.save"', false)
        ->assertSee('data-shortcut-action="calendar.delete"', false)
        ->assertSee('data-shortcut-action="calendar.cancel"', false)
        ->assertSee(__('calendar.help.private_calendar'))
        ->assertDontSee('type="datetime-local"', false)
        ->assertDontSee(__('calendar.fields.owner'))
        ->assertDontSee('name="user_id"', false)
        ->assertDontSee('name="owner_id"', false)
        ->assertDontSee('data-id=', false);

    expect(Activity::query()->where('action', 'calendar.view')->exists())->toBeTrue();
});

test('user can create own event and submitted user id is ignored', function () {
    $actor = calendarActor(['calendar.view', 'calendar.create']);
    $other = User::factory()->create();

    $this->actingAs($actor)
        ->postJson(route('admin.calendar.events.store'), calendarPayload([
            'title' => 'Owner Event',
            'user_id' => $other->getKey(),
            'owner_id' => $other->getKey(),
        ]))
        ->assertCreated()
        ->assertJsonPath('data.event.title', 'Owner Event')
        ->assertJsonPath('data.event.extendedProps.meeting_url', 'https://meet.example.com/planning')
        ->assertJsonMissing(['user_id' => $other->getKey()])
        ->assertJsonMissing(['owner_id' => $other->getKey()])
        ->assertJsonMissing(['id' => 1]);

    $event = CalendarEvent::query()->where('title', 'Owner Event')->firstOrFail();
    $createActivity = Activity::query()->where('action', 'calendar_events.create')->first();

    expect($event->user_id)->toBe($actor->getKey())
        ->and($event->public_uuid)->not->toBeEmpty()
        ->and($event->created_by)->toBe($actor->getKey())
        ->and($event->updated_by)->toBeNull()
        ->and($createActivity)->not->toBeNull()
        ->and($createActivity?->subject_id)->toBeNull()
        ->and($createActivity?->properties->has('user_id'))->toBeFalse()
        ->and($createActivity?->properties->get('record')['doc_num'] ?? null)
        ->toBe($event->public_uuid);
});

test('calendar create stores configured day month datetime without swapping month and day', function () {
    $actor = calendarActor(['calendar.view', 'calendar.create']);

    $this->actingAs($actor)
        ->postJson(route('admin.calendar.events.store'), calendarPayload([
            'title' => 'Displayed Date Event',
            'starts_at' => '10/05/2026 3:00 AM',
            'ends_at' => '10/05/2026 4:00 AM',
            'reminder_at' => '10/05/2026 2:30 AM',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.event.title', 'Displayed Date Event');

    $event = CalendarEvent::query()->where('title', 'Displayed Date Event')->firstOrFail();

    expect($event->starts_at->format('Y-m-d H:i:s'))->toBe('2026-05-10 03:00:00')
        ->and($event->ends_at?->format('Y-m-d H:i:s'))->toBe('2026-05-10 04:00:00')
        ->and($event->reminder_at?->format('Y-m-d H:i:s'))->toBe('2026-05-10 02:30:00');
});

test('calendar event reminder is scheduled and dispatched to event owner', function () {
    $actor = calendarActor(['calendar.view', 'calendar.create']);

    $this->actingAs($actor)
        ->postJson(route('admin.calendar.events.store'), calendarPayload([
            'title' => 'Reminder Event',
            'starts_at' => now()->addMinutes(10)->format('Y-m-d H:i:s'),
            'ends_at' => now()->addMinutes(40)->format('Y-m-d H:i:s'),
            'reminder_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ]))
        ->assertCreated();

    $event = CalendarEvent::query()->where('title', 'Reminder Event')->firstOrFail();
    $notification = UserNotification::query()
        ->where('dedupe_key', "calendar.event_reminder:{$event->getKey()}:{$actor->getKey()}")
        ->firstOrFail();

    expect($notification->delivered_at)->toBeNull()
        ->and($notification->user_id)->toBe($actor->getKey());

    $this->artisan('notifications:dispatch-due')->assertSuccessful();
    $this->artisan('notifications:dispatch-due')->assertSuccessful();

    expect(UserNotification::query()
        ->where('dedupe_key', "calendar.event_reminder:{$event->getKey()}:{$actor->getKey()}")
        ->count())->toBe(1)
        ->and($notification->refresh()->delivered_at)->not->toBeNull();
});

test('user sees only own events', function () {
    $actor = calendarActor(['calendar.view']);
    $other = User::factory()->create();
    $ownEvent = CalendarEvent::factory()->for($actor, 'user')->create(['title' => 'Own Event']);

    CalendarEvent::factory()->for($other, 'user')->create(['title' => 'Other Event']);

    $this->actingAs($actor)
        ->getJson(route('admin.calendar.events'))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $ownEvent->public_uuid)
        ->assertJsonPath('0.title', 'Own Event')
        ->assertJsonMissing(['title' => 'Other Event'])
        ->assertJsonMissing(['owner_name' => $actor->name])
        ->assertJsonMissing(['user_id' => $actor->getKey()]);
});

test('calendar event feed respects requested visible range', function () {
    $actor = calendarActor(['calendar.view']);
    $visibleEvent = CalendarEvent::factory()->for($actor, 'user')->create([
        'title' => 'Visible Event',
        'starts_at' => '2026-05-12 09:00:00',
        'ends_at' => '2026-05-12 10:00:00',
    ]);

    CalendarEvent::factory()->for($actor, 'user')->create([
        'title' => 'Outside Event',
        'starts_at' => '2026-06-12 09:00:00',
        'ends_at' => '2026-06-12 10:00:00',
    ]);

    $this->actingAs($actor)
        ->getJson(route('admin.calendar.events', [
            'start' => '2026-05-01T00:00:00+03:00',
            'end' => '2026-06-01T00:00:00+03:00',
        ]))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $visibleEvent->public_uuid)
        ->assertJsonPath('0.editable', false)
        ->assertJsonMissing(['title' => 'Outside Event']);
});

test('user can view own event details', function () {
    $actor = calendarActor(['calendar.view']);
    $event = CalendarEvent::factory()->for($actor, 'user')->create([
        'title' => 'Details Event',
        'location' => 'Main Office',
    ]);

    $this->actingAs($actor)
        ->getJson(route('admin.calendar.events.show', $event))
        ->assertOk()
        ->assertJsonPath('data.event.id', $event->public_uuid)
        ->assertJsonPath('data.event.title', 'Details Event')
        ->assertJsonPath('data.event.extendedProps.location', 'Main Office')
        ->assertJsonMissing(['owner_name' => $actor->name])
        ->assertJsonPath('data.event.extendedProps.can_edit', false);
});

test('calendar event details include settings formatted dates while raw FullCalendar dates remain ISO compatible', function () {
    app(SettingService::class)->set(SettingService::DateFormatKey, 'd.m.Y');
    app(SettingService::class)->set(SettingService::DateTimeFormatKey, 'd.m.Y H:i');

    $actor = calendarActor(['calendar.view']);
    $event = CalendarEvent::factory()->for($actor, 'user')->create([
        'title' => 'Formatted Timed Event',
        'starts_at' => '2026-05-19 07:17:00',
        'ends_at' => '2026-05-19 08:45:00',
        'reminder_at' => '2026-05-19 06:30:00',
        'all_day' => false,
    ]);

    $this->actingAs($actor)
        ->getJson(route('admin.calendar.events.show', $event))
        ->assertOk()
        ->assertJsonPath('data.event.start', '2026-05-19T07:17:00+03:00')
        ->assertJsonPath('data.event.end', '2026-05-19T08:45:00+03:00')
        ->assertJsonPath('data.event.extendedProps.formatted_start', '19.05.2026 07:17')
        ->assertJsonPath('data.event.extendedProps.formatted_end', '19.05.2026 08:45')
        ->assertJsonPath('data.event.extendedProps.formatted_range', '19.05.2026 07:17 - 19.05.2026 08:45')
        ->assertJsonPath('data.event.extendedProps.formatted_reminder_at', '19.05.2026 06:30')
        ->assertJsonMissing(['owner_name' => $actor->name]);
});

test('calendar all day event details display exclusive end date using configured date format', function () {
    app(SettingService::class)->set(SettingService::DateFormatKey, 'd.m.Y');
    app(SettingService::class)->set(SettingService::DateTimeFormatKey, 'd.m.Y H:i');

    $actor = calendarActor(['calendar.view']);
    $event = CalendarEvent::factory()->for($actor, 'user')->create([
        'title' => 'Formatted All Day Event',
        'starts_at' => '2026-05-19 00:00:00',
        'ends_at' => '2026-05-22 00:00:00',
        'all_day' => true,
    ]);

    $this->actingAs($actor)
        ->getJson(route('admin.calendar.events.show', $event))
        ->assertOk()
        ->assertJsonPath('data.event.start', '2026-05-19T00:00:00+03:00')
        ->assertJsonPath('data.event.end', '2026-05-22T00:00:00+03:00')
        ->assertJsonPath('data.event.extendedProps.formatted_start', '19.05.2026')
        ->assertJsonPath('data.event.extendedProps.formatted_end', '21.05.2026')
        ->assertJsonPath('data.event.extendedProps.formatted_range', '19.05.2026 - 21.05.2026');
});

test('user cannot view another users event', function () {
    $actor = calendarActor(['calendar.view']);
    $other = User::factory()->create();
    $event = CalendarEvent::factory()->for($other, 'user')->create();

    $this->actingAs($actor)
        ->getJson(route('admin.calendar.events.show', $event))
        ->assertNotFound();
});

test('user cannot update another users event', function () {
    $actor = calendarActor(['calendar.view', 'calendar.edit']);
    $other = User::factory()->create();
    $event = CalendarEvent::factory()->for($other, 'user')->create();

    $this->actingAs($actor)
        ->putJson(route('admin.calendar.events.update', $event), calendarPayload(['title' => 'Blocked Update']))
        ->assertNotFound();

    expect($event->refresh()->title)->not->toBe('Blocked Update');
});

test('user cannot move another users event', function () {
    $actor = calendarActor(['calendar.view', 'calendar.edit']);
    $other = User::factory()->create();
    $event = CalendarEvent::factory()->for($other, 'user')->create([
        'starts_at' => now()->addDay()->setTime(9, 0),
    ]);

    $this->actingAs($actor)
        ->patchJson(route('admin.calendar.events.move', $event), [
            'starts_at' => now()->addDays(2)->setTime(13, 0)->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(2)->setTime(14, 0)->format('Y-m-d H:i:s'),
            'all_day' => false,
        ])
        ->assertNotFound();

    expect($event->refresh()->starts_at->isSameDay(now()->addDay()))->toBeTrue();
});

test('user can update and move own event', function () {
    $actor = calendarActor(['calendar.view', 'calendar.edit']);
    $event = CalendarEvent::factory()->for($actor, 'user')->create([
        'title' => 'Original',
        'starts_at' => now()->addDay()->setTime(9, 0),
        'ends_at' => now()->addDay()->setTime(10, 0),
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.calendar.events.update', $event), calendarPayload([
            'title' => 'Updated Event',
            'status' => CalendarEvent::StatusConfirmed,
            'color' => 'success',
        ]))
        ->assertOk()
        ->assertJsonPath('data.event.title', 'Updated Event')
        ->assertJsonPath('data.event.extendedProps.status', CalendarEvent::StatusConfirmed);

    $this->patchJson(route('admin.calendar.events.move', $event), [
        'starts_at' => now()->addDays(2)->setTime(13, 0)->format('Y-m-d H:i:s'),
        'ends_at' => now()->addDays(2)->setTime(14, 0)->format('Y-m-d H:i:s'),
        'all_day' => false,
    ])
        ->assertOk()
        ->assertJsonPath('type', 'moved');

    $event->refresh();

    expect($event->title)->toBe('Updated Event')
        ->and($event->status)->toBe(CalendarEvent::StatusConfirmed)
        ->and($event->updated_by)->toBe($actor->getKey())
        ->and(Activity::query()->where('action', 'calendar_events.update')->exists())->toBeTrue()
        ->and(Activity::query()->where('action', 'calendar_events.move')->exists())->toBeTrue();
});

test('user can update own event status', function () {
    $actor = calendarActor(['calendar.view', 'calendar.complete']);
    $event = CalendarEvent::factory()->for($actor, 'user')->create([
        'status' => CalendarEvent::StatusPending,
    ]);

    $this->actingAs($actor)
        ->patchJson(route('admin.calendar.events.status', $event), [
            'status' => CalendarEvent::StatusCancelled,
        ])
        ->assertOk()
        ->assertJsonPath('data.event.extendedProps.status', CalendarEvent::StatusCancelled);

    expect($event->refresh()->status)->toBe(CalendarEvent::StatusCancelled)
        ->and(Activity::query()->where('action', 'calendar_events.status_change')->exists())->toBeTrue();
});

test('user can delete own event with soft delete and activity log', function () {
    $actor = calendarActor(['calendar.view', 'calendar.delete']);
    $event = CalendarEvent::factory()->for($actor, 'user')->create();

    $this->actingAs($actor)
        ->deleteJson(route('admin.calendar.events.destroy', $event))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($event->refresh()->trashed())->toBeTrue()
        ->and($event->deleted_by)->toBe($actor->getKey())
        ->and(Activity::query()->where('action', 'calendar_events.delete')->exists())->toBeTrue();
});

test('user cannot delete another users event', function () {
    $actor = calendarActor(['calendar.view', 'calendar.delete']);
    $other = User::factory()->create();
    $event = CalendarEvent::factory()->for($other, 'user')->create();

    $this->actingAs($actor)
        ->deleteJson(route('admin.calendar.events.destroy', $event))
        ->assertNotFound();

    expect($event->refresh()->trashed())->toBeFalse();
});

test('calendar event json uses public uuid and hides internal ids', function () {
    $actor = calendarActor(['calendar.view', 'calendar.edit', 'calendar.delete']);
    $event = CalendarEvent::factory()->for($actor, 'user')->create();

    $response = $this->actingAs($actor)
        ->getJson(route('admin.calendar.events.show', $event))
        ->assertOk()
        ->json('data.event');

    expect($response['id'])->toBe($event->public_uuid)
        ->and($response['id'])->not->toBe((string) $event->getKey())
        ->and($response)->not->toHaveKey('user_id')
        ->and($response)->not->toHaveKey('internal_id');
});

test('calendar validation errors are returned for invalid event payload', function () {
    $actor = calendarActor(['calendar.view', 'calendar.create']);

    $this->actingAs($actor)
        ->postJson(route('admin.calendar.events.store'), [
            'title' => '',
            'starts_at' => 'not-a-date',
            'status' => 'invalid',
            'color' => 'purple',
            'meeting_url' => 'not-a-url',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'starts_at', 'status', 'color', 'meeting_url']);
});

test('calendar rejects end date before start date', function () {
    $actor = calendarActor(['calendar.view', 'calendar.create']);

    $this->actingAs($actor)
        ->postJson(route('admin.calendar.events.store'), calendarPayload([
            'starts_at' => now()->addDay()->setTime(11, 0)->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ends_at']);
});

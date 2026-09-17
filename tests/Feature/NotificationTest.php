<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Models\AuthLog;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Models\UserNotification;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\InactiveSessionService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function notificationActor(array $permissions = []): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user;
}

function notificationAdminActor(array $permissions): User
{
    $user = notificationActor($permissions);
    $role = Role::query()->whereKey(1)->first();

    if (! $role instanceof Role) {
        $role = Role::query()->create([
            'name' => 'admin',
            'guard_name' => 'web',
            'doc_number' => 1,
            'doc_num' => 'Role-00001',
        ]);
    }

    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

test('notification poll returns only authenticated users delivered notifications', function () {
    $actor = notificationActor();
    $other = User::factory()->create();

    UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'task.assigned',
        'category' => 'task',
        'title' => 'Mine',
        'body' => 'Visible',
        'delivered_at' => now(),
    ]);
    UserNotification::query()->create([
        'user_id' => $other->getKey(),
        'type' => 'task.assigned',
        'category' => 'task',
        'title' => 'Other',
        'body' => 'Hidden',
        'delivered_at' => now(),
    ]);

    $this->actingAs($actor)
        ->getJson(route('admin.notifications.poll'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1)
        ->assertSee('Mine')
        ->assertDontSee('Other');

    UserNotification::query()->where('user_id', $actor->getKey())->delete();

    $this->getJson(route('admin.notifications.poll'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0)
        ->assertJsonPath('data.notifications', []);
});

test('notification poll keeps its contract and ordering within its database budget', function () {
    $actor = notificationActor();
    $other = User::factory()->create();
    $referenceTime = now();

    foreach (range(1, 11) as $position) {
        UserNotification::query()->create([
            'user_id' => $actor->getKey(),
            'type' => 'task.assigned',
            'category' => 'task',
            'title' => "Unread {$position}",
            'body' => "Unread body {$position}",
            'url' => "/tasks/unread-{$position}",
            'delivered_at' => $referenceTime->copy()->subMinutes($position),
            'metadata' => ['unused' => str_repeat('x', 100)],
        ]);
    }

    foreach (range(1, 3) as $position) {
        UserNotification::query()->create([
            'user_id' => $actor->getKey(),
            'type' => 'calendar.event_reminder',
            'category' => 'calendar',
            'title' => "Read {$position}",
            'body' => null,
            'url' => null,
            'delivered_at' => $referenceTime->copy()->subMinutes($position),
            'read_at' => $referenceTime,
            'metadata' => ['unused' => str_repeat('x', 100)],
        ]);
    }

    UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'task.assigned',
        'category' => 'task',
        'title' => 'Not delivered',
        'scheduled_for' => $referenceTime->copy()->addMinute(),
    ]);
    UserNotification::query()->create([
        'user_id' => $other->getKey(),
        'type' => 'task.assigned',
        'category' => 'task',
        'title' => 'Other user',
        'delivered_at' => $referenceTime,
    ]);

    $retrievedNotifications = 0;
    Event::listen('eloquent.retrieved: '.UserNotification::class, function () use (&$retrievedNotifications): void {
        $retrievedNotifications++;
    });
    DB::flushQueryLog();
    DB::enableQueryLog();
    $startedAt = hrtime(true);

    try {
        $response = $this->actingAs($actor)->getJson(route('admin.notifications.poll'));
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    $notificationQueries = collect($queries)
        ->filter(fn (array $query): bool => str_contains($query['query'], 'user_notifications'));
    $pollQuery = $notificationQueries->sole()['query'];
    $payload = $response->assertOk()->json('data');

    expect($notificationQueries)->toHaveCount(1)
        ->and(count($queries))->toBeLessThanOrEqual(8)
        ->and($elapsedMilliseconds)->toBeLessThan(1000)
        ->and($retrievedNotifications)->toBe(0)
        ->and($pollQuery)->not->toContain('metadata', 'dedupe_key', 'scheduled_for', 'updated_at')
        ->and(strtolower($pollQuery))->toContain('select count(*)')
        ->and(strtolower($pollQuery))->not->toContain(' over ')
        ->and($payload['unread_count'])->toBe(11)
        ->and($payload['session_identity'])->toBeString()->toHaveLength(64)
        ->and($payload['notifications'])->toHaveCount(10)
        ->and(array_keys($payload['notifications'][0]))->toBe([
            'id',
            'sequence',
            'event_id',
            'type',
            'category',
            'module',
            'severity',
            'requires_action',
            'sound_key',
            'suppress_in_app_alert',
            'conversation_uuid',
            'title',
            'body',
            'url',
            'is_read',
            'time',
        ])
        ->and(collect($payload['notifications'])->pluck('title')->all())->toBe([
            'Unread 1',
            'Unread 2',
            'Unread 3',
            'Unread 4',
            'Unread 5',
            'Unread 6',
            'Unread 7',
            'Unread 8',
            'Unread 9',
            'Unread 10',
        ]);
});

test('notification poll is passive and does not touch presence or activity logs', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 10, 3, 0, 0));

    $actor = notificationActor();

    $this->actingAs($actor)
        ->withSession([InactiveSessionService::LastActivitySessionKey => now()->getTimestamp()])
        ->postJson(route('session.touch'))
        ->assertOk();

    $presence = UserPresenceSession::query()->where('user_id', $actor->getKey())->firstOrFail();
    $lastSeenAt = $presence->last_seen_at?->toDateTimeString();
    $lastActivityAt = $presence->last_activity_at?->toDateTimeString();
    $activityLogCount = Activity::query()->count();
    $authLogCount = AuthLog::query()->count();

    Carbon::setTestNow(now()->addMinute());

    UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'task.assigned',
        'category' => 'task',
        'title' => 'Passive poll',
        'body' => 'Visible without activity touch',
        'delivered_at' => now(),
    ]);

    $this->getJson(route('admin.notifications.poll'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1)
        ->assertSee('Passive poll');

    $presence->refresh();

    expect($presence->status)->toBe(UserPresenceService::StatusOnline)
        ->and($presence->last_seen_at?->toDateTimeString())->toBe($lastSeenAt)
        ->and($presence->last_activity_at?->toDateTimeString())->toBe($lastActivityAt)
        ->and(Activity::query()->count())->toBe($activityLogCount)
        ->and(AuthLog::query()->count())->toBe($authLogCount);

    Carbon::setTestNow();
});

test('notification polling assets load only in authenticated app layout', function () {
    $actor = notificationActor(['my_board.view']);

    $this->actingAs($actor)
        ->get(route('admin.my-board.index'))
        ->assertOk()
        ->assertSee('data-notifications-root', false)
        ->assertSee('assets/js/modules/Core/notifications.js', false);

    auth()->logout();

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('data-notifications-root', false)
        ->assertDontSee('assets/js/modules/Core/notifications.js', false);
});

test('notification diagnostics remain internal after the customer page is retired', function () {
    $operator = notificationActor(['settings.pwa.view']);
    $this->actingAs($operator);
    config()->set('webpush.vapid.private_key', 'never-visible-diagnostic-key');
    $this->artisan('notifications:dispatch-due')->assertSuccessful();

    $runtime = Cache::get('notifications.runtime.last_dispatch');
    expect($runtime)->toBeArray()
        ->and($runtime['status'])->toBe('successful')
        ->and($runtime)->not->toHaveKeys(['private_key', 'subscriptions'])
        ->and(Route::has('admin.notifications.diagnostics'))->toBeFalse();

    $this->get('/admin/notifications/diagnostics')
        ->assertNotFound()
        ->assertDontSee('never-visible-diagnostic-key');
});

test('notification can be marked read only by recipient', function () {
    $actor = notificationActor();
    $other = User::factory()->create();
    $notification = UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'task.assigned',
        'category' => 'task',
        'title' => 'Read me',
        'delivered_at' => now(),
    ]);

    $this->actingAs($other)
        ->postJson(route('admin.notifications.read', $notification))
        ->assertNotFound();

    $this->actingAs($actor)
        ->postJson(route('admin.notifications.read', $notification))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    expect($notification->refresh()->read_at)->not->toBeNull();
});

test('notification center tolerates legacy incomplete notification data', function () {
    $actor = notificationActor();

    UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'legacy.event',
        'category' => 'legacy',
        'module' => 'modules',
        'title' => 'Legacy notification',
        'metadata' => null,
        'url' => '/admin/missing-document/legacy-record',
        'delivered_at' => now(),
    ]);

    $this->actingAs($actor)
        ->get(route('admin.notifications.index'))
        ->assertOk()
        ->assertSee('Legacy notification');
});

test('notification center uses shared fields readable labels and compact customer controls', function () {
    $actor = notificationActor();

    UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'chat.message',
        'category' => 'chat',
        'module' => 'chat',
        'title' => 'Readable notification',
        'delivered_at' => now(),
    ]);
    UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'internal.raw-event',
        'category' => 'legacy',
        'module' => 'internal-module',
        'title' => 'Unknown notification',
        'delivered_at' => now()->subMinute(),
    ]);
    UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'purchases.submitted',
        'category' => 'purchases',
        'module' => 'purchases',
        'title' => 'Approval requested',
        'delivered_at' => now()->subMinutes(2),
    ]);

    $response = $this->actingAs($actor)
        ->get(route('admin.notifications.index', [
            'state' => 'all',
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
        ]))
        ->assertOk()
        ->assertSee('data-notifications-center', false)
        ->assertSee('data-push-notification-explained', false)
        ->assertSee('js-select2-local', false)
        ->assertSee('js-date-picker', false)
        ->assertSee('form-control form-control-sm', false)
        ->assertSee('form-select form-select-sm', false)
        ->assertSee(__('notifications.types.chat_message'))
        ->assertSee(__('notifications.types.other'))
        ->assertSee(__('notifications.operational.title', [
            'module' => __('notifications.modules.purchases'),
            'status' => __('notifications.statuses.submitted'),
        ]))
        ->assertSee(__('notifications.modules.other'))
        ->assertDontSee('data-notification-sound-test', false)
        ->assertDontSee('admin.notifications.diagnostics', false)
        ->assertDontSee(__('notifications.push.permission_granted'));

    expect($response->getContent())
        ->not->toContain('>chat.message<', '>internal.raw-event<', '>internal-module<')
        ->toContain('value="'.now()->subDay()->toDateString().'"')
        ->toContain('value="'.now()->toDateString().'"');
});

test('notification center explains reversed dates and distinguishes filtered empty results', function () {
    $actor = notificationActor();

    $this->actingAs($actor)
        ->from(route('admin.notifications.index'))
        ->get(route('admin.notifications.index', ['from' => '2026-09-17', 'to' => '2026-09-16']))
        ->assertRedirect(route('admin.notifications.index'))
        ->assertSessionHasErrors([
            'to' => __('notifications.validation.to_after_or_equal'),
        ]);

    $this->get(route('admin.notifications.index', ['search' => 'does-not-exist']))
        ->assertOk()
        ->assertSee(__('notifications.empty_filtered'));

    $this->get(route('admin.notifications.index'))
        ->assertOk()
        ->assertSee(__('notifications.empty'))
        ->assertDontSee(__('notifications.empty_filtered'));

    $this->get(route('admin.notifications.index', ['module' => 'chat', 'type' => 'chat.message']))
        ->assertOk()
        ->assertSee('value="chat" selected', false)
        ->assertSee('value="chat.message" selected', false)
        ->assertSee(__('notifications.modules.chat'))
        ->assertSee(__('notifications.types.chat_message'));
});

test('marking all notifications read stays scoped to the authenticated recipient', function () {
    $actor = notificationActor();
    $other = notificationActor();
    $actorNotification = UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'task.assigned',
        'category' => 'task',
        'title' => 'Actor unread',
        'delivered_at' => now(),
    ]);
    $otherNotification = UserNotification::query()->create([
        'user_id' => $other->getKey(),
        'type' => 'task.assigned',
        'category' => 'task',
        'title' => 'Other unread',
        'delivered_at' => now(),
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.notifications.read-all'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    expect($actorNotification->refresh()->read_at)->not->toBeNull()
        ->and($otherNotification->refresh()->read_at)->toBeNull();
});

test('opening a notification marks only it read and handles unavailable targets safely', function () {
    $actor = notificationActor(['my_board.view']);
    $other = notificationActor(['my_board.view']);
    $unavailable = UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'legacy.event',
        'category' => 'legacy',
        'title' => 'Unavailable target',
        'url' => '/admin/missing-document/legacy-record',
        'delivered_at' => now(),
    ]);
    $valid = UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'task.assigned',
        'category' => 'task',
        'title' => 'Valid target',
        'url' => route('admin.my-board.index', [], false),
        'delivered_at' => now(),
    ]);
    $missingDocument = UserNotification::query()->create([
        'user_id' => $actor->getKey(),
        'type' => 'task.assigned',
        'category' => 'task',
        'title' => 'Missing document',
        'url' => route('admin.my-board.show', ['userTask' => 'Task-MISSING'], false),
        'delivered_at' => now(),
    ]);

    $this->actingAs($other)
        ->get(route('admin.notifications.open', $unavailable))
        ->assertNotFound();

    $this->actingAs($actor)
        ->get(route('admin.notifications.open', $unavailable))
        ->assertRedirect(route('admin.notifications.index'))
        ->assertSessionHas('warning', __('notifications.messages.target_unavailable'));

    expect($unavailable->refresh()->read_at)->not->toBeNull()
        ->and($valid->refresh()->read_at)->toBeNull()
        ->and($missingDocument->refresh()->read_at)->toBeNull();

    $this->get(route('admin.notifications.open', $missingDocument))
        ->assertRedirect(route('admin.notifications.index'))
        ->assertSessionHas('warning', __('notifications.messages.target_unavailable'));

    $this->get(route('admin.notifications.open', $valid))
        ->assertRedirect(route('admin.my-board.index'));

    expect($valid->refresh()->read_at)->not->toBeNull();

    $restricted = notificationActor();
    $forbiddenTarget = UserNotification::query()->create([
        'user_id' => $restricted->getKey(),
        'type' => 'task.assigned',
        'category' => 'task',
        'title' => 'Forbidden target',
        'url' => route('admin.my-board.index', [], false),
        'delivered_at' => now(),
    ]);

    $this->actingAs($restricted)
        ->get(route('admin.notifications.open', $forbiddenTarget))
        ->assertRedirect(route('admin.notifications.index'))
        ->assertSessionHas('warning', __('notifications.messages.target_unavailable'));
});

test('creating board task notifies assigned users without notifying creator', function () {
    $actor = notificationAdminActor(['my_board.view', 'my_board.create', 'my_board.assign']);
    $assignee = User::factory()->create();

    $this->actingAs($actor)
        ->postJson(route('admin.my-board.store'), [
            'title' => 'Notify assigned user',
            'description' => '',
            'type' => UserTask::TypeTask,
            'status' => UserTask::StatusTodo,
            'priority' => UserTask::PriorityNormal,
            'color' => 'primary',
            'assignee_doc_nums' => [$actor->doc_num, $assignee->doc_num],
        ])
        ->assertCreated();

    expect(UserNotification::query()->where('user_id', $assignee->getKey())->where('type', 'task.assigned')->count())->toBe(1)
        ->and(UserNotification::query()->where('user_id', $actor->getKey())->where('type', 'task.assigned')->count())->toBe(0);
});

test('updating board task assignment notifies newly added assignee once', function () {
    $actor = notificationAdminActor(['my_board.view', 'my_board.create', 'my_board.edit', 'my_board.assign']);
    $firstAssignee = User::factory()->create();
    $secondAssignee = User::factory()->create();

    $response = $this->actingAs($actor)
        ->postJson(route('admin.my-board.store'), [
            'title' => 'Extend assignment',
            'description' => '',
            'type' => UserTask::TypeTask,
            'status' => UserTask::StatusTodo,
            'priority' => UserTask::PriorityNormal,
            'color' => 'primary',
            'assignee_doc_nums' => [$firstAssignee->doc_num],
        ])
        ->assertCreated();

    $docNum = $response->json('data.item.doc_num');

    $this->actingAs($actor)
        ->putJson(route('admin.my-board.update', $docNum), [
            'title' => 'Extend assignment',
            'description' => '',
            'type' => UserTask::TypeTask,
            'status' => UserTask::StatusTodo,
            'priority' => UserTask::PriorityNormal,
            'color' => 'primary',
            'assignee_doc_nums' => [$firstAssignee->doc_num, $secondAssignee->doc_num],
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->putJson(route('admin.my-board.update', $docNum), [
            'title' => 'Extend assignment',
            'description' => '',
            'type' => UserTask::TypeTask,
            'status' => UserTask::StatusTodo,
            'priority' => UserTask::PriorityNormal,
            'color' => 'primary',
            'assignee_doc_nums' => [$firstAssignee->doc_num, $secondAssignee->doc_num],
        ])
        ->assertOk();

    expect(UserNotification::query()
        ->where('user_id', $secondAssignee->getKey())
        ->where('type', 'task.assigned')
        ->count())->toBe(1);
});

test('scheduled task reminders cover approaching due and overdue without duplicate runs', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 15, 9));
    $assignee = notificationActor();
    $task = UserTask::factory()->assignedTo($assignee)->create([
        'status' => UserTask::StatusTodo,
        'due_at' => now()->addMinutes(20),
    ]);

    $this->artisan('notifications:dispatch-due')->assertSuccessful();
    $this->artisan('notifications:dispatch-due')->assertSuccessful();
    expect(UserNotification::query()->where('user_id', $assignee->getKey())->where('type', 'task.due_soon')->count())->toBe(1);

    $this->travel(21)->minutes();
    $this->artisan('notifications:dispatch-due')->assertSuccessful();
    expect(UserNotification::query()->where('user_id', $assignee->getKey())->where('type', 'task.due')->count())->toBe(1)
        ->and(UserNotification::query()->where('user_id', $assignee->getKey())->where('type', 'task.overdue')->count())->toBe(0);

    $this->travel(10)->minutes();
    $this->artisan('notifications:dispatch-due')->assertSuccessful();
    $this->artisan('notifications:dispatch-due')->assertSuccessful();
    expect(UserNotification::query()->where('user_id', $assignee->getKey())->where('type', 'task.overdue')->count())->toBe(1);

    $task->forceFill(['status' => UserTask::StatusDone, 'completed_at' => now()])->save();
    $this->travel(1)->day();
    $this->artisan('notifications:dispatch-due')->assertSuccessful();
    expect(UserNotification::query()->where('user_id', $assignee->getKey())->count())->toBe(3);

    Carbon::setTestNow();
});

test('task due dispatch catches scheduler downtime and scans beyond duplicate reminders', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 15, 12));
    $assignee = notificationActor();
    $alreadyDispatchedTask = UserTask::factory()->assignedTo($assignee)->create([
        'status' => UserTask::StatusTodo,
        'due_at' => now()->subHours(3),
    ]);
    $waitingTask = UserTask::factory()->assignedTo($assignee)->create([
        'status' => UserTask::StatusTodo,
        'due_at' => now()->subHours(2),
    ]);

    UserNotification::query()->create([
        'user_id' => $assignee->getKey(),
        'type' => 'task.due',
        'category' => 'task',
        'title' => 'Already dispatched',
        'delivered_at' => now()->subHours(3),
        'dedupe_key' => "task.due:{$alreadyDispatchedTask->getKey()}:{$assignee->getKey()}:{$alreadyDispatchedTask->due_at?->format('YmdHis')}",
    ]);

    $this->artisan('notifications:dispatch-due --limit=1')->assertSuccessful();

    expect(UserNotification::query()
        ->where('user_id', $assignee->getKey())
        ->where('type', 'task.due')
        ->where('dedupe_key', "task.due:{$waitingTask->getKey()}:{$assignee->getKey()}:{$waitingTask->due_at?->format('YmdHis')}")
        ->count())->toBe(1);

    Carbon::setTestNow();
});

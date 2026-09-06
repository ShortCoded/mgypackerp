<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    try {
        $response = $this->actingAs($actor)->getJson(route('admin.notifications.poll'));
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    $notificationQueries = collect($queries)
        ->filter(fn (array $query): bool => str_contains($query['query'], 'user_notifications'));
    $pollQuery = $notificationQueries->sole()['query'];
    $payload = $response->assertOk()->json('data');

    expect($notificationQueries)->toHaveCount(1)
        ->and($retrievedNotifications)->toBe(0)
        ->and($pollQuery)->not->toContain('metadata', 'dedupe_key', 'scheduled_for', 'updated_at')
        ->and(strtolower($pollQuery))->toContain('select count(*)')
        ->and(strtolower($pollQuery))->not->toContain(' over ')
        ->and($payload['unread_count'])->toBe(11)
        ->and($payload['session_identity'])->toBeString()->toHaveLength(64)
        ->and($payload['notifications'])->toHaveCount(10)
        ->and(array_keys($payload['notifications'][0]))->toBe([
            'id',
            'type',
            'category',
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

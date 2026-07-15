<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Auth\Models\AuthLog;
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
    $actor = notificationActor(['my_board.view', 'my_board.create', 'my_board.assign']);
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
    $actor = notificationActor(['my_board.view', 'my_board.create', 'my_board.edit', 'my_board.assign']);
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

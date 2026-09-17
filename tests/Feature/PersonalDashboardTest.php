<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ChatConversation;
use Modules\Core\Models\ChatMessage;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\ChatService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

afterEach(fn () => Carbon::setTestNow());

function personalDashboardActor(array $permissions = []): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function personalDashboardConversation(User $recipient, User $sender, int $messageCount = 1, ?Carbon $readAt = null): ChatConversation
{
    $conversation = ChatConversation::query()->create([
        'type' => ChatConversation::TypeDirect,
        'created_by' => $sender->getKey(),
        'last_message_at' => now(),
    ]);
    $conversation->allParticipants()->attach([
        $recipient->getKey() => ['last_read_at' => $readAt, 'created_at' => now(), 'updated_at' => now()],
        $sender->getKey() => ['last_read_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    foreach (range(1, $messageCount) as $position) {
        ChatMessage::query()->create([
            'conversation_id' => $conversation->getKey(),
            'sender_id' => $sender->getKey(),
            'body' => "Message {$position}",
            'sent_at' => now()->subSeconds($messageCount - $position),
        ]);
    }

    return $conversation;
}

test('personal dashboard cards use their real sources and open matching filtered destinations', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 10));
    $user = personalDashboardActor([
        'my_board.view',
        'chat.view',
        'purchases.purchase_requisition_approvals.approve',
        'purchases.purchase_requisitions.view',
    ]);
    $sender = User::factory()->create();

    UserTask::factory()->assignedTo($user)->create(['title' => 'Open dashboard task', 'status' => UserTask::StatusTodo, 'due_at' => null]);
    UserTask::factory()->assignedTo($user)->create(['title' => 'Due dashboard task', 'status' => UserTask::StatusTodo, 'due_at' => now()->addHours(3)]);
    UserTask::factory()->assignedTo($user)->create(['title' => 'Overdue dashboard task', 'status' => UserTask::StatusTodo, 'due_at' => now()->subDay()]);
    UserTask::factory()->assignedTo($user)->create(['title' => 'Progress dashboard task', 'status' => UserTask::StatusInProgress, 'due_at' => null]);
    UserTask::factory()->assignedTo($user)->create(['title' => 'Completed today task', 'status' => UserTask::StatusDone, 'completed_at' => now()->subHour()]);
    UserTask::factory()->assignedTo($user)->create(['title' => 'Completed yesterday task', 'status' => UserTask::StatusDone, 'completed_at' => now()->subDay()]);

    personalDashboardConversation($user, $sender, 2);
    personalDashboardConversation($user, $sender);
    personalDashboardConversation($user, $sender, readAt: now());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $startedAt = hrtime(true);

    try {
        $response = $this->actingAs($user)->getJson(route('dashboard.data'))->assertOk();
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        $queryCount = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    $cards = collect($response->json('data.cards'))->keyBy('key');

    expect($cards)->toHaveCount(8)
        ->and($cards->get('open_tasks'))
        ->toMatchArray(['value' => 4, 'url' => route('admin.my-board.index', ['focus' => 'open'], false)])
        ->and($cards->get('due_today'))
        ->toMatchArray(['value' => 1, 'url' => route('admin.my-board.index', ['focus' => 'due_today'], false)])
        ->and($cards->get('overdue'))
        ->toMatchArray(['value' => 1, 'url' => route('admin.my-board.index', ['focus' => 'overdue'], false)])
        ->and($cards->get('in_progress'))
        ->toMatchArray(['value' => 1, 'url' => route('admin.my-board.index', ['focus' => 'in_progress'], false)])
        ->and($cards->get('completed_today'))
        ->toMatchArray(['value' => 1, 'url' => route('admin.my-board.index', ['focus' => 'completed_today'], false)])
        ->and($cards->get('unread_conversations'))
        ->toMatchArray(['value' => 2, 'url' => route('admin.chat.index', ['filter' => 'unread'], false)])
        ->and($cards->get('approvals'))
        ->toMatchArray(['value' => 0, 'url' => route('dashboard.pending-decisions', [], false)])
        ->and($queryCount)->toBeLessThanOrEqual(30)
        ->and($elapsedMilliseconds)->toBeLessThan(1000);

    $completedPayload = json_encode($this->getJson(route('admin.my-board.data', ['focus' => 'completed_today']))->assertOk()->json(), JSON_THROW_ON_ERROR);
    expect($completedPayload)->toContain('Completed today task')
        ->not->toContain('Completed yesterday task', 'Open dashboard task', 'Overdue dashboard task');

    Carbon::setTestNow();
});

test('dashboard hides unavailable cards and reflows without exposing task data', function () {
    $user = personalDashboardActor();
    UserTask::factory()->assignedTo($user)->create(['title' => 'Permission hidden task', 'status' => UserTask::StatusTodo]);

    $response = $this->actingAs($user)->getJson(route('dashboard.data'))->assertOk();

    expect(collect($response->json('data.cards'))->pluck('key')->all())->toBe(['unread'])
        ->and($response->json('data.work_items'))->toBe([])
        ->and($response->json('data.summary.required_count'))->toBe(0);
});

test('dashboard content header omits duplicated operating context and update timestamps', function () {
    $user = personalDashboardActor();

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('dashboard.personal.summary_empty'))
        ->assertDontSee('plastics-dashboard-context', false);

    preg_match('/<header class="[^"]*plastics-dashboard-header[^"]*">(.*?)<\/header>/s', $response->getContent(), $header);
    preg_match('/<section class="plastics-dashboard-section" data-personal-dashboard>(.*?)<\/section>/s', $response->getContent(), $personalSection);

    expect($header[1] ?? '')
        ->toContain(__('dashboard.title'))
        ->not->toContain('Last updated', 'fa-building', 'fa-code-branch', 'fa-calendar-alt')
        ->and($personalSection[1] ?? '')
        ->not->toContain('Last updated');
});

test('unread conversation metric falls when a conversation is read rather than counting messages', function () {
    $recipient = personalDashboardActor(['chat.view']);
    $sender = User::factory()->create();
    $conversation = personalDashboardConversation($recipient, $sender, 3);

    expect(app(ChatService::class)->unreadConversationCount($recipient))->toBe(1);

    $conversation->allParticipants()->updateExistingPivot($recipient->getKey(), ['last_read_at' => now()->addSecond()]);

    expect(app(ChatService::class)->unreadConversationCount($recipient))->toBe(0);
});

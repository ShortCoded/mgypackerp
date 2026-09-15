<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Core\Models\ChatConversation;
use Modules\Core\Models\ChatMessage;
use Modules\Core\Models\ChatMessageAttachment;
use Modules\Core\Models\UserNotification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function chatActor(array $permissions = ['chat.view', 'chat.create', 'chat.send']): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('chat permissions are discoverable and assigned to admin role', function () {
    $this->seed(PermissionSeeder::class);

    $adminRole = Role::query()
        ->where('name', 'admin')
        ->where('guard_name', 'web')
        ->firstOrFail();

    foreach (['view', 'create', 'send'] as $action) {
        expect(Permission::query()->where('name', "chat.{$action}")->exists())->toBeTrue()
            ->and($adminRole->hasPermissionTo("chat.{$action}"))->toBeTrue();
    }
});

test('chat page requires chat view permission', function () {
    $withoutPermission = User::factory()->create();

    $this->actingAs($withoutPermission)
        ->get(route('admin.chat.index'))
        ->assertForbidden();

    $actor = chatActor(['chat.view', 'chat.create', 'chat.send']);

    $this->actingAs($actor)
        ->get(route('admin.chat.index'))
        ->assertOk()
        ->assertSee(__('chat.title'))
        ->assertSee('card-chat overflow-hidden', false)
        ->assertSee('chat-sidebar', false)
        ->assertSee('contacts-search-wrapper', false)
        ->assertSee('chat-content-header', false)
        ->assertDontSee('btn-chat-info', false)
        ->assertDontSee('conversation-info', false)
        ->assertSee('data-chat-close', false)
        ->assertSee('chat-editor-area', false)
        ->assertSee('data-chat-composer', false)
        ->assertSee('data-chat-attachment-input', false)
        ->assertSee('data-chat-emoji-picker', false)
        ->assertSee('vendors/emoji-mart/browser.js', false)
        ->assertSee('data-chat-reply-preview', false)
        ->assertSee(__('chat.forward_message'))
        ->assertDontSee(__('chat.say_hi'))
        ->assertDontSee('data-chat-action="archive"', false)
        ->assertDontSee('data-chat-action="delete"', false)
        ->assertDontSee('data-chat-delete-message', false)
        ->assertSee('assets/js/modules/Core/chat.js', false);
});

test('user can create direct conversation and duplicate request returns existing conversation', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);

    $first = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.conversation.id');

    $second = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->assertOk()
        ->json('data.conversation.id');

    expect($second)->toBe($first)
        ->and(ChatConversation::query()->count())->toBe(1);
});

test('user cannot create conversation with self or inactive user', function () {
    $actor = chatActor();
    $inactive = User::factory()->create(['status' => 'inactive']);
    $blocked = User::factory()->create(['status' => 'blocked']);
    $deleted = User::factory()->create(['status' => 'active', 'deleted_at' => now()]);

    $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $actor->doc_num])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $inactive->doc_num])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $blocked->doc_num])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $deleted->doc_num])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_doc_num']);
});

test('participant can send and poll messages while non participant cannot', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);
    $outsider = chatActor(['chat.view', 'chat.send']);

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $messageId = $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => '<strong>Hello</strong>'])
        ->assertOk()
        ->assertJsonPath('data.message.body', '<strong>Hello</strong>')
        ->assertJsonPath('data.message.read_status', 'sent')
        ->assertJsonStructure([
            'data' => [
                'message' => [
                    'date_key',
                    'date_label',
                    'sent_at_label',
                ],
            ],
        ])
        ->json('data.message.id');

    $this->actingAs($recipient)
        ->getJson(route('admin.chat.messages.poll', $conversationId))
        ->assertOk()
        ->assertJsonPath('data.messages.0.id', $messageId);

    $this->actingAs($outsider)
        ->getJson(route('admin.chat.messages.poll', $conversationId))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'Nope'])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->postJson(route('admin.chat.messages.read', $conversationId))
        ->assertForbidden();
});

test('participant can send emoji and attachment messages', function () {
    Storage::fake('local');

    $actor = chatActor();
    $recipient = chatActor(['chat.view']);

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $this->actingAs($actor)
        ->post(route('admin.chat.messages.store', $conversationId), [
            'body' => 'Ready 😀',
            'attachments' => [
                UploadedFile::fake()->image('receipt.png', 80, 80),
            ],
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.message.body', 'Ready 😀')
        ->assertJsonCount(1, 'data.message.attachments')
        ->assertJsonPath('data.message.attachments.0.name', 'receipt.png')
        ->assertJsonPath('data.message.attachments.0.is_image', true);

    $attachment = ChatMessageAttachment::query()->firstOrFail();
    Storage::disk('local')->assertExists($attachment->file_path);
});

test('participant can send attachment only but empty body without attachment fails', function () {
    Storage::fake('local');

    $actor = chatActor();
    $recipient = chatActor(['chat.view']);

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body']);

    $this->actingAs($actor)
        ->post(route('admin.chat.messages.store', $conversationId), [
            'attachments' => [
                UploadedFile::fake()->create('terms.pdf', 64, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.message.body', null)
        ->assertJsonCount(1, 'data.message.attachments');
});

test('chat attachment downloads are limited to participants', function () {
    Storage::fake('local');

    $actor = chatActor();
    $recipient = chatActor(['chat.view']);
    $outsider = chatActor(['chat.view']);

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $attachmentUrl = $this->actingAs($actor)
        ->post(route('admin.chat.messages.store', $conversationId), [
            'attachments' => [
                UploadedFile::fake()->create('terms.pdf', 64, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('data.message.attachments.0.url');

    $this->actingAs($recipient)
        ->get($attachmentUrl)
        ->assertOk();

    $this->actingAs($outsider)
        ->get($attachmentUrl)
        ->assertForbidden();
});

test('empty chat message is rejected', function () {
    $actor = chatActor();
    $recipient = User::factory()->create(['status' => 'active']);

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body']);
});

test('unread count works and mark read updates participant timestamp', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'Unread for recipient'])
        ->assertOk();

    $this->actingAs($recipient)
        ->getJson(route('admin.chat.conversations'))
        ->assertOk()
        ->assertJsonPath('data.conversations.0.unread_count', 1);

    $this->actingAs($recipient)
        ->postJson(route('admin.chat.messages.read', $conversationId))
        ->assertOk();

    $this->actingAs($recipient)
        ->getJson(route('admin.chat.conversations'))
        ->assertOk()
        ->assertJsonPath('data.conversations.0.unread_count', 0);
});

test('participant can mute conversation for self only', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $conversation = ChatConversation::query()->where('public_uuid', $conversationId)->firstOrFail();

    $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.mute', $conversationId))
        ->assertOk()
        ->assertJsonPath('data.muted', true);

    expect(DB::table('chat_conversation_user')
        ->where('conversation_id', $conversation->getKey())
        ->where('user_id', $actor->getKey())
        ->whereNotNull('muted_at')
        ->exists())->toBeTrue()
        ->and(DB::table('chat_conversation_user')
            ->where('conversation_id', $conversation->getKey())
            ->where('user_id', $recipient->getKey())
            ->whereNotNull('muted_at')
            ->exists())->toBeFalse();
});

test('participant can reply to message in same conversation', function () {
    $actor = chatActor();
    $recipient = chatActor();

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $originalId = $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'Original message'])
        ->assertOk()
        ->json('data.message.id');

    $replyId = $this->actingAs($recipient)
        ->postJson(route('admin.chat.messages.store', $conversationId), [
            'body' => 'Reply message',
            'reply_to_message_id' => $originalId,
        ])
        ->assertOk()
        ->assertJsonPath('data.message.reply_to.id', $originalId)
        ->assertJsonPath('data.message.reply_to.snippet', 'Original message')
        ->json('data.message.id');

    $reply = ChatMessage::query()->where('public_uuid', $replyId)->firstOrFail();
    $original = ChatMessage::query()->where('public_uuid', $originalId)->firstOrFail();

    expect((int) $reply->reply_to_message_id)->toBe((int) $original->getKey());
});

test('participant cannot reply to message from another conversation', function () {
    $actor = chatActor();
    $recipient = chatActor();
    $target = chatActor(['chat.view']);

    $firstConversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $secondConversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $target->doc_num])
        ->json('data.conversation.id');

    $otherMessageId = $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $secondConversationId), ['body' => 'Wrong thread'])
        ->assertOk()
        ->json('data.message.id');

    $this->actingAs($recipient)
        ->postJson(route('admin.chat.messages.store', $firstConversationId), [
            'body' => 'Invalid reply',
            'reply_to_message_id' => $otherMessageId,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reply_to_message_id']);
});

test('participant can forward accessible text message while non participant cannot', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);
    $target = chatActor(['chat.view']);
    $outsider = chatActor();

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $messageId = $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'Forward me'])
        ->assertOk()
        ->json('data.message.id');

    $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.forward', $messageId), ['user_doc_num' => $target->doc_num])
        ->assertOk()
        ->assertJsonPath('data.message.body', 'Forward me')
        ->assertJsonPath('data.message.forwarded_from.name', $actor->name)
        ->assertJsonPath('data.message.forwarded_from.label', __('chat.forwarded_from', ['name' => $actor->name]));

    $this->actingAs($target)
        ->getJson(route('admin.chat.conversations'))
        ->assertOk()
        ->assertJsonCount(1, 'data.conversations');

    $this->actingAs($outsider)
        ->postJson(route('admin.chat.messages.forward', $messageId), ['user_doc_num' => $target->doc_num])
        ->assertForbidden();
});

test('participant cannot forward message to the same direct conversation', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $messageId = $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'Do not loop'])
        ->assertOk()
        ->json('data.message.id');

    $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.forward', $messageId), ['user_doc_num' => $recipient->doc_num])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_doc_num'])
        ->assertJsonPath('errors.user_doc_num.0', __('chat.cannot_forward_same_conversation'));
});

test('conversation payload shows presence status from active sessions', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);

    UserPresenceSession::query()->create([
        'user_id' => $recipient->getKey(),
        'session_fingerprint' => 'chat-presence-test',
        'status' => 'online',
        'login_at' => now(),
        'last_seen_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->assertOk();

    $this->actingAs($actor)
        ->getJson(route('admin.chat.conversations'))
        ->assertOk()
        ->assertJsonPath('data.conversations.0.user.presence.status', 'online');
});

test('own message read status changes when recipient reads conversation', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $messageId = $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'Read me'])
        ->assertOk()
        ->assertJsonPath('data.message.read_status', 'sent')
        ->json('data.message.id');

    $this->actingAs($recipient)
        ->postJson(route('admin.chat.messages.read', $conversationId))
        ->assertOk();

    expect(UserNotification::query()->where('user_id', $recipient->getKey())->where('type', 'chat.message')->sole()->read_at)->not->toBeNull();

    $this->actingAs($actor)
        ->getJson(route('admin.chat.messages.poll', $conversationId))
        ->assertOk()
        ->assertJsonPath('data.messages.0.id', $messageId)
        ->assertJsonPath('data.messages.0.read_status', 'read');
});

test('sending a chat message creates one notification for recipient only', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'Notification please'])
        ->assertOk();

    expect(UserNotification::query()
        ->where('user_id', $recipient->getKey())
        ->where('type', 'chat.message')
        ->count())->toBe(1)
        ->and(UserNotification::query()
            ->where('user_id', $recipient->getKey())
            ->where('type', 'chat.message')
            ->first()?->url)->toContain(route('admin.chat.index', [], false).'?conversation=')
        ->and(UserNotification::query()
            ->where('user_id', $recipient->getKey())
            ->where('type', 'chat.message')
            ->first()?->dedupe_key)->toStartWith('chat.message:')
        ->and(UserNotification::query()
            ->where('user_id', $recipient->getKey())
            ->where('type', 'chat.message')
            ->first()?->required_permission)->toBe('chat.view')
        ->and(UserNotification::query()
            ->where('user_id', $actor->getKey())
            ->where('type', 'chat.message')
            ->count())->toBe(0);
});

test('revoked chat participant cannot poll old notifications or receive new ones', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);
    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'Before permission removal'])
        ->assertOk();

    $recipient->revokePermissionTo('chat.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $recipient->unsetRelation('permissions')->unsetRelation('roles');

    $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'After permission removal'])
        ->assertOk();

    $poll = $this->actingAs($recipient)
        ->getJson(route('admin.notifications.poll'))
        ->assertOk();

    expect(UserNotification::query()->where('user_id', $recipient->getKey())->where('type', 'chat.message')->count())->toBe(1)
        ->and($poll->json('data.unread_count'))->toBe(0)
        ->and($poll->json('data.notifications'))->toBe([]);
});

test('retrying the same client message creates one message and one notification', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);
    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');
    $clientMessageId = (string) Str::uuid();
    $payload = ['body' => 'Send exactly once', 'client_message_id' => $clientMessageId];

    $firstMessageId = $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), $payload)
        ->assertOk()
        ->json('data.message.id');
    $secondMessageId = $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), $payload)
        ->assertOk()
        ->json('data.message.id');

    expect($secondMessageId)->toBe($firstMessageId)
        ->and(ChatMessage::query()->where('client_message_id', $clientMessageId)->count())->toBe(1)
        ->and(UserNotification::query()->where('user_id', $recipient->getKey())->where('type', 'chat.message')->count())->toBe(1);
});

test('a participant who left cannot poll old chat notifications or receive new ones', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);
    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');
    $conversation = ChatConversation::query()->where('public_uuid', $conversationId)->sole();

    $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'Before leaving'])
        ->assertOk();
    DB::table('chat_conversation_user')
        ->where('conversation_id', $conversation->getKey())
        ->where('user_id', $recipient->getKey())
        ->update(['deleted_at' => now()]);

    $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'After leaving'])
        ->assertOk();
    $poll = $this->actingAs($recipient)->getJson(route('admin.notifications.poll'))->assertOk();

    expect(UserNotification::query()->where('user_id', $recipient->getKey())->where('type', 'chat.message')->count())->toBe(1)
        ->and($poll->json('data.unread_count'))->toBe(0)
        ->and($poll->json('data.notifications'))->toBe([]);
});

test('muted conversation keeps a durable notification while suppressing its alert and sound', function () {
    $actor = chatActor();
    $recipient = chatActor(['chat.view']);

    $conversationId = $this->actingAs($actor)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->json('data.conversation.id');

    $this->actingAs($recipient)
        ->postJson(route('admin.chat.conversations.mute', $conversationId))
        ->assertOk()
        ->assertJsonPath('data.muted', true);

    $this->actingAs($actor)
        ->postJson(route('admin.chat.messages.store', $conversationId), ['body' => 'Quiet notification'])
        ->assertOk();

    $notification = UserNotification::query()
        ->where('user_id', $recipient->getKey())
        ->where('type', 'chat.message')
        ->sole();

    expect($notification->sound_key)->toBeNull()
        ->and($notification->suppress_in_app_alert)->toBeTrue();
});

test('chat polling endpoints do not touch active presence', function () {
    $actor = chatActor(['chat.view']);

    $this->actingAs($actor)
        ->getJson(route('admin.chat.conversations'))
        ->assertOk();

    $this->assertDatabaseMissing('user_presence_sessions', [
        'user_id' => $actor->getKey(),
        'status' => 'online',
    ]);
});

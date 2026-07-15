<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Models\ChatConversation;
use Modules\Core\Models\ChatMessage;
use Modules\Core\Models\ChatMessageAttachment;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChatService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly UserPresenceService $presence,
    ) {}

    /**
     * @return Collection<int, ChatConversation>
     */
    public function conversationsFor(User $user): Collection
    {
        return ChatConversation::query()
            ->visibleTo($user)
            ->whereHas('participants', function (Builder $participants) use ($user): void {
                $participants->whereKey($user->getKey())
                    ->whereNull('chat_conversation_user.deleted_at');
            })
            ->with(['participants', 'messages' => fn ($query) => $query->latest('sent_at')->limit(1), 'messages.sender', 'messages.attachments'])
            ->orderByDesc(DB::raw('COALESCE(last_message_at, created_at)'))
            ->limit(100)
            ->get();
    }

    public function createDirectConversation(User $creator, string $targetDocNum): ChatConversation
    {
        $target = User::query()
            ->where('doc_num', $targetDocNum)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if (! $target instanceof User || $target->is($creator)) {
            throw ValidationException::withMessages([
                'user_doc_num' => __('chat.messages.cannot_message_user'),
            ]);
        }

        $existing = $this->findDirectConversation($creator, $target);

        if ($existing instanceof ChatConversation) {
            $existing->participants()->updateExistingPivot($creator->getKey(), [
                'deleted_at' => null,
                'updated_at' => now(),
            ]);

            return $existing->loadMissing('participants');
        }

        return DB::transaction(function () use ($creator, $target): ChatConversation {
            $conversation = ChatConversation::query()->create([
                'type' => ChatConversation::TypeDirect,
                'created_by' => $creator->getKey(),
            ]);

            $conversation->participants()->syncWithoutDetaching([
                $creator->getKey() => ['last_read_at' => now()],
                $target->getKey() => ['last_read_at' => null],
            ]);

            return $conversation->loadMissing('participants');
        });
    }

    /**
     * @param  array<int, UploadedFile>  $attachments
     */
    public function sendMessage(
        ChatConversation $conversation,
        User $sender,
        ?string $body,
        array $attachments = [],
        ?string $replyToMessageUuid = null,
        ?ChatMessage $forwardedFromMessage = null,
    ): ChatMessage {
        $this->abortUnlessParticipant($conversation, $sender);
        $replyToMessage = $this->resolveReplyToMessage($conversation, $replyToMessageUuid);

        return DB::transaction(function () use ($conversation, $sender, $body, $attachments, $replyToMessage, $forwardedFromMessage): ChatMessage {
            $sentAt = now();
            $message = $conversation->messages()->create([
                'sender_id' => $sender->getKey(),
                'reply_to_message_id' => $replyToMessage?->getKey(),
                'forwarded_from_message_id' => $forwardedFromMessage?->getKey(),
                'forwarded_from_user_id' => $forwardedFromMessage?->sender_id,
                'body' => filled($body) ? trim((string) $body) : null,
                'sent_at' => $sentAt,
            ]);

            foreach ($attachments as $attachment) {
                if (! $attachment instanceof UploadedFile || ! $attachment->isValid()) {
                    continue;
                }

                $path = $attachment->storeAs(
                    'chat/attachments',
                    Str::uuid().'.'.$attachment->getClientOriginalExtension(),
                    'local',
                );

                $message->attachments()->create([
                    'file_path' => $path,
                    'original_name' => $attachment->getClientOriginalName(),
                    'mime_type' => $attachment->getMimeType(),
                    'size_bytes' => $attachment->getSize(),
                ]);
            }

            $conversation->forceFill(['last_message_at' => $sentAt])->save();
            $conversation->participants()->updateExistingPivot($sender->getKey(), [
                'last_read_at' => $sentAt,
                'deleted_at' => null,
                'updated_at' => now(),
            ]);

            $recipientIds = $conversation->participants()
                ->whereKeyNot($sender->getKey())
                ->pluck('users.id')
                ->all();

            foreach ($recipientIds as $recipientId) {
                $conversation->participants()->updateExistingPivot($recipientId, [
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);
            }

            $this->notifyRecipients($conversation, $message, $sender);

            return $message->load(['sender', 'attachments', 'replyToMessage.sender', 'replyToMessage.attachments', 'forwardedFromUser']);
        });
    }

    public function toggleMute(ChatConversation $conversation, User $user): bool
    {
        $this->abortUnlessParticipant($conversation, $user);
        $conversation->loadMissing('participants');

        $participant = $conversation->participants
            ->first(fn (User $participant): bool => (int) $participant->getKey() === (int) $user->getKey());
        $muted = $this->pivotTimestamp($participant?->pivot?->muted_at) === null;

        $conversation->participants()->updateExistingPivot($user->getKey(), [
            'muted_at' => $muted ? now() : null,
            'updated_at' => now(),
        ]);

        return $muted;
    }

    public function forwardMessage(ChatMessage $message, User $sender, string $targetDocNum): ChatMessage
    {
        $message->loadMissing(['conversation.participants', 'attachments', 'sender']);
        $this->abortUnlessParticipant($message->conversation, $sender);

        $target = User::query()
            ->where('doc_num', $targetDocNum)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if (! $target instanceof User || $target->is($sender)) {
            throw ValidationException::withMessages([
                'user_doc_num' => __('chat.messages.cannot_message_user'),
            ]);
        }

        if ($message->conversation->participants->contains(fn (User $participant): bool => $participant->is($target))) {
            throw ValidationException::withMessages([
                'user_doc_num' => __('chat.cannot_forward_same_conversation'),
            ]);
        }

        $body = trim((string) $message->body);

        if ($body === '' && $message->attachments->isEmpty()) {
            throw ValidationException::withMessages([
                'message' => __('chat.messages.forward_attachments_not_supported'),
            ]);
        }

        $targetConversation = $this->createDirectConversation($sender, $target->doc_num);

        return DB::transaction(function () use ($targetConversation, $sender, $message, $body): ChatMessage {
            $forwarded = $this->sendMessage($targetConversation, $sender, $body, [], null, $message);

            foreach ($message->attachments as $attachment) {
                $this->copyAttachment($attachment, $forwarded);
            }

            return $forwarded->load(['sender', 'attachments', 'replyToMessage.sender', 'replyToMessage.attachments', 'forwardedFromUser']);
        });
    }

    public function markRead(ChatConversation $conversation, User $user): void
    {
        $this->abortUnlessParticipant($conversation, $user);

        $conversation->participants()->updateExistingPivot($user->getKey(), [
            'last_read_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    public function messagesFor(ChatConversation $conversation, User $user, ?string $after = null, ?string $before = null, int $limit = 50): Collection
    {
        $this->abortUnlessParticipant($conversation, $user);

        $query = $conversation->messages()
            ->with(['sender', 'attachments', 'replyToMessage.sender', 'replyToMessage.attachments', 'forwardedFromUser'])
            ->limit(max(1, min($limit, 100)));

        if ($after !== null && $after !== '') {
            $afterMessage = $conversation->messages()->where('public_uuid', $after)->first();

            if ($afterMessage instanceof ChatMessage) {
                $query->where(function (Builder $builder) use ($afterMessage): void {
                    $builder->where('sent_at', '>', $afterMessage->sent_at)
                        ->orWhere(function (Builder $nested) use ($afterMessage): void {
                            $nested->where('sent_at', $afterMessage->sent_at)
                                ->where('id', '>', $afterMessage->getKey());
                        });
                });
            }
        } elseif ($before !== null && $before !== '') {
            $beforeMessage = $conversation->messages()->where('public_uuid', $before)->first();

            if ($beforeMessage instanceof ChatMessage) {
                $query->where(function (Builder $builder) use ($beforeMessage): void {
                    $builder->where('sent_at', '<', $beforeMessage->sent_at)
                        ->orWhere(function (Builder $nested) use ($beforeMessage): void {
                            $nested->where('sent_at', $beforeMessage->sent_at)
                                ->where('id', '<', $beforeMessage->getKey());
                        });
                });
            }
        }

        if ($after !== null && $after !== '') {
            $query->orderBy('sent_at')->orderBy('id');
        } else {
            $query->orderByDesc('sent_at')->orderByDesc('id');
        }

        $messages = $query->get();

        if ($after === null || $after === '') {
            $messages = $messages->reverse()->values();
        }

        return $messages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function conversationPayloads(Collection $conversations, User $viewer): array
    {
        return $conversations
            ->map(fn (ChatConversation $conversation): array => $this->conversationPayload($conversation, $viewer))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function conversationPayload(ChatConversation $conversation, User $viewer): array
    {
        $conversation->loadMissing(['participants', 'messages.sender', 'messages.attachments']);
        $other = $this->otherParticipant($conversation, $viewer);
        $latest = $conversation->messages->sortByDesc('sent_at')->first();

        return [
            'id' => $conversation->public_uuid,
            'type' => $conversation->type,
            'user' => $other ? $this->userPayload($other) : null,
            'title' => $other?->name ?? $conversation->title ?? __('chat.direct_conversation'),
            'latest_message' => $latest instanceof ChatMessage ? $this->latestPreview($latest, $viewer) : null,
            'latest_message_time' => $latest instanceof ChatMessage ? $this->humanTime($latest->sent_at) : null,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'created_at_label' => $this->humanDateTime($conversation->created_at),
            'unread_count' => $this->unreadCount($conversation, $viewer),
            'other_last_read_at' => $this->pivotTimestamp($other?->pivot?->last_read_at)?->toISOString(),
            'is_muted' => $this->pivotTimestamp($this->viewerPivotValue($conversation, $viewer, 'muted_at')) !== null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messagePayloads(Collection $messages, User $viewer): array
    {
        return $messages
            ->map(fn (ChatMessage $message): array => $this->messagePayload($message, $viewer))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function messagePayload(ChatMessage $message, User $viewer): array
    {
        $message->loadMissing(['sender', 'attachments', 'conversation.participants', 'replyToMessage.sender', 'replyToMessage.attachments', 'forwardedFromUser']);
        $isOwn = (int) $message->sender_id === (int) $viewer->getKey();
        $readByRecipient = $isOwn && $this->readByOtherParticipant($message, $viewer);

        return [
            'id' => $message->public_uuid,
            'body' => $message->body,
            'sent_at' => $message->sent_at?->toISOString(),
            'sent_at_label' => $this->messageTime($message->sent_at),
            'date_key' => $message->sent_at?->toDateString(),
            'date_label' => $this->messageDateSeparator($message->sent_at),
            'is_own' => $isOwn,
            'read_status' => $readByRecipient ? 'read' : 'sent',
            'read_status_label' => $readByRecipient ? __('chat.read') : __('chat.sent_status'),
            'sender' => $this->userPayload($message->sender),
            'reply_to' => $message->replyToMessage instanceof ChatMessage ? $this->messageReferencePayload($message->replyToMessage) : null,
            'forwarded_from' => $message->forwardedFromUser instanceof User ? [
                'name' => $message->forwardedFromUser->name,
                'label' => __('chat.forwarded_from', ['name' => $message->forwardedFromUser->name]),
            ] : null,
            'attachments' => $message->attachments
                ->map(fn (ChatMessageAttachment $attachment): array => $this->attachmentPayload($attachment))
                ->values()
                ->all(),
        ];
    }

    public function abortUnlessParticipant(ChatConversation $conversation, User $user): void
    {
        if (! $conversation->participants()->whereKey($user->getKey())->exists()) {
            throw new HttpException(403, __('chat.messages.not_participant'));
        }
    }

    private function findDirectConversation(User $first, User $second): ?ChatConversation
    {
        return ChatConversation::query()
            ->where('type', ChatConversation::TypeDirect)
            ->whereHas('participants', fn (Builder $query): Builder => $query->whereKey($first->getKey()))
            ->whereHas('participants', fn (Builder $query): Builder => $query->whereKey($second->getKey()))
            ->withCount('participants')
            ->get()
            ->first(fn (ChatConversation $conversation): bool => (int) $conversation->participants_count === 2);
    }

    private function otherParticipant(ChatConversation $conversation, User $viewer): ?User
    {
        return $conversation->participants
            ->first(fn (User $participant): bool => (int) $participant->getKey() !== (int) $viewer->getKey());
    }

    private function unreadCount(ChatConversation $conversation, User $viewer): int
    {
        $participant = $conversation->participants
            ->first(fn (User $participant): bool => (int) $participant->getKey() === (int) $viewer->getKey());
        $lastReadAt = $participant?->pivot?->last_read_at;

        return $conversation->messages()
            ->where('sender_id', '!=', $viewer->getKey())
            ->when($lastReadAt, fn (Builder $query) => $query->where('sent_at', '>', $lastReadAt))
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(?User $user): array
    {
        if (! $user instanceof User) {
            return [
                'doc_num' => null,
                'name' => __('common.messages.unknown'),
                'email' => null,
                'initials' => '?',
                'presence' => [
                    'status' => 'offline',
                    'label' => __('chat.offline'),
                    'last_seen_label' => null,
                ],
            ];
        }

        return [
            'doc_num' => $user->doc_num,
            'name' => $user->name,
            'email' => $user->email,
            'initials' => $this->initials($user->name),
            'presence' => $this->presencePayload($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function infoPayload(ChatConversation $conversation, User $viewer): array
    {
        $this->abortUnlessParticipant($conversation, $viewer);
        $conversation->loadMissing(['participants', 'messages.attachments']);

        $attachments = $conversation->messages
            ->flatMap(fn (ChatMessage $message): Collection => $message->attachments)
            ->take(12)
            ->map(fn (ChatMessageAttachment $attachment): array => $this->attachmentPayload($attachment))
            ->values()
            ->all();

        return [
            'conversation' => $this->conversationPayload($conversation, $viewer),
            'shared_attachments' => $attachments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentPayload(ChatMessageAttachment $attachment): array
    {
        $mime = (string) $attachment->mime_type;

        return [
            'id' => $attachment->public_uuid,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_label' => $this->formatBytes((int) $attachment->size_bytes),
            'is_image' => Str::startsWith($mime, 'image/'),
            'url' => route('admin.chat.attachments.show', $attachment, false),
        ];
    }

    private function readByOtherParticipant(ChatMessage $message, User $viewer): bool
    {
        $conversation = $message->conversation;

        if (! $conversation instanceof ChatConversation || ! $message->sent_at) {
            return false;
        }

        $other = $this->otherParticipant($conversation, $viewer);
        $lastReadAt = $this->pivotTimestamp($other?->pivot?->last_read_at);

        return $lastReadAt !== null && $lastReadAt->greaterThanOrEqualTo($message->sent_at);
    }

    private function resolveReplyToMessage(ChatConversation $conversation, ?string $replyToMessageUuid): ?ChatMessage
    {
        if (! is_string($replyToMessageUuid) || trim($replyToMessageUuid) === '') {
            return null;
        }

        $replyToMessage = $conversation->messages()
            ->with(['sender', 'attachments'])
            ->where('public_uuid', $replyToMessageUuid)
            ->first();

        if (! $replyToMessage instanceof ChatMessage) {
            throw ValidationException::withMessages([
                'reply_to_message_id' => __('chat.messages.invalid_reply_message'),
            ]);
        }

        return $replyToMessage;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageReferencePayload(ChatMessage $message): array
    {
        $message->loadMissing(['sender', 'attachments']);

        return [
            'id' => $message->public_uuid,
            'sender_name' => $message->sender?->name ?? __('common.messages.unknown'),
            'snippet' => $this->messageSnippet($message),
        ];
    }

    private function messageSnippet(ChatMessage $message): string
    {
        $body = trim((string) $message->body);

        if ($body !== '') {
            return Str::limit($body, 120);
        }

        if ($message->attachments->isNotEmpty()) {
            return __('chat.attachment');
        }

        return __('chat.message');
    }

    private function copyAttachment(ChatMessageAttachment $source, ChatMessage $target): void
    {
        if (! Storage::disk('local')->exists($source->file_path)) {
            return;
        }

        $extension = pathinfo($source->file_path, PATHINFO_EXTENSION) ?: pathinfo((string) $source->original_name, PATHINFO_EXTENSION);
        $path = 'chat/attachments/'.Str::uuid().($extension ? ".{$extension}" : '');

        Storage::disk('local')->copy($source->file_path, $path);

        $target->attachments()->create([
            'file_path' => $path,
            'original_name' => $source->original_name,
            'mime_type' => $source->mime_type,
            'size_bytes' => $source->size_bytes,
        ]);
    }

    private function pivotTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    private function viewerPivotValue(ChatConversation $conversation, User $viewer, string $column): mixed
    {
        $conversation->loadMissing('participants');

        $participant = $conversation->participants
            ->first(fn (User $participant): bool => (int) $participant->getKey() === (int) $viewer->getKey());

        return $participant?->pivot?->{$column};
    }

    /**
     * @return array<string, mixed>
     */
    private function presencePayload(User $user): array
    {
        $latestSession = UserPresenceSession::query()
            ->where('user_id', $user->getKey())
            ->latest('last_seen_at')
            ->latest('updated_at')
            ->first();

        $isOnline = $latestSession instanceof UserPresenceSession
            && $this->presence->isFreshActiveSession($latestSession);
        $lastSeenAt = $latestSession?->last_seen_at;

        return [
            'status' => $isOnline ? 'online' : 'offline',
            'label' => $isOnline ? __('chat.online') : __('chat.offline'),
            'last_seen_at' => $lastSeenAt?->toISOString(),
            'last_seen_label' => $lastSeenAt instanceof Carbon
                ? __('chat.last_seen').' '.$this->humanTime($lastSeenAt)
                : null,
        ];
    }

    private function humanTime(?Carbon $date): ?string
    {
        if (! $date instanceof Carbon) {
            return null;
        }

        $now = now();

        if ($date->greaterThanOrEqualTo($now->copy()->subMinute())) {
            return __('chat.just_now');
        }

        if ($date->greaterThanOrEqualTo($now->copy()->subHour())) {
            return __('chat.time_ago', ['time' => $date->diffForHumans($now, true)]);
        }

        if ($date->isToday()) {
            return __('chat.today').' '.$date->translatedFormat('g:i A');
        }

        if ($date->isYesterday()) {
            return __('chat.yesterday');
        }

        if ($date->greaterThanOrEqualTo($now->copy()->subDays(6))) {
            return $date->translatedFormat('D');
        }

        return $date->translatedFormat('d/m/Y g:i A');
    }

    private function humanDateTime(?Carbon $date): ?string
    {
        if (! $date instanceof Carbon) {
            return null;
        }

        return $date->translatedFormat('d/m/Y g:i A');
    }

    private function messageTime(?Carbon $date): ?string
    {
        if (! $date instanceof Carbon) {
            return null;
        }

        return $date->translatedFormat('g:i A');
    }

    private function messageDateSeparator(?Carbon $date): ?string
    {
        if (! $date instanceof Carbon) {
            return null;
        }

        if ($date->isToday()) {
            return __('chat.today');
        }

        if ($date->isYesterday()) {
            return __('chat.yesterday');
        }

        return $date->translatedFormat('M j, Y');
    }

    private function latestPreview(ChatMessage $message, User $viewer): string
    {
        $prefix = (int) $message->sender_id === (int) $viewer->getKey() ? __('chat.you_prefix').' ' : '';
        $body = trim((string) $message->body);

        if ($body !== '') {
            return $prefix.$body;
        }

        if ($message->attachments->isNotEmpty()) {
            return $prefix.trans_choice('chat.attachments_count', $message->attachments->count(), [
                'count' => $message->attachments->count(),
            ]);
        }

        return $prefix.__('chat.message');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }

    private function notifyRecipients(ChatConversation $conversation, ChatMessage $message, User $sender): void
    {
        $conversation->loadMissing('participants');

        foreach ($conversation->participants as $participant) {
            if ((int) $participant->getKey() === (int) $sender->getKey()) {
                continue;
            }

            if ($this->pivotTimestamp($participant->pivot?->muted_at) !== null) {
                continue;
            }

            $this->notifications->createImmediate(
                $participant,
                'chat.message',
                __('notifications.types.chat_message'),
                __('notifications.messages.chat_message', ['name' => $sender->name]),
                route('admin.chat.index', ['conversation' => $conversation->public_uuid], false),
                [
                    'conversation_uuid' => $conversation->public_uuid,
                    'message_uuid' => $message->public_uuid,
                    'sender_doc_num' => $sender->doc_num,
                    'sender_name' => $sender->name,
                ],
                "chat.message:{$message->getKey()}:{$participant->getKey()}",
            );
        }
    }

    private function initials(?string $name): string
    {
        $words = preg_split('/\s+/u', trim((string) $name)) ?: [];
        $letters = collect($words)
            ->filter()
            ->take(2)
            ->map(fn (string $word): string => mb_substr($word, 0, 1))
            ->implode('');

        return $letters !== '' ? mb_strtoupper($letters) : '?';
    }
}

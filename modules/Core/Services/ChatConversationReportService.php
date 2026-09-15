<?php

namespace Modules\Core\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Models\ChatConversation;
use Modules\Core\Models\ChatMessage;
use Modules\Core\Models\ChatMessageAttachment;

class ChatConversationReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ChatConversation>
     */
    public function conversationQuery(array $filters = []): Builder
    {
        return $this->baseConversationQuery($filters)
            ->with([
                'allParticipants:id,name,doc_num,email,status',
                'creator:id,name,doc_num',
                'messages' => fn ($query) => $query
                    ->withTrashed()
                    ->with(['sender:id,name,doc_num', 'attachments'])
                    ->latest('sent_at')
                    ->latest('id')
                    ->limit(1),
            ])
            ->withCount([
                'messages as messages_count' => function (Builder $query) use ($filters): Builder {
                    return $this->applyMessageDateFilters($query->withTrashed(), $filters);
                },
            ])
            ->addSelect([
                'attachments_count' => ChatMessageAttachment::query()
                    ->selectRaw('COUNT(*)')
                    ->join('chat_messages', 'chat_messages.id', '=', 'chat_message_attachments.chat_message_id')
                    ->whereColumn('chat_messages.conversation_id', 'chat_conversations.id')
                    ->when(filled($filters['date_from'] ?? null), fn ($query) => $query->where('chat_messages.sent_at', '>=', Carbon::parse($filters['date_from'])->startOfDay()))
                    ->when(filled($filters['date_to'] ?? null), fn ($query) => $query->where('chat_messages.sent_at', '<=', Carbon::parse($filters['date_to'])->endOfDay())),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<ChatConversation>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->conversationQuery($filters)
            ->orderByRaw('last_message_at IS NULL')
            ->latest('last_message_at')
            ->latest('id')
            ->paginate(max(10, min($perPage, 100)))
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{conversations: int, messages: int, attachments: int}
     */
    public function statistics(array $filters): array
    {
        return [
            'conversations' => (clone $this->baseConversationQuery($filters))->count(),
            'messages' => (clone $this->messageQuery($filters))->count(),
            'attachments' => ChatMessageAttachment::query()
                ->whereHas('message', function (Builder $message) use ($filters): void {
                    $message->withTrashed()
                        ->whereIn('conversation_id', $this->baseConversationQuery($filters)->select('chat_conversations.id'));
                    $this->applyMessageDateFilters($message, $filters);
                })
                ->count(),
        ];
    }

    public function find(string $publicUuid): ChatConversation
    {
        return $this->conversationQuery(['conversation_uuid' => $publicUuid])->firstOrFail();
    }

    /**
     * @return LengthAwarePaginator<ChatMessage>
     */
    public function paginateMessages(ChatConversation $conversation, int $perPage = 100): LengthAwarePaginator
    {
        return $this->messageQuery(['conversation_uuid' => $conversation->public_uuid])
            ->oldest('sent_at')
            ->oldest('id')
            ->paginate(max(25, min($perPage, 200)))
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ChatConversation>
     */
    public function transcript(array $filters): Collection
    {
        return $this->baseConversationQuery($filters)
            ->with([
                'allParticipants:id,name,doc_num,email,status',
                'creator:id,name,doc_num',
                'messages' => function ($query) use ($filters) {
                    $this->applyMessageDateFilters($query->withTrashed(), $filters);

                    return $query->with([
                        'sender:id,name,doc_num',
                        'attachments',
                        'replyToMessage' => fn ($reply) => $reply->withTrashed()->select(['id', 'public_uuid', 'sender_id', 'body']),
                        'forwardedFromMessage' => fn ($forwarded) => $forwarded->withTrashed()->select(['id', 'public_uuid']),
                        'forwardedFromUser:id,name,doc_num',
                    ])
                        ->oldest('sent_at')
                        ->oldest('id');
                },
            ])
            ->withCount([
                'messages as messages_count' => function (Builder $query) use ($filters): Builder {
                    return $this->applyMessageDateFilters($query->withTrashed(), $filters);
                },
            ])
            ->orderByRaw('last_message_at IS NULL')
            ->latest('last_message_at')
            ->latest('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ChatMessage>
     */
    public function messageQuery(array $filters): Builder
    {
        $query = ChatMessage::query()
            ->withTrashed()
            ->whereIn('conversation_id', $this->baseConversationQuery($filters)->select('chat_conversations.id'))
            ->with([
                'sender:id,name,doc_num',
                'attachments',
                'conversation' => fn ($query) => $query
                    ->withTrashed()
                    ->with('allParticipants:id,name,doc_num,email,status'),
                'replyToMessage' => fn ($query) => $query->withTrashed()->select(['id', 'public_uuid', 'sender_id', 'body']),
                'forwardedFromMessage' => fn ($query) => $query->withTrashed()->select(['id', 'public_uuid']),
                'forwardedFromUser:id,name,doc_num',
            ]);

        return $this->applyMessageDateFilters($query, $filters);
    }

    public function title(ChatConversation $conversation): string
    {
        $title = trim((string) $conversation->title);

        if ($title !== '') {
            return $title;
        }

        $participants = $conversation->allParticipants
            ->map(fn ($participant): string => trim((string) $participant->name))
            ->filter()
            ->unique()
            ->values();

        return $participants->isNotEmpty()
            ? $participants->implode(' ↔ ')
            : __('chat.report.untitled_conversation');
    }

    /** @return list<string> */
    public function readBy(ChatMessage $message, ?ChatConversation $conversation = null): array
    {
        $conversation ??= $message->relationLoaded('conversation') ? $message->conversation : null;

        if (! $message->sent_at instanceof Carbon || ! $conversation instanceof ChatConversation) {
            return [];
        }

        return $conversation->allParticipants
            ->reject(fn ($participant): bool => (int) $participant->getKey() === (int) $message->sender_id)
            ->filter(function ($participant) use ($message): bool {
                $lastReadAt = $participant->pivot?->last_read_at;

                return $lastReadAt && Carbon::parse($lastReadAt)->greaterThanOrEqualTo($message->sent_at);
            })
            ->map(fn ($participant): string => (string) $participant->name)
            ->values()
            ->all();
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ChatConversation>
     */
    private function baseConversationQuery(array $filters): Builder
    {
        $query = ChatConversation::query()->withTrashed();
        $search = trim((string) ($filters['q'] ?? ''));
        $participant = trim((string) ($filters['participant'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');

        if ($search !== '') {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $conversation) use ($filters, $term): void {
                $conversation
                    ->whereRaw("LOWER(COALESCE(chat_conversations.title, '')) LIKE ?", [$term])
                    ->orWhereHas('allParticipants', function (Builder $participant) use ($term): void {
                        $participant->where(function (Builder $user) use ($term): void {
                            $user->whereRaw("LOWER(COALESCE(users.name, '')) LIKE ?", [$term])
                                ->orWhereRaw("LOWER(COALESCE(users.doc_num, '')) LIKE ?", [$term]);
                        });
                    })
                    ->orWhereHas('messages', function (Builder $message) use ($filters, $term): void {
                        $message->withTrashed()->whereRaw("LOWER(COALESCE(chat_messages.body, '')) LIKE ?", [$term]);
                        $this->applyMessageDateFilters($message, $filters);
                    });
            });
        }

        if ($participant !== '') {
            $term = '%'.mb_strtolower($participant).'%';
            $query->whereHas('allParticipants', function (Builder $participantQuery) use ($term): void {
                $participantQuery->where(function (Builder $user) use ($term): void {
                    $user->whereRaw("LOWER(COALESCE(users.name, '')) LIKE ?", [$term])
                        ->orWhereRaw("LOWER(COALESCE(users.doc_num, '')) LIKE ?", [$term]);
                });
            });
        }

        if (filled($filters['date_from'] ?? null) || filled($filters['date_to'] ?? null)) {
            $query->whereHas('messages', function (Builder $message) use ($filters): void {
                $this->applyMessageDateFilters($message->withTrashed(), $filters);
            });
        }

        if (array_key_exists('has_attachments', $filters) && $filters['has_attachments'] !== null && $filters['has_attachments'] !== '') {
            $method = (string) $filters['has_attachments'] === '1' ? 'whereHas' : 'whereDoesntHave';
            $query->{$method}('messages', function (Builder $message) use ($filters): Builder {
                $message->withTrashed()->whereHas('attachments');

                return $this->applyMessageDateFilters($message, $filters);
            });
        }

        if ($status === 'active') {
            $query->whereNull('chat_conversations.deleted_at');
        } elseif ($status === 'deleted') {
            $query->whereNotNull('chat_conversations.deleted_at');
        }

        if (filled($filters['conversation_uuid'] ?? null)) {
            $query->where('chat_conversations.public_uuid', $filters['conversation_uuid']);
        }

        return $query;
    }

    /**
     * @param  Builder<ChatMessage>|Relation<ChatMessage, *, *>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<ChatMessage>|Relation<ChatMessage, *, *>
     */
    private function applyMessageDateFilters(Builder|Relation $query, array $filters): Builder|Relation
    {
        if (filled($filters['date_from'] ?? null)) {
            $query->where('sent_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->where('sent_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        return $query;
    }
}

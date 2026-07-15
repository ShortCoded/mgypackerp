<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChatMessage extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'conversation_id',
        'sender_id',
        'reply_to_message_id',
        'forwarded_from_message_id',
        'forwarded_from_user_id',
        'body',
        'sent_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChatMessage $message): void {
            $message->public_uuid ??= (string) Str::uuid();
            $message->sent_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    /**
     * @return BelongsTo<ChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<ChatMessage, $this>
     */
    public function replyToMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_message_id');
    }

    /**
     * @return BelongsTo<ChatMessage, $this>
     */
    public function forwardedFromMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'forwarded_from_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function forwardedFromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_from_user_id');
    }

    /**
     * @return HasMany<ChatMessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ChatMessageAttachment::class, 'chat_message_id');
    }
}

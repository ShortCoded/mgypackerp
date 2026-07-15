<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChatMessageAttachment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'chat_message_id',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChatMessageAttachment $attachment): void {
            $attachment->public_uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    /**
     * @return BelongsTo<ChatMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }
}

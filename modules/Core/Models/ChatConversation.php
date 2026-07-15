<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChatConversation extends Model
{
    use SoftDeletes;

    public const TypeDirect = 'direct';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'type',
        'title',
        'created_by',
        'last_message_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => self::TypeDirect,
    ];

    protected static function booted(): void
    {
        static::creating(function (ChatConversation $conversation): void {
            $conversation->public_uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_conversation_user', 'conversation_id', 'user_id')
            ->withPivot(['last_read_at', 'muted_at', 'archived_at', 'deleted_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    /**
     * @param  Builder<ChatConversation>  $query
     * @return Builder<ChatConversation>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('participants', fn (Builder $participants): Builder => $participants->whereKey($user->getKey()));
    }
}

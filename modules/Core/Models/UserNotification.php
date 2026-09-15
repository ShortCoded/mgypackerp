<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserNotification extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'event_uuid',
        'user_id',
        'type',
        'category',
        'module',
        'severity',
        'requires_action',
        'sound_key',
        'suppress_in_app_alert',
        'title',
        'body',
        'external_title',
        'external_body',
        'url',
        'required_permission',
        'company_id',
        'branch_id',
        'conversation_id',
        'scheduled_for',
        'delivered_at',
        'read_at',
        'push_status',
        'push_attempts',
        'push_last_attempt_at',
        'push_error_code',
        'metadata',
        'dedupe_key',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserNotification $notification): void {
            if (! is_string($notification->public_uuid) || trim($notification->public_uuid) === '') {
                $notification->public_uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'requires_action' => 'boolean',
            'suppress_in_app_alert' => 'boolean',
            'push_attempts' => 'integer',
            'push_last_attempt_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<UserNotification>  $query
     * @return Builder<UserNotification>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    /**
     * @param  Builder<UserNotification>  $query
     * @return Builder<UserNotification>
     */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->whereNotNull('delivered_at');
    }
}

<?php

namespace Modules\Core\Models;

use App\Models\User;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory, SoftDeletes;

    public const StatusPending = 'pending';

    public const StatusConfirmed = 'confirmed';

    public const StatusCompleted = self::StatusConfirmed;

    public const StatusCancelled = 'cancelled';

    /**
     * @var list<string>
     */
    public const Statuses = [
        self::StatusPending,
        self::StatusConfirmed,
        self::StatusCancelled,
    ];

    /**
     * @var list<string>
     */
    public const Colors = [
        'primary',
        'success',
        'info',
        'warning',
        'danger',
        'secondary',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'user_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'all_day',
        'status',
        'color',
        'location',
        'meeting_url',
        'reminder_at',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'all_day' => false,
        'status' => self::StatusPending,
    ];

    protected static function booted(): void
    {
        static::creating(function (CalendarEvent $event): void {
            if (! is_string($event->public_uuid) || trim($event->public_uuid) === '') {
                $event->public_uuid = (string) Str::uuid();
            }
        });
    }

    protected static function newFactory(): CalendarEventFactory
    {
        return CalendarEventFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'reminder_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}

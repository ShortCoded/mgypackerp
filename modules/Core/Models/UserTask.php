<?php

namespace Modules\Core\Models;

use App\Models\User;
use Database\Factories\UserTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserTask extends Model
{
    /** @use HasFactory<UserTaskFactory> */
    use HasFactory, SoftDeletes;

    public const TypeNote = 'note';

    public const TypeTask = 'task';

    public const AttachmentCollection = 'user_task_attachments';

    /**
     * @var list<string>
     */
    public const Types = [
        self::TypeNote,
        self::TypeTask,
    ];

    public const StatusTodo = 'todo';

    public const StatusInProgress = 'in_progress';

    public const StatusWaiting = 'waiting';

    public const StatusDone = 'done';

    /**
     * @var list<string>
     */
    public const Statuses = [
        self::StatusTodo,
        self::StatusInProgress,
        self::StatusWaiting,
        self::StatusDone,
    ];

    public const PriorityLow = 'low';

    public const PriorityNormal = 'normal';

    public const PriorityHigh = 'high';

    public const PriorityUrgent = 'urgent';

    /**
     * @var list<string>
     */
    public const Priorities = [
        self::PriorityLow,
        self::PriorityNormal,
        self::PriorityHigh,
        self::PriorityUrgent,
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
        'doc_number',
        'doc_num',
        'title',
        'description',
        'type',
        'status',
        'is_active',
        'board_list_id',
        'priority',
        'color',
        'assigned_to',
        'assigned_by',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'start_at',
        'due_at',
        'completed_at',
        'position',
        'restored_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => self::TypeTask,
        'status' => self::StatusTodo,
        'is_active' => true,
        'priority' => self::PriorityNormal,
        'position' => 0,
    ];

    protected static function newFactory(): UserTaskFactory
    {
        return UserTaskFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_task_assignees')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<MyBoardLabel, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(MyBoardLabel::class, 'my_board_task_label')
            ->withTimestamps();
    }

    /**
     * @return HasMany<MyBoardTaskComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(MyBoardTaskComment::class, 'user_task_id');
    }

    /**
     * @return HasMany<MyBoardTaskView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(MyBoardTaskView::class, 'user_task_id');
    }

    /**
     * @return MorphMany<ArchiveFileUsage, $this>
     */
    public function archiveFileUsages(): MorphMany
    {
        return $this->morphMany(ArchiveFileUsage::class, 'usable');
    }

    /**
     * @return MorphMany<ArchiveFileUsage, $this>
     */
    public function attachmentUsages(): MorphMany
    {
        return $this->morphMany(ArchiveFileUsage::class, 'usable')
            ->where('collection', self::AttachmentCollection)
            ->whereNull('role')
            ->with(['file', 'createdBy:id,name,doc_num']);
    }

    /**
     * @return BelongsTo<BoardList, $this>
     */
    public function boardList(): BelongsTo
    {
        return $this->belongsTo(BoardList::class, 'board_list_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
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
     * @param  Builder<UserTask>  $query
     * @return Builder<UserTask>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $visibilityQuery) use ($user): void {
            $visibilityQuery
                ->where('user_tasks.created_by', $user->getKey())
                ->orWhere('user_tasks.assigned_to', $user->getKey())
                ->orWhere('user_tasks.assigned_by', $user->getKey())
                ->orWhereHas('assignees', fn (Builder $query) => $query->whereKey($user->getKey()));
        });
    }
}

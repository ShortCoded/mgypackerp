<?php

namespace Modules\Core\Models;

use App\Models\User;
use Database\Factories\QuickTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuickTask extends Model
{
    /** @use HasFactory<QuickTaskFactory> */
    use HasFactory, SoftDeletes;

    public const StatusNew = 'new';

    public const StatusInProgress = 'in_progress';

    public const StatusReady = 'ready';

    public const StatusDone = 'done';

    public const StatusCancelled = 'cancelled';

    /**
     * @var list<string>
     */
    public const Statuses = [
        self::StatusNew,
        self::StatusInProgress,
        self::StatusReady,
        self::StatusDone,
        self::StatusCancelled,
    ];

    /**
     * @var list<string>
     */
    public const ActiveStatuses = [
        self::StatusNew,
        self::StatusInProgress,
        self::StatusReady,
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
    protected $fillable = [
        'company_id',
        'branch_id',
        'task_board_id',
        'doc_number',
        'doc_num',
        'title',
        'summary',
        'details',
        'status',
        'priority',
        'assigned_to',
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
        'status' => self::StatusNew,
        'priority' => self::PriorityNormal,
    ];

    protected static function newFactory(): QuickTaskFactory
    {
        return QuickTaskFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<TaskBoard, $this>
     */
    public function taskBoard(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'task_board_id');
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
     * @return HasMany<QuickTaskAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(QuickTaskAttachment::class, 'quick_task_id');
    }

    /**
     * @param  Builder<QuickTask>  $query
     * @return Builder<QuickTask>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    /**
     * @param  Builder<QuickTask>  $query
     * @return Builder<QuickTask>
     */
    public function scopeActiveForBoard(Builder $query): Builder
    {
        return $query->whereIn($this->getTable().'.status', self::ActiveStatuses);
    }
}

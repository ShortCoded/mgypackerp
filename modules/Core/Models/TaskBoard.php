<?php

namespace Modules\Core\Models;

use App\Models\User;
use Database\Factories\TaskBoardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Auth\Models\Role;

class TaskBoard extends Model
{
    /** @use HasFactory<TaskBoardFactory> */
    use HasFactory, SoftDeletes;

    public const DisplayThemeLight = 'light';

    public const DisplayThemeDark = 'dark';

    /**
     * @var list<string>
     */
    public const DisplayThemes = [
        self::DisplayThemeLight,
        self::DisplayThemeDark,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'doc_number',
        'doc_num',
        'name',
        'description',
        'is_active',
        'is_public',
        'requires_password',
        'display_theme',
        'public_token',
        'public_password_hash',
        'last_public_access_at',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'public_password_hash',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'is_public' => false,
        'requires_password' => false,
        'display_theme' => self::DisplayThemeLight,
    ];

    protected static function newFactory(): TaskBoardFactory
    {
        return TaskBoardFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'requires_password' => 'boolean',
            'last_public_access_at' => 'datetime',
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
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_board_user', 'task_board_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'task_board_role', 'task_board_id', 'role_id')
            ->withTimestamps();
    }

    /**
     * @return HasMany<QuickTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(QuickTask::class, 'task_board_id');
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
     * @param  Builder<TaskBoard>  $query
     * @return Builder<TaskBoard>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function displayUrl(): string
    {
        return route('public.task-boards.display', $this->public_token);
    }

    public function userDisplayUrl(): string
    {
        return route('public.task-boards.user-display', $this->public_token);
    }
}

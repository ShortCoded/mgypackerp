<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoardList extends Model
{
    use SoftDeletes;

    public const TypeTask = UserTask::TypeTask;

    public const TypeNote = UserTask::TypeNote;

    /**
     * @var list<string>
     */
    public const Types = [
        self::TypeTask,
        self::TypeNote,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'type',
        'name',
        'slug',
        'status',
        'color',
        'position',
        'is_system',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => self::TypeTask,
        'status' => UserTask::StatusTodo,
        'color' => 'primary',
        'position' => 0,
        'is_system' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    /**
     * @return HasMany<UserTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(UserTask::class, 'board_list_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<BoardList>  $query
     * @return Builder<BoardList>
     */
    public function scopeForType(Builder $query, string $type): Builder
    {
        return $query->where('type', in_array($type, self::Types, true) ? $type : self::TypeTask);
    }
}

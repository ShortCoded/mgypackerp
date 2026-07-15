<?php

namespace Modules\Core\Models;

use App\Models\User;
use Database\Factories\MyBoardLabelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MyBoardLabel extends Model
{
    /** @use HasFactory<MyBoardLabelFactory> */
    use HasFactory, SoftDeletes;

    public const StatusActive = 'active';

    public const ColorDefault = 'default';

    public const ColorPrimary = 'primary';

    public const ColorSuccess = 'success';

    public const ColorInfo = 'info';

    public const ColorWarning = 'warning';

    public const ColorDanger = 'danger';

    public const Colors = [
        self::ColorDefault,
        self::ColorPrimary,
        self::ColorSuccess,
        self::ColorInfo,
        self::ColorWarning,
        self::ColorDanger,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'name',
        'color',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected $attributes = [
        'color' => self::ColorDefault,
        'status' => self::StatusActive,
    ];

    protected static function newFactory(): MyBoardLabelFactory
    {
        return MyBoardLabelFactory::new();
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<UserTask, $this>
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(UserTask::class, 'my_board_task_label')
            ->withTimestamps();
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
}

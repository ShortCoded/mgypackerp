<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ExcelImportBatch extends Model
{
    public const ModuleProducts = 'products';

    public const ModuleFixedAssets = 'fixed_assets';

    public const StatusUploaded = 'uploaded';

    public const StatusValidating = 'validating';

    public const StatusInvalid = 'invalid';

    public const StatusReady = 'ready';

    public const StatusImporting = 'importing';

    public const StatusImported = 'imported';

    public const StatusFailed = 'failed';

    public const StatusCancelled = 'cancelled';

    public const StatusExpired = 'expired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'module',
        'template_version',
        'owner_user_id',
        'company_id',
        'context_snapshot',
        'original_filename',
        'private_path',
        'checksum',
        'status',
        'total_rows',
        'valid_rows',
        'error_rows',
        'warning_count',
        'created_result_count',
        'result_summary',
        'expires_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            $batch->public_uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_snapshot' => 'array',
            'result_summary' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<ExcelImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ExcelImportRow::class, 'batch_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('owner_user_id', $user->getKey());
    }

    public function isReady(): bool
    {
        return $this->status === self::StatusReady && $this->error_rows === 0;
    }
}

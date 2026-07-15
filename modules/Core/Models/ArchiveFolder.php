<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Lang;

class ArchiveFolder extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'parent_id',
        'name',
        'slug',
        'path_cache',
        'hidden_from_picker',
        'module',
        'record_type',
        'record_doc_num',
        'attachable_type',
        'attachable_id',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hidden_from_picker' => 'boolean',
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

    public function displayPath(?string $rootLabel = null): string
    {
        return self::displayPathFromCache($this->path_cache, $rootLabel);
    }

    public static function displayPathFromCache(?string $pathCache, ?string $rootLabel = null): string
    {
        return implode(' / ', self::displayPathSegmentsFromCache($pathCache, $rootLabel));
    }

    /**
     * @return list<string>
     */
    public static function displayPathSegmentsFromCache(?string $pathCache, ?string $rootLabel = null): array
    {
        $rootLabel = trim((string) ($rootLabel ?: __('archive.file_manager')));
        $segments = preg_split('/\s*\/\s*/u', trim((string) $pathCache)) ?: [];
        $segments = array_values(array_filter(array_map(
            fn (string $segment): string => trim($segment),
            $segments,
        ), fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return [$rootLabel];
        }

        if (self::isInternalRootSegment($segments[0])) {
            $segments[0] = $rootLabel;
        }

        return $segments;
    }

    private static function isInternalRootSegment(string $segment): bool
    {
        $internalRootLabels = array_values(array_unique(array_filter([
            (string) __('archive.root.general'),
            (string) Lang::get('archive.root.general', [], 'en'),
            (string) Lang::get('archive.root.general', [], 'ar'),
            'General',
            'عام',
        ])));

        return in_array(trim($segment), $internalRootLabels, true);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<ArchiveFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ArchiveFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ArchiveFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ArchiveFile::class, 'archive_folder_id');
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

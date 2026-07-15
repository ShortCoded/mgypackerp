<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class QuickTaskAttachment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'quick_task_id',
        'archive_file_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (QuickTaskAttachment $attachment): void {
            $attachment->public_uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    /**
     * @return BelongsTo<QuickTask, $this>
     */
    public function quickTask(): BelongsTo
    {
        return $this->belongsTo(QuickTask::class, 'quick_task_id');
    }

    /**
     * @return BelongsTo<ArchiveFile, $this>
     */
    public function archiveFile(): BelongsTo
    {
        return $this->belongsTo(ArchiveFile::class, 'archive_file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPreviewable(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/')
            || $this->mime_type === 'application/pdf';
    }
}

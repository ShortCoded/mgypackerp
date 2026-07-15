<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\ArchiveFile;

class QuotationAttachment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'quotation_id',
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
        static::creating(function (QuotationAttachment $attachment): void {
            $attachment->public_uuid ??= (string) Str::uuid();
        });
    }

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

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function archiveFile(): BelongsTo
    {
        return $this->belongsTo(ArchiveFile::class, 'archive_file_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

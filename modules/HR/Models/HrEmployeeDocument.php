<?php

namespace Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\ArchiveFile;

class HrEmployeeDocument extends Model
{
    use SoftDeletes;

    protected $table = 'hr_employee_documents';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'employee_id',
        'document_type_id',
        'document_type',
        'document_number_text',
        'title',
        'archive_file_id',
        'file_path',
        'original_name',
        'mime_type',
        'extension',
        'size',
        'issue_date',
        'issued_at',
        'expires_at',
        'alert_before_expiry_days',
        'file_label',
        'sort_order',
        'notes',
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
        'document_type' => 'other',
        'size' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'issue_date' => 'date',
            'expires_at' => 'date',
            'alert_before_expiry_days' => 'integer',
            'sort_order' => 'integer',
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
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    /**
     * @return BelongsTo<HrDocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(HrDocumentType::class, 'document_type_id');
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

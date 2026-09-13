<?php

namespace Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductionQualityInspectionReport extends Model
{
    use SoftDeletes;

    public const StatusSubmitted = 'submitted';

    protected $table = 'quality_inspection_reports';

    protected $guarded = ['id'];

    protected $attributes = [
        'result' => 'pending',
        'status' => self::StatusSubmitted,
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $report) => $report->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'affected_base_quantity' => 'decimal:8',
            'evidence' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ProductionQualityInspection::class, 'quality_inspection_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

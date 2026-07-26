<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPosition extends HrFoundationModel
{
    protected $table = 'hr_positions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'job_id',
        'grade_id',
        'job_level_id',
        'reports_to_position_id',
        'code',
        'name',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    /**
     * @return BelongsTo<HrJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(HrJob::class, 'job_id');
    }

    /**
     * @return BelongsTo<HrGrade, $this>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(HrGrade::class, 'grade_id');
    }

    /**
     * @return BelongsTo<HrJobLevel, $this>
     */
    public function jobLevel(): BelongsTo
    {
        return $this->belongsTo(HrJobLevel::class, 'job_level_id');
    }

    /**
     * @return BelongsTo<HrPosition, $this>
     */
    public function reportsToPosition(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'reports_to_position_id');
    }
}

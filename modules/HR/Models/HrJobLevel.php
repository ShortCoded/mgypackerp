<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrJobLevel extends HrFoundationModel
{
    protected $table = 'hr_job_levels';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'grade_id',
        'code',
        'name',
        'rank',
        'status',
        'notes',
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
            ...parent::casts(),
            'rank' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<HrGrade, $this>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(HrGrade::class, 'grade_id');
    }
}

<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrAttendanceRule extends HrFoundationModel
{
    protected $table = 'hr_attendance_rules';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'name',
        'code',
        'regulation_id',
        'work_start_time',
        'work_end_time',
        'grace_minutes_late',
        'grace_minutes_early_leave',
        'allowed_late_minutes_per_month',
        'allowed_early_leave_minutes_per_month',
        'deduct_after_late_minutes',
        'deduct_after_early_leave_minutes',
        'overtime_allowed',
        'overtime_after_minutes',
        'break_minutes',
        'weekend_days',
        'requires_check_in',
        'requires_check_out',
        'status',
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
        'status' => 'active',
        'grace_minutes_late' => 0,
        'grace_minutes_early_leave' => 0,
        'overtime_allowed' => false,
        'break_minutes' => 0,
        'requires_check_in' => true,
        'requires_check_out' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'weekend_days' => 'array',
            'overtime_allowed' => 'boolean',
            'requires_check_in' => 'boolean',
            'requires_check_out' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<HrRegulation, $this>
     */
    public function regulation(): BelongsTo
    {
        return $this->belongsTo(HrRegulation::class, 'regulation_id');
    }
}

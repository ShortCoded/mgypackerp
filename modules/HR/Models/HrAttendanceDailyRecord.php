<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;

class HrAttendanceDailyRecord extends Model
{
    protected $table = 'hr_attendance_daily_records';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'company_id', 'branch_id', 'shift_id', 'work_date', 'check_in_at', 'check_out_at', 'total_break_minutes', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'status', 'last_calculated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'last_calculated_at' => 'datetime',
            'total_break_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'overtime_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<HrEmployee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** @return BelongsTo<HrShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(HrShift::class, 'shift_id');
    }
}

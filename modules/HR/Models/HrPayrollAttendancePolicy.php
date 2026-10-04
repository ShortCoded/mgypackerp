<?php

namespace Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;

class HrPayrollAttendancePolicy extends Model
{
    use SoftDeletes;

    public const MonthlyCalendarDays = 'calendar_days_in_period';

    public const MonthlyFixedDivisor = 'salary_day_divisor';

    public const WeeklyCalendarDays = 'calendar_days';

    public const WeeklyFinalizedAttendance = 'finalized_attendance_days';

    public const WeeklyFinalizedAttendanceOrPaidLeave = 'finalized_attendance_or_paid_leave';

    public const WeeklyScheduledWork = 'scheduled_work_days';

    public const WeeklyScheduledWorkAndPaidHoliday = 'scheduled_work_and_paid_holiday';

    public const DailyFinalizedAttendance = 'finalized_attendance_days';

    public const DailyFinalizedAttendanceOrPaidLeave = 'finalized_attendance_or_paid_leave';

    public const DailyCalendarDays = 'calendar_days';

    public const DailyScheduledWork = 'scheduled_work_days';

    public const DailyScheduledWorkAndPaidHoliday = 'scheduled_work_and_paid_holiday';

    public const HourlyFinalizedMinutes = 'finalized_worked_minutes';

    public const HourlyFinalizedMinutesOrPaidLeave = 'finalized_worked_minutes_or_paid_leave';

    public const ShiftFinalizedAttendance = 'finalized_attendance_shifts';

    public const ShiftFinalizedAttendanceOrPaidLeave = 'finalized_attendance_shifts_or_paid_leave';

    public const PieceApprovedOutput = 'approved_piece_quantities';

    protected $table = 'hr_payroll_attendance_policies';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'branch_id',
        'branch_scope_key',
        'effective_from',
        'effective_to',
        'deduct_absence',
        'deduct_late',
        'deduct_early_leave',
        'deduct_unpaid_leave',
        'monthly_partial_method',
        'weekly_accrual_method',
        'weekly_work_days',
        'daily_accrual_method',
        'hourly_accrual_method',
        'hourly_rounding_mode',
        'hourly_rounding_increment_minutes',
        'shift_accrual_method',
        'piece_accrual_method',
        'salary_day_divisor',
        'standard_day_minutes',
        'deduction_payroll_item_code',
        'deduction_rules',
        'same_day_late_early_mode',
        'deduction_rounding_mode',
        'status',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'deduct_absence' => 'boolean',
            'deduct_late' => 'boolean',
            'deduct_early_leave' => 'boolean',
            'deduct_unpaid_leave' => 'boolean',
            'salary_day_divisor' => 'integer',
            'standard_day_minutes' => 'integer',
            'weekly_work_days' => 'integer',
            'hourly_rounding_increment_minutes' => 'integer',
            'deduction_rules' => 'array',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

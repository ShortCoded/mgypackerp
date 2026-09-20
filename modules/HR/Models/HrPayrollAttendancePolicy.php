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
        'salary_day_divisor',
        'standard_day_minutes',
        'deduction_payroll_item_code',
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

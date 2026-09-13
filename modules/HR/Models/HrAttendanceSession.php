<?php

namespace Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;

class HrAttendanceSession extends Model
{
    public const StatusOpen = 'open';

    public const StatusClosed = 'closed';

    /** @var list<string> */
    protected $fillable = ['public_uuid', 'employee_id', 'company_id', 'assigned_branch_id', 'shift_id', 'scheduled_start_time', 'scheduled_end_time', 'scheduled_crosses_midnight', 'allowed_late_minutes', 'allowed_early_leave_minutes', 'overtime_enabled', 'work_date', 'status', 'started_at', 'ended_at', 'total_break_minutes', 'worked_minutes', 'source', 'created_by', 'updated_by'];

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->public_uuid ??= (string) Str::uuid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_crosses_midnight' => 'boolean',
            'allowed_late_minutes' => 'integer',
            'allowed_early_leave_minutes' => 'integer',
            'overtime_enabled' => 'boolean',
            'work_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'total_break_minutes' => 'integer',
            'worked_minutes' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
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
    public function assignedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'assigned_branch_id');
    }

    /** @return BelongsTo<HrShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(HrShift::class, 'shift_id');
    }

    /** @return HasMany<HrAttendanceEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(HrAttendanceEvent::class, 'session_id')->orderBy('occurred_at')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

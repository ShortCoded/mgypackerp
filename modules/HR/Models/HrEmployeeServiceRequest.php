<?php

namespace Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;

class HrEmployeeServiceRequest extends Model
{
    use SoftDeletes;

    public const StatusSubmitted = 'submitted';

    public const StatusApproved = 'approved';

    public const StatusRejected = 'rejected';

    public const StatusCancelled = 'cancelled';

    /** @var list<string> */
    protected $fillable = ['public_uuid', 'employee_id', 'company_id', 'branch_id', 'request_type', 'subject', 'details', 'requested_from', 'requested_to', 'requested_minutes', 'amount', 'currency_id', 'payload', 'status', 'submitted_at', 'resolved_at', 'resolved_by', 'resolution_notes', 'created_by', 'updated_by'];

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->public_uuid ??= (string) Str::uuid();
        });
    }

    /** @return list<string> */
    public static function types(): array
    {
        return ['leave', 'attendance_adjustment', 'overtime', 'remote_work', 'salary_advance', 'device_asset', 'employment_letter', 'profile_update', 'other'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['requested_from' => 'date', 'requested_to' => 'date', 'amount' => 'decimal:2', 'payload' => 'array', 'submitted_at' => 'datetime', 'resolved_at' => 'datetime'];
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
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

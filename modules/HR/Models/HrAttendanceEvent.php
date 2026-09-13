<?php

namespace Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;

class HrAttendanceEvent extends Model
{
    public const CheckIn = 'check_in';

    public const BreakStart = 'break_start';

    public const BreakEnd = 'break_end';

    public const CheckOut = 'check_out';

    /** @var list<string> */
    protected $fillable = ['public_uuid', 'session_id', 'employee_id', 'company_id', 'assigned_branch_id', 'actual_branch_id', 'event_type', 'occurred_at', 'received_at', 'source', 'latitude', 'longitude', 'accuracy_meters', 'distance_meters', 'geofence_status', 'location_source', 'idempotency_key', 'ip_address', 'user_agent', 'notes', 'client_context', 'created_by'];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->public_uuid ??= (string) Str::uuid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'received_at' => 'datetime', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'accuracy_meters' => 'decimal:2', 'distance_meters' => 'integer', 'client_context' => 'array'];
    }

    /** @return list<string> */
    public static function types(): array
    {
        return [self::CheckIn, self::BreakStart, self::BreakEnd, self::CheckOut];
    }

    /** @return BelongsTo<HrAttendanceSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(HrAttendanceSession::class, 'session_id');
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

    /** @return BelongsTo<Branch, $this> */
    public function actualBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'actual_branch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

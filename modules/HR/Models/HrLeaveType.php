<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrLeaveType extends Model
{
    use SoftDeletes;

    protected $table = 'hr_leave_types';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'status', 'metadata', 'notes', 'created_by', 'updated_by', 'deleted_by', 'restored_by', 'restored_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'restored_at' => 'datetime'];
    }

    /** @return Attribute<string, string> */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): string => mb_strtoupper(trim((string) $value)));
    }

    public function requiresBalance(): bool
    {
        return (bool) data_get($this->metadata, 'requires_balance', false);
    }

    public function isPaid(): bool
    {
        $paymentStatus = data_get($this->metadata, 'payment_status')
            ?? data_get($this->metadata, 'payroll_treatment');

        if ($paymentStatus !== null) {
            return $paymentStatus === 'paid';
        }

        return (bool) data_get($this->metadata, 'is_paid', true);
    }

    public function annualEntitlementDays(): ?float
    {
        $days = data_get($this->metadata, 'annual_entitlement_days');

        return $days === null ? null : (float) $days;
    }

    public function carryForwardMaxDays(): ?float
    {
        $days = data_get($this->metadata, 'carry_forward_max_days');

        return $days === null ? null : (float) $days;
    }
}

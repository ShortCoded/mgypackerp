<?php

namespace Modules\HR\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\HR\Models\HrPayrollAttendancePolicy;

final class PayrollAttendancePolicyService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createVersion(int $companyId, array $data, int $actorId): HrPayrollAttendancePolicy
    {
        try {
            return DB::transaction(function () use ($companyId, $data, $actorId): HrPayrollAttendancePolicy {
                Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                $branch = null;
                if (filled($data['branch_doc_num'] ?? null)) {
                    $branch = Branch::query()
                        ->where('company_id', $companyId)
                        ->where('doc_num', $data['branch_doc_num'])
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $effectiveFrom = CarbonImmutable::parse($data['effective_from'])->startOfDay();
                $scopeKey = $branch === null ? 'company' : 'branch:'.$branch->getKey();
                $versions = HrPayrollAttendancePolicy::query()
                    ->where('company_id', $companyId)
                    ->where('branch_scope_key', $scopeKey)
                    ->orderBy('effective_from')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($versions->contains(fn (HrPayrollAttendancePolicy $policy): bool => $policy->effective_from->isSameDay($effectiveFrom))) {
                    throw new DomainException(__('hr_payroll_policies.validation.effective_from_unique'));
                }

                $versions->reduce(function (?HrPayrollAttendancePolicy $previous, HrPayrollAttendancePolicy $current): HrPayrollAttendancePolicy {
                    if ($previous instanceof HrPayrollAttendancePolicy
                        && ($previous->effective_to === null || $previous->effective_to->greaterThanOrEqualTo($current->effective_from))) {
                        throw new DomainException(__('hr_payroll_policies.validation.effective_period_overlap'));
                    }

                    return $current;
                });

                $previous = $versions->filter(fn (HrPayrollAttendancePolicy $policy): bool => $policy->effective_from->lessThan($effectiveFrom))->last();
                $next = $versions->first(fn (HrPayrollAttendancePolicy $policy): bool => $policy->effective_from->greaterThan($effectiveFrom));

                if ($previous instanceof HrPayrollAttendancePolicy) {
                    $previous->update([
                        'effective_to' => $effectiveFrom->subDay()->toDateString(),
                        'updated_by' => $actorId,
                    ]);
                }

                return HrPayrollAttendancePolicy::query()->create([
                    'company_id' => $companyId,
                    'branch_id' => $branch?->getKey(),
                    'branch_scope_key' => $scopeKey,
                    'effective_from' => $effectiveFrom->toDateString(),
                    'effective_to' => $next?->effective_from?->subDay()->toDateString(),
                    'deduct_absence' => (bool) ($data['deduct_absence'] ?? false),
                    'deduct_late' => (bool) ($data['deduct_late'] ?? false),
                    'deduct_early_leave' => (bool) ($data['deduct_early_leave'] ?? false),
                    'deduct_unpaid_leave' => (bool) ($data['deduct_unpaid_leave'] ?? false),
                    'salary_day_divisor' => (int) $data['salary_day_divisor'],
                    'standard_day_minutes' => (int) $data['standard_day_minutes'],
                    'deduction_payroll_item_code' => $data['deduction_payroll_item_code'] ?? null,
                    'status' => 'active',
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'hr_payroll_attendance_policy_scope_start_unique')
                || (str_contains($exception->getMessage(), 'hr_payroll_attendance_policies.company_id')
                    && str_contains($exception->getMessage(), 'branch_scope_key'))) {
                throw new DomainException(__('hr_payroll_policies.validation.effective_from_unique'), previous: $exception);
            }

            throw $exception;
        }
    }
}

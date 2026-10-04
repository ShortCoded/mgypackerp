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
    /** @var array<string, array{name: string, kind: string, classification: string}> */
    private const StandardItems = [
        'BASIC' => ['name' => 'Basic Salary', 'kind' => 'earning', 'classification' => 'salary_expense'],
        'OVERTIME' => ['name' => 'Overtime', 'kind' => 'earning', 'classification' => 'salary_expense'],
        'ALLOWANCE' => ['name' => 'Allowance', 'kind' => 'earning', 'classification' => 'salary_expense'],
        'BONUS' => ['name' => 'Bonus', 'kind' => 'earning', 'classification' => 'salary_expense'],
        'ATTENDANCE-DEDUCTION' => ['name' => 'Attendance Deduction', 'kind' => 'deduction', 'classification' => 'direct_labor_cost'],
        'PAYROLL-TAX' => ['name' => 'Payroll Tax', 'kind' => 'deduction', 'classification' => 'payroll_tax_payable'],
        'SOCIAL-INSURANCE' => ['name' => 'Social Insurance', 'kind' => 'deduction', 'classification' => 'social_insurance_payable'],
        'EMPLOYER-INSURANCE' => ['name' => 'Employer Social Insurance', 'kind' => 'employer', 'classification' => 'insurance_expense'],
        'SALARY-ADVANCE' => ['name' => 'Salary Advance', 'kind' => 'deduction', 'classification' => 'employee_advances'],
        'OTHER-DEDUCTION' => ['name' => 'Other Deduction', 'kind' => 'deduction', 'classification' => 'direct_labor_cost'],
    ];

    /** @return list<string> */
    public function missingStandardItems(): array
    {
        $present = DB::table('hr_payroll_items')
            ->whereIn('code', array_keys(self::StandardItems))
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('code')
            ->all();

        return array_values(array_diff(array_keys(self::StandardItems), $present));
    }

    /** @return list<string> */
    public function installStandardItems(int $actorId): array
    {
        return DB::transaction(function () use ($actorId): array {
            $classifications = DB::table('account_classifications')
                ->whereIn('code', array_unique(array_column(self::StandardItems, 'classification')))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'code'])
                ->keyBy('code');
            $created = [];

            foreach (self::StandardItems as $code => $definition) {
                $classification = $classifications->get($definition['classification']);
                if ($classification === null) {
                    throw new DomainException(__('hr_payroll_policies.validation.catalog_classification_missing', ['code' => $definition['classification']]));
                }

                $items = DB::table('hr_payroll_items')->where('code', $code)->lockForUpdate()->get();
                if ($items->isNotEmpty()) {
                    if ($items->count() !== 1 || $items->first()->deleted_at !== null
                        || $items->first()->status !== 'active' || $items->first()->item_kind !== $definition['kind']) {
                        throw new DomainException(__('hr_payroll_policies.validation.catalog_item_conflict', ['code' => $code]));
                    }

                    continue;
                }

                DB::table('hr_payroll_items')->insert([
                    'code' => $code,
                    'name' => $definition['name'],
                    'item_kind' => $definition['kind'],
                    'account_classification_id' => $classification->id,
                    'is_system' => true,
                    'status' => 'active',
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created[] = $code;
            }

            return $created;
        }, attempts: 3);
    }

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
                    'monthly_partial_method' => $data['monthly_partial_method'] ?? null,
                    'weekly_accrual_method' => $data['weekly_accrual_method'] ?? null,
                    'weekly_work_days' => $data['weekly_work_days'] ?? null,
                    'daily_accrual_method' => $data['daily_accrual_method'] ?? null,
                    'hourly_accrual_method' => $data['hourly_accrual_method'] ?? null,
                    'hourly_rounding_mode' => $data['hourly_rounding_mode'] ?? null,
                    'hourly_rounding_increment_minutes' => $data['hourly_rounding_increment_minutes'] ?? null,
                    'shift_accrual_method' => $data['shift_accrual_method'] ?? null,
                    'piece_accrual_method' => $data['piece_accrual_method'] ?? null,
                    'salary_day_divisor' => (int) $data['salary_day_divisor'],
                    'standard_day_minutes' => (int) $data['standard_day_minutes'],
                    'deduction_payroll_item_code' => $data['deduction_payroll_item_code'] ?? null,
                    'deduction_rules' => $data['deduction_rules'] ?? null,
                    'same_day_late_early_mode' => $data['same_day_late_early_mode'] ?? null,
                    'deduction_rounding_mode' => $data['deduction_rounding_mode'] ?? null,
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

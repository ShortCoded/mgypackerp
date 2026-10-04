<?php

namespace Modules\HR\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Models\HrEmployee;

final class HrEmployeeWageVersionService
{
    public const PayBases = ['monthly_salary', 'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate'];

    public function __construct(
        private readonly OperatingScopeAccessService $scope,
        private readonly HrLifecycleAuditLogger $auditLogger,
    ) {}

    /** @return array{employees_checked: int, cards_updated: int} */
    public function projectEffectiveRates(): array
    {
        $asOf = now()->toDateString();
        $result = ['employees_checked' => 0, 'cards_updated' => 0];

        Company::query()->active()->select('id')->chunkById(25, function ($companies) use ($asOf, &$result): void {
            foreach ($companies as $company) {
                HrEmployee::query()
                    ->where('company_id', $company->getKey())
                    ->whereExists(fn ($query) => $query
                        ->selectRaw('1')
                        ->from('hr_employee_salary_assignments')
                        ->whereColumn('hr_employee_salary_assignments.employee_id', 'hr_employees.id')
                        ->where('hr_employee_salary_assignments.effective_from', '<=', $asOf)
                        ->whereNull('hr_employee_salary_assignments.deleted_at')
                        ->whereNotNull('hr_employee_salary_assignments.pay_basis'))
                    ->select('id')
                    ->chunkById(100, function ($employees) use ($company, $asOf, &$result): void {
                        foreach ($employees as $candidate) {
                            $updated = DB::transaction(function () use ($company, $candidate, $asOf): bool {
                                Company::query()->whereKey($company->getKey())->active()->lockForUpdate()->firstOrFail();
                                $employee = HrEmployee::query()
                                    ->where('company_id', $company->getKey())
                                    ->lockForUpdate()
                                    ->findOrFail($candidate->getKey());
                                $versions = DB::table('hr_employee_salary_assignments')
                                    ->where('employee_id', $employee->getKey())
                                    ->where('effective_from', '<=', $asOf)
                                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf))
                                    ->whereNull('deleted_at')
                                    ->whereNotNull('pay_basis')
                                    ->orderByDesc('effective_from')
                                    ->orderByDesc('id')
                                    ->limit(2)
                                    ->lockForUpdate()
                                    ->get();
                                if ($versions->count() > 1) {
                                    throw new DomainException(__('hr_wage_versions.messages.sequence_invalid'));
                                }
                                $version = $versions->first();
                                if ($version === null) {
                                    return false;
                                }

                                $field = $this->rateField((string) $version->pay_basis);
                                $rate = $this->rate((string) $version->{$field}, $field);
                                $scale = $field === 'basic_salary' ? 2 : 4;
                                if ($employee->pay_basis === $version->pay_basis
                                    && bccomp((string) $employee->{$field}, $rate, $scale) === 0) {
                                    return false;
                                }

                                $employee->forceFill([
                                    'pay_basis' => $version->pay_basis,
                                    $field => $rate,
                                    'updated_by' => null,
                                ])->save();
                                $this->auditLogger->logStrict(
                                    Request::create('/artisan/hr:wages:project-current', 'POST'),
                                    'employee_wage_projection.apply',
                                    (int) $company->getKey(),
                                    [
                                        'employee_id' => (int) $employee->getKey(),
                                        'assignment_id' => (int) $version->id,
                                        'effective_from' => (string) $version->effective_from,
                                        'pay_basis' => (string) $version->pay_basis,
                                    ],
                                    $employee,
                                    'employee-wage:'.$employee->getKey().':'.$version->id,
                                    redactSensitiveProperties: true,
                                );

                                return true;
                            }, attempts: 3);

                            $result['employees_checked']++;
                            if ($updated) {
                                $result['cards_updated']++;
                            }
                        }
                    });
            }
        });

        return $result;
    }

    public function record(int $companyId, int $employeeId, string $effectiveFrom, string $payBasis, string $rate, string $reason, User $actor): object
    {
        return DB::transaction(function () use ($companyId, $employeeId, $effectiveFrom, $payBasis, $rate, $reason, $actor): object {
            $employee = $this->employeeForMutation($companyId, $employeeId, $actor);
            $field = $this->rateField($payBasis);
            $normalizedRate = $this->rate($rate, $field);
            $this->assertDate($employee, $effectiveFrom);
            $this->assertNoPostedPayroll($employee, $effectiveFrom, null);

            $last = DB::table('hr_employee_salary_assignments')
                ->where('employee_id', $employeeId)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($last !== null) {
                if ($last->pay_basis === null) {
                    throw new DomainException(__('hr_wage_versions.messages.verify_legacy_first'));
                }
                if ($effectiveFrom <= $last->effective_from
                    || ($last->effective_to !== null
                        && $effectiveFrom !== CarbonImmutable::parse($last->effective_to)->addDay()->toDateString())) {
                    throw new DomainException(__('hr_wage_versions.messages.sequence_invalid'));
                }
                if ($last->effective_to === null) {
                    DB::table('hr_employee_salary_assignments')->where('id', $last->id)->update([
                        'effective_to' => CarbonImmutable::parse($effectiveFrom)->subDay()->toDateString(),
                        'updated_by' => $actor->getKey(),
                        'updated_at' => now(),
                    ]);
                }
            } else {
                $startingDates = array_filter([
                    $employee->hire_date?->toDateString(),
                    $employee->contract_start_date?->toDateString(),
                ]);
                if ($startingDates === [] || $effectiveFrom !== max($startingDates)) {
                    throw new DomainException(__('hr_wage_versions.messages.initial_date_invalid'));
                }
            }

            $values = [
                'employee_id' => $employeeId,
                'effective_from' => $effectiveFrom,
                'basic_salary' => '0.00',
                'pay_basis' => $payBasis,
                $field => $normalizedRate,
                'reason' => trim($reason),
                'created_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $id = DB::table('hr_employee_salary_assignments')->insertGetId($values);
            $this->synchronizeCurrentRate($employee, $payBasis, $field, $normalizedRate, $effectiveFrom, $actor);

            return DB::table('hr_employee_salary_assignments')->where('id', $id)->first();
        }, attempts: 3);
    }

    public function verifyLegacy(int $companyId, int $employeeId, int $assignmentId, string $payBasis, string $rate, string $reason, User $actor): object
    {
        return DB::transaction(function () use ($companyId, $employeeId, $assignmentId, $payBasis, $rate, $reason, $actor): object {
            $employee = $this->employeeForMutation($companyId, $employeeId, $actor);
            $field = $this->rateField($payBasis);
            $normalizedRate = $this->rate($rate, $field);
            $assignment = DB::table('hr_employee_salary_assignments')
                ->where('employee_id', $employeeId)
                ->where('id', $assignmentId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if ($assignment === null || $assignment->pay_basis !== null) {
                throw new DomainException(__('hr_wage_versions.messages.legacy_unavailable'));
            }
            $this->assertNoPostedPayroll($employee, (string) $assignment->effective_from, $assignment->effective_to);

            $values = [
                'pay_basis' => $payBasis,
                $field => $normalizedRate,
                'reason' => trim($reason),
                'updated_by' => $actor->getKey(),
                'updated_at' => now(),
            ];
            DB::table('hr_employee_salary_assignments')->where('id', $assignmentId)->update($values);
            if ($assignment->effective_to === null || $assignment->effective_to >= now()->toDateString()) {
                $this->synchronizeCurrentRate($employee, $payBasis, $field, $normalizedRate, (string) $assignment->effective_from, $actor);
            }

            return DB::table('hr_employee_salary_assignments')->where('id', $assignmentId)->first();
        }, attempts: 3);
    }

    private function employeeForMutation(int $companyId, int $employeeId, User $actor): HrEmployee
    {
        if (! $actor->can('hr.employees.edit')) {
            throw new AuthorizationException;
        }
        $company = Company::query()->whereKey($companyId)->active()->lockForUpdate()->firstOrFail();
        if (! $this->scope->canAccessCompany($actor, $company)) {
            throw new AuthorizationException;
        }
        $employee = HrEmployee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($employeeId);
        if ($employee->branch_id === null || ! $this->scope->canAccessBranch($actor, $employee->branch, $company)) {
            throw new AuthorizationException;
        }

        return $employee;
    }

    private function assertDate(HrEmployee $employee, string $effectiveFrom): void
    {
        if (($employee->hire_date !== null && $effectiveFrom < $employee->hire_date->toDateString())
            || ($employee->contract_start_date !== null && $effectiveFrom < $employee->contract_start_date->toDateString())) {
            throw new DomainException(__('hr_wage_versions.messages.date_invalid'));
        }
    }

    private function assertNoPostedPayroll(HrEmployee $employee, string $from, ?string $to): void
    {
        $posted = DB::table('hr_payslips as slip')
            ->join('hr_payroll_runs as run', 'run.id', '=', 'slip.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('slip.employee_id', $employee->getKey())
            ->where('period.company_id', $employee->company_id)
            ->whereIn('run.status', ['approved', 'posted'])
            ->whereNull('run.deleted_at')
            ->whereNull('period.deleted_at')
            ->where('period.period_end', '>=', $from)
            ->when($to !== null, fn ($query) => $query->where('period.period_start', '<=', $to))
            ->exists();
        if ($posted) {
            throw new DomainException(__('hr_wage_versions.messages.posted_payroll_requires_correction'));
        }
    }

    private function synchronizeCurrentRate(HrEmployee $employee, string $payBasis, string $field, string $rate, string $effectiveFrom, User $actor): void
    {
        if ($effectiveFrom <= now()->toDateString()) {
            $employee->forceFill(['pay_basis' => $payBasis, $field => $rate, 'updated_by' => $actor->getKey()])->save();
        }
    }

    private function rateField(string $basis): string
    {
        return match ($basis) {
            'monthly_salary' => 'basic_salary',
            'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate' => $basis,
            default => throw new DomainException(__('hr_wage_versions.messages.basis_invalid')),
        };
    }

    private function rate(string $rate, string $field): string
    {
        $scale = $field === 'basic_salary' ? 2 : 4;
        if (bccomp($rate, '0', $scale) <= 0) {
            throw new DomainException(__('hr_wage_versions.messages.rate_invalid'));
        }

        return bcadd($rate, '0', $scale);
    }
}

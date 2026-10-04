<?php

namespace Modules\HR\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrEmployee;

final class HrEmployeeOrganizationAssignmentService
{
    public function __construct(private readonly OperatingScopeAccessService $scope) {}

    public function recordCreation(HrEmployee $employee): void
    {
        if ($employee->branch_id === null || $employee->hire_date === null) {
            return;
        }

        DB::table('hr_employee_organization_assignments')->insert([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->getKey(),
            'branch_id' => $employee->branch_id,
            'department_id' => $employee->department_id,
            'effective_from' => $employee->hire_date->toDateString(),
            'source_type' => 'employee_creation',
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function registerInitial(
        int $companyId,
        int $employeeId,
        string $effectiveFrom,
        ?int $costCenterId,
        string $reason,
        User $actor,
    ): object {
        return DB::transaction(function () use ($companyId, $employeeId, $effectiveFrom, $costCenterId, $reason, $actor): object {
            [$company, $employee] = $this->employeeForMutation($companyId, $employeeId, $actor);
            if ($employee->branch_id === null || DB::table('hr_employee_organization_assignments')->where('employee_id', $employeeId)->exists()) {
                throw new DomainException(__('hr_organization_assignments.messages.initial_unavailable'));
            }
            $this->validateStart($employee, $effectiveFrom);
            $this->assertBranchAccess($company, (int) $employee->branch_id, $actor);
            $this->assertCostCenter($companyId, $costCenterId);

            $id = DB::table('hr_employee_organization_assignments')->insertGetId([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'branch_id' => $employee->branch_id,
                'department_id' => $employee->department_id,
                'cost_center_id' => $costCenterId,
                'effective_from' => $effectiveFrom,
                'source_type' => 'initial_verified',
                'reason' => trim($reason),
                'created_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('hr_employee_organization_assignments')->where('id', $id)->first();
        }, attempts: 3);
    }

    public function transfer(
        int $companyId,
        int $employeeId,
        string $branchDocNum,
        ?string $departmentDocNum,
        ?string $costCenterDocNum,
        string $effectiveFrom,
        string $reason,
        User $actor,
    ): object {
        return DB::transaction(function () use ($companyId, $employeeId, $branchDocNum, $departmentDocNum, $costCenterDocNum, $effectiveFrom, $reason, $actor): object {
            [$company, $employee] = $this->employeeForMutation($companyId, $employeeId, $actor);
            $this->validateStart($employee, $effectiveFrom);
            $last = DB::table('hr_employee_organization_assignments')
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->orderByDesc('effective_from')
                ->lockForUpdate()
                ->first();
            if ($last === null || $last->effective_to !== null || $effectiveFrom <= $last->effective_from) {
                throw new DomainException(__('hr_organization_assignments.messages.transfer_sequence_invalid'));
            }

            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->where('doc_num', $branchDocNum)
                ->active()
                ->first();
            if (! $branch instanceof Branch) {
                throw new DomainException(__('hr_organization_assignments.messages.branch_invalid'));
            }
            $this->assertBranchAccess($company, (int) $last->branch_id, $actor);
            $this->assertBranchAccess($company, (int) $branch->getKey(), $actor);
            $departmentId = $departmentDocNum === null ? null : HrDepartment::query()
                ->where('doc_num', $departmentDocNum)
                ->where('status', 'active')
                ->value('id');
            if ($departmentDocNum !== null && $departmentId === null) {
                throw new DomainException(__('hr_organization_assignments.messages.department_invalid'));
            }
            $costCenterId = $costCenterDocNum === null ? null : CostCenter::query()
                ->where('company_id', $companyId)
                ->where('doc_num', $costCenterDocNum)
                ->where('status', 'active')
                ->where('is_group', false)
                ->value('id');
            if ($costCenterDocNum !== null && $costCenterId === null) {
                throw new DomainException(__('hr_organization_assignments.messages.cost_center_invalid'));
            }

            DB::table('hr_employee_organization_assignments')->where('id', $last->id)->update([
                'effective_to' => CarbonImmutable::parse($effectiveFrom)->subDay()->toDateString(),
                'updated_at' => now(),
            ]);
            $id = DB::table('hr_employee_organization_assignments')->insertGetId([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'branch_id' => $branch->getKey(),
                'department_id' => $departmentId,
                'cost_center_id' => $costCenterId,
                'effective_from' => $effectiveFrom,
                'source_type' => 'transfer',
                'reason' => trim($reason),
                'created_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->synchronizeCurrentCard($employee);

            return DB::table('hr_employee_organization_assignments')->where('id', $id)->first();
        }, attempts: 3);
    }

    public function synchronizeCurrentCard(HrEmployee $employee): void
    {
        $current = DB::table('hr_employee_organization_assignments')
            ->where('employee_id', $employee->getKey())
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))
            ->orderByDesc('effective_from')
            ->first();
        if ($current === null) {
            return;
        }

        if ((int) $employee->branch_id !== (int) $current->branch_id
            || $employee->department_id !== $current->department_id) {
            $employee->forceFill([
                'branch_id' => $current->branch_id,
                'department_id' => $current->department_id,
                'updated_by' => $current->created_by,
            ])->save();
        }
    }

    /** @return array{Company, HrEmployee} */
    private function employeeForMutation(int $companyId, int $employeeId, User $actor): array
    {
        if (! $actor->can('hr.employees.edit')) {
            throw new AuthorizationException;
        }
        $company = Company::query()->active()->findOrFail($companyId);
        if (! $this->scope->canAccessCompany($actor, $company)) {
            throw new AuthorizationException;
        }
        $employee = HrEmployee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($employeeId);

        return [$company, $employee];
    }

    private function assertBranchAccess(Company $company, int $branchId, User $actor): void
    {
        $branch = Branch::query()->where('company_id', $company->getKey())->find($branchId);
        if (! $branch instanceof Branch || ! $this->scope->canAccessBranch($actor, $branch, $company)) {
            throw new AuthorizationException;
        }
    }

    private function assertCostCenter(int $companyId, ?int $costCenterId): void
    {
        if ($costCenterId !== null && ! CostCenter::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_group', false)
            ->whereKey($costCenterId)
            ->exists()) {
            throw new DomainException(__('hr_organization_assignments.messages.cost_center_invalid'));
        }
    }

    private function validateStart(HrEmployee $employee, string $effectiveFrom): void
    {
        if (($employee->hire_date !== null && $effectiveFrom < $employee->hire_date->toDateString())
            || trim($effectiveFrom) === '') {
            throw new DomainException(__('hr_organization_assignments.messages.date_invalid'));
        }
    }
}

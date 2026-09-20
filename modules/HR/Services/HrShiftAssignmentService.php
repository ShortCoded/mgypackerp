<?php

namespace Modules\HR\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeShiftAssignment;
use Modules\HR\Models\HrShift;

class HrShiftAssignmentService
{
    public function __construct(private readonly OperatingScopeAccessService $scope) {}

    /**
     * @param  list<int>  $employeeIds
     * @return list<HrEmployeeShiftAssignment>
     */
    public function assign(int $companyId, array $employeeIds, string $shiftDocNum, string $effectiveFrom, ?string $effectiveTo, User $actor): array
    {
        $employeeIds = collect($employeeIds)->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();

        return DB::transaction(function () use ($companyId, $employeeIds, $shiftDocNum, $effectiveFrom, $effectiveTo, $actor): array {
            $company = Company::query()->findOrFail($companyId);
            $allowedBranchIds = $this->scope->allowedBranchQuery($actor, [(string) $company->doc_num])
                ->pluck('branches.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $employees = HrEmployee::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->whereIn('branch_id', $allowedBranchIds !== [] ? $allowedBranchIds : [0])
                ->whereIn('id', $employeeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'branch_id']);

            if ($employees->count() !== count($employeeIds)) {
                throw new DomainException(__('hr_shift_assignments.messages.employee_scope_invalid'));
            }

            $shift = HrShift::query()->where('doc_num', $shiftDocNum)->where('status', 'active')->lockForUpdate()->first();
            if (! $shift instanceof HrShift) {
                throw new DomainException(__('hr_shift_assignments.messages.shift_unavailable'));
            }

            $overlap = HrEmployeeShiftAssignment::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('effective_from', '<=', $effectiveTo ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $effectiveFrom))
                ->orderBy('employee_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($overlap instanceof HrEmployeeShiftAssignment) {
                throw new DomainException(__('hr_shift_assignments.messages.overlap'));
            }

            $now = now();
            $rows = array_map(fn (int $employeeId): array => [
                'employee_id' => $employeeId,
                'shift_id' => $shift->getKey(),
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'created_by' => $actor->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ], $employeeIds);

            HrEmployeeShiftAssignment::query()->insert($rows);

            return HrEmployeeShiftAssignment::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('shift_id', $shift->getKey())
                ->whereDate('effective_from', $effectiveFrom)
                ->get()
                ->all();
        });
    }

    public function updateEffectiveTo(int $companyId, HrEmployeeShiftAssignment $assignment, string $effectiveTo, User $actor): HrEmployeeShiftAssignment
    {
        $employeeId = (int) $assignment->employee_id;

        return DB::transaction(function () use ($companyId, $assignment, $effectiveTo, $actor, $employeeId): HrEmployeeShiftAssignment {
            $company = Company::query()->findOrFail($companyId);
            $allowedBranchIds = $this->scope->hasUnrestrictedBranchAccess($actor)
                ? null
                : $this->scope->allowedBranchQuery($actor, [(string) $company->doc_num])
                    ->pluck('branches.id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();
            $employee = HrEmployee::query()
                ->where('company_id', $companyId)
                ->when($allowedBranchIds !== null, fn ($query) => $query->whereIn('branch_id', $allowedBranchIds !== [] ? $allowedBranchIds : [0]))
                ->lockForUpdate()
                ->find($employeeId);

            if (! $employee instanceof HrEmployee) {
                throw new DomainException(__('hr_shift_assignments.messages.employee_scope_invalid'));
            }

            $locked = HrEmployeeShiftAssignment::query()
                ->where('employee_id', $employee->getKey())
                ->lockForUpdate()
                ->find($assignment->getKey());

            if (! $locked instanceof HrEmployeeShiftAssignment) {
                throw new DomainException(__('hr_shift_assignments.messages.assignment_unavailable'));
            }

            if ($effectiveTo < $locked->effective_from->toDateString()) {
                throw new DomainException(__('hr_shift_assignments.messages.invalid_effective_to'));
            }

            $overlap = HrEmployeeShiftAssignment::query()
                ->where('employee_id', $employee->getKey())
                ->where('id', '!=', $locked->getKey())
                ->where('effective_from', '<=', $effectiveTo)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $locked->effective_from->toDateString()))
                ->orderBy('id')
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw new DomainException(__('hr_shift_assignments.messages.overlap'));
            }

            $locked->update([
                'effective_to' => $effectiveTo,
                'updated_by' => $actor->getKey(),
            ]);

            return $locked->refresh();
        });
    }
}

<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Http\Requests\StoreShiftAssignmentRequest;
use Modules\HR\Http\Requests\UpdateShiftAssignmentRequest;
use Modules\HR\Models\HrEmployeeShiftAssignment;
use Modules\HR\Models\HrShift;
use Modules\HR\Services\HrLifecycleAuditLogger;
use Modules\HR\Services\HrShiftAssignmentService;

class HrShiftAssignmentController extends Controller
{
    public function __construct(
        private readonly HrShiftAssignmentService $assignments,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly HrLifecycleAuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $branchIds = $this->scope->hasUnrestrictedBranchAccess($request->user())
            ? null
            : $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->pluck('branches.id')->all();
        $assignments = HrEmployeeShiftAssignment::query()
            ->with(['employee:id,doc_num,full_name,company_id,branch_id', 'employee.branch:id,name,doc_num', 'shift:id,doc_num,name'])
            ->whereHas('employee', fn ($query) => $query
                ->where('company_id', $company->getKey())
                ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0])))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(30);

        return view('modules.hr.shift-assignments.index', [
            'assignments' => $assignments,
            'shifts' => HrShift::query()->where('status', 'active')->orderBy('name')->get(['doc_num', 'name']),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.shift-assignments.index'),
        ]);
    }

    public function store(StoreShiftAssignmentRequest $request): RedirectResponse
    {
        $companyId = $this->companies->requireCompanyId($request);

        try {
            DB::transaction(function () use ($request, $companyId): void {
                $assignments = $this->assignments->assign(
                    $companyId,
                    $request->validated('employee_ids'),
                    $request->validated('shift_doc_num'),
                    $request->validated('effective_from'),
                    $request->validated('effective_to'),
                    $request->user(),
                );

                foreach ($assignments as $assignment) {
                    $assignment->loadMissing(['employee:id,doc_num', 'shift:id,doc_num']);
                    $this->audit->logStrict(
                        $request,
                        'hr.shift_assignments.assign',
                        $companyId,
                        [
                            'assignment_id' => $assignment->getKey(),
                            'employee_doc_num' => $assignment->employee?->doc_num,
                            'shift_doc_num' => $assignment->shift?->doc_num,
                            'effective_from' => $assignment->effective_from?->toDateString(),
                            'effective_to' => $assignment->effective_to?->toDateString(),
                        ],
                        $assignment,
                        'shift-assignment:'.$assignment->getKey().':assigned',
                    );
                }
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['assignment' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_shift_assignments.messages.created'));
    }

    public function update(UpdateShiftAssignmentRequest $request, HrEmployeeShiftAssignment $assignment): RedirectResponse
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 404);
        $branchIds = $this->scope->hasUnrestrictedBranchAccess($request->user())
            ? null
            : $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->pluck('branches.id')->all();
        abort_unless(HrEmployeeShiftAssignment::query()
            ->whereKey($assignment->getKey())
            ->whereHas('employee', fn ($query) => $query
                ->where('company_id', $company->getKey())
                ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0])))
            ->exists(), 404);
        try {
            DB::transaction(function () use ($request, $company, $assignment): void {
                $oldEffectiveTo = $assignment->effective_to?->toDateString();
                $updatedAssignment = $this->assignments->updateEffectiveTo(
                    (int) $company->getKey(),
                    $assignment,
                    $request->validated('effective_to'),
                    $request->user(),
                );
                $updatedAssignment->loadMissing(['employee:id,doc_num', 'shift:id,doc_num']);
                $this->audit->logStrict(
                    $request,
                    'hr.shift_assignments.end',
                    (int) $company->getKey(),
                    [
                        'assignment_id' => $updatedAssignment->getKey(),
                        'employee_doc_num' => $updatedAssignment->employee?->doc_num,
                        'shift_doc_num' => $updatedAssignment->shift?->doc_num,
                        'effective_from' => $updatedAssignment->effective_from?->toDateString(),
                        'old_effective_to' => $oldEffectiveTo,
                        'new_effective_to' => $updatedAssignment->effective_to?->toDateString(),
                    ],
                    $updatedAssignment,
                    'shift-assignment:'.$updatedAssignment->getKey().':ended:'.$updatedAssignment->effective_to?->toDateString(),
                );
            });
        } catch (DomainException $exception) {
            return back()->withErrors(['assignment' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_shift_assignments.messages.updated'));
    }
}

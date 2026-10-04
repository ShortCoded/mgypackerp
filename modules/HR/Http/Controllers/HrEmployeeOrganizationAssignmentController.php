<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Select2ResponseService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Services\HrEmployeeOrganizationAssignmentService;
use Modules\HR\Services\HrLifecycleAuditLogger;

final class HrEmployeeOrganizationAssignmentController extends Controller
{
    public function __construct(
        private readonly HrEmployeeOrganizationAssignmentService $assignments,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly HrLifecycleAuditLogger $audit,
        private readonly Select2ResponseService $select2,
        private readonly DataTableSearchService $search,
    ) {}

    public function index(Request $request, HrEmployee $employee): View
    {
        $companyId = $this->scopedCompanyId($request, $employee);
        $assignments = DB::table('hr_employee_organization_assignments as assignment')
            ->join('branches as branch', 'branch.id', '=', 'assignment.branch_id')
            ->leftJoin('hr_departments as department', 'department.id', '=', 'assignment.department_id')
            ->leftJoin('cost_centers as cost_center', 'cost_center.id', '=', 'assignment.cost_center_id')
            ->where('assignment.company_id', $companyId)
            ->where('assignment.employee_id', $employee->getKey())
            ->select([
                'assignment.id', 'assignment.effective_from', 'assignment.effective_to',
                'assignment.source_type', 'assignment.reason', 'assignment.created_at',
                'branch.doc_num as branch_doc_num', 'branch.name as branch_name',
                'department.doc_num as department_doc_num', 'department.name as department_name',
                'cost_center.doc_num as cost_center_doc_num', 'cost_center.name as cost_center_name',
            ])
            ->orderByDesc('assignment.effective_from')
            ->paginate(30);

        return view('modules.hr.employees.organization-assignments', compact('employee', 'assignments'));
    }

    public function storeInitial(Request $request, HrEmployee $employee): RedirectResponse
    {
        $companyId = $this->scopedCompanyId($request, $employee);
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'cost_center_doc_num' => ['nullable', 'string', Rule::exists('cost_centers', 'doc_num')
                ->where('company_id', $companyId)->where('status', 'active')->where('is_group', false)->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $costCenterId = filled($data['cost_center_doc_num'] ?? null)
            ? CostCenter::query()->where('company_id', $companyId)->where('doc_num', $data['cost_center_doc_num'])->value('id')
            : null;

        try {
            DB::transaction(function () use ($request, $employee, $companyId, $data, $costCenterId): void {
                $assignment = $this->assignments->registerInitial(
                    $companyId, (int) $employee->getKey(), $data['effective_from'],
                    $costCenterId, $data['reason'], $request->user(),
                );
                $this->logAssignment($request, $employee, $companyId, $assignment);
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['assignment' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_organization_assignments.messages.saved'));
    }

    public function storeTransfer(Request $request, HrEmployee $employee): RedirectResponse
    {
        $companyId = $this->scopedCompanyId($request, $employee);
        $data = $request->validate([
            'branch_doc_num' => ['required', 'string', Rule::exists('branches', 'doc_num')
                ->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')],
            'department_doc_num' => ['nullable', 'string', Rule::exists('hr_departments', 'doc_num')
                ->where('status', 'active')->whereNull('deleted_at')],
            'cost_center_doc_num' => ['nullable', 'string', Rule::exists('cost_centers', 'doc_num')
                ->where('company_id', $companyId)->where('status', 'active')->where('is_group', false)->whereNull('deleted_at')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            DB::transaction(function () use ($request, $employee, $companyId, $data): void {
                $assignment = $this->assignments->transfer(
                    $companyId, (int) $employee->getKey(), $data['branch_doc_num'],
                    $data['department_doc_num'] ?? null, $data['cost_center_doc_num'] ?? null,
                    $data['effective_from'], $data['reason'], $request->user(),
                );
                $this->logAssignment($request, $employee, $companyId, $assignment);
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['assignment' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_organization_assignments.messages.saved'));
    }

    public function costCenters(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('hr.employees.edit'), 403);
        $companyId = $this->companies->requireCompanyId($request);
        $query = CostCenter::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_group', false)
            ->select(['id', 'doc_num', 'name', 'cost_center_code'])
            ->orderBy('name')
            ->orderBy('id');
        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['cost_centers.doc_num', 'cost_centers.name', 'cost_centers.cost_center_code']]);
        }

        return response()->json($this->select2->paginated($query, $request, fn (CostCenter $center): array => [
            'id' => (string) $center->doc_num,
            'text' => $center->name.' / '.$center->doc_num,
        ]));
    }

    private function scopedCompanyId(Request $request, HrEmployee $employee): int
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null && (int) $employee->company_id === (int) $company->getKey(), 404);
        abort_unless($employee->branch_id !== null
            && $this->scope->canAccessBranch($request->user(), $employee->branch, $company), 403);

        return (int) $company->getKey();
    }

    private function logAssignment(Request $request, HrEmployee $employee, int $companyId, object $assignment): void
    {
        $this->audit->logStrict(
            $request,
            'hr.employee_organization_assignment.'.$assignment->source_type,
            $companyId,
            [
                'assignment_id' => $assignment->id,
                'employee_doc_num' => $employee->doc_num,
                'branch_id' => $assignment->branch_id,
                'department_id' => $assignment->department_id,
                'cost_center_id' => $assignment->cost_center_id,
                'effective_from' => $assignment->effective_from,
                'reason' => $assignment->reason,
            ],
            $employee,
            'employee-organization-assignment:'.$assignment->id,
            redactSensitiveProperties: true,
        );
    }
}

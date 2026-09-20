<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Http\Requests\StorePayrollAttendancePolicyRequest;
use Modules\HR\Models\HrPayrollAttendancePolicy;
use Modules\HR\Services\PayrollAttendancePolicyService;

class PayrollAttendancePolicyController extends Controller
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly PayrollAttendancePolicyService $policies,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request): View
    {
        abort_unless((bool) $request->user()?->can('hr.payroll_attendance_policies.view'), 403);
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $unrestricted = $this->scope->hasUnrestrictedBranchAccess($request->user());
        $branchIds = $unrestricted ? null : $this->scope
            ->allowedBranchQuery($request->user(), [(string) $company->doc_num])
            ->pluck('branches.id')
            ->all();

        return view('modules.hr.payroll-attendance-policies.index', [
            'policies' => HrPayrollAttendancePolicy::query()
                ->with('branch:id,doc_num,name')
                ->where('company_id', $company->getKey())
                ->when(! $unrestricted, fn ($query) => $query->whereNotNull('branch_id')->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0]))
                ->orderByDesc('effective_from')
                ->paginate(30),
            'branches' => $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->get(['branches.doc_num', 'branches.name']),
            'payrollItems' => DB::table('hr_payroll_items')->where('item_kind', 'deduction')->where('status', 'active')->whereNull('deleted_at')->orderBy('name')->get(['code', 'name']),
            'canCreateCompanyPolicy' => $unrestricted,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.payroll-attendance-policies.index'),
        ]);
    }

    public function store(StorePayrollAttendancePolicyRequest $request): RedirectResponse
    {
        try {
            $policy = $this->policies->createVersion(
                $this->companies->requireCompanyId($request),
                $request->validated(),
                $request->user()->getKey(),
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['effective_from' => $exception->getMessage()]);
        }

        $this->activityLogger->log($request, 'hr', 'payroll_attendance_policy.create', 'success', [
            'subject' => $policy,
            'company_id' => $policy->company_id,
            'properties_only' => true,
            'properties' => [
                'branch_id' => $policy->branch_id,
                'effective_from' => $policy->effective_from?->toDateString(),
                'deduction_payroll_item_code' => $policy->deduction_payroll_item_code,
            ],
        ]);

        return back()->with('success', __('hr_payroll_policies.messages.created'));
    }
}

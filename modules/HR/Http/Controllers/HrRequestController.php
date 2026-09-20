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
use Modules\HR\Http\Requests\SelfService\ReviewEmployeeServiceRequest;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Services\HrEmployeeRequestService;
use Modules\HR\Services\HrLifecycleAuditLogger;

class HrRequestController extends Controller
{
    public function __construct(
        private readonly HrEmployeeRequestService $requests,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly HrLifecycleAuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $company = $this->companies->currentCompany($request);
        $companyId = $company?->getKey();
        $branchIds = $company === null
            ? []
            : $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->pluck('branches.id')->all();
        $unrestrictedBranches = $this->scope->hasUnrestrictedBranchAccess($request->user());
        $requests = HrEmployeeServiceRequest::query()
            ->with(['employee:id,doc_num,full_name', 'branch:id,doc_num,name', 'currency:id,code,name', 'resolvedBy:id,name'])
            ->when($companyId === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->when($companyId !== null && ! $unrestrictedBranches, fn ($query) => $query->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0]))
            ->when($request->filled('type'), fn ($query) => $query->where('request_type', $request->string('type')->trim()->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->trim()->toString()))
            ->latest('submitted_at')
            ->paginate(30)
            ->withQueryString();

        return view('modules.hr.requests.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.hr-requests.index'),
            'employeeRequests' => $requests,
            'requestTypes' => HrEmployeeServiceRequest::types(),
        ]);
    }

    public function review(ReviewEmployeeServiceRequest $request, HrEmployeeServiceRequest $employeeRequest): RedirectResponse
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null && (int) $employeeRequest->company_id === (int) $company->getKey(), 404);
        if ($employeeRequest->branch_id === null) {
            abort_unless($this->scope->hasUnrestrictedBranchAccess($request->user()), 404);
        } else {
            abort_unless($this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
                ->where('branches.id', $employeeRequest->branch_id)
                ->exists(), 404);
        }
        $previousStatus = $employeeRequest->status;

        try {
            DB::transaction(function () use ($request, $employeeRequest, $previousStatus): void {
                $reviewedRequest = $this->requests->review(
                    $employeeRequest,
                    $request->user(),
                    $request->validated('decision'),
                    $request->validated('resolution_notes'),
                );
                $reviewedRequest->loadMissing('employee:id,doc_num');
                $this->audit->logStrict(
                    $request,
                    $reviewedRequest->status === HrEmployeeServiceRequest::StatusApproved ? 'hr.requests.approve' : 'hr.requests.reject',
                    (int) $reviewedRequest->company_id,
                    [
                        'request_public_uuid' => $reviewedRequest->public_uuid,
                        'employee_doc_num' => $reviewedRequest->employee?->doc_num,
                        'request_type' => $reviewedRequest->request_type,
                        'previous_status' => $previousStatus,
                        'status' => $reviewedRequest->status,
                    ],
                    $reviewedRequest,
                    'employee-request:'.$reviewedRequest->getKey().':'.$reviewedRequest->status,
                );

                if ($reviewedRequest->status === HrEmployeeServiceRequest::StatusApproved
                    && $reviewedRequest->request_type === 'leave'
                    && filled(data_get($reviewedRequest->payload, 'leave_balance_ledger_id'))) {
                    $this->audit->logStrict(
                        $request,
                        'hr.leave_balances.consume',
                        (int) $reviewedRequest->company_id,
                        [
                            'request_public_uuid' => $reviewedRequest->public_uuid,
                            'employee_doc_num' => $reviewedRequest->employee?->doc_num,
                            'leave_balance_ledger_id' => (int) data_get($reviewedRequest->payload, 'leave_balance_ledger_id'),
                            'leave_type_id' => (int) data_get($reviewedRequest->payload, 'leave_type_id'),
                            'balance_year' => (int) data_get($reviewedRequest->payload, 'balance_year'),
                            'leave_days' => (float) data_get($reviewedRequest->payload, 'leave_days'),
                        ],
                        $reviewedRequest,
                        'leave-balance-ledger:'.data_get($reviewedRequest->payload, 'leave_balance_ledger_id'),
                    );
                }
            });
        } catch (DomainException $exception) {
            return back()->withErrors(['request' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_requests.messages.reviewed'));
    }
}

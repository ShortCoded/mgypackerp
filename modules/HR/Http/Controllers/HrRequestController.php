<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Http\Requests\SelfService\ReviewEmployeeServiceRequest;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Services\HrEmployeeRequestService;

class HrRequestController extends Controller
{
    public function __construct(
        private readonly HrEmployeeRequestService $requests,
        private readonly OperatingCompanyContextService $companies,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request): View
    {
        $companyId = $this->companies->currentCompanyId($request);
        $requests = HrEmployeeServiceRequest::query()
            ->with(['employee:id,doc_num,full_name', 'branch:id,doc_num,name', 'currency:id,code,name', 'resolvedBy:id,name'])
            ->when($companyId === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
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
        $companyId = $this->companies->currentCompanyId($request);
        abort_unless((int) $employeeRequest->company_id === (int) $companyId, 404);

        try {
            $this->requests->review(
                $employeeRequest,
                $request->user(),
                $request->validated('decision'),
                $request->validated('resolution_notes'),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['request' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_requests.messages.reviewed'));
    }
}

<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Currency;
use Modules\HR\Http\Requests\Attendance\StoreAttendancePunchRequest;
use Modules\HR\Http\Requests\SelfService\StoreEmployeeServiceRequest;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Services\HrAttendanceService;
use Modules\HR\Services\HrEmployeeRequestService;

class EmployeeSelfServiceController extends Controller
{
    public function __construct(
        private readonly HrAttendanceService $attendance,
        private readonly HrEmployeeRequestService $requests,
    ) {}

    public function index(Request $request): View
    {
        $attendance = $this->attendance->statusForUser($request->user());
        $employee = $request->user()->hrEmployee()->first();
        $requests = $employee === null
            ? collect()
            : HrEmployeeServiceRequest::query()
                ->with(['currency:id,code,name', 'resolvedBy:id,name'])
                ->where('employee_id', $employee->getKey())
                ->latest('submitted_at')
                ->limit(50)
                ->get();

        return view('modules.hr.self-service.index', [
            'attendance' => $attendance,
            'employeeRequests' => $requests,
            'requestTypes' => HrEmployeeServiceRequest::types(),
            'currencies' => Currency::query()
                ->where('company_id', $employee?->company_id ?? 0)
                ->orderBy('code')
                ->get(['doc_num', 'code', 'name']),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->attendance->statusForUser($request->user())]);
    }

    public function punch(StoreAttendancePunchRequest $request): JsonResponse
    {
        try {
            $status = $this->attendance->recordSelfServicePunch(
                $request->user(),
                $request->validated(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => __('hr_attendance.messages.punch_recorded'),
            'data' => $status,
        ]);
    }

    public function storeRequest(StoreEmployeeServiceRequest $request): RedirectResponse
    {
        try {
            $this->requests->createForUser($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['request' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_requests.messages.created'));
    }

    public function cancelRequest(Request $request, HrEmployeeServiceRequest $employeeRequest): RedirectResponse
    {
        try {
            $this->requests->cancelForUser($request->user(), $employeeRequest);
        } catch (DomainException $exception) {
            return back()->withErrors(['request' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_requests.messages.cancelled'));
    }
}

<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Currency;
use Modules\HR\Http\Requests\Attendance\StoreAttendancePunchRequest;
use Modules\HR\Http\Requests\SelfService\StoreEmployeeServiceRequest;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Services\HrAttendanceService;
use Modules\HR\Services\HrEmployeeRequestService;
use Modules\HR\Services\HrLifecycleAuditLogger;

class EmployeeSelfServiceController extends Controller
{
    public function __construct(
        private readonly HrAttendanceService $attendance,
        private readonly HrEmployeeRequestService $requests,
        private readonly HrLifecycleAuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $attendance = $this->attendance->statusForUser($request->user());
        $employee = $request->user()->hrEmployee()->with(['branch:id,name', 'departmentModel:id,name', 'job:id,name', 'defaultShift:id,name'])->first();
        $requests = $employee === null
            ? collect()
            : HrEmployeeServiceRequest::query()
                ->with(['currency:id,code,name', 'resolvedBy:id,name'])
                ->where('employee_id', $employee->getKey())
                ->latest('submitted_at')
                ->limit(50)
                ->get();
        $payslips = $employee === null
            ? collect()
            : DB::table('hr_payslips as payslip')
                ->join('hr_payroll_runs as run', 'run.id', '=', 'payslip.payroll_run_id')
                ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
                ->where('payslip.employee_id', $employee->getKey())
                ->whereIn('payslip.status', ['approved', 'posted'])
                ->whereNull('run.deleted_at')
                ->whereNull('period.deleted_at')
                ->latest('period.period_end')
                ->limit(24)
                ->get(['payslip.id', 'payslip.net_amount', 'payslip.status', 'period.period_start', 'period.period_end']);

        return view('modules.hr.self-service.index', [
            'attendance' => $attendance,
            'employeeRequests' => $requests,
            'payslips' => $payslips,
            'employee' => $employee,
            'selfServiceSummary' => [
                'pending_requests' => $requests->where('status', HrEmployeeServiceRequest::StatusSubmitted)->count(),
                'approved_requests' => $requests->where('status', HrEmployeeServiceRequest::StatusApproved)->count(),
                'payslips' => $payslips->count(),
                'latest_net' => $payslips->first()?->net_amount,
            ],
            'requestTypes' => HrEmployeeServiceRequest::types(),
            'leaveTypes' => $employee === null ? [] : $this->requests->leaveOptionsForEmployee($employee),
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
            DB::transaction(function () use ($request): void {
                $employeeRequest = $this->requests->createForUser($request->user(), $request->validated());
                $employeeRequest->loadMissing('employee:id,doc_num');
                $this->audit->logStrict(
                    $request,
                    'hr.requests.submit',
                    (int) $employeeRequest->company_id,
                    [
                        'request_public_uuid' => $employeeRequest->public_uuid,
                        'employee_doc_num' => $employeeRequest->employee?->doc_num,
                        'request_type' => $employeeRequest->request_type,
                        'status' => $employeeRequest->status,
                    ],
                    $employeeRequest,
                    'employee-request:'.$employeeRequest->getKey().':submitted',
                );
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['request' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_requests.messages.created'));
    }

    public function cancelRequest(Request $request, HrEmployeeServiceRequest $employeeRequest): RedirectResponse
    {
        $previousStatus = $employeeRequest->status;

        try {
            DB::transaction(function () use ($request, $employeeRequest, $previousStatus): void {
                $cancelledRequest = $this->requests->cancelForUser($request->user(), $employeeRequest);
                $cancelledRequest->loadMissing('employee:id,doc_num');
                $this->audit->logStrict(
                    $request,
                    'hr.requests.cancel',
                    (int) $cancelledRequest->company_id,
                    [
                        'request_public_uuid' => $cancelledRequest->public_uuid,
                        'employee_doc_num' => $cancelledRequest->employee?->doc_num,
                        'request_type' => $cancelledRequest->request_type,
                        'previous_status' => $previousStatus,
                        'status' => $cancelledRequest->status,
                    ],
                    $cancelledRequest,
                    'employee-request:'.$cancelledRequest->getKey().':cancelled',
                );
            });
        } catch (DomainException $exception) {
            return back()->withErrors(['request' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_requests.messages.cancelled'));
    }
}

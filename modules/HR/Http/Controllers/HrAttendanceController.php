<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Http\Requests\Attendance\HrAttendanceReportRequest;
use Modules\HR\Http\Requests\Attendance\StoreManualAttendanceEventRequest;
use Modules\HR\Models\HrAttendanceEvent;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Services\HrAttendanceReportService;
use Modules\HR\Services\HrAttendanceService;
use Modules\HR\Services\HrLifecycleAuditLogger;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrAttendanceController extends Controller
{
    public function __construct(
        private readonly HrAttendanceService $attendance,
        private readonly HrAttendanceReportService $reports,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly HrLifecycleAuditLogger $audit,
    ) {}

    public function index(HrAttendanceReportRequest $request): View
    {
        $company = $this->companies->currentCompany($request);
        $effectiveCompanyId = $company?->getKey() ?? 0;
        $branchIds = $company === null || $this->scope->hasUnrestrictedBranchAccess($request->user())
            ? null
            : $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->pluck('branches.id')->all();
        $filters = $request->filters();
        $employees = HrEmployee::query()
            ->active()
            ->where('company_id', $effectiveCompanyId)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0]))
            ->orderBy('full_name')
            ->get(['doc_num', 'full_name']);
        $branches = $company === null
            ? Branch::query()->whereRaw('1 = 0')->get(['doc_num', 'name'])
            : ($branchIds === null
                ? Branch::query()->where('company_id', $company->getKey())->orderBy('name')->get(['doc_num', 'name'])
                : $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->get(['doc_num', 'name']));

        return view('modules.hr.attendance.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.employee-attendance.index'),
            'sessions' => $this->reports->paginate($effectiveCompanyId, $filters, $branchIds),
            'summary' => $this->reports->summary($effectiveCompanyId, $filters, $branchIds),
            'filters' => $filters,
            'employees' => $employees,
            'branches' => $branches,
            'manualIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function exportCsv(HrAttendanceReportRequest $request): StreamedResponse
    {
        $company = $this->companies->currentCompany($request);
        abort_if($company === null, 404);
        $branchIds = $this->scope->hasUnrestrictedBranchAccess($request->user())
            ? null
            : $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->pluck('branches.id')->all();

        return $this->reports->exportCsv((int) $company->getKey(), $request->filters(), $branchIds);
    }

    public function storeManual(StoreManualAttendanceEventRequest $request): RedirectResponse
    {
        $company = $this->companies->currentCompany($request);
        abort_if($company === null, 404);
        $branchIds = $this->scope->hasUnrestrictedBranchAccess($request->user())
            ? null
            : $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->pluck('branches.id')->all();
        $employee = HrEmployee::query()
            ->where('company_id', $company->getKey())
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0]))
            ->where('doc_num', $request->validated('employee_doc_num'))
            ->firstOrFail();
        $idempotencyKey = (string) $request->validated('idempotency_key');

        try {
            DB::transaction(function () use ($request, $company, $employee, $idempotencyKey): void {
                $previousEvent = HrAttendanceEvent::query()
                    ->where('employee_id', $employee->getKey())
                    ->where('idempotency_key', '!=', $idempotencyKey)
                    ->latest('occurred_at')
                    ->latest('id')
                    ->first();

                $this->attendance->recordManualPunch($employee, $request->user(), $request->validated());
                $event = HrAttendanceEvent::query()
                    ->where('employee_id', $employee->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();

                $this->audit->logStrict(
                    $request,
                    'hr.attendance.manual_correction',
                    (int) $company->getKey(),
                    [
                        'attendance_event_public_uuid' => $event->public_uuid,
                        'employee_doc_num' => $employee->doc_num,
                        'session_id' => $event->session_id,
                        'previous_event_type' => $previousEvent?->event_type,
                        'previous_occurred_at' => $previousEvent?->occurred_at?->toJSON(),
                        'event_type' => $event->event_type,
                        'occurred_at' => $event->occurred_at?->toJSON(),
                        'source' => $event->source,
                        'has_correction_reason' => filled($request->validated('notes')),
                    ],
                    $event,
                    'attendance-event:'.$event->getKey().':manual-correction',
                );
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['attendance' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_attendance.messages.manual_recorded'));
    }
}

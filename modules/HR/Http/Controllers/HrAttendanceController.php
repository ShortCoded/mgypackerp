<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Http\Requests\Attendance\HrAttendanceReportRequest;
use Modules\HR\Http\Requests\Attendance\StoreManualAttendanceEventRequest;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Services\HrAttendanceReportService;
use Modules\HR\Services\HrAttendanceService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrAttendanceController extends Controller
{
    public function __construct(
        private readonly HrAttendanceService $attendance,
        private readonly HrAttendanceReportService $reports,
        private readonly OperatingCompanyContextService $companies,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(HrAttendanceReportRequest $request): View
    {
        $companyId = $this->companies->currentCompanyId($request);
        $effectiveCompanyId = $companyId ?? 0;
        $filters = $request->filters();
        $employees = HrEmployee::query()
            ->active()
            ->where('company_id', $effectiveCompanyId)
            ->orderBy('full_name')
            ->get(['doc_num', 'full_name']);
        $branches = Branch::query()
            ->where('company_id', $effectiveCompanyId)
            ->orderBy('name')
            ->get(['doc_num', 'name']);

        return view('modules.hr.attendance.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.employee-attendance.index'),
            'sessions' => $this->reports->paginate($effectiveCompanyId, $filters),
            'summary' => $this->reports->summary($effectiveCompanyId, $filters),
            'filters' => $filters,
            'employees' => $employees,
            'branches' => $branches,
            'manualIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function exportCsv(HrAttendanceReportRequest $request): StreamedResponse
    {
        $companyId = $this->companies->currentCompanyId($request);
        abort_if($companyId === null, 404);

        return $this->reports->exportCsv($companyId, $request->filters());
    }

    public function storeManual(StoreManualAttendanceEventRequest $request): RedirectResponse
    {
        $companyId = $this->companies->currentCompanyId($request);
        $employee = HrEmployee::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $request->validated('employee_doc_num'))
            ->firstOrFail();

        try {
            $this->attendance->recordManualPunch($employee, $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['attendance' => $exception->getMessage()]);
        }

        return back()->with('success', __('hr_attendance.messages.manual_recorded'));
    }
}

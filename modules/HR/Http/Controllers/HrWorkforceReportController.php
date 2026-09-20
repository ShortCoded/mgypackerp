<?php

namespace Modules\HR\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\HR\Exports\HrWorkforceReportExport;
use Modules\HR\Http\Requests\EmployeeReportRequest;
use Modules\HR\Http\Requests\LeaveRequestReportRequest;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Services\HrWorkforceReportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HrWorkforceReportController extends Controller
{
    public function __construct(
        private readonly HrWorkforceReportService $reports,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function employees(EmployeeReportRequest $request): View
    {
        $company = $this->companies->currentCompany($request);
        abort_if($company === null, 409);
        $filters = $request->filters();

        return view('modules.hr.workforce-reports.employees', [
            'report' => $this->reports->employees((int) $company->getKey(), $request->user(), $filters),
            'filters' => $filters,
            ...$this->filterOptions($request->user(), (string) $company->doc_num),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.reports.employees'),
        ]);
    }

    public function leaveRequests(LeaveRequestReportRequest $request): View
    {
        $company = $this->companies->currentCompany($request);
        abort_if($company === null, 409);
        $filters = $request->filters();

        return view('modules.hr.workforce-reports.leave-requests', [
            'report' => $this->reports->leaveRequests((int) $company->getKey(), $request->user(), $filters),
            'filters' => $filters,
            'branches' => $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->get(['branches.doc_num', 'branches.name']),
            'departments' => $this->activeFoundation('hr_departments'),
            'leaveTypes' => DB::table('hr_leave_types')->whereNull('deleted_at')->orderBy('name')->get(['code', 'name']),
            'approvers' => $this->reports->approvers((int) $company->getKey(), $request->user()),
            'requestTypes' => HrEmployeeServiceRequest::types(),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.reports.leave-requests'),
        ]);
    }

    public function exportEmployees(EmployeeReportRequest $request, ReportPdfService $pdf): BinaryFileResponse|Response
    {
        $company = $this->companies->currentCompany($request);
        abort_if($company === null, 409);
        $filters = $request->filters();
        $headings = $this->headings('employees');
        $rows = $this->reports->employeeRows((int) $company->getKey(), $request->user(), $filters)
            ->map(fn (object $row): array => $this->employeeRow($row))
            ->all();

        return $this->export($request->validated('format'), $pdf, 'employees', $headings, $rows, $filters);
    }

    public function exportLeaveRequests(LeaveRequestReportRequest $request, ReportPdfService $pdf): BinaryFileResponse|Response
    {
        $company = $this->companies->currentCompany($request);
        abort_if($company === null, 409);
        $filters = $request->filters();
        $headings = $this->headings('leave_requests');
        $rows = $this->reports->leaveRequestRows((int) $company->getKey(), $request->user(), $filters);
        $exportRows = $rows->map(fn (object $row): array => $this->leaveRequestRow($row))->all();
        $exportRows = [...$exportRows, ...$this->leaveTotalRows($rows)];

        return $this->export($request->validated('format'), $pdf, 'leave_requests', $headings, $exportRows, $filters);
    }

    /** @return array<string, Collection<int, object>> */
    private function filterOptions(User $user, string $companyDocNum): array
    {
        return [
            'branches' => $this->scope->allowedBranchQuery($user, [$companyDocNum])->get(['branches.doc_num', 'branches.name']),
            'departments' => $this->activeFoundation('hr_departments'),
            'sections' => $this->activeFoundation('hr_sections'),
            'jobs' => $this->activeFoundation('hr_jobs'),
            'employmentTypes' => $this->activeFoundation('hr_employment_types'),
        ];
    }

    /** @return Collection<int, object> */
    private function activeFoundation(string $table): Collection
    {
        return DB::table($table)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['doc_num', 'name']);
    }

    /** @return list<string> */
    private function headings(string $type): array
    {
        $keys = $type === 'employees'
            ? ['employee_code', 'employee', 'branch', 'department', 'section', 'job', 'employment_type', 'hire_date', 'gender', 'status']
            : ['document_number', 'employee', 'request_type', 'leave_type', 'from', 'to', 'days_duration', 'balance_impact', 'status', 'approver', 'approval_date'];

        return array_map(fn (string $key): string => __('hr_workforce_reports.columns.'.$key), $keys);
    }

    /** @return list<mixed> */
    private function employeeRow(object $row): array
    {
        return [
            $row->employee_code ?: $row->doc_num,
            $row->full_name,
            $row->branch_name,
            $row->department_name,
            $row->section_name,
            $row->job_name,
            $row->employment_type_name,
            $row->hire_date,
            $row->gender ? __('hr.employees.genders.'.$row->gender) : '',
            __('hr.employees.statuses.'.$row->status),
        ];
    }

    /** @return list<mixed> */
    private function leaveRequestRow(object $row): array
    {
        return [
            $row->public_uuid,
            $row->employee_name,
            __('hr_requests.types.'.$row->request_type),
            $row->leave_type_name,
            $row->requested_from,
            $row->requested_to,
            $row->request_type === 'leave'
                ? $row->leave_days
                : ($row->requested_minutes === null ? '' : __('hr_workforce_reports.units.minutes_value', ['value' => $row->requested_minutes])),
            $row->balance_impact,
            __('hr_requests.statuses.'.$row->status),
            $row->approver_name,
            $row->resolved_at,
        ];
    }

    /** @param Collection<int, object> $rows @return list<list<mixed>> */
    private function leaveTotalRows(Collection $rows): array
    {
        $leaveRows = $rows->where('request_type', 'leave')->where('status', 'approved');

        return [
            [__('hr_workforce_reports.totals.request_count'), $rows->count(), '', '', '', '', '', '', '', '', ''],
            [__('hr_workforce_reports.totals.leave_days'), (float) $leaveRows->sum('leave_days'), '', '', '', '', '', '', '', '', ''],
            [__('hr_workforce_reports.totals.paid_leave_days'), (float) $leaveRows->where('leave_is_paid', true)->sum('leave_days'), '', '', '', '', '', '', '', '', ''],
            [__('hr_workforce_reports.totals.unpaid_leave_days'), (float) $leaveRows->where('leave_is_paid', false)->sum('leave_days'), '', '', '', '', '', '', '', '', ''],
        ];
    }

    /** @param list<string> $headings @param list<list<mixed>> $rows @param array<string, mixed> $filters */
    private function export(mixed $format, ReportPdfService $pdf, string $type, array $headings, array $rows, array $filters): BinaryFileResponse|Response
    {
        abort_unless(is_string($format), 422);
        $filename = $type === 'employees' ? 'employee-report' : 'leave-request-report';

        if ($format === 'pdf') {
            return $pdf->stream('reports.hr.workforce', [
                'title' => __('hr_workforce_reports.'.$type.'.title'),
                'headings' => $headings,
                'rows' => $rows,
                'filters' => $filters,
            ], $filename.'.pdf', 'L');
        }

        return Excel::download(
            new HrWorkforceReportExport($headings, $rows),
            $filename.'.'.$format,
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }
}

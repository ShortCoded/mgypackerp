<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Auth\DataTables\ActivityLogsReportDataTable;
use Modules\Auth\Exports\ActivityLogsExport;
use Modules\Auth\Services\Reports\ActivityLogReport;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ActivityLogReportController extends Controller
{
    public function __construct(
        private readonly ActivityLogReport $report,
    ) {}

    public function index(): View
    {
        return view('modules.auth.activity-logs.index');
    }

    public function data(Request $request, ActivityLogsReportDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function filterUsers(Request $request): JsonResponse
    {
        return response()->json($this->report->userOptions($request));
    }

    public function filterCompanies(Request $request): JsonResponse
    {
        return response()->json($this->report->companyOptions($request));
    }

    public function filterAreas(Request $request): JsonResponse
    {
        return response()->json($this->report->areaOptions($request));
    }

    public function filterActions(Request $request): JsonResponse
    {
        return response()->json($this->report->actionOptions($request));
    }

    public function filterStatuses(Request $request): JsonResponse
    {
        return response()->json($this->report->statusOptions($request));
    }

    public function details(string $activityLog): JsonResponse
    {
        $activity = $this->report->query()
            ->where(config('activitylog.table_name', 'activity_log').'.public_id', $activityLog)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'title' => __('activity_logs.details_title'),
            'data' => $this->report->details($activity),
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new ActivityLogsExport($this->report, $this->report->filtersFromRequest($request)),
            'activity-logs.xlsx'
        );
    }

    public function exportCsv(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new ActivityLogsExport($this->report, $this->report->filtersFromRequest($request)),
            'activity-logs.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function exportPdf(Request $request, ReportPdfService $pdf): Response
    {
        $rows = $this->report->query($this->report->filtersFromRequest($request))
            ->latest(config('activitylog.table_name', 'activity_log').'.created_at')
            ->limit(500)
            ->get();

        return $pdf->stream('reports.activity-logs', [
            'title' => __('activity_logs.report_title'),
            'headings' => $this->report->headings(),
            'rows' => $rows->map(fn ($row): array => $this->report->pdfMap($row))->all(),
            'limited' => $rows->count() === 500,
        ], 'activity-logs-report.pdf');
    }
}

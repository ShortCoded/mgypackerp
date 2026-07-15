<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Auth\DataTables\AuthLogsReportDataTable;
use Modules\Auth\Exports\AuthLogsExport;
use Modules\Auth\Services\Reports\AuthLogReport;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AuthLogReportController extends Controller
{
    public function __construct(
        private readonly AuthLogReport $report,
    ) {}

    public function index(): View
    {
        return view('modules.auth.auth-logs.index');
    }

    public function data(Request $request, AuthLogsReportDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function filterUsers(Request $request): JsonResponse
    {
        return response()->json($this->report->userOptions($request));
    }

    public function filterEvents(Request $request): JsonResponse
    {
        return response()->json($this->report->eventOptions($request));
    }

    public function filterStatuses(Request $request): JsonResponse
    {
        return response()->json($this->report->statusOptions($request));
    }

    public function filterFailureReasons(Request $request): JsonResponse
    {
        return response()->json($this->report->failureReasonOptions($request));
    }

    public function filterDevices(Request $request): JsonResponse
    {
        return response()->json($this->report->deviceOptions($request));
    }

    public function filterBrowsers(Request $request): JsonResponse
    {
        return response()->json($this->report->browserOptions($request));
    }

    public function filterOperatingSystems(Request $request): JsonResponse
    {
        return response()->json($this->report->osOptions($request));
    }

    public function filterCountries(Request $request): JsonResponse
    {
        return response()->json($this->report->countryOptions($request));
    }

    public function filterCities(Request $request): JsonResponse
    {
        return response()->json($this->report->cityOptions($request));
    }

    public function filterGuards(Request $request): JsonResponse
    {
        return response()->json($this->report->guardOptions($request));
    }

    public function details(string $authLog): JsonResponse
    {
        $log = $this->report->query()
            ->where('auth_logs.public_id', $authLog)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'title' => __('auth_logs.details_title'),
            'data' => $this->report->details($log),
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new AuthLogsExport($this->report, $this->report->filtersFromRequest($request)),
            'auth-logs.xlsx'
        );
    }

    public function exportCsv(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new AuthLogsExport($this->report, $this->report->filtersFromRequest($request)),
            'auth-logs.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function exportPdf(Request $request, ReportPdfService $pdf): Response
    {
        $rows = $this->report->query($this->report->filtersFromRequest($request))
            ->latest('auth_logs.created_at')
            ->limit(500)
            ->get();

        return $pdf->stream('reports.auth-logs', [
            'title' => __('auth_logs.report_title'),
            'headings' => $this->report->headings(),
            'rows' => $rows->map(fn ($row): array => $this->report->map($row))->all(),
            'limited' => $rows->count() === 500,
        ], 'auth-logs-report.pdf');
    }
}

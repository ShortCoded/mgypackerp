<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\DataTables\BusinessPartnerDataReportDataTable;
use Modules\Core\Exports\BusinessPartnerDataReportExport;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\Reports\BusinessPartnerDataReport;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class BusinessPartnerDataReportController extends Controller
{
    public function __construct(
        protected readonly BusinessPartnerDataReport $report,
        private readonly BusinessPartnerDataReportDataTable $dataTable,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(): View
    {
        return view('modules.core.reports.business-partners.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($this->report->routeName('index')),
            'report' => $this->report->pageData(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return $this->dataTable->json($request);
    }

    public function filterAccounts(Request $request): JsonResponse
    {
        return response()->json($this->report->accountOptions($request));
    }

    public function filterAccountGroups(Request $request): JsonResponse
    {
        return response()->json($this->report->accountGroupOptions($request));
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new BusinessPartnerDataReportExport($this->report, $this->report->filtersFromRequest($request)),
            $this->report->filename('xlsx'),
        );
    }

    public function exportCsv(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new BusinessPartnerDataReportExport($this->report, $this->report->filtersFromRequest($request)),
            $this->report->filename('csv'),
            ExcelFormat::CSV,
        );
    }

    public function exportPdf(Request $request, ReportPdfService $pdf): Response
    {
        $filters = $this->report->filtersFromRequest($request);
        $result = $this->report->pdfResult($filters);

        return $pdf->stream('reports.business-partner-data', [
            'title' => $this->report->reportTitle(),
            'companyName' => $this->report->companyName(),
            'headings' => $this->report->pdfHeadings(),
            'rows' => $result['rows']->map(fn ($row): array => $this->report->pdfMap($row))->all(),
            'filters' => $this->report->filterSummary($filters),
            'limited' => $result['limited'],
        ], $this->report->filename('pdf'), 'L');
    }
}

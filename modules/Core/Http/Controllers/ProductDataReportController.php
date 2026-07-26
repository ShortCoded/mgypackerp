<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\DataTables\ProductDataReportDataTable;
use Modules\Core\Exports\ProductDataReportExport;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\Reports\ProductDataReport;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ProductDataReportController extends Controller
{
    public function __construct(
        private readonly ProductDataReport $report,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(): View
    {
        return view('modules.core.reports.products-data.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.reports.products-data.index'),
            'classificationOptions' => $this->report->classificationOptions(),
        ]);
    }

    public function data(Request $request, ProductDataReportDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function filterProducts(Request $request): JsonResponse
    {
        return response()->json($this->report->productOptions($request));
    }

    public function filterComponents(Request $request): JsonResponse
    {
        return response()->json($this->report->componentOptions($request));
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $filters = $this->report->filtersFromRequest($request);

        return Excel::download(
            new ProductDataReportExport($this->report, $filters),
            'products-data-report.xlsx'
        );
    }

    public function exportCsv(Request $request): BinaryFileResponse
    {
        $filters = $this->report->filtersFromRequest($request);

        return Excel::download(
            new ProductDataReportExport($this->report, $filters, forCsv: true),
            'products-data-report.csv',
            ExcelFormat::CSV,
        );
    }

    public function exportPdf(Request $request, ReportPdfService $pdf): Response
    {
        $filters = $this->report->filtersFromRequest($request);

        return $pdf->stream('reports.products-data', [
            'title' => __('product_data_report.report_title'),
            'headings' => $this->report->headings($filters),
            'rows' => $this->report->rows($filters)->map(fn ($row): array => $this->report->map($row, $filters))->all(),
            'filters' => $this->report->filterSummary($filters),
            'mode' => $this->report->mode($filters),
        ], 'products-data-report.pdf', 'L');
    }
}

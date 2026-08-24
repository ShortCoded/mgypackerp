<?php

namespace Modules\FixedAssets\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Company;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\FixedAssets\Exports\FixedAssetReportExport;
use Modules\FixedAssets\Services\FixedAssetReportService;
use Modules\FixedAssets\Services\FixedAssetPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class FixedAssetReportController extends Controller
{
    public function __construct(
        private readonly FixedAssetReportService $reports,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->reports->filters($request);

        return view('modules.fixed-assets.reports.index', [
            'report' => $this->reports->report($filters),
            'filters' => $filters,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.fixed-assets.reports.index'),
        ]);
    }

    public function print(Request $request, FixedAssetPdfService $pdf): Response
    {
        return $this->streamPdf($request, $pdf);
    }

    public function excel(Request $request): BinaryFileResponse
    {
        $report = $this->reports->report($this->reports->filters($request));

        return Excel::download(new FixedAssetReportExport($report), 'fixed-assets-'.$report['type'].'.xlsx');
    }

    public function pdf(Request $request, FixedAssetPdfService $pdf): Response
    {
        return $this->streamPdf($request, $pdf);
    }

    private function streamPdf(Request $request, FixedAssetPdfService $pdf): Response
    {
        $report = $this->reports->report($this->reports->filters($request));
        $company = Company::query()->findOrFail($this->companies->requireCompanyId());

        return $pdf->stream('reports.fixed-assets', $company, [
            'title' => $report['title'],
            'report' => $report,
        ], 'fixed-assets-'.$report['type'].'.pdf', 'L');
    }
}

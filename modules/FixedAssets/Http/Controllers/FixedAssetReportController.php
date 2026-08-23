<?php

namespace Modules\FixedAssets\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Company;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\FixedAssets\Exports\FixedAssetReportExport;
use Modules\FixedAssets\Services\FixedAssetReportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class FixedAssetReportController extends Controller
{
    public function __construct(
        private readonly FixedAssetReportService $reports,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly CompanyPrintIdentityService $printIdentities,
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

    public function print(Request $request): View
    {
        $company = Company::query()->findOrFail($this->companies->requireCompanyId());

        return view('modules.fixed-assets.reports.print', ['report' => $this->reports->report($this->reports->filters($request)), 'companyPrintIdentity' => $this->printIdentities->forCompany($company)]);
    }

    public function excel(Request $request): BinaryFileResponse
    {
        $report = $this->reports->report($this->reports->filters($request));

        return Excel::download(new FixedAssetReportExport($report), 'fixed-assets-'.$report['type'].'.xlsx');
    }

    public function pdf(Request $request, ReportPdfService $pdf): Response
    {
        $report = $this->reports->report($this->reports->filters($request));
        $company = Company::query()->findOrFail($this->companies->requireCompanyId());

        return $pdf->stream('reports.fixed-assets', ['title' => $report['title'], 'report' => $report, 'companyPrintIdentity' => $this->printIdentities->forCompany($company)], 'fixed-assets-'.$report['type'].'.pdf', 'L');
    }
}

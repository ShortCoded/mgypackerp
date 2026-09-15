<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Production\Exports\ProductionReportExport;
use Modules\Production\Services\ProductionReportService;
use Modules\Sales\Services\SalesCycleReadService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductionReportController extends Controller
{
    /** @var list<string> */
    private const Sections = ['overview', 'orders', 'runs', 'materials', 'quality', 'receipts'];

    public function __construct(
        private readonly OperatingContextService $context,
        private readonly ProductionReportService $reports,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
        private readonly SalesCycleReadService $salesCycle,
        private readonly NumericFormatService $numbers,
        private readonly DateFormatService $dates,
    ) {}

    public function index(Request $request): View
    {
        [, $report] = $this->report($request);
        $section = $this->section($request);

        return view('modules.production.reports.index', [
            ...$report,
            'section' => $section,
            'numbers' => $this->numbers,
            'dates' => $this->dates,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [, $report] = $this->report($request);
        $section = $this->section($request);

        return Excel::download(
            new ProductionReportExport($report, $section),
            'production-'.$section.'-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function print(Request $request): Response
    {
        [$context, $report] = $this->report($request);
        $section = $this->section($request);
        $company = Company::query()->findOrFail($context['company_id']);

        return $this->pdf->stream('reports.production.operations', [
            ...$report,
            'section' => $section,
            'numbers' => $this->numbers,
            'dates' => $this->dates,
            'title' => __('production_execution.reports.sections.'.$section),
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
        ], 'production-'.$section.'-report.pdf');
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function report(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('production_execution.messages.operating_context_required'));
        $report = $this->reports->report(
            $context['company_id'],
            $context['financial_period_id'],
            $context['branch_id'],
            [
                ...$request->only(['status', 'from', 'to']),
                'production_run_id' => $request->integer('production_run_id') ?: null,
            ],
            false,
        );

        $report['backorders'] = $this->salesCycle->backorders((int) $context['company_id'], (int) $context['branch_id']);

        return [$context, $report];
    }

    private function section(Request $request): string
    {
        $section = (string) ($request->route('section') ?: $request->query('section', 'overview'));

        abort_unless(in_array($section, self::Sections, true), 404);

        return $section;
    }
}

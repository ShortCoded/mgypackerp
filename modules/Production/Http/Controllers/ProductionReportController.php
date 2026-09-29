<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Branch;
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
    private const Sections = ['overview', 'orders', 'runs', 'materials', 'quality', 'receipts', 'control'];

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
        $section = $this->section($request);
        $this->authorizeSection($request, $section, 'view');
        [, $report] = $this->report($request, $section);

        return view('modules.production.reports.index', [
            ...$report,
            'section' => $section,
            'numbers' => $this->numbers,
            'dates' => $this->dates,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $section = $this->section($request);
        $this->authorizeSection($request, $section, 'export');
        [, $report] = $this->report($request, $section);

        return Excel::download(
            new ProductionReportExport($report, $section),
            'production-'.$section.'-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function print(Request $request): Response
    {
        $section = $this->section($request);
        $this->authorizeSection($request, $section, 'print');
        [$context, $report] = $this->report($request, $section);
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
    private function report(Request $request, string $section): array
    {
        $context = $this->context->snapshot($request);
        if ($section === 'control') {
            abort_unless($context['company_id'] && $context['financial_period_id'], 422, __('production_execution.messages.operating_context_required'));
            $filters = $request->validate([
                'branch_id' => ['nullable', 'integer'],
                'branch_doc_num' => ['nullable', 'string', 'max:50'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date', 'after_or_equal:from'],
                'status' => ['nullable', 'string', 'max:40'],
                'product' => ['nullable', 'string', 'max:100'],
                'machine' => ['nullable', 'string', 'max:100'],
                'shift' => ['nullable', 'string', 'max:100'],
                'stage' => ['nullable', 'string', 'max:100'],
                'order' => ['nullable', 'string', 'max:100'],
            ]);
            $branches = $this->context->allowedBranchQueryForCurrentCompany($request)
                ->where('branches.type', Branch::TypeFactory)
                ->orderBy('branches.name')
                ->get();
            $branchIds = $branches->modelKeys();
            abort_if(filled($filters['branch_id'] ?? null) && ! in_array((int) $filters['branch_id'], $branchIds, true), 403);
            $selectedBranch = filled($filters['branch_doc_num'] ?? null)
                ? $branches->firstWhere('doc_num', $filters['branch_doc_num'])
                : (filled($filters['branch_id'] ?? null) ? $branches->firstWhere('id', (int) $filters['branch_id']) : null);
            abort_if(filled($filters['branch_doc_num'] ?? null) && ! $selectedBranch, 403);
            abort_if($selectedBranch && filled($filters['branch_id'] ?? null) && (int) $selectedBranch->getKey() !== (int) $filters['branch_id'], 403);
            $filters['branch_id'] = $selectedBranch?->getKey();
            $filters['branch_doc_num'] = $selectedBranch?->doc_num;

            return [$context, [
                ...$this->reports->controlReport((int) $context['company_id'], (int) $context['financial_period_id'], $branchIds, $filters),
                'controlBranches' => $branches,
                'controlFilters' => $filters,
                'controlCompanyDocNum' => $context['company_doc_num'],
            ]];
        }

        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('production_execution.messages.operating_context_required'));
        $report = $this->reports->report(
            $context['company_id'],
            $context['financial_period_id'],
            $context['branch_id'],
            [
                ...$request->only(['status', 'from', 'to', 'operational_focus']),
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

    private function authorizeSection(Request $request, string $section, string $action): void
    {
        $permission = 'production.reports.'.$section;

        abort_unless($request->user()?->can("{$permission}.view"), 403);

        if ($action !== 'view') {
            abort_unless($request->user()?->can("{$permission}.{$action}"), 403);
        }
    }
}

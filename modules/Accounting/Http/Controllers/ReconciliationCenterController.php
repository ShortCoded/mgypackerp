<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Exports\ReconciliationCenterExport;
use Modules\Accounting\Services\ReconciliationCenterService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ReconciliationCenterController extends Controller
{
    public function __construct(
        private readonly ReconciliationCenterService $reconciliations,
        private readonly OperatingContextService $operatingContext,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly NumericFormatService $numbers,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request): View
    {
        [$context, $period, $branch, $filters, $report] = $this->report($request, false);

        return view('modules.accounting.reports.reconciliation-center', [
            'report' => $report,
            'filters' => $filters,
            'period' => $period,
            'selectedBranch' => $branch,
            'branches' => $this->branches($request),
            'types' => ReconciliationCenterService::types(),
            'numbers' => $this->numbers,
            'operatingContext' => $this->operatingContext->current($request),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.accounting.reports.reconciliation-center'),
            'companyId' => $context['company_id'],
        ]);
    }

    public function export(Request $request): BinaryFileResponse|Response
    {
        [$context, , , , $report] = $this->report($request, true);
        $format = (string) $request->route('reconciliation_export_format');

        if ($format === 'pdf') {
            $company = Company::query()->findOrFail($context['company_id']);

            return $this->pdf->stream('reports.reconciliation-center', [
                'title' => __('reconciliation_center.title'),
                'report' => $report,
                'companyPrintIdentity' => $this->printIdentity->forCompany($company),
            ], 'reconciliation-center.pdf', 'L');
        }

        $writer = $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
        $extension = $format === 'csv' ? 'csv' : 'xlsx';

        return Excel::download(new ReconciliationCenterExport($report), "reconciliation-center.{$extension}", $writer);
    }

    /** @return array{0: array<string, mixed>, 1: FinancialPeriod, 2: Branch, 3: array<string, mixed>, 4: ?array<string, mixed>} */
    private function report(Request $request, bool $required): array
    {
        $context = $this->operatingContext->snapshot($request);
        abort_unless(
            $context['company_id'] && $context['financial_period_id'] && $context['branch_id'],
            422,
            __('reconciliation_center.errors.context_required'),
        );

        $period = FinancialPeriod::query()
            ->where('company_id', $context['company_id'])
            ->findOrFail($context['financial_period_id']);
        $validated = $request->validate([
            'run' => ['nullable', 'boolean'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'branch_doc_num' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(ReconciliationCenterService::types())],
        ]);
        $branch = filled($validated['branch_doc_num'] ?? null)
            ? $this->operatingContext->allowedBranchForCurrentCompany($request, (string) $validated['branch_doc_num'])
            : Branch::query()->where('company_id', $context['company_id'])->findOrFail($context['branch_id']);
        if (! $branch instanceof Branch) {
            throw ValidationException::withMessages([
                'branch_doc_num' => __('operating_context.validation.branch_invalid'),
            ]);
        }
        $request->merge(['branch_doc_num' => $branch->doc_num]);
        $filters = [
            'from_date' => (string) ($validated['from_date'] ?? $period->from_date->toDateString()),
            'to_date' => (string) ($validated['to_date'] ?? $period->to_date->toDateString()),
            'branch_doc_num' => $branch->doc_num,
            'type' => $validated['type'] ?? null,
        ];

        if ($filters['from_date'] < $period->from_date->toDateString()
            || $filters['to_date'] > $period->to_date->toDateString()
            || $filters['from_date'] > $filters['to_date']) {
            throw ValidationException::withMessages([
                'from_date' => __('reconciliation_center.errors.date_range', [
                    'from' => $period->from_date->toDateString(),
                    'to' => $period->to_date->toDateString(),
                ]),
            ]);
        }

        $shouldRun = $required || $request->boolean('run');
        $report = $shouldRun ? $this->reconciliations->report(
            (int) $context['company_id'],
            (int) $context['financial_period_id'],
            (int) $branch->getKey(),
            $filters['from_date'],
            $filters['to_date'],
            $filters['type'],
        ) : null;

        return [$context, $period, $branch, $filters, $report];
    }

    /** @return list<Branch> */
    private function branches(Request $request): array
    {
        return $this->operatingContext->allowedBranchQueryForCurrentCompany($request)
            ->active()
            ->orderBy('name')
            ->get()
            ->all();
    }
}

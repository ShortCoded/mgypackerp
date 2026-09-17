<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Exports\FinancialStatementReportExport;
use Modules\Accounting\Http\Requests\FinancialStatementReportRequest;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\FinancialStatementQueryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FinancialStatementReportController extends Controller
{
    public function __construct(
        private readonly FinancialStatementQueryService $statements,
        private readonly OperatingContextService $operatingContext,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(FinancialStatementReportRequest $request): View
    {
        $context = $this->operatingContext->snapshot($request);
        $period = $context['financial_period_id']
            ? FinancialPeriod::query()->find($context['financial_period_id'])
            : null;
        $result = null;

        if ($request->boolean('run')) {
            [$result, $validated] = $this->result($request, $context);
            $this->log($request, 'view', $context, $validated);
        }

        return view('modules.accounting.reports.financial-statements', [
            'result' => $result,
            'period' => $period,
            'operatingContext' => $this->operatingContext->current($request),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.accounting.reports.financial-statements'),
            'branches' => $this->branches($request),
        ]);
    }

    public function export(FinancialStatementReportRequest $request, ReportPdfService $pdf): BinaryFileResponse|Response
    {
        $format = (string) $request->route('financial_statement_export_format');
        $context = $this->operatingContext->snapshot($request);
        [$result, $validated] = $this->result($request, $context);
        $this->log($request, 'export', $context, $validated, $format);
        $fileName = str_replace('_', '-', $result['statement_type']);

        if ($format === 'pdf') {
            return $pdf->stream('reports.financial-statements', [
                'title' => __('financial_statements.types.'.$result['statement_type']),
                'result' => $result,
            ], "{$fileName}.pdf", 'L');
        }

        $writer = $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
        $extension = $format === 'csv' ? 'csv' : 'xlsx';

        return Excel::download(new FinancialStatementReportExport($result), "{$fileName}.{$extension}", $writer);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function result(FinancialStatementReportRequest $request, array $context): array
    {
        $validated = $request->validated();
        $branch = $request->filled('branch_doc_num')
            ? $this->operatingContext->allowedBranchForCurrentCompany($request, (string) $validated['branch_doc_num'])
            : null;
        $costCenterId = $request->filled('cost_center_doc_num')
            ? CostCenter::query()
                ->forCompany((int) $context['company_id'])
                ->where('doc_num', $validated['cost_center_doc_num'])
                ->value('id')
            : null;

        return [
            $this->statements->report([
                ...$validated,
                'company_id' => (int) $context['company_id'],
                'branch_id' => $branch?->getKey(),
                'cost_center_id' => $costCenterId ? (int) $costCenterId : null,
            ]),
            $validated,
        ];
    }

    /** @return list<Branch> */
    private function branches(FinancialStatementReportRequest $request): array
    {
        return $this->operatingContext
            ->allowedBranchQueryForCurrentCompany($request)
            ->active()
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $validated
     */
    private function log(
        FinancialStatementReportRequest $request,
        string $action,
        array $context,
        array $validated,
        ?string $format = null,
    ): void {
        $properties = collect($validated)
            ->only([
                'statement_type',
                'view_mode',
                'from_date',
                'to_date',
                'comparison_from_date',
                'comparison_to_date',
                'branch_doc_num',
                'cost_center_doc_num',
            ])
            ->all();

        if ($format !== null) {
            $properties['format'] = $format;
        }

        $this->activityLogger->log($request, 'accounting', "reports.financial_statements.{$action}", 'success', [
            'company_id' => $context['company_id'],
            'properties_only' => true,
            'properties' => $properties,
        ]);
    }
}

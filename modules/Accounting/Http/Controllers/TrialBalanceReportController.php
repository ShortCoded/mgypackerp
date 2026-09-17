<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Exports\TrialBalanceReportExport;
use Modules\Accounting\Http\Requests\TrialBalanceReportRequest;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\TrialBalanceQueryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TrialBalanceReportController extends Controller
{
    public function __construct(
        private readonly TrialBalanceQueryService $trialBalance,
        private readonly OperatingContextService $operatingContext,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(TrialBalanceReportRequest $request): View
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

        return view('modules.accounting.reports.trial-balance', [
            'result' => $result,
            'period' => $period,
            'operatingContext' => $this->operatingContext->current($request),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.accounting.reports.trial-balance'),
            'branches' => $this->branches($request),
            'accountLevels' => $this->accountLevels($context),
        ]);
    }

    public function export(TrialBalanceReportRequest $request, ReportPdfService $pdf): BinaryFileResponse|Response
    {
        $format = (string) $request->route('trial_balance_export_format');
        $context = $this->operatingContext->snapshot($request);
        [$result, $validated] = $this->result($request, $context);
        $this->log($request, 'export', $context, $validated, $format);

        if ($format === 'pdf') {
            return $pdf->stream('reports.trial-balance', [
                'title' => __('trial_balance.title'),
                'result' => $result,
            ], 'trial-balance.pdf', 'L');
        }

        $writer = $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
        $extension = $format === 'csv' ? 'csv' : 'xlsx';

        return Excel::download(new TrialBalanceReportExport($result), "trial-balance.{$extension}", $writer);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function result(TrialBalanceReportRequest $request, array $context): array
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
            $this->trialBalance->report([
                'company_id' => (int) $context['company_id'],
                'from_date' => $validated['from_date'],
                'to_date' => $validated['to_date'],
                'branch_id' => $branch?->getKey(),
                'cost_center_id' => $costCenterId ? (int) $costCenterId : null,
                'include_zero' => $request->boolean('include_zero'),
                'value_mode' => $validated['value_mode'],
                'totals_basis' => $validated['totals_basis'],
                'display_mode' => $validated['display_mode'],
                'level' => isset($validated['level']) ? (int) $validated['level'] : null,
            ]),
            $validated,
        ];
    }

    /** @return list<Branch> */
    private function branches(TrialBalanceReportRequest $request): array
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
     * @return list<int>
     */
    private function accountLevels(array $context): array
    {
        if (! is_numeric($context['company_id'] ?? null)) {
            return [1];
        }

        $maximumLevel = (int) (Account::query()
            ->withTrashed()
            ->forCompany((int) $context['company_id'])
            ->max('level') ?? 1);

        return range(1, max(1, $maximumLevel));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $validated
     */
    private function log(
        TrialBalanceReportRequest $request,
        string $action,
        array $context,
        array $validated,
        ?string $format = null,
    ): void {
        $properties = [
            'from_date' => $validated['from_date'],
            'to_date' => $validated['to_date'],
            'branch_doc_num' => $validated['branch_doc_num'] ?? null,
            'cost_center_doc_num' => $validated['cost_center_doc_num'] ?? null,
            'include_zero' => $request->boolean('include_zero'),
            'value_mode' => $validated['value_mode'],
            'totals_basis' => $validated['totals_basis'],
            'display_mode' => $validated['display_mode'],
            'level' => isset($validated['level']) ? (int) $validated['level'] : null,
        ];

        if ($format !== null) {
            $properties['format'] = $format;
        }

        $this->activityLogger->log($request, 'accounting', "reports.trial_balance.{$action}", 'success', [
            'company_id' => $context['company_id'],
            'properties_only' => true,
            'properties' => $properties,
        ]);
    }
}

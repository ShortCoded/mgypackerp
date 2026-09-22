<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\HR\Exports\PayrollReportExport;
use Modules\HR\Http\Requests\PayrollReportRequest;
use Modules\HR\Services\PayrollReportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollReportController extends Controller
{
    public function __construct(
        private readonly PayrollReportService $reports,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function payroll(PayrollReportRequest $request): View
    {
        return $this->index($request, 'payroll');
    }

    public function payments(PayrollReportRequest $request): View
    {
        return $this->index($request, 'payments');
    }

    public function exportPayroll(PayrollReportRequest $request, ReportPdfService $pdf): BinaryFileResponse|Response
    {
        return $this->export($request, $pdf, 'payroll');
    }

    public function exportPayments(PayrollReportRequest $request, ReportPdfService $pdf): BinaryFileResponse|Response
    {
        return $this->export($request, $pdf, 'payments');
    }

    private function index(PayrollReportRequest $request, string $type): View
    {
        $company = $this->companies->currentCompany($request);
        abort_if($company === null, 409);
        $filters = $request->filters();
        $report = $type === 'payroll'
            ? $this->reports->payroll((int) $company->getKey(), $request->user(), $filters)
            : $this->reports->payments((int) $company->getKey(), $request->user(), $filters);

        return view('modules.hr.payroll-reports.index', [
            'type' => $type,
            'report' => $report,
            'filters' => $filters,
            'branches' => $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->get(['branches.doc_num', 'branches.name']),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.reports.'.($type === 'payroll' ? 'payroll' : 'payments')),
        ]);
    }

    private function export(PayrollReportRequest $request, ReportPdfService $pdf, string $type): BinaryFileResponse|Response
    {
        $company = $this->companies->currentCompany($request);
        abort_if($company === null, 409);
        $filters = $request->filters();
        $rows = $type === 'payroll'
            ? $this->reports->payrollRows((int) $company->getKey(), $request->user(), $filters)
            : $this->reports->paymentRows((int) $company->getKey(), $request->user(), $filters);
        $format = $request->validated('format');
        abort_unless(is_string($format), 422);
        $headings = $this->headings($type);
        $exportRows = [
            ...$rows->map(fn (object $row): array => $this->row($type, $row))->all(),
            ...$this->totalRows($type, $rows),
        ];
        $filename = $type === 'payroll' ? 'payroll-report' : 'payroll-payment-report';

        if ($format === 'pdf') {
            return $pdf->stream('reports.hr.payroll', [
                'title' => __('hr_payroll_reports.'.$type.'.title'),
                'headings' => $headings,
                'rows' => $exportRows,
                'filters' => $filters,
            ], $filename.'.pdf', 'L');
        }

        return Excel::download(
            new PayrollReportExport($headings, $exportRows, $type === 'payroll' ? [7, 8, 9] : [9]),
            $filename.'.'.$format,
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }

    /** @return list<string> */
    private function headings(string $type): array
    {
        $keys = $type === 'payroll'
            ? ['run', 'period', 'branch', 'employee_code', 'employee', 'currency', 'gross', 'deductions', 'net', 'status']
            : ['run', 'period', 'branch', 'employee_code', 'employee', 'voucher', 'payment_date', 'currency', 'amount', 'status', 'journal'];

        return array_map(fn (string $key): string => __('hr_payroll_reports.columns.'.$key), $keys);
    }

    /** @return list<mixed> */
    private function row(string $type, object $row): array
    {
        if ($type === 'payroll') {
            return [
                $row->payroll_run_id,
                $row->period_start.' — '.$row->period_end,
                $row->branch_name,
                $row->employee_doc_num,
                $row->employee_name,
                $row->currency_code,
                (string) $row->gross_amount,
                (string) $row->deduction_amount,
                (string) $row->net_amount,
                __('hr_payroll.status.'.$row->status),
            ];
        }

        return [
            $row->payroll_run_id,
            $row->period_start.' — '.$row->period_end,
            $row->branch_name,
            $row->employee_doc_num,
            $row->employee_name ?: __('hr_payroll.labels.legacy_branch_payment'),
            $row->voucher_doc_num,
            $row->voucher_date,
            $row->currency_code,
            (string) $row->amount,
            __('hr_payroll.status.'.$row->status),
            $row->journal_doc_num ?: $row->reversal_journal_doc_num,
        ];
    }

    /** @param Collection<int, object> $rows @return list<list<mixed>> */
    private function totalRows(string $type, Collection $rows): array
    {
        if ($type === 'payroll') {
            return $rows
                ->groupBy(fn (object $row): string => ($row->currency_id ?? 'null').'|'.($row->currency_code ?? ''))
                ->map(function (Collection $currencyRows): array {
                    $currency = $currencyRows->first()?->currency_code;

                    return [
                        __('hr_payroll_reports.totals.export_label'), '', '', '', '', $currency,
                        $this->sumDecimalColumn($currencyRows, 'gross_amount'),
                        $this->sumDecimalColumn($currencyRows, 'deduction_amount'),
                        $this->sumDecimalColumn($currencyRows, 'net_amount'),
                        '',
                    ];
                })
                ->values()
                ->all();
        }

        return collect(['amount' => null, 'approved' => 'approved', 'cancelled' => 'cancelled'])
            ->map(function (?string $status, string $label) use ($rows): array {
                $amount = $this->sumDecimalColumn($status === null ? $rows : $rows->where('status', $status), 'amount');

                $currency = $rows->first()?->currency_code;

                return [__('hr_payroll_reports.totals.'.$label), '', '', '', '', '', '', $currency, $amount, '', ''];
            })
            ->values()
            ->all();
    }

    /** @param Collection<int, object> $rows */
    private function sumDecimalColumn(Collection $rows, string $column): string
    {
        return $rows->reduce(
            fn (string $total, object $row): string => bcadd($total, (string) $row->{$column}, 4),
            '0.0000',
        );
    }
}

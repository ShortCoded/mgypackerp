<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\HR\Services\PayrollReportService;

class PayslipController extends Controller
{
    public function __construct(
        private readonly PayrollReportService $reports,
        private readonly OperatingCompanyContextService $companies,
        private readonly CompanyPrintIdentityService $printIdentities,
    ) {}

    public function adminShow(Request $request, int $payslip): View
    {
        return view('modules.hr.payslips.show', $this->adminPayload($request, $payslip));
    }

    public function adminPdf(Request $request, int $payslip, ReportPdfService $pdf): Response
    {
        return $this->pdf($pdf, $this->adminPayload($request, $payslip));
    }

    public function employeeShow(Request $request, int $payslip): View
    {
        return view('modules.hr.payslips.show', $this->employeePayload($request, $payslip));
    }

    public function employeePdf(Request $request, int $payslip, ReportPdfService $pdf): Response
    {
        return $this->pdf($pdf, $this->employeePayload($request, $payslip));
    }

    /** @return array<string, mixed> */
    private function adminPayload(Request $request, int $payslip): array
    {
        $companyId = $this->companies->requireCompanyId($request);

        return $this->reports->payslipForAdmin($payslip, $companyId, $request->user()) + ['selfService' => false];
    }

    /** @return array<string, mixed> */
    private function employeePayload(Request $request, int $payslip): array
    {
        $employee = $request->user()->hrEmployee()->first();
        abort_if($employee === null, 404);

        return $this->reports->payslipForEmployee($payslip, (int) $employee->getKey()) + ['selfService' => true];
    }

    /** @param array<string, mixed> $payload */
    private function pdf(ReportPdfService $pdf, array $payload): Response
    {
        $company = Company::query()->withTrashed()->findOrFail((int) $payload['payslip']->company_id);

        return $pdf->stream('reports.hr.payslip', [
            'title' => __('hr_payroll_reports.payslip.title'),
            'companyPrintIdentity' => $this->printIdentities->forCompany($company),
            'printIdentityPolicy' => 'report',
            ...$payload,
        ], 'payslip-'.$payload['payslip']->id.'.pdf');
    }
}

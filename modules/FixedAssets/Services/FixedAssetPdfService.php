<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Http\Response;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\Reports\ReportPdfService;

class FixedAssetPdfService
{
    public function __construct(
        private readonly ReportPdfService $pdf,
        private readonly CompanyPrintIdentityService $printIdentities,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function stream(
        string $view,
        Company $company,
        array $data,
        string $filename,
        string $orientation = 'P',
    ): Response {
        return $this->pdf->stream($view, $data + [
            'companyPrintIdentity' => $this->printIdentities->forCompany($company),
            'pdfStylesView' => 'reports.fixed-assets.partials.styles',
        ], $filename, $orientation);
    }
}

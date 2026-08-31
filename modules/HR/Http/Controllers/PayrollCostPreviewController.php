<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Services\PayrollCostAllocationService;

class PayrollCostPreviewController extends Controller
{
    public function __invoke(Request $request, int $payrollRun, PayrollCostAllocationService $allocations): View
    {
        $preview = $allocations->previewRun($payrollRun);
        abort_unless(
            (int) $preview['run']->company_id === app(OperatingCompanyContextService::class)->requireCompanyId($request),
            404,
        );

        return view('modules.hr.payroll.cost-preview', $preview);
    }
}

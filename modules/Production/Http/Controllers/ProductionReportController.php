<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Services\ProductionReportService;

class ProductionReportController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly ProductionReportService $reports,
    ) {}

    public function index(Request $request): View
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'], 422, 'Operating context is required.');

        return view('modules.production.reports.index', [
            'runs' => $this->reports->runs($context['company_id'], $request->only(['status', 'from', 'to'])),
            'materials' => $this->reports->materialReconciliation($context['company_id'], $request->integer('production_run_id') ?: null),
            'kpis' => $this->reports->keyPerformanceIndicators($context['company_id']),
        ]);
    }
}

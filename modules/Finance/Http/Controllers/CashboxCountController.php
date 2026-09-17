<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Services\FinanceReportService;

final class CashboxCountController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $reports,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingContextService $context,
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->reports->filters($request, FinanceReportService::CashboxBalances);
        $filters['branch_id'] = $this->context->snapshot($request)['branch_id'];

        return view('modules.finance.cashbox-count.index', [
            'report' => $this->reports->report($filters),
            'filters' => $filters,
            'filterOptions' => $this->reports->filterOptions($filters['branch_id']),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($request->route()?->getName() ?? 'admin.finance.cashbox-count.index'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $this->reports->filters($request, FinanceReportService::CashboxBalances);
        $filters['branch_id'] = $this->context->snapshot($request)['branch_id'];
        $rows = $this->reports->report($filters)['rows'];

        return response()->json([
            'draw' => max(0, $request->integer('draw')),
            'recordsTotal' => $rows->count(),
            'recordsFiltered' => $rows->count(),
            'data' => $rows->values(),
        ]);
    }
}

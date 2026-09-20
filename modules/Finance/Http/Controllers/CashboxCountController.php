<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Http\Requests\CashboxCounts\StoreCashboxCountRequest;
use Modules\Finance\Http\Requests\CashboxCounts\UpdateCashboxCountRequest;
use Modules\Finance\Models\CashboxCount;
use Modules\Finance\Services\CashboxCountService;
use Modules\Finance\Services\FinanceReportService;

final class CashboxCountController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $reports,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingContextService $context,
        private readonly CashboxCountService $counts,
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->reports->filters($request, FinanceReportService::CashboxBalances);
        $filters['branch_id'] = $this->context->snapshot($request)['branch_id'];

        return view('modules.finance.cashbox-count.index', [
            'report' => $this->reports->report($filters),
            'filters' => $filters,
            'filterOptions' => $this->reports->filterOptions(['branch_id' => $filters['branch_id']]),
            'counts' => CashboxCount::query()
                ->where('company_id', $filters['company_id'] ?? $this->context->snapshot($request)['company_id'])
                ->where('branch_id', $filters['branch_id'])
                ->with(['cashbox', 'currency', 'createdBy'])
                ->latest('count_date')->latest('id')->limit(50)->get(),
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

    public function store(StoreCashboxCountRequest $request): RedirectResponse
    {
        $count = $this->counts->create($request->validated());

        return redirect()->route('admin.finance.cashbox-count.show', $count)
            ->with('success', __('cashbox_count.messages.created'));
    }

    public function show(Request $request, CashboxCount $cashboxCount): View
    {
        $this->assertBranchScope($request, $cashboxCount);

        return $this->document($request, $cashboxCount);
    }

    public function update(UpdateCashboxCountRequest $request, CashboxCount $cashboxCount): RedirectResponse
    {
        $this->assertBranchScope($request, $cashboxCount);
        abort_unless($cashboxCount->status === CashboxCount::StatusReopened, 409, __('cashbox_count.messages.reopen_required'));
        $this->counts->update($cashboxCount, $request->validated());

        return redirect()->route('admin.finance.cashbox-count.show', $cashboxCount)
            ->with('success', __('cashbox_count.messages.updated'));
    }

    public function reopen(Request $request, CashboxCount $cashboxCount): RedirectResponse
    {
        $this->assertBranchScope($request, $cashboxCount);
        $this->counts->reopen($cashboxCount);

        return redirect()->route('admin.finance.cashbox-count.show', $cashboxCount)
            ->with('success', __('cashbox_count.messages.reopened'));
    }

    public function print(Request $request, CashboxCount $cashboxCount): View
    {
        $this->assertBranchScope($request, $cashboxCount);

        return $this->document($request, $cashboxCount, true);
    }

    private function document(Request $request, CashboxCount $count, bool $print = false): View
    {
        return view('modules.finance.cashbox-count.show', [
            'count' => $count->loadMissing(['cashbox', 'currency', 'branch', 'createdBy', 'reopenedBy']),
            'print' => $print,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.finance.cashbox-count.index'),
        ]);
    }

    private function assertBranchScope(Request $request, CashboxCount $count): void
    {
        $context = $this->context->snapshot($request);
        abort_unless((int) $count->branch_id === (int) $context['branch_id'], 404);
    }
}

<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Http\Requests\StoreProductionExpenseRequest;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionExpenseRequestService;

class ProductionExpenseRequestController extends Controller
{
    public function index(Request $request, ProductionExecutionDataTable $dataTable): View|JsonResponse
    {
        if ($request->expectsJson()) {
            return $dataTable->expenses($request);
        }

        $context = app(OperatingContextService::class)->snapshot($request);
        $companyId = $context['company_id'];
        $branchId = $context['branch_id'];
        $hasContext = $companyId && $context['financial_period_id'] && $branchId;

        return view('modules.production.expenses.index', [
            'runs' => ProductionRun::query()->when($hasContext, fn ($query) => $query->where('company_id', $companyId)->where('financial_period_id', $context['financial_period_id'])->where('branch_id', $branchId), fn ($query) => $query->whereRaw('1 = 0'))->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled])->latest()->get(),
            'currencies' => Currency::query()->when($companyId, fn ($query) => $query->forCompany((int) $companyId)->active(), fn ($query) => $query->whereRaw('1 = 0'))->get(),
            'cashboxes' => Cashbox::query()->when($companyId && $branchId, fn ($query) => $query->forCompany((int) $companyId)->where('branch_id', $branchId)->active(), fn ($query) => $query->whereRaw('1 = 0'))->get(),
            'bankAccounts' => BankAccount::query()->when($companyId, fn ($query) => $query->forCompany((int) $companyId)->active(), fn ($query) => $query->whereRaw('1 = 0'))->get(),
            'expenseAccounts' => Account::query()->when($companyId, fn ($query) => $query->forCompany((int) $companyId)->active()->where('account_type', Account::TypeExpense)->where('is_postable', true), fn ($query) => $query->whereRaw('1 = 0'))->get(),
        ]);
    }

    public function store(StoreProductionExpenseRequest $request, ProductionExpenseRequestService $service): RedirectResponse
    {
        try {
            $data = $request->validated();
            $service->create(ProductionRun::query()->findOrFail($data['production_run_id']), $data);

            return redirect()->route('admin.production.expenses.index')->with('success', __('production_execution.messages.expense_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['expense' => $exception->getMessage()]);
        }
    }

    public function approve(ProductionExpenseRequest $productionExpenseRequest, ProductionExpenseRequestService $service): JsonResponse
    {
        return $this->guard(fn () => $service->approve($productionExpenseRequest));
    }

    public function pay(ProductionExpenseRequest $productionExpenseRequest, ProductionExpenseRequestService $service): JsonResponse
    {
        return $this->guard(fn () => $service->pay($productionExpenseRequest));
    }

    public function reverse(Request $request, ProductionExpenseRequest $productionExpenseRequest, ProductionExpenseRequestService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->guard(fn () => $service->reverse($productionExpenseRequest, $data['reason']));
    }

    private function guard(callable $callback): JsonResponse
    {
        try {
            $record = $callback();

            return response()->json(['success' => true, 'status' => $record->status, 'message' => __('production_execution.messages.operation_completed')]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}

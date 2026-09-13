<?php

namespace Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Maintenance\DataTables\MaintenanceDataTable;
use Modules\Maintenance\Exports\MaintenanceWorkOrderExport;
use Modules\Maintenance\Http\Requests\CompleteMaintenanceWorkOrder;
use Modules\Maintenance\Http\Requests\StoreMaintenanceExpenseRequest;
use Modules\Maintenance\Http\Requests\StoreMaintenanceMaterialRequest;
use Modules\Maintenance\Http\Requests\StoreMaintenanceRequest;
use Modules\Maintenance\Http\Requests\StoreMaintenanceWorkOrder;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Maintenance\Models\MaintenanceRequest;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Maintenance\Services\MaintenanceMaterialRequestService;
use Modules\Maintenance\Services\MaintenanceWorkflowService;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Services\ProductionExpenseRequestService;
use Modules\Purchases\Models\Supplier;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MaintenanceController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function requests(Request $request, MaintenanceDataTable $dataTable): View|JsonResponse
    {
        if ($request->expectsJson()) {
            return $dataTable->requests($request);
        }

        return view('modules.maintenance.requests.index', ['assets' => $this->assets($request)]);
    }

    public function storeRequest(StoreMaintenanceRequest $request, MaintenanceWorkflowService $service): RedirectResponse
    {
        try {
            $service->reportBreakdown($request->validated());

            return redirect()->route('admin.maintenance.requests.index')->with('success', __('maintenance.messages.request_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['request' => $exception->getMessage()]);
        }
    }

    public function destroyRequest(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->assertRequestInContext($request, $maintenanceRequest);
        if ($maintenanceRequest->status !== MaintenanceRequest::StatusOpen || $maintenanceRequest->workOrder()->exists()) {
            return response()->json(['success' => false, 'message' => __('maintenance.messages.request_not_deletable')], 422);
        }

        $maintenanceRequest->update(['deleted_by' => $request->user()?->getKey()]);
        $maintenanceRequest->delete();

        return response()->json(['success' => true, 'message' => __('maintenance.messages.request_deleted')]);
    }

    public function restoreRequest(Request $request, string $maintenanceRequest): JsonResponse
    {
        $context = app(OperatingContextService::class)->snapshot($request);
        $record = MaintenanceRequest::onlyTrashed()
            ->when($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('doc_num', $maintenanceRequest)
            ->firstOrFail();
        $record->restore();
        $record->update(['restored_by' => $request->user()?->getKey(), 'restored_at' => now()]);

        return response()->json(['success' => true, 'message' => __('maintenance.messages.request_restored')]);
    }

    public function orders(Request $request, MaintenanceDataTable $dataTable): View|JsonResponse
    {
        if ($request->expectsJson()) {
            return $dataTable->orders($request);
        }

        return view('modules.maintenance.orders.index');
    }

    public function materialRequests(Request $request, MaintenanceDataTable $dataTable): View|JsonResponse
    {
        if ($request->expectsJson()) {
            return $dataTable->materialRequests($request);
        }

        $context = $this->context->snapshot($request);
        $hasContext = $context['company_id'] && $context['financial_period_id'] && $context['branch_id'];

        return view('modules.maintenance.material-requests.index', [
            'orders' => MaintenanceWorkOrder::query()->when($hasContext, fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))->whereNotIn('status', [MaintenanceWorkOrder::StatusCompleted, MaintenanceWorkOrder::StatusClosed, MaintenanceWorkOrder::StatusCancelled])->with('asset')->latest()->get(),
            'stores' => BranchStore::query()->when($context['branch_id'], fn ($query) => $query->where('branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))->orderBy('position')->get(),
            'products' => Product::query()->when($context['company_id'], fn ($query) => $query->where('company_id', $context['company_id']), fn ($query) => $query->whereRaw('1 = 0'))->where('status', 'active')->where('item_classification', '!=', Product::ClassificationService)->orderBy('name')->get(),
        ]);
    }

    public function storeMaterialRequest(StoreMaintenanceMaterialRequest $request, MaintenanceMaterialRequestService $service): RedirectResponse
    {
        try {
            $data = $request->validated();
            $service->create(MaintenanceWorkOrder::query()->findOrFail($data['maintenance_work_order_id']), $data);

            return redirect()->route('admin.maintenance.material-requests.index')->with('success', __('maintenance.messages.material_request_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['materials' => $exception->getMessage()]);
        }
    }

    public function approveMaterialRequest(MaintenanceMaterialRequest $maintenanceMaterialRequest, MaintenanceMaterialRequestService $service): JsonResponse
    {
        return $this->guard(fn () => $service->approve($maintenanceMaterialRequest));
    }

    public function issueMaterialRequest(MaintenanceMaterialRequest $maintenanceMaterialRequest, MaintenanceMaterialRequestService $service): JsonResponse
    {
        return $this->guard(fn () => $service->issue($maintenanceMaterialRequest));
    }

    public function returnMaterialRequest(MaintenanceMaterialRequest $maintenanceMaterialRequest, MaintenanceMaterialRequestService $service): JsonResponse
    {
        return $this->guard(fn () => $service->returnUnused($maintenanceMaterialRequest));
    }

    public function expenses(Request $request, MaintenanceDataTable $dataTable): View|JsonResponse
    {
        if ($request->expectsJson()) {
            return $dataTable->expenses($request);
        }

        $context = $this->context->snapshot($request);
        $companyId = $context['company_id'];
        $branchId = $context['branch_id'];
        $hasContext = $companyId && $context['financial_period_id'] && $branchId;

        return view('modules.maintenance.expenses.index', [
            'orders' => MaintenanceWorkOrder::query()->when($hasContext, fn ($query) => $query->forContext((int) $companyId, (int) $context['financial_period_id'], (int) $branchId), fn ($query) => $query->whereRaw('1 = 0'))->whereNotIn('status', [MaintenanceWorkOrder::StatusCompleted, MaintenanceWorkOrder::StatusClosed, MaintenanceWorkOrder::StatusCancelled])->with('asset')->latest()->get(),
            'currencies' => Currency::query()->when($companyId, fn ($query) => $query->forCompany((int) $companyId)->active(), fn ($query) => $query->whereRaw('1 = 0'))->get(),
            'cashboxes' => Cashbox::query()->when($companyId && $branchId, fn ($query) => $query->forCompany((int) $companyId)->where('branch_id', $branchId)->active(), fn ($query) => $query->whereRaw('1 = 0'))->get(),
            'bankAccounts' => BankAccount::query()->when($companyId, fn ($query) => $query->forCompany((int) $companyId)->active(), fn ($query) => $query->whereRaw('1 = 0'))->get(),
            'expenseAccounts' => Account::query()->when($companyId, fn ($query) => $query->forCompany((int) $companyId)->active()->where('account_type', Account::TypeExpense)->where('is_postable', true), fn ($query) => $query->whereRaw('1 = 0'))->get(),
        ]);
    }

    public function storeExpense(StoreMaintenanceExpenseRequest $request, ProductionExpenseRequestService $service): RedirectResponse
    {
        try {
            $data = $request->validated();
            $service->createForMaintenance(MaintenanceWorkOrder::query()->findOrFail($data['maintenance_work_order_id']), $data);

            return redirect()->route('admin.maintenance.expenses.index')->with('success', __('maintenance.messages.expense_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['expense' => $exception->getMessage()]);
        }
    }

    public function approveExpense(ProductionExpenseRequest $productionExpenseRequest, ProductionExpenseRequestService $service): JsonResponse
    {
        return $this->guard(fn () => $service->approve($productionExpenseRequest));
    }

    public function payExpense(ProductionExpenseRequest $productionExpenseRequest, ProductionExpenseRequestService $service): JsonResponse
    {
        return $this->guard(fn () => $service->pay($productionExpenseRequest));
    }

    public function reverseExpense(Request $request, ProductionExpenseRequest $productionExpenseRequest, ProductionExpenseRequestService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->guard(fn () => $service->reverse($productionExpenseRequest, $data['reason']));
    }

    public function exportOrders(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new MaintenanceWorkOrderExport($this->ordersForContext($request)),
            'maintenance-work-orders-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function createOrder(Request $request): View
    {
        $context = app(OperatingContextService::class)->snapshot($request);
        $maintenanceRequest = $request->filled('request') ? MaintenanceRequest::query()
            ->when($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('doc_num', $request->string('request'))->firstOrFail() : null;

        return view('modules.maintenance.orders.form', [
            'requestRecord' => $maintenanceRequest,
            'assets' => $this->assets($request),
            'molds' => ProductionMold::query()->when($context['company_id'] && $context['branch_id'], fn ($query) => $query->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))->orderBy('name')->get(),
            'suppliers' => Supplier::query()->when($context['company_id'], fn ($query) => $query->where('company_id', $context['company_id']), fn ($query) => $query->whereRaw('1 = 0'))->orderBy('name')->get(),
        ]);
    }

    public function showOrder(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): View
    {
        $this->assertOrderInContext($request, $maintenanceWorkOrder);

        return view('modules.maintenance.orders.show', [
            'order' => $maintenanceWorkOrder->load(['asset', 'mold', 'request', 'productionRun', 'supplier', 'materialRequests.lines.product', 'materialRequests.store', 'materialRequests.issueDocument', 'materialRequests.returnDocument', 'expenses.currency', 'expenses.cashVoucher']),
        ]);
    }

    public function printOrder(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): Response
    {
        $this->assertOrderInContext($request, $maintenanceWorkOrder);
        $order = $maintenanceWorkOrder->load(['asset', 'mold', 'request', 'productionRun', 'supplier', 'materialRequests.lines.product', 'expenses.currency']);
        $company = Company::query()->findOrFail($order->company_id);

        return $this->pdf->stream('reports.maintenance.work-order', [
            'title' => __('maintenance.orders.print_title').' — '.$order->doc_num,
            'order' => $order,
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
        ], str('maintenance-work-order-'.$order->doc_num)->slug().'.pdf');
    }

    public function storeOrder(StoreMaintenanceWorkOrder $request, MaintenanceWorkflowService $service): RedirectResponse
    {
        try {
            $data = $request->validated();
            $context = app(OperatingContextService::class)->snapshot($request);
            $source = filled($data['maintenance_request_doc_num'] ?? null) ? MaintenanceRequest::query()
                ->when($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
                ->where('doc_num', $data['maintenance_request_doc_num'])->firstOrFail() : null;
            $service->createWorkOrder($data, $source);

            return redirect()->route('admin.maintenance.orders.index')->with('success', __('maintenance.messages.order_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['order' => $exception->getMessage()]);
        }
    }

    public function completeForm(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): View
    {
        $this->assertOrderInContext($request, $maintenanceWorkOrder);

        return view('modules.maintenance.orders.complete', ['order' => $maintenanceWorkOrder->load('asset')]);
    }

    public function approve(MaintenanceWorkOrder $maintenanceWorkOrder, MaintenanceWorkflowService $service): JsonResponse
    {
        return $this->guard(fn () => $service->approve($maintenanceWorkOrder));
    }

    public function start(MaintenanceWorkOrder $maintenanceWorkOrder, MaintenanceWorkflowService $service): JsonResponse
    {
        return $this->guard(fn () => $service->start($maintenanceWorkOrder));
    }

    public function complete(CompleteMaintenanceWorkOrder $request, MaintenanceWorkOrder $maintenanceWorkOrder, MaintenanceWorkflowService $service): RedirectResponse
    {
        try {
            $service->complete($maintenanceWorkOrder, $request->validated());

            return redirect()->route('admin.maintenance.orders.index')->with('success', __('maintenance.messages.order_completed'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['order' => $exception->getMessage()]);
        }
    }

    public function close(MaintenanceWorkOrder $maintenanceWorkOrder, MaintenanceWorkflowService $service): JsonResponse
    {
        return $this->guard(fn () => $service->close($maintenanceWorkOrder));
    }

    /** @return Collection<int, FixedAsset> */
    private function assets(Request $request): Collection
    {
        $context = $this->context->snapshot($request);

        return FixedAsset::query()
            ->when($context['company_id'] && $context['branch_id'], fn ($query) => $query->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNotIn('status', [FixedAsset::StatusDisposed, FixedAsset::StatusSold, FixedAsset::StatusWrittenOff])
            ->orderBy('asset_name')->get();
    }

    /** @return Collection<int, MaintenanceWorkOrder> */
    private function ordersForContext(Request $request): Collection
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('maintenance.messages.operating_context_required'));

        Company::query()->findOrFail($context['company_id']);

        return MaintenanceWorkOrder::query()
            ->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id'])
            ->with(['asset', 'supplier'])
            ->orderByDesc('planned_start_at')
            ->get();
    }

    private function guard(callable $callback): JsonResponse
    {
        try {
            $record = $callback();

            return response()->json(['success' => true, 'status' => $record->status, 'message' => __('maintenance.messages.operation_completed')]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function assertOrderInContext(Request $request, MaintenanceWorkOrder $order): void
    {
        $context = $this->context->snapshot($request);
        abort_unless(
            $context['company_id'] && $context['financial_period_id'] && $context['branch_id']
            && (int) $order->company_id === (int) $context['company_id']
            && (int) $order->financial_period_id === (int) $context['financial_period_id']
            && (int) $order->branch_id === (int) $context['branch_id'],
            404,
        );
    }

    private function assertRequestInContext(Request $request, MaintenanceRequest $maintenanceRequest): void
    {
        $context = $this->context->snapshot($request);
        abort_unless(
            $context['company_id'] && $context['financial_period_id'] && $context['branch_id']
            && (int) $maintenanceRequest->company_id === (int) $context['company_id']
            && (int) $maintenanceRequest->financial_period_id === (int) $context['financial_period_id']
            && (int) $maintenanceRequest->branch_id === (int) $context['branch_id'],
            404,
        );
    }
}

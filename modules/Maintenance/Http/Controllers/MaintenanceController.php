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
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\Select2ResponseService;
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
            new MaintenanceWorkOrderExport(
                $this->ordersForContext($request),
                (bool) $request->user()?->can('maintenance.reports.financial'),
            ),
            'maintenance-work-orders-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function reports(Request $request): View
    {
        return view('modules.maintenance.reports.index', $this->maintenanceReport($request));
    }

    public function exportReport(Request $request): BinaryFileResponse
    {
        $report = $this->maintenanceReport($request);

        return Excel::download(
            new MaintenanceWorkOrderExport($report['orders'], $report['canViewFinancial']),
            'maintenance-operations-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function printReport(Request $request): Response
    {
        $report = $this->maintenanceReport($request);
        $company = Company::query()->findOrFail($report['context']['company_id']);

        return $this->pdf->stream('reports.maintenance.operations', [
            ...$report,
            'title' => __('maintenance.reports.title'),
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
        ], 'maintenance-operations-report.pdf', 'L');
    }

    public function createOrder(Request $request): View
    {
        $context = app(OperatingContextService::class)->snapshot($request);
        $maintenanceRequest = $request->filled('request') ? MaintenanceRequest::query()
            ->when($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('doc_num', $request->string('request'))->firstOrFail() : null;
        $selectedAssetId = $maintenanceRequest?->fixed_asset_id ?: (int) $request->session()->getOldInput('fixed_asset_id');
        $selectedMoldId = (int) $request->session()->getOldInput('production_mold_id');
        $selectedSupplierId = (int) $request->session()->getOldInput('supplier_id');

        return view('modules.maintenance.orders.form', [
            'requestRecord' => $maintenanceRequest,
            'assets' => FixedAsset::query()
                ->when($context['company_id'] && $context['branch_id'], fn ($query) => $query->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
                ->whereKey($selectedAssetId ?: -1)
                ->get(),
            'molds' => ProductionMold::query()->when($context['company_id'] && $context['branch_id'], fn ($query) => $query->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))->whereKey($selectedMoldId ?: -1)->get(),
            'suppliers' => Supplier::query()->when($context['company_id'], fn ($query) => $query->where('company_id', $context['company_id']), fn ($query) => $query->whereRaw('1 = 0'))->whereKey($selectedSupplierId ?: -1)->get(),
        ]);
    }

    public function select2(
        Request $request,
        string $lookup,
        DataTableSearchService $search,
        Select2ResponseService $select2,
    ): JsonResponse {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['branch_id'], 422, __('maintenance.messages.operating_context_required'));
        $terms = $search->terms($request->input('q', $request->input('term')));

        return match ($lookup) {
            'assets' => response()->json($select2->paginated(
                tap(FixedAsset::query()
                    ->where('company_id', $context['company_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->whereNotIn('status', [FixedAsset::StatusDisposed, FixedAsset::StatusSold, FixedAsset::StatusWrittenOff])
                    ->orderBy('asset_name'), fn ($query) => $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'asset_name', 'serial_number']])),
                $request,
                fn (FixedAsset $asset): array => ['id' => (string) $asset->getKey(), 'text' => trim($asset->doc_num.' — '.$asset->asset_name)],
            )),
            'molds' => response()->json($select2->paginated(
                tap(ProductionMold::query()
                    ->where('company_id', $context['company_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->orderBy('name'), fn ($query) => $search->applyMultiTermSearch($query, $terms, ['text' => ['code', 'name']])),
                $request,
                fn (ProductionMold $mold): array => ['id' => (string) $mold->getKey(), 'text' => trim($mold->code.' — '.$mold->name)],
            )),
            'suppliers' => response()->json($select2->paginated(
                tap(Supplier::query()
                    ->where('company_id', $context['company_id'])
                    ->where('status', 'active')
                    ->orderBy('name'), fn ($query) => $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'name', 'phone', 'mobile']])),
                $request,
                fn (Supplier $supplier): array => ['id' => (string) $supplier->getKey(), 'text' => trim($supplier->doc_num.' — '.$supplier->name)],
            )),
            default => abort(404),
        };
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
            ->with([
                'asset', 'mold', 'supplier', 'request',
                'materialRequests.lines.product', 'materialRequests.issueDocument', 'materialRequests.returnDocument',
                'expenses.currency', 'expenses.cashVoucher', 'expenses.journalEntry',
            ])
            ->orderByDesc('planned_start_at')
            ->get();
    }

    /** @return array<string, mixed> */
    private function maintenanceReport(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless(
            $context['company_id'] && $context['financial_period_id'] && $context['branch_id'],
            422,
            __('maintenance.messages.operating_context_required'),
        );
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'maintenance_type' => ['nullable', 'in:preventive,corrective,emergency,external'],
            'service_mode' => ['nullable', 'in:internal,external'],
            'status' => ['nullable', 'in:draft,approved,in_progress,completed,closed,cancelled'],
        ]);

        $orders = MaintenanceWorkOrder::query()
            ->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id'])
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['maintenance_type'] ?? null, fn ($query, $value) => $query->where('maintenance_type', $value))
            ->when($filters['service_mode'] ?? null, fn ($query, $value) => $query->where('service_mode', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->with([
                'asset', 'mold', 'supplier', 'request',
                'materialRequests.lines.product', 'materialRequests.issueDocument', 'materialRequests.returnDocument',
                'expenses.currency', 'expenses.cashVoucher', 'expenses.journalEntry',
            ])
            ->latest('created_at')
            ->get();
        $requests = MaintenanceRequest::query()
            ->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id'])
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('reported_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('reported_at', '<=', $date))
            ->count();
        $materialLines = $orders->flatMap(fn (MaintenanceWorkOrder $order) => $order->materialRequests->flatMap->lines);
        $expenses = $orders->flatMap->expenses;
        $expenseTotals = $expenses
            ->groupBy(fn (ProductionExpenseRequest $expense): string => $expense->currency?->code ?: '—')
            ->map(fn ($rows, string $currency): array => [
                'currency' => $currency,
                'requested' => $rows->sum(fn (ProductionExpenseRequest $expense): float => (float) $expense->amount),
                'paid' => $rows->where('status', ProductionExpenseRequest::StatusPaid)->sum(fn (ProductionExpenseRequest $expense): float => (float) $expense->amount),
                'count' => $rows->count(),
            ])->values();
        $downtimeMinutes = $orders->sum(function (MaintenanceWorkOrder $order): int {
            if (! $order->actual_start_at || ! $order->actual_end_at || $order->actual_end_at->lessThan($order->actual_start_at)) {
                return 0;
            }

            return (int) $order->actual_start_at->diffInMinutes($order->actual_end_at);
        });

        return [
            'context' => $context,
            'filters' => $filters,
            'orders' => $orders,
            'expenseTotals' => $expenseTotals,
            'canViewFinancial' => (bool) $request->user()?->can('maintenance.reports.financial'),
            'kpis' => [
                'breakdown_reports' => $requests,
                'work_orders' => $orders->count(),
                'open_orders' => $orders->whereIn('status', [
                    MaintenanceWorkOrder::StatusDraft,
                    MaintenanceWorkOrder::StatusApproved,
                    MaintenanceWorkOrder::StatusInProgress,
                ])->count(),
                'completed_orders' => $orders->whereIn('status', [
                    MaintenanceWorkOrder::StatusCompleted,
                    MaintenanceWorkOrder::StatusClosed,
                ])->count(),
                'internal_orders' => $orders->where('service_mode', 'internal')->count(),
                'external_orders' => $orders->where('service_mode', 'external')->count(),
                'downtime_hours' => round($downtimeMinutes / 60, 2),
                'requested_material_quantity' => $materialLines->sum(fn ($line): float => (float) $line->requested_quantity),
                'issued_material_quantity' => $materialLines->sum(fn ($line): float => (float) $line->issued_quantity),
                'returned_material_quantity' => $materialLines->sum(fn ($line): float => (float) $line->returned_quantity),
            ],
        ];
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

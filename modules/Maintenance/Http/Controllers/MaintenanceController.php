<?php

namespace Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
use Modules\Maintenance\Http\Requests\RecordMaintenanceCompletionRequest;
use Modules\Maintenance\Http\Requests\RecordMaintenanceWorkOrderEventRequest;
use Modules\Maintenance\Http\Requests\StoreMaintenanceExpenseRequest;
use Modules\Maintenance\Http\Requests\StoreMaintenanceMaterialRequest;
use Modules\Maintenance\Http\Requests\StoreMaintenanceRequest;
use Modules\Maintenance\Http\Requests\StoreMaintenanceWorkOrder;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Maintenance\Models\MaintenancePlanDue;
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

        return view('modules.maintenance.requests.index');
    }

    public function createRequest(Request $request): View
    {
        return $this->requestForm($request);
    }

    public function showRequest(Request $request, MaintenanceRequest $maintenanceRequest): View
    {
        $this->assertRequestInContext($request, $maintenanceRequest);

        return $this->requestForm($request, $maintenanceRequest, 'view');
    }

    public function editRequest(Request $request, MaintenanceRequest $maintenanceRequest): View
    {
        $this->assertRequestInContext($request, $maintenanceRequest);
        abort_unless($maintenanceRequest->status === MaintenanceRequest::StatusOpen && ! $maintenanceRequest->workOrder()->exists(), 422, __('maintenance.messages.request_not_editable'));

        return $this->requestForm($request, $maintenanceRequest, 'edit');
    }

    public function storeRequest(StoreMaintenanceRequest $request, MaintenanceWorkflowService $service): RedirectResponse
    {
        try {
            $record = $service->reportBreakdown($request->validated());

            return $this->redirectAfterSave($request, $record, 'admin.maintenance.requests', __('maintenance.messages.request_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['request' => $exception->getMessage()]);
        }
    }

    public function updateRequest(StoreMaintenanceRequest $request, MaintenanceRequest $maintenanceRequest, MaintenanceWorkflowService $service): RedirectResponse
    {
        try {
            $this->assertRequestInContext($request, $maintenanceRequest);
            $record = $service->updateBreakdown($maintenanceRequest, $request->validated());

            return $this->redirectAfterSave($request, $record, 'admin.maintenance.requests', __('maintenance.messages.request_updated'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['request' => $exception->getMessage()]);
        }
    }

    public function bulkDeleteRequests(Request $request): JsonResponse
    {
        $docNums = $request->validate(['doc_nums' => ['required', 'array', 'max:100'], 'doc_nums.*' => ['required', 'string', 'distinct']])['doc_nums'];
        $context = $this->requiredContext($request);
        $deleted = DB::transaction(function () use ($docNums, $context, $request): int {
            $records = MaintenanceRequest::query()->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])->whereIn('doc_num', $docNums)->lockForUpdate()->get();
            $count = 0;
            foreach ($records as $record) {
                if ($record->status !== MaintenanceRequest::StatusOpen || $record->workOrder()->exists()) {
                    continue;
                }
                $record->update(['deleted_by' => $request->user()?->getKey()]);
                $record->delete();
                $count++;
            }

            return $count;
        });

        return response()->json(['success' => true, 'message' => __('maintenance.messages.bulk_deleted', ['count' => $deleted])]);
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

    public function editOrder(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): View
    {
        $this->assertOrderInContext($request, $maintenanceWorkOrder);
        abort_unless($maintenanceWorkOrder->status === MaintenanceWorkOrder::StatusDraft, 422, __('maintenance.messages.order_not_editable'));

        return $this->orderForm($request, $maintenanceWorkOrder, 'edit');
    }

    public function updateOrder(StoreMaintenanceWorkOrder $request, MaintenanceWorkOrder $maintenanceWorkOrder, MaintenanceWorkflowService $service): RedirectResponse
    {
        try {
            $this->assertOrderInContext($request, $maintenanceWorkOrder);
            $record = $service->updateWorkOrder($maintenanceWorkOrder, $request->validated());

            return $this->redirectAfterSave($request, $record, 'admin.maintenance.orders', __('maintenance.messages.order_updated'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['order' => $exception->getMessage()]);
        }
    }

    public function destroyOrder(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): JsonResponse
    {
        $this->assertOrderInContext($request, $maintenanceWorkOrder);
        if (! $this->orderIsDeletable($maintenanceWorkOrder)) {
            return response()->json(['success' => false, 'message' => __('maintenance.messages.order_not_deletable')], 422);
        }

        DB::transaction(function () use ($maintenanceWorkOrder, $request): void {
            $maintenanceWorkOrder->update(['deleted_by' => $request->user()?->getKey()]);
            $maintenanceWorkOrder->delete();
            $maintenanceWorkOrder->request?->update([
                'status' => MaintenanceRequest::StatusOpen,
                'converted_by' => null,
                'converted_at' => null,
                'updated_by' => $request->user()?->getKey(),
            ]);
        });

        return response()->json(['success' => true, 'message' => __('maintenance.messages.order_deleted')]);
    }

    public function restoreOrder(Request $request, string $maintenanceWorkOrder): JsonResponse
    {
        $record = $this->trashedOrder($request, $maintenanceWorkOrder);
        if ($record->request && $record->request->workOrder()->whereKeyNot($record->getKey())->exists()) {
            return response()->json(['success' => false, 'message' => __('maintenance.messages.order_restore_conflict')], 422);
        }

        DB::transaction(function () use ($record, $request): void {
            $record->restore();
            $record->update(['restored_by' => $request->user()?->getKey(), 'restored_at' => now(), 'updated_by' => $request->user()?->getKey()]);
            $record->request?->update(['status' => MaintenanceRequest::StatusConverted, 'converted_by' => $request->user()?->getKey(), 'converted_at' => now(), 'updated_by' => $request->user()?->getKey()]);
        });

        return response()->json(['success' => true, 'message' => __('maintenance.messages.order_restored')]);
    }

    public function bulkDeleteOrders(Request $request): JsonResponse
    {
        $docNums = $request->validate(['doc_nums' => ['required', 'array', 'max:100'], 'doc_nums.*' => ['required', 'string', 'distinct']])['doc_nums'];
        $context = $this->requiredContext($request);
        $deleted = 0;
        DB::transaction(function () use ($docNums, $context, $request, &$deleted): void {
            $records = MaintenanceWorkOrder::query()->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])->whereIn('doc_num', $docNums)->lockForUpdate()->get();
            foreach ($records as $record) {
                if (! $this->orderIsDeletable($record)) {
                    continue;
                }
                $record->update(['deleted_by' => $request->user()?->getKey()]);
                $record->delete();
                $record->request?->update(['status' => MaintenanceRequest::StatusOpen, 'converted_by' => null, 'converted_at' => null, 'updated_by' => $request->user()?->getKey()]);
                $deleted++;
            }
        });

        return response()->json(['success' => true, 'message' => __('maintenance.messages.bulk_deleted', ['count' => $deleted])]);
    }

    public function materialRequests(Request $request, MaintenanceDataTable $dataTable): View|JsonResponse
    {
        if ($request->expectsJson()) {
            return $dataTable->materialRequests($request);
        }

        return view('modules.maintenance.material-requests.index');
    }

    public function createMaterialRequest(Request $request): View
    {
        return $this->materialRequestForm($request);
    }

    public function showMaterialRequest(Request $request, MaintenanceMaterialRequest $maintenanceMaterialRequest): View
    {
        $this->assertMaterialRequestInContext($request, $maintenanceMaterialRequest);

        return $this->materialRequestForm($request, $maintenanceMaterialRequest, 'view');
    }

    public function editMaterialRequest(Request $request, MaintenanceMaterialRequest $maintenanceMaterialRequest): View
    {
        $this->assertMaterialRequestInContext($request, $maintenanceMaterialRequest);
        abort_unless($this->materialRequestIsEditable($maintenanceMaterialRequest), 422, __('maintenance.messages.material_request_not_editable'));

        return $this->materialRequestForm($request, $maintenanceMaterialRequest, 'edit');
    }

    public function storeMaterialRequest(StoreMaintenanceMaterialRequest $request, MaintenanceMaterialRequestService $service): RedirectResponse
    {
        try {
            $data = $request->validated();
            $record = $service->create(MaintenanceWorkOrder::query()->findOrFail($data['maintenance_work_order_id']), $data);

            return $this->redirectAfterSave($request, $record, 'admin.maintenance.material-requests', __('maintenance.messages.material_request_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['materials' => $exception->getMessage()]);
        }
    }

    public function updateMaterialRequest(StoreMaintenanceMaterialRequest $request, MaintenanceMaterialRequest $maintenanceMaterialRequest, MaintenanceMaterialRequestService $service): RedirectResponse
    {
        try {
            $this->assertMaterialRequestInContext($request, $maintenanceMaterialRequest);
            $record = $service->update($maintenanceMaterialRequest, $request->validated());

            return $this->redirectAfterSave($request, $record, 'admin.maintenance.material-requests', __('maintenance.messages.material_request_updated'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['materials' => $exception->getMessage()]);
        }
    }

    public function destroyMaterialRequest(Request $request, MaintenanceMaterialRequest $maintenanceMaterialRequest): JsonResponse
    {
        $this->assertMaterialRequestInContext($request, $maintenanceMaterialRequest);
        if (! $this->materialRequestIsEditable($maintenanceMaterialRequest)) {
            return response()->json(['success' => false, 'message' => __('maintenance.messages.material_request_not_deletable')], 422);
        }
        $maintenanceMaterialRequest->update(['deleted_by' => $request->user()?->getKey()]);
        $maintenanceMaterialRequest->delete();

        return response()->json(['success' => true, 'message' => __('maintenance.messages.material_request_deleted')]);
    }

    public function restoreMaterialRequest(Request $request, string $maintenanceMaterialRequest): JsonResponse
    {
        $record = $this->trashedMaterialRequest($request, $maintenanceMaterialRequest);
        $record->restore();
        $record->update(['restored_by' => $request->user()?->getKey(), 'restored_at' => now(), 'updated_by' => $request->user()?->getKey()]);

        return response()->json(['success' => true, 'message' => __('maintenance.messages.material_request_restored')]);
    }

    public function bulkDeleteMaterialRequests(Request $request): JsonResponse
    {
        return $this->bulkDeleteScopedDocuments($request, MaintenanceMaterialRequest::class, fn (MaintenanceMaterialRequest $record): bool => $this->materialRequestIsEditable($record));
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

        return view('modules.maintenance.expenses.index');
    }

    public function createExpense(Request $request): View
    {
        return $this->expenseForm($request);
    }

    public function showExpense(Request $request, ProductionExpenseRequest $productionExpenseRequest): View
    {
        $this->assertMaintenanceExpenseInContext($request, $productionExpenseRequest);

        return $this->expenseForm($request, $productionExpenseRequest, 'view');
    }

    public function editExpense(Request $request, ProductionExpenseRequest $productionExpenseRequest): View
    {
        $this->assertMaintenanceExpenseInContext($request, $productionExpenseRequest);
        abort_unless($productionExpenseRequest->status === ProductionExpenseRequest::StatusSubmitted, 422, __('maintenance.messages.expense_not_editable'));

        return $this->expenseForm($request, $productionExpenseRequest, 'edit');
    }

    public function storeExpense(StoreMaintenanceExpenseRequest $request, ProductionExpenseRequestService $service): RedirectResponse
    {
        try {
            $data = $request->validated();
            $record = $service->createForMaintenance(MaintenanceWorkOrder::query()->findOrFail($data['maintenance_work_order_id']), $data);

            return $this->redirectAfterSave($request, $record, 'admin.maintenance.expenses', __('maintenance.messages.expense_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['expense' => $exception->getMessage()]);
        }
    }

    public function updateExpense(StoreMaintenanceExpenseRequest $request, ProductionExpenseRequest $productionExpenseRequest, ProductionExpenseRequestService $service): RedirectResponse
    {
        try {
            $this->assertMaintenanceExpenseInContext($request, $productionExpenseRequest);
            $record = $service->updateForMaintenance($productionExpenseRequest, $request->validated());

            return $this->redirectAfterSave($request, $record, 'admin.maintenance.expenses', __('maintenance.messages.expense_updated'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['expense' => $exception->getMessage()]);
        }
    }

    public function destroyExpense(Request $request, ProductionExpenseRequest $productionExpenseRequest): JsonResponse
    {
        $this->assertMaintenanceExpenseInContext($request, $productionExpenseRequest);
        if ($productionExpenseRequest->status !== ProductionExpenseRequest::StatusSubmitted) {
            return response()->json(['success' => false, 'message' => __('maintenance.messages.expense_not_deletable')], 422);
        }
        $productionExpenseRequest->update(['deleted_by' => $request->user()?->getKey()]);
        $productionExpenseRequest->delete();

        return response()->json(['success' => true, 'message' => __('maintenance.messages.expense_deleted')]);
    }

    public function restoreExpense(Request $request, string $productionExpenseRequest): JsonResponse
    {
        $record = $this->trashedExpense($request, $productionExpenseRequest);
        $record->restore();
        $record->update(['restored_by' => $request->user()?->getKey(), 'restored_at' => now(), 'updated_by' => $request->user()?->getKey()]);

        return response()->json(['success' => true, 'message' => __('maintenance.messages.expense_restored')]);
    }

    public function bulkDeleteExpenses(Request $request): JsonResponse
    {
        return $this->bulkDeleteScopedDocuments($request, ProductionExpenseRequest::class, fn (ProductionExpenseRequest $record): bool => $record->maintenance_work_order_id !== null && $record->status === ProductionExpenseRequest::StatusSubmitted);
    }

    public function approveExpense(Request $request, ProductionExpenseRequest $productionExpenseRequest, ProductionExpenseRequestService $service): JsonResponse
    {
        $this->assertMaintenanceExpenseInContext($request, $productionExpenseRequest);

        return $this->guard(fn () => $service->approve($productionExpenseRequest));
    }

    public function payExpense(Request $request, ProductionExpenseRequest $productionExpenseRequest, ProductionExpenseRequestService $service): JsonResponse
    {
        $this->assertMaintenanceExpenseInContext($request, $productionExpenseRequest);

        return $this->guard(fn () => $service->pay($productionExpenseRequest));
    }

    public function reverseExpense(Request $request, ProductionExpenseRequest $productionExpenseRequest, ProductionExpenseRequestService $service): JsonResponse
    {
        $this->assertMaintenanceExpenseInContext($request, $productionExpenseRequest);
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
        return $this->orderForm($request);
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
            'maintainables' => $this->maintainablesLookup($request, $search, $select2, $context, $terms),
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
            'order' => $maintenanceWorkOrder->load(['asset', 'mold', 'request.qualityInspection', 'maintenancePlanDue.plan', 'productionRun', 'supplier', 'events.recordedBy', 'materialRequests.lines.product', 'materialRequests.store', 'materialRequests.issueDocument', 'materialRequests.returnDocument', 'expenses.currency', 'expenses.cashVoucher']),
        ]);
    }

    public function printOrder(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): Response
    {
        $this->assertOrderInContext($request, $maintenanceWorkOrder);
        $order = $maintenanceWorkOrder->load(['asset', 'mold', 'request.qualityInspection', 'maintenancePlanDue.plan', 'productionRun', 'supplier', 'events.recordedBy', 'materialRequests.lines.product', 'expenses.currency']);
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
            $record = $service->createWorkOrder($data, $source);

            return $this->redirectAfterSave($request, $record, 'admin.maintenance.orders', __('maintenance.messages.order_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['order' => $exception->getMessage()]);
        }
    }

    public function completeForm(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): View
    {
        $this->assertOrderInContext($request, $maintenanceWorkOrder);

        return view('modules.maintenance.orders.complete', [
            'order' => $maintenanceWorkOrder->load(['asset', 'mold', 'materialRequests.lines.product']),
        ]);
    }

    public function approve(MaintenanceWorkOrder $maintenanceWorkOrder, MaintenanceWorkflowService $service): JsonResponse
    {
        return $this->guard(fn () => $service->approve($maintenanceWorkOrder));
    }

    public function start(MaintenanceWorkOrder $maintenanceWorkOrder, MaintenanceWorkflowService $service): JsonResponse
    {
        return $this->guard(fn () => $service->start($maintenanceWorkOrder));
    }

    public function recordEvent(
        RecordMaintenanceWorkOrderEventRequest $request,
        MaintenanceWorkOrder $maintenanceWorkOrder,
        string $eventAction,
        MaintenanceWorkflowService $service,
    ): JsonResponse|RedirectResponse {
        try {
            $order = $service->recordExecutionEvent($maintenanceWorkOrder, $eventAction, $request->validated());

            return $request->expectsJson()
                ? response()->json(['success' => true, 'status' => $order->status, 'message' => __('maintenance.messages.event_recorded')])
                : redirect()->route('admin.maintenance.orders.show', $order)->with('success', __('maintenance.messages.event_recorded'));
        } catch (DomainException $exception) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $exception->getMessage()], 422)
                : back()->withInput()->withErrors(['event' => $exception->getMessage()]);
        }
    }

    public function complete(RecordMaintenanceCompletionRequest $request, MaintenanceWorkOrder $maintenanceWorkOrder, MaintenanceWorkflowService $service): RedirectResponse
    {
        try {
            $order = $service->complete($maintenanceWorkOrder, $request->validated());

            $message = $order->status === MaintenanceWorkOrder::StatusCompleted
                ? __('maintenance.messages.order_completed')
                : __('maintenance.messages.failed_test_recorded');

            return redirect()->route('admin.maintenance.orders.index')->with('success', $message);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['order' => $exception->getMessage()]);
        }
    }

    public function close(MaintenanceWorkOrder $maintenanceWorkOrder, MaintenanceWorkflowService $service): JsonResponse
    {
        return $this->guard(fn () => $service->close($maintenanceWorkOrder));
    }

    private function requestForm(Request $request, ?MaintenanceRequest $record = null, string $mode = 'create'): View
    {
        $record?->load(['asset', 'mold', 'workOrder']);

        return view('modules.maintenance.requests.form', [
            'record' => $record,
            'mode' => $mode,
            'maintainables' => $this->selectedMaintainables($request, $record?->fixed_asset_id, $record?->production_mold_id),
        ]);
    }

    private function orderForm(Request $request, ?MaintenanceWorkOrder $record = null, string $mode = 'create'): View
    {
        $context = $this->requiredContext($request);
        $record?->load(['asset', 'mold', 'request', 'supplier']);
        $requestRecord = $record?->request;
        if (! $record && $request->filled('request')) {
            $requestRecord = MaintenanceRequest::query()
                ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
                ->where('doc_num', $request->string('request')->toString())
                ->firstOrFail();
        }
        $assetId = $requestRecord?->fixed_asset_id ?? $record?->fixed_asset_id;
        $moldId = $requestRecord?->production_mold_id ?? $record?->production_mold_id;
        $supplierId = (int) old('supplier_id', $record?->supplier_id ?? 0);

        return view('modules.maintenance.orders.form', [
            'record' => $record,
            'mode' => $mode,
            'requestRecord' => $requestRecord,
            'maintainables' => $this->selectedMaintainables($request, $assetId, $moldId),
            'suppliers' => Supplier::query()->where('company_id', $context['company_id'])->whereKey($supplierId ?: -1)->get(),
        ]);
    }

    private function materialRequestForm(Request $request, ?MaintenanceMaterialRequest $record = null, string $mode = 'create'): View
    {
        $context = $this->requiredContext($request);
        $record?->load(['workOrder.asset', 'workOrder.mold', 'store', 'lines.product']);
        $selectedOrderId = (int) old('maintenance_work_order_id', $record?->maintenance_work_order_id ?? $request->integer('order'));
        $selectedProductIds = collect(old('lines', []))->pluck('product_id')->merge($record?->lines->pluck('product_id') ?? [])->filter()->map(fn ($id): int => (int) $id)->unique();

        return view('modules.maintenance.material-requests.form', [
            'record' => $record,
            'mode' => $mode,
            'orders' => MaintenanceWorkOrder::query()
                ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
                ->where(fn ($query) => $query
                    ->whereNotIn('status', [MaintenanceWorkOrder::StatusCompleted, MaintenanceWorkOrder::StatusClosed, MaintenanceWorkOrder::StatusCancelled])
                    ->orWhere('id', $selectedOrderId))
                ->with(['asset', 'mold'])->latest()->get(),
            'stores' => BranchStore::query()->where('branch_id', $context['branch_id'])->orderBy('position')->get(),
            'products' => Product::query()
                ->where('company_id', $context['company_id'])
                ->where(fn ($query) => $query->where('status', 'active')->orWhereIn('id', $selectedProductIds))
                ->where('item_classification', '!=', Product::ClassificationService)
                ->orderBy('name')->get(),
        ]);
    }

    private function expenseForm(Request $request, ?ProductionExpenseRequest $record = null, string $mode = 'create'): View
    {
        $context = $this->requiredContext($request);
        $record?->load(['maintenanceWorkOrder.asset', 'maintenanceWorkOrder.mold', 'currency', 'cashbox', 'bankAccount', 'expenseAccount']);
        $selectedOrderId = (int) old('maintenance_work_order_id', $record?->maintenance_work_order_id ?? $request->integer('order'));

        return view('modules.maintenance.expenses.form', [
            'record' => $record,
            'mode' => $mode,
            'orders' => MaintenanceWorkOrder::query()
                ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
                ->where(fn ($query) => $query
                    ->whereNotIn('status', [MaintenanceWorkOrder::StatusCompleted, MaintenanceWorkOrder::StatusClosed, MaintenanceWorkOrder::StatusCancelled])
                    ->orWhere('id', $selectedOrderId))
                ->with(['asset', 'mold'])->latest()->get(),
            'currencies' => Currency::query()->forCompany($context['company_id'])->active()->get(),
            'cashboxes' => Cashbox::query()->forCompany($context['company_id'])->where('branch_id', $context['branch_id'])->active()->get(),
            'bankAccounts' => BankAccount::query()->forCompany($context['company_id'])->active()->get(),
            'expenseAccounts' => Account::query()->forCompany($context['company_id'])->active()->where('account_type', Account::TypeExpense)->where('is_postable', true)->get(),
        ]);
    }

    /** @return Collection<int, Model> */
    private function selectedMaintainables(Request $request, ?int $assetId = null, ?int $moldId = null): Collection
    {
        $context = $this->requiredContext($request);
        $old = old('maintainable_key');
        if (is_string($old) && str_contains($old, ':')) {
            [$type, $id] = explode(':', $old, 2);
            $assetId = $type === 'asset' && ctype_digit($id) ? (int) $id : null;
            $moldId = $type === 'mold' && ctype_digit($id) ? (int) $id : null;
        }

        return FixedAsset::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereKey($assetId ?: -1)
            ->get()
            ->concat(ProductionMold::query()
                ->where('company_id', $context['company_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereKey($moldId ?: -1)
                ->get());
    }

    private function maintainablesLookup(
        Request $request,
        DataTableSearchService $search,
        Select2ResponseService $select2,
        array $context,
        array $terms,
    ): JsonResponse {
        $perPage = $select2->perPage();
        $page = max(1, $request->integer('page', 1));
        $limit = min(200, ($page * $perPage) + 1);
        $assets = tap(FixedAsset::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereNotIn('status', [FixedAsset::StatusDisposed, FixedAsset::StatusSold, FixedAsset::StatusWrittenOff])
            ->orderBy('asset_name'), fn ($query) => $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'asset_name', 'serial_number']]))
            ->limit($limit)->get()
            ->map(fn (FixedAsset $asset): array => ['id' => 'asset:'.$asset->getKey(), 'text' => __('maintenance.maintainable_types.asset').' — '.trim($asset->doc_num.' — '.$asset->asset_name)]);
        $molds = tap(ProductionMold::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereNot('status', ProductionMold::StatusUnavailable)
            ->orderBy('name'), fn ($query) => $search->applyMultiTermSearch($query, $terms, ['text' => ['code', 'name']]))
            ->limit($limit)->get()
            ->map(fn (ProductionMold $mold): array => ['id' => 'mold:'.$mold->getKey(), 'text' => __('maintenance.maintainable_types.mold').' — '.trim($mold->code.' — '.$mold->name)]);
        $items = $assets->concat($molds)->sortBy('text')->values();
        $offset = ($page - 1) * $perPage;

        return response()->json([
            'results' => $items->slice($offset, $perPage)->values()->all(),
            'pagination' => ['more' => $items->count() > $offset + $perPage],
        ]);
    }

    private function redirectAfterSave(Request $request, Model $record, string $routePrefix, string $message): RedirectResponse
    {
        $action = $request->string('submit_action')->trim()->toString();
        $route = match ($action) {
            'save_view', 'save' => $routePrefix.'.show',
            'save_edit' => $routePrefix.'.edit',
            'save_new' => $routePrefix.'.create',
            default => $routePrefix.'.index',
        };
        $parameters = in_array($action, ['save_view', 'save', 'save_edit'], true) ? [$record] : [];

        return redirect()->route($route, $parameters)->with('success', $message);
    }

    /** @param class-string<Model> $model */
    private function bulkDeleteScopedDocuments(Request $request, string $model, callable $canDelete): JsonResponse
    {
        $docNums = $request->validate(['doc_nums' => ['required', 'array', 'max:100'], 'doc_nums.*' => ['required', 'string', 'distinct']])['doc_nums'];
        $context = $this->requiredContext($request);
        $deleted = 0;
        DB::transaction(function () use ($model, $docNums, $context, $request, $canDelete, &$deleted): void {
            $records = $model::query()->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])->whereIn('doc_num', $docNums)->lockForUpdate()->get();
            foreach ($records as $record) {
                if (! $canDelete($record)) {
                    continue;
                }
                $record->update(['deleted_by' => $request->user()?->getKey()]);
                $record->delete();
                $deleted++;
            }
        });

        return response()->json(['success' => true, 'message' => __('maintenance.messages.bulk_deleted', ['count' => $deleted])]);
    }

    private function orderIsDeletable(MaintenanceWorkOrder $order): bool
    {
        return $order->status === MaintenanceWorkOrder::StatusDraft
            && ! $order->materialRequests()->exists()
            && ! $order->expenses()->exists()
            && ! $order->events()->exists();
    }

    private function materialRequestIsEditable(MaintenanceMaterialRequest $request): bool
    {
        return $request->status === MaintenanceMaterialRequest::StatusSubmitted
            && $request->inventory_issue_document_id === null
            && $request->inventory_return_document_id === null;
    }

    private function trashedOrder(Request $request, string $docNum): MaintenanceWorkOrder
    {
        $context = $this->requiredContext($request);

        return MaintenanceWorkOrder::onlyTrashed()->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])->with('request')->where('doc_num', $docNum)->firstOrFail();
    }

    private function trashedMaterialRequest(Request $request, string $docNum): MaintenanceMaterialRequest
    {
        $context = $this->requiredContext($request);

        return MaintenanceMaterialRequest::onlyTrashed()->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])->where('doc_num', $docNum)->firstOrFail();
    }

    private function trashedExpense(Request $request, string $docNum): ProductionExpenseRequest
    {
        $context = $this->requiredContext($request);

        return ProductionExpenseRequest::onlyTrashed()->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])->whereNotNull('maintenance_work_order_id')->where('doc_num', $docNum)->firstOrFail();
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('maintenance.messages.operating_context_required'));

        return ['company_id' => (int) $context['company_id'], 'financial_period_id' => (int) $context['financial_period_id'], 'branch_id' => (int) $context['branch_id']];
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

    /** @return Collection<int, ProductionMold> */
    private function molds(Request $request): Collection
    {
        $context = $this->context->snapshot($request);

        return ProductionMold::query()
            ->when($context['company_id'] && $context['branch_id'], fn ($query) => $query->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNot('status', ProductionMold::StatusUnavailable)
            ->orderBy('name')
            ->get();
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
                'asset', 'mold', 'supplier', 'request', 'maintenancePlanDue.plan',
                'materialRequests.lines.product', 'materialRequests.lines.unit', 'materialRequests.issueDocument.lines', 'materialRequests.returnDocument.lines',
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
            'maintenance_type' => ['nullable', 'in:preventive,corrective,emergency,condition_based,external'],
            'service_mode' => ['nullable', 'in:internal,external,mixed'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'discipline' => ['nullable', 'in:electrical,mechanical,molds,other'],
            'test_result' => ['nullable', 'in:passed,failed'],
            'status' => ['nullable', 'in:draft,approved,in_progress,completed,closed,cancelled'],
            'operational_focus' => ['nullable', 'in:open,breakdown,overdue,planned_due'],
        ]);

        $orders = MaintenanceWorkOrder::query()
            ->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id'])
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['maintenance_type'] ?? null, fn ($query, $value) => $query->where('maintenance_type', $value))
            ->when($filters['service_mode'] ?? null, fn ($query, $value) => $query->where('service_mode', $value))
            ->when($filters['priority'] ?? null, fn ($query, $value) => $query->where('priority', $value))
            ->when($filters['discipline'] ?? null, fn ($query, $value) => $query->where('discipline', $value))
            ->when($filters['test_result'] ?? null, fn ($query, $value) => $query->where('test_result', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when(($filters['operational_focus'] ?? null) === 'open', fn ($query) => $query->operationallyOpen())
            ->when(($filters['operational_focus'] ?? null) === 'overdue', fn ($query) => $query->overdue())
            ->with([
                'asset', 'mold', 'supplier', 'request', 'maintenancePlanDue.plan',
                'materialRequests.lines.product', 'materialRequests.lines.unit', 'materialRequests.issueDocument.lines', 'materialRequests.returnDocument.lines',
                'expenses.currency', 'expenses.cashVoucher', 'expenses.journalEntry',
            ])
            ->latest('created_at')
            ->get();
        $requests = MaintenanceRequest::query()
            ->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id'])
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('reported_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('reported_at', '<=', $date))
            ->when(in_array($filters['operational_focus'] ?? null, ['open', 'breakdown'], true), fn ($query) => $query->operationallyOpen())
            ->when(($filters['operational_focus'] ?? null) === 'breakdown', fn ($query) => $query->breakdowns())
            ->with(['asset', 'mold', 'workOrder'])
            ->latest('reported_at')
            ->get();
        $planDues = MaintenancePlanDue::query()
            ->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id'])
            ->operationallyOpen()
            ->when(($filters['operational_focus'] ?? null) === 'overdue', fn ($query) => $query->overdue())
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('due_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('due_at', '<=', $date))
            ->with(['plan.asset', 'plan.mold', 'workOrder'])
            ->orderBy('due_at')
            ->get();
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
            $releasedAt = $order->machine_released_at ?? $order->actual_end_at;
            if (! $order->actual_start_at || ! $releasedAt || $releasedAt->lessThan($order->actual_start_at)) {
                return 0;
            }

            return (int) $order->actual_start_at->diffInMinutes($releasedAt);
        });
        $waitMinutes = $orders->sum(function (MaintenanceWorkOrder $order): int {
            $currentPauseMinutes = $order->paused_at ? (int) $order->paused_at->diffInMinutes(now()) : 0;

            return $order->total_paused_minutes + $currentPauseMinutes;
        });

        return [
            'context' => $context,
            'filters' => $filters,
            'orders' => $orders,
            'requests' => $requests,
            'planDues' => $planDues,
            'expenseTotals' => $expenseTotals,
            'canViewFinancial' => (bool) $request->user()?->can('maintenance.reports.financial'),
            'kpis' => [
                'breakdown_reports' => $requests->where('request_type', 'breakdown')->count(),
                'open_requests' => $requests->where('status', MaintenanceRequest::StatusOpen)->count(),
                'planned_due' => $planDues->count(),
                'overdue' => $orders->filter(fn (MaintenanceWorkOrder $order): bool => in_array($order->status, [MaintenanceWorkOrder::StatusDraft, MaintenanceWorkOrder::StatusApproved, MaintenanceWorkOrder::StatusInProgress], true) && $order->planned_end_at?->isPast())->count()
                    + $planDues->filter(fn (MaintenancePlanDue $due): bool => $due->due_at?->isPast())->count(),
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
                'wait_hours' => round($waitMinutes / 60, 2),
                'active_repair_hours' => round(max(0, $downtimeMinutes - $waitMinutes) / 60, 2),
                'requested_material_quantity' => $materialLines->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->requested_quantity, 8), '0.00000000'),
                'issued_material_quantity' => $materialLines->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->issued_quantity, 8), '0.00000000'),
                'consumed_material_quantity' => $materialLines->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->consumed_quantity, 8), '0.00000000'),
                'returned_material_quantity' => $materialLines->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->returned_quantity, 8), '0.00000000'),
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

    private function assertMaterialRequestInContext(Request $request, MaintenanceMaterialRequest $maintenanceMaterialRequest): void
    {
        $context = $this->context->snapshot($request);
        abort_unless(
            $context['company_id'] && $context['financial_period_id'] && $context['branch_id']
            && (int) $maintenanceMaterialRequest->company_id === (int) $context['company_id']
            && (int) $maintenanceMaterialRequest->financial_period_id === (int) $context['financial_period_id']
            && (int) $maintenanceMaterialRequest->branch_id === (int) $context['branch_id'],
            404,
        );
    }

    private function assertMaintenanceExpenseInContext(Request $request, ProductionExpenseRequest $expense): void
    {
        $context = $this->context->snapshot($request);
        abort_unless(
            $expense->maintenance_work_order_id !== null
            && $context['company_id'] && $context['financial_period_id'] && $context['branch_id']
            && (int) $expense->company_id === (int) $context['company_id']
            && (int) $expense->financial_period_id === (int) $context['financial_period_id']
            && (int) $expense->branch_id === (int) $context['branch_id'],
            404,
        );
    }
}

<?php

namespace Modules\Purchases\Http\Controllers;

use Closure;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrGovernorate;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Purchases\DataTables\ProcurementDocumentsDataTable;
use Modules\Purchases\Exports\ProcurementCycleReportExport;
use Modules\Purchases\Http\Requests\ProcurementWorkflowRequest;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderChangeRequest;
use Modules\Purchases\Models\PurchaseOrderDeliverySchedule;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\PurchaseReturnLine;
use Modules\Purchases\Models\RequestForQuotation;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Models\SupplierSelection;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Purchases\Services\ProcurementAuditService;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Modules\Purchases\Services\SupplyOrderService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProcurementWorkflowController extends Controller
{
    public function __construct(
        private readonly ProcurementSourcingService $sourcing,
        private readonly ProcurementReceivingService $receiving,
        private readonly ProcurementSettlementService $settlement,
        private readonly OperatingContextService $operatingContext,
        private readonly ProcurementCycleReport $procurementReport,
        private readonly SupplyOrderService $supplyOrders,
    ) {}

    public function requisitionsIndex(): View
    {
        return $this->documentIndex('purchase_requisitions');
    }

    public function requisitionLinesIndex(): RedirectResponse
    {
        return to_route('admin.purchases.purchase-requisitions.index');
    }

    public function requisitionApprovalsIndex(): RedirectResponse
    {
        return to_route('admin.purchases.purchase-requisitions.index');
    }

    public function createRequisition(): View
    {
        return $this->requisitionForm();
    }

    public function editRequisition(PurchaseRequisition $purchaseRequisition): View
    {
        $this->assertRequisitionOrigin($purchaseRequisition);
        abort_if($purchaseRequisition->isLockedForEditing(), 403);

        return $this->requisitionForm($purchaseRequisition);
    }

    private function requisitionForm(?PurchaseRequisition $record = null): View
    {
        $context = $this->context();
        try {
            $store = $this->sourcing->requisitionStore($context, $record?->branchStore?->public_uuid);
        } catch (DomainException $exception) {
            abort(403, $exception->getMessage());
        }
        $record?->load(['lines.product.equivalentUnit', 'lines.product.unit', 'lines.unit', 'requesterEmployee', 'branchStore']);
        $employeeId = old('requester_employee_id', $record?->requester_employee_id);
        $employee = filled($employeeId) ? HrEmployee::query()->where('company_id', $context['company_id'])->find($employeeId) : null;
        $productNumbers = collect(old('lines', []))->pluck('product_doc_num')->filter()->unique();
        $products = Product::query()->forCompany($context['company_id'])->whereIn('doc_num', $productNumbers)->with(['unit', 'equivalentUnit'])->get()->keyBy('doc_num');

        return view('modules.purchases.procurement.requisition-form', [
            'record' => $record, 'company' => Company::query()->findOrFail($context['company_id']),
            'branch' => Branch::query()->findOrFail($context['branch_id']), 'store' => $store,
            'employee' => $employee, 'products' => $products,
        ]);
    }

    public function updateRequisition(ProcurementWorkflowRequest $request, PurchaseRequisition $purchaseRequisition): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->updateRequisition($purchaseRequisition, $request->validated()), 'admin.purchases.purchase-requisitions.show');
    }

    public function rejectRequisition(ProcurementWorkflowRequest $request, PurchaseRequisition $purchaseRequisition): JsonResponse|RedirectResponse
    {
        $this->assertRequisitionApprovalBranch($purchaseRequisition);

        return $this->execute($request, fn () => $this->sourcing->rejectRequisition($purchaseRequisition, $request->validated('rejection_reason')), 'admin.purchases.purchase-requisitions.show');
    }

    public function cancelRequisition(ProcurementWorkflowRequest $request, PurchaseRequisition $purchaseRequisition): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->finishRequisition($purchaseRequisition, PurchaseRequisition::StatusCancelled, $request->validated('cancel_reason')), 'admin.purchases.purchase-requisitions.show');
    }

    public function closeRequisition(ProcurementWorkflowRequest $request, PurchaseRequisition $purchaseRequisition): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->finishRequisition($purchaseRequisition, PurchaseRequisition::StatusClosed), 'admin.purchases.purchase-requisitions.show');
    }

    public function destroyRequisition(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse|RedirectResponse
    {
        $this->assertRequisitionOrigin($purchaseRequisition);
        try {
            $this->sourcing->deleteRequisition($purchaseRequisition);
        } catch (DomainException $exception) {
            return back()->withErrors(['document' => $exception->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['success' => true]) : to_route('admin.purchases.purchase-requisitions.index');
    }

    public function requisitionAvailability(Request $request, InventoryAvailabilityService $availability): JsonResponse
    {
        $input = $request->validate(['branch_store_uuid' => ['nullable', 'uuid'], 'product_doc_num' => ['required', 'string']]);
        $context = $this->context();
        $store = $this->sourcing->requisitionStore($context, $input['branch_store_uuid'] ?? null);
        $product = Product::query()->forCompany($context['company_id'])->active()->purchasable()->where('doc_num', $input['product_doc_num'])->firstOrFail();
        $storeIds = $store ? [$store->getKey()] : BranchStore::query()->where('branch_id', $context['branch_id'])->purchasingEligible()->pluck('id')->all();
        $stock = ['on_hand' => 0, 'reserved' => 0, 'available' => 0];
        foreach ($storeIds as $storeId) {
            $balance = $availability->forProduct($context['company_id'], $storeId, $product->getKey());
            foreach (array_keys($stock) as $key) {
                $stock[$key] += (float) $balance[$key];
            }
        }

        return response()->json($stock);
    }

    public function storeRequisition(ProcurementWorkflowRequest $request): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->createRequisition($request->validated()), 'admin.purchases.purchase-requisitions.show');
    }

    public function showRequisition(PurchaseRequisition $purchaseRequisition): View
    {
        $this->assertCurrent($purchaseRequisition);
        $purchaseRequisition->load(['lines.product', 'lines.unit', 'branch', 'branchStore', 'requestsForQuotation', 'supplierQuotations', 'suggestedSupplier', 'requesterEmployee', 'requestedBy', 'submittedBy', 'approvedBy', 'rejectedBy']);

        return $this->showView('purchase_requisition', $purchaseRequisition, false);
    }

    public function submitRequisition(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->submitRequisition($purchaseRequisition), 'admin.purchases.purchase-requisitions.show');
    }

    public function approveRequisition(ProcurementWorkflowRequest $request, PurchaseRequisition $purchaseRequisition): JsonResponse|RedirectResponse
    {
        $this->assertRequisitionApprovalBranch($purchaseRequisition);

        return $this->execute($request, fn () => $this->sourcing->approveRequisition($purchaseRequisition, $request->validated('approved_quantities', [])), 'admin.purchases.purchase-requisitions.show');
    }

    public function rfqsIndex(): View
    {
        return $this->documentIndex('request_for_quotations');
    }

    public function createRfq(PurchaseRequisition $purchaseRequisition): View|RedirectResponse
    {
        $this->assertAdministrativeBranch();
        $this->assertCurrent($purchaseRequisition);

        if (! in_array($purchaseRequisition->status, [
            PurchaseRequisition::StatusApproved,
            PurchaseRequisition::StatusPartiallyConverted,
        ], true)) {
            return to_route('admin.purchases.purchase-requisitions.show', $purchaseRequisition)
                ->withErrors(['purchase_requisition' => __('The purchase requisition must be approved before creating a request for quotation.')]);
        }

        $purchaseRequisition->load(['lines.product', 'lines.unit']);

        return view('modules.purchases.procurement.rfq-form', [
            'record' => $purchaseRequisition,
            'suppliers' => Supplier::query()->forCompany($this->context()['company_id'])->whereIn('doc_num', old('supplier_doc_nums', []))->get(),
        ]);
    }

    public function editRfq(RequestForQuotation $record): View|RedirectResponse
    {
        $this->assertCurrent($record);
        abort_unless($record->status === 'draft', 403);
        $record->loadMissing('lines');
        $view = $this->createRfq($record->requisition);

        return $view instanceof View ? $view->with(['draft' => $record, 'suppliers' => $record->suppliers]) : $view;
    }

    public function updateRfq(ProcurementWorkflowRequest $request, RequestForQuotation $record): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->updateRequestForQuotation($record, $request->validated()), 'admin.purchases.request-for-quotations.show');
    }

    public function destroyRfq(Request $request, RequestForQuotation $record): JsonResponse|RedirectResponse
    {
        return $this->execute($request, function () use ($record) {
            $this->sourcing->deleteSourcingDraft($record);

            return $record;
        }, 'admin.purchases.request-for-quotations.index');
    }

    public function storeRfq(ProcurementWorkflowRequest $request, PurchaseRequisition $purchaseRequisition): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();

        return $this->execute($request, fn () => $this->sourcing->createRequestForQuotation($purchaseRequisition, $request->validated()), 'admin.purchases.request-for-quotations.show');
    }

    public function showRfq(RequestForQuotation $requestForQuotation): View
    {
        $this->assertCurrent($requestForQuotation);
        $requestForQuotation->load(['requisition', 'lines.product', 'lines.unit', 'suppliers', 'quotations.supplier']);

        return $this->showView('request_for_quotation', $requestForQuotation, false);
    }

    public function issueRfq(Request $request, RequestForQuotation $requestForQuotation): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->issueRequestForQuotation($requestForQuotation), 'admin.purchases.request-for-quotations.show');
    }

    public function quotationsIndex(): View
    {
        return $this->documentIndex('supplier_quotations');
    }

    public function chooseQuotationSource(): View
    {
        $this->assertAdministrativeBranch();

        return view('modules.purchases.procurement.quotation-source-picker');
    }

    public function quotationLinesIndex(): RedirectResponse
    {
        return to_route('admin.purchases.supplier-quotation-entry.index');
    }

    public function createQuotation(RequestForQuotation $requestForQuotation): View
    {
        $this->assertAdministrativeBranch();

        return $this->quotationForm($requestForQuotation);
    }

    public function createQuotationFromSource(string $sourceType, string $sourceDocument): View
    {
        $this->assertAdministrativeBranch();

        return $this->quotationForm($this->supplierQuotationSource($sourceType, $sourceDocument));
    }

    public function editQuotation(SupplierQuotation $record): View|RedirectResponse
    {
        $this->assertCurrent($record);
        abort_unless($record->status === 'draft', 403);
        $record->loadMissing(['lines', 'requestForQuotation', 'purchaseRequisition', 'purchaseOrder']);
        $source = $record->sourceDocument();
        abort_unless($source, 404);
        $view = $this->quotationForm($source, $record);

        return $view instanceof View ? $view : abort(404);
    }

    public function updateQuotation(ProcurementWorkflowRequest $request, SupplierQuotation $record): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->updateSupplierQuotation($record, $request->validated()), 'admin.purchases.supplier-quotation-entry.show');
    }

    public function destroyQuotation(Request $request, SupplierQuotation $record): JsonResponse|RedirectResponse
    {
        return $this->execute($request, function () use ($record) {
            $this->sourcing->deleteSourcingDraft($record);

            return $record;
        }, 'admin.purchases.supplier-quotation-entry.index');
    }

    public function storeQuotation(ProcurementWorkflowRequest $request, RequestForQuotation $requestForQuotation): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();

        return $this->execute($request, fn () => $this->sourcing->createSupplierQuotation($requestForQuotation, $request->validated()), 'admin.purchases.supplier-quotation-entry.show');
    }

    public function storeQuotationFromSource(ProcurementWorkflowRequest $request, string $sourceType, string $sourceDocument): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();
        $source = $this->supplierQuotationSource($sourceType, $sourceDocument);

        return $this->execute($request, fn () => $this->sourcing->createSupplierQuotation($source, $request->validated()), 'admin.purchases.supplier-quotation-entry.show');
    }

    public function showQuotation(SupplierQuotation $supplierQuotation): View
    {
        $this->assertCurrent($supplierQuotation);
        $supplierQuotation->load(['supplier', 'currency', 'requestForQuotation', 'purchaseRequisition', 'purchaseOrder', 'lines.product', 'lines.unit', 'attachmentUsages.file']);

        return $this->showView('supplier_quotation', $supplierQuotation, true);
    }

    public function submitQuotation(Request $request, SupplierQuotation $supplierQuotation): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->submitSupplierQuotation($supplierQuotation), 'admin.purchases.supplier-quotation-entry.show');
    }

    public function comparisonIndex(): View
    {
        $context = $this->context();
        $records = RequestForQuotation::query()->forContext($context['company_id'], $context['financial_period_id'])
            ->whereHas('quotations', fn ($query) => $query->where('status', 'submitted'))
            ->withCount('quotations')->latest('issue_date')->paginate(25);

        return $this->indexView('quotation_comparisons', __('Supplier Quotation Comparison'), $records, true);
    }

    public function compare(RequestForQuotation $requestForQuotation): View
    {
        $requestForQuotation->load(['lines.product', 'lines.unit']);

        return view('modules.purchases.procurement.comparison', [
            'record' => $requestForQuotation,
            'lines' => $this->sourcing->comparison($requestForQuotation),
        ]);
    }

    public function selectionsIndex(): View
    {
        $context = $this->context();
        $records = SupplierSelection::query()->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->with('requestForQuotation')->withCount('lines')->latest('selection_date')->paginate(25);

        return $this->indexView('supplier_selections', __('Supplier Selections'), $records, true);
    }

    public function createSelection(RequestForQuotation $requestForQuotation): View
    {
        return view('modules.purchases.procurement.selection-form', [
            'record' => $requestForQuotation,
            'lines' => $this->sourcing->comparison($requestForQuotation),
        ]);
    }

    public function storeSelection(ProcurementWorkflowRequest $request, RequestForQuotation $requestForQuotation): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->createSupplierSelection($requestForQuotation, $request->validated()), 'admin.purchases.supplier-selection.show');
    }

    public function showSelection(SupplierSelection $supplierSelection): View
    {
        $this->assertCurrent($supplierSelection);
        $supplierSelection->load(['requestForQuotation', 'lines.supplier', 'lines.product', 'lines.unit', 'lines.purchaseOrder']);

        return $this->showView('supplier_selection', $supplierSelection, true);
    }

    public function approveSelection(Request $request, SupplierSelection $supplierSelection): JsonResponse|RedirectResponse
    {
        return $this->execute($request, function () use ($supplierSelection): SupplierSelection {
            $this->sourcing->approveSelection($supplierSelection);

            return $supplierSelection->refresh();
        }, 'admin.purchases.supplier-selection.show');
    }

    public function changeRequestsIndex(): View
    {
        $context = $this->context();
        $records = PurchaseOrderChangeRequest::query()->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])->with('purchaseOrder')->latest('request_date')->paginate(25);

        return $this->indexView('purchase_order_change_requests', __('Purchase Order Change Requests'), $records, true);
    }

    public function createChangeRequest(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load('lines.product');

        return view('modules.purchases.procurement.change-request-form', ['record' => $purchaseOrder]);
    }

    public function storeChangeRequest(ProcurementWorkflowRequest $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->settlement->requestPurchaseOrderChange($purchaseOrder, $request->validated()), 'admin.purchases.purchase-order-change-requests.show');
    }

    public function showChangeRequest(PurchaseOrderChangeRequest $purchaseOrderChangeRequest): View
    {
        $purchaseOrderChangeRequest->load('purchaseOrder.supplier');

        return $this->showView('purchase_order_change_request', $purchaseOrderChangeRequest, true);
    }

    public function approveChangeRequest(Request $request, PurchaseOrderChangeRequest $purchaseOrderChangeRequest): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->settlement->approvePurchaseOrderChange($purchaseOrderChangeRequest), 'admin.purchases.purchase-order-change-requests.show');
    }

    public function deliverySchedulesIndex(): View
    {
        $context = $this->context();
        $records = PurchaseOrderDeliverySchedule::query()->with(['purchaseOrder.supplier', 'purchaseOrderLine.product'])
            ->where('company_id', $context['company_id'])->where('financial_period_id', $context['financial_period_id'])
            ->orderBy('scheduled_date')->paginate(25);

        return $this->indexView('delivery_schedules', __('Purchase Order Delivery Schedule'), $records, false);
    }

    public function createDeliverySchedule(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load(['supplier', 'lines.product', 'lines.unit', 'lines.deliverySchedules']);

        return view('modules.purchases.procurement.delivery-form', ['record' => $purchaseOrder]);
    }

    public function storeDeliverySchedule(ProcurementWorkflowRequest $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->receiving->createDeliverySchedules($purchaseOrder, $request->validated()), 'admin.purchases.purchase-orders.show');
    }

    public function supplyOrdersIndex(): View
    {
        return $this->documentIndex('supply_orders');
    }

    public function createSupplyOrder(Request $request): View
    {
        $this->assertAdministrativeBranch();
        $context = $this->context();
        $sourceType = $request->filled('purchase_invoice') ? SupplyOrder::SourcePurchaseInvoice : SupplyOrder::SourcePurchaseOrder;
        $sourceDocNum = $request->string($sourceType === SupplyOrder::SourcePurchaseInvoice ? 'purchase_invoice' : 'purchase_order')->trim()->toString();
        if ($sourceDocNum === '') {
            return view('modules.purchases.procurement.supply-order-source');
        }
        $source = $sourceType === SupplyOrder::SourcePurchaseOrder
            ? PurchaseOrder::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('doc_num', $sourceDocNum)->firstOrFail()
            : PurchaseInvoice::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('doc_num', $sourceDocNum)->firstOrFail();

        return $this->supplyOrderForm($source);
    }

    public function editSupplyOrder(SupplyOrder $supplyOrder): View
    {
        $this->assertAdministrativeBranch();
        abort_unless($supplyOrder->status === SupplyOrder::StatusDraft, 403);
        $source = $supplyOrder->source_type === SupplyOrder::SourcePurchaseInvoice
            ? $supplyOrder->purchaseInvoice
            : $supplyOrder->purchaseOrder;

        return $this->supplyOrderForm($source, $supplyOrder);
    }

    private function supplyOrderForm(PurchaseOrder|PurchaseInvoice $source, ?SupplyOrder $record = null): View
    {
        $source->loadMissing(['supplier', 'lines.product', 'lines.unit']);
        if ($source instanceof PurchaseInvoice) {
            $source->loadMissing('purchaseOrder.branchStore');
        } else {
            $source->loadMissing('branchStore');
        }
        $sourceLines = $this->supplyOrders->sourceLines($source, $record);
        $record?->load(['lines', 'supplier', 'branchStore']);

        return view('modules.purchases.procurement.supply-order-form', compact('source', 'sourceLines', 'record'));
    }

    public function storeSupplyOrder(ProcurementWorkflowRequest $request): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();

        return $this->execute($request, fn () => $this->supplyOrders->create($request->validated()), 'admin.purchases.supply-orders.show');
    }

    public function updateSupplyOrder(ProcurementWorkflowRequest $request, SupplyOrder $supplyOrder): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();

        return $this->execute($request, fn () => $this->supplyOrders->update($supplyOrder, $request->validated()), 'admin.purchases.supply-orders.show');
    }

    public function showSupplyOrder(SupplyOrder $supplyOrder): View
    {
        $supplyOrder->load(['supplier', 'branchStore', 'purchaseOrder', 'purchaseInvoice', 'lines.product', 'lines.unit', 'lines.purchaseOrderLine', 'receipts']);
        $context = $this->context();
        abort_unless(
            (int) $supplyOrder->company_id === $context['company_id']
            && (int) $supplyOrder->financial_period_id === $context['financial_period_id']
            && (
                (int) $supplyOrder->branch_id === $context['branch_id']
                || ($supplyOrder->status !== SupplyOrder::StatusDraft && (int) $supplyOrder->branchStore?->branch_id === $context['branch_id'])
                || $this->isAdministrativeBranch()
            ),
            404,
        );

        return $this->showView('supply_order', $supplyOrder, false);
    }

    public function issueSupplyOrder(Request $request, SupplyOrder $supplyOrder): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();

        return $this->execute($request, fn () => $this->supplyOrders->issue($supplyOrder), 'admin.purchases.supply-orders.show');
    }

    public function cancelSupplyOrder(ProcurementWorkflowRequest $request, SupplyOrder $supplyOrder): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();

        return $this->execute($request, fn () => $this->supplyOrders->cancel($supplyOrder, $request->validated('cancel_reason')), 'admin.purchases.supply-orders.show');
    }

    public function destroySupplyOrder(Request $request, SupplyOrder $supplyOrder): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();

        try {
            $this->supplyOrders->deleteDraft($supplyOrder);
        } catch (DomainException $exception) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $exception->getMessage()], 422)
                : back()->withErrors(['document' => $exception->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['success' => true])
            : to_route('admin.purchases.supply-orders.index');
    }

    public function receiptsIndex(): View
    {
        return $this->documentIndex('goods_receipts');
    }

    public function receiptLinesIndex(): RedirectResponse
    {
        return to_route('admin.purchases.goods-receipt-notes.index');
    }

    public function createReceipt(string $sourceDocument): View
    {
        $this->assertInventoryBranch();
        $context = $this->context();
        $inspection = GoodsReceiptInspection::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('doc_num', $sourceDocument)
            ->whereIn('source_type', ['purchase_order', 'supply_order'])
            ->where('status', 'finalized')
            ->whereIn('result', ['accepted', 'partially_accepted'])
            ->with([
                'purchaseOrder.supplier', 'purchaseOrder.branchStore', 'supplyOrder',
                'lines.product', 'lines.unit', 'lines.purchaseOrderLine', 'lines.supplyOrderLine', 'lines.deliverySchedule',
                'lines.receiptLines.receipt',
            ])
            ->firstOrFail();
        abort_unless($inspection->purchaseOrder instanceof PurchaseOrder, 422);
        abort_unless($inspection->hasReceiptableQuantity(), 422);

        return view('modules.purchases.procurement.receipt-form', [
            'record' => $inspection->purchaseOrder,
            'sourceInspection' => $inspection,
        ]);
    }

    public function storeReceipt(ProcurementWorkflowRequest $request, string $sourceDocument): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();
        $context = $this->context();
        $inspection = GoodsReceiptInspection::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('doc_num', $sourceDocument)
            ->whereIn('source_type', ['purchase_order', 'supply_order'])
            ->where('status', 'finalized')
            ->whereIn('result', ['accepted', 'partially_accepted'])
            ->with('lines.receiptLines.receipt')
            ->firstOrFail();
        abort_unless($inspection->hasReceiptableQuantity(), 422);

        return $this->execute($request, fn () => $this->receiving->createReceiptFromInspection($inspection, $request->validated()), 'admin.purchases.goods-receipt-notes.show');
    }

    public function editReceipt(string $goodsReceiptNote): View
    {
        $this->assertInventoryBranch();
        $draft = $this->receipt($goodsReceiptNote)->load([
            'sourceInspection.lines.product', 'sourceInspection.lines.unit', 'sourceInspection.lines.purchaseOrderLine',
            'sourceInspection.lines.supplyOrderLine', 'sourceInspection.lines.deliverySchedule',
            'sourceInspection.lines.receiptLines.receipt',
            'lines.deliverySchedule', 'lines.supplyOrderLine',
            'purchaseOrder.lines' => fn ($query) => $query->withQuantityProgress()->with(['product', 'unit', 'deliverySchedules']),
            'supplyOrder.lines' => fn ($query) => $query->with(['product', 'unit', 'purchaseOrderLine.deliverySchedules']),
        ]);
        abort_unless($draft->status === 'draft' && $draft->posting_status === 'unposted' && ! $draft->hasBlockingInspection(), 403);

        if ($draft->sourceInspection instanceof GoodsReceiptInspection) {
            return view('modules.purchases.procurement.receipt-form', [
                'record' => $draft->purchaseOrder,
                'sourceInspection' => $draft->sourceInspection,
                'draft' => $draft,
            ]);
        }

        if ($draft->supplyOrder instanceof SupplyOrder) {
            return view('modules.purchases.procurement.supply-receipt-form', ['record' => $draft->supplyOrder, 'draft' => $draft]);
        }

        return view('modules.purchases.procurement.receipt-form', ['record' => $draft->purchaseOrder, 'draft' => $draft]);
    }

    public function updateReceipt(ProcurementWorkflowRequest $request, string $goodsReceiptNote): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();

        return $this->execute($request, fn () => $this->receiving->updateReceipt($this->receipt($goodsReceiptNote), $request->validated()), 'admin.purchases.goods-receipt-notes.show');
    }

    public function destroyReceipt(Request $request, string $goodsReceiptNote): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();

        return $this->execute($request, fn () => $this->receiving->deleteReceipt($this->receipt($goodsReceiptNote)), 'admin.purchases.goods-receipt-notes.index');
    }

    public function postReceipt(Request $request, string $goodsReceiptNote): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();

        return $this->execute($request, fn () => $this->receiving->postReceipt($this->receipt($goodsReceiptNote)), 'admin.purchases.goods-receipt-notes.show');
    }

    public function reverseReceipt(ProcurementWorkflowRequest $request, string $goodsReceiptNote): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();

        return $this->execute($request, fn () => $this->receiving->reverseReceipt($this->receipt($goodsReceiptNote), $request->validated('reversal_reason')), 'admin.purchases.goods-receipt-notes.show');
    }

    public function showReceipt(string $goodsReceiptNote): View
    {
        $receipt = $this->receipt($goodsReceiptNote)->load(['purchaseOrder', 'supplyOrder', 'supplier', 'branchStore', 'lines.product', 'lines.unit', 'lines.supplyOrderLine', 'inspection', 'sourceInspection']);

        return $this->showView('goods_receipt', $receipt, false);
    }

    public function cancelReceipt(ProcurementWorkflowRequest $request, string $goodsReceiptNote): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();
        $receipt = $this->receipt($goodsReceiptNote);

        return $this->execute(
            $request,
            fn () => $this->receiving->cancelBeforeQuality($receipt, (string) $request->validated('cancel_reason')),
            'admin.purchases.goods-receipt-notes.show',
            $goodsReceiptNote,
        );
    }

    public function inspectionsIndex(): View
    {
        return $this->documentIndex('goods_receipt_inspections');
    }

    public function createInspection(string $sourceDocument): View
    {
        $this->assertInventoryBranch();
        $context = $this->context();
        $source = SupplyOrder::query()
            ->where('company_id', $context['company_id'])
            ->whereHas('branchStore', fn ($query) => $query->where('branch_id', $context['branch_id']))
            ->where('doc_num', $sourceDocument)
            ->first();
        if ($source instanceof SupplyOrder) {
            abort_unless(in_array($source->status, [SupplyOrder::StatusIssued, SupplyOrder::StatusPartiallyReceived], true), 422);
            $source->load([
                'supplier', 'branchStore', 'purchaseOrder',
                'lines' => fn ($query) => $query
                    ->withSum(['receiptLines as committed_receipt_quantity' => fn ($receipts) => $receipts
                        ->whereHas('receipt', fn ($documents) => $documents->whereNotIn('status', ['cancelled', 'reversed']))], 'delivered_quantity')
                    ->with(['product', 'unit', 'inspectionLines.inspection', 'inspectionLines.receiptLines.receipt']),
                'lines.purchaseOrderLine' => fn ($query) => $query
                    ->withQuantityProgress()
                    ->withSum(['receiptLines as pending_receipt_quantity' => fn ($receipts) => $receipts
                        ->whereHas('receipt', fn ($documents) => $documents->where('posting_status', 'unposted')->where('status', 'draft'))], 'delivered_quantity')
                    ->with(['deliverySchedules', 'inspectionLines.inspection', 'inspectionLines.receiptLines.receipt']),
            ]);
        } else {
            $source = PurchaseOrder::query()
                ->where('company_id', $context['company_id'])
                ->whereHas('branchStore', fn ($query) => $query->where('branch_id', $context['branch_id']))
                ->where('doc_num', $sourceDocument)
                ->where('status', PurchaseOrder::StatusApproved)
                ->with([
                    'supplier', 'branchStore',
                    'lines' => fn ($query) => $query
                        ->withQuantityProgress()
                        ->withSum(['receiptLines as pending_receipt_quantity' => fn ($receipts) => $receipts
                            ->whereHas('receipt', fn ($documents) => $documents->where('posting_status', 'unposted')->where('status', 'draft'))], 'delivered_quantity')
                        ->with(['product', 'unit', 'deliverySchedules', 'inspectionLines.inspection', 'inspectionLines.receiptLines.receipt']),
                ])
                ->firstOrFail();
        }

        foreach ($source->lines as $line) {
            $pendingInspectionQuantity = $this->pendingInspectionQuantity($line->inspectionLines);
            if ($source instanceof SupplyOrder) {
                $orderLine = $line->purchaseOrderLine;
                $orderPendingInspectionQuantity = $orderLine === null
                    ? 0
                    : $this->pendingInspectionQuantity($orderLine->inspectionLines);
                $sourceRemaining = max(0, (float) $line->ordered_quantity - (float) $line->committed_receipt_quantity - $pendingInspectionQuantity);
                $orderRemaining = $orderLine === null ? 0 : max(
                    0,
                    $orderLine->quantityProgress()['remaining'] - (float) $orderLine->pending_receipt_quantity - $orderPendingInspectionQuantity,
                );
                $line->setAttribute('available_inspection_quantity', min($sourceRemaining, $orderRemaining));
            } else {
                $line->setAttribute('available_inspection_quantity', max(
                    0,
                    $line->quantityProgress()['remaining'] - (float) $line->pending_receipt_quantity - $pendingInspectionQuantity,
                ));
            }
        }

        return view('modules.purchases.procurement.inspection-form', ['record' => $source]);
    }

    public function storeInspection(ProcurementWorkflowRequest $request, string $sourceDocument): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();
        $context = $this->context();
        $source = SupplyOrder::query()
            ->where('company_id', $context['company_id'])
            ->whereHas('branchStore', fn ($query) => $query->where('branch_id', $context['branch_id']))
            ->where('doc_num', $sourceDocument)
            ->first()
            ?? PurchaseOrder::query()
                ->where('company_id', $context['company_id'])
                ->whereHas('branchStore', fn ($query) => $query->where('branch_id', $context['branch_id']))
                ->where('doc_num', $sourceDocument)
                ->firstOrFail();

        return $this->execute($request, fn () => $this->receiving->inspectPurchaseSource($source, $request->validated()), 'admin.purchases.goods-receipt-inspection.show');
    }

    public function showInspection(GoodsReceiptInspection $goodsReceiptInspection): View
    {
        $this->assertCurrent($goodsReceiptInspection);
        $goodsReceiptInspection->load([
            'branch', 'receipt.supplier', 'purchaseOrder.supplier', 'purchaseOrder.branchStore.branch',
            'supplyOrder.supplier', 'supplyOrder.branchStore.branch',
            'receipts.supplier', 'lines.product', 'lines.unit', 'lines.purchaseOrderLine', 'lines.supplyOrderLine',
            'lines.receiptLine', 'lines.receiptLines.receipt',
            'attachmentUsages.file',
        ]);

        return $this->showView('goods_receipt_inspection', $goodsReceiptInspection, false);
    }

    public function returnsIndex(): View
    {
        return $this->documentIndex('purchase_returns');
    }

    public function returnLinesIndex(): RedirectResponse
    {
        return to_route('admin.purchases.purchase-returns.index');
    }

    public function createReturn(Request $request, ?PurchaseReturn $draft = null): View
    {
        $this->assertInventoryBranch();
        $context = $this->context();
        $sourceReceipt = $draft?->receipt ?? (filled($request->query('receipt')) ? $this->receipt($request->query('receipt')) : null);
        if ($sourceReceipt instanceof UnpricedInventoryReceipt) {
            abort_unless($sourceReceipt->approved && $sourceReceipt->posting_status === 'posted' && ! in_array($sourceReceipt->status, ['cancelled', 'reversed'], true), 422, __('Only posted receipts can be returned.'));
        }
        $order = $sourceReceipt?->purchaseOrder ?? (filled($request->query('purchase_order'))
            ? PurchaseOrder::query()->forCompany($context['company_id'])
                ->whereHas('branchStore', fn ($query) => $query->where('branch_id', $context['branch_id']))
                ->where('doc_num', $request->query('purchase_order'))->firstOrFail() : null);
        $order?->load(['supplier', 'lines.product']);
        $receiptLines = $order ? UnpricedInventoryReceiptLine::query()->with(['receipt', 'product', 'unit'])
            ->whereHas('receipt', fn ($query) => $query->where('purchase_order_id', $order->getKey())->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
            ->when($sourceReceipt, fn ($query) => $query->where('receipt_id', $sourceReceipt->getKey()))->get()
            ->map(function ($line) use ($draft) {
                $activeReturns = PurchaseReturnLine::query()->where('receipt_line_id', $line->getKey())
                    ->whereHas('purchaseReturn', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed'])->when($draft, fn ($query) => $query->whereKeyNot($draft->getKey())))->get();
                $line->setAttribute('returnable_quantity', max(0, (float) $line->accepted_quantity - (float) $activeReturns->where('from_quarantine', false)->sum('quantity')));
                $line->setAttribute('returnable_quarantine_quantity', max(0, (float) $line->rejected_quantity - (float) $activeReturns->where('from_quarantine', true)->sum('quantity')));
                $line->setAttribute('previously_returned_quantity', (float) $activeReturns->sum('quantity'));

                return $line;
            })->filter(fn ($line) => $line->returnable_quantity > 0 || $line->returnable_quarantine_quantity > 0)->values() : collect();
        $invoices = $order ? PurchaseInvoice::query()->where('purchase_order_id', $order->getKey())->whereIn('status', ['approved', 'closed'])
            ->when($sourceReceipt, fn ($query) => $query->whereHas('lines', fn ($query) => $query->whereIn('receipt_line_id', $sourceReceipt->lines()->pluck('id'))))->limit(2)->get() : collect();
        $selectedInvoice = old('purchase_invoice_doc_num', $draft?->purchaseInvoice?->doc_num);
        if (filled($selectedInvoice)) {
            $invoices = $order?->purchaseInvoices()->where('doc_num', $selectedInvoice)->get() ?? collect();
        } elseif ($invoices->count() > 1) {
            $invoices = collect();
        }

        return view('modules.purchases.procurement.return-form', [
            'record' => $order, 'sourceReceipt' => $sourceReceipt, 'draft' => $draft,
            'orders' => collect($order ? [$order] : []),
            'receiptLines' => $receiptLines, 'invoices' => $invoices,
        ]);
    }

    public function editReturn(Request $request, PurchaseReturn $purchaseReturn): View
    {
        $this->assertCurrent($purchaseReturn);
        abort_unless($purchaseReturn->status === 'draft', 403);

        return $this->createReturn($request, $purchaseReturn->load(['lines', 'receipt']));
    }

    public function updateReturn(ProcurementWorkflowRequest $request, PurchaseReturn $purchaseReturn): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();

        return $this->execute($request, fn () => $this->settlement->updatePurchaseReturn($purchaseReturn, $request->validated()), 'admin.purchases.purchase-returns.show');
    }

    public function destroyReturn(Request $request, PurchaseReturn $purchaseReturn): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();

        return $this->execute($request, fn () => $this->settlement->deletePurchaseReturn($purchaseReturn), 'admin.purchases.purchase-returns.index');
    }

    public function storeReturn(ProcurementWorkflowRequest $request): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();

        return $this->execute($request, fn () => $this->settlement->createPurchaseReturn($request->validated()), 'admin.purchases.purchase-returns.show');
    }

    public function showReturn(PurchaseReturn $purchaseReturn): View
    {
        $this->assertCurrent($purchaseReturn);
        $purchaseReturn->load(['supplier', 'purchaseOrder', 'receipt', 'purchaseInvoice', 'lines.product', 'lines.unit']);

        return $this->showView('purchase_return', $purchaseReturn, true);
    }

    public function approveReturn(Request $request, PurchaseReturn $purchaseReturn): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();

        return $this->execute($request, fn () => $this->settlement->approvePurchaseReturn($purchaseReturn), 'admin.purchases.purchase-returns.show');
    }

    public function reverseReturn(ProcurementWorkflowRequest $request, PurchaseReturn $purchaseReturn): JsonResponse|RedirectResponse
    {
        $this->assertInventoryBranch();

        return $this->execute(
            $request,
            fn () => $this->settlement->reversePurchaseReturn($purchaseReturn, (string) $request->validated('reversal_reason')),
            'admin.purchases.purchase-returns.show',
        );
    }

    public function supplierPaymentsIndex(): View
    {
        $this->assertAdministrativeBranch();
        $context = $this->context();
        $records = SupplierPaymentContext::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('financial_period_id', $context['financial_period_id'])
            ->with(['cashVoucher', 'bankAccount.bank', 'cheque', 'supplier', 'purchaseOrder'])->withCount('allocations')->latest('id')->paginate(25);

        return $this->indexView('supplier_payments', __('Supplier Payments'), $records, true);
    }

    public function supplierAdvancesIndex(): View
    {
        $this->assertAdministrativeBranch();
        $context = $this->context();
        $records = SupplierPaymentContext::query()->where('company_id', $context['company_id'])->where('is_advance', true)
            ->with(['cashVoucher', 'bankAccount.bank', 'cheque', 'supplier', 'purchaseOrder'])->withCount('allocations')->latest('id')->paginate(25);

        return $this->indexView('supplier_advances', __('Supplier Advances'), $records, true);
    }

    public function createSupplierPayment(Request $request): View
    {
        $this->assertAdministrativeBranch();
        $context = $this->context();
        $invoices = PurchaseInvoice::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->whereIn('status', ['approved', 'closed'])->where('remaining_amount', '>', 0)->with(['supplier', 'currency', 'paymentSchedules'])->get();
        $selectedInvoice = $invoices->firstWhere('doc_num', $request->string('invoice')->trim()->toString());
        $selectedSupplierDocNum = (string) $request->old('supplier_doc_num', $selectedInvoice?->supplier?->doc_num ?? '');
        $selectedPurchaseOrderDocNum = (string) $request->old('purchase_order_doc_num', '');

        return view('modules.purchases.procurement.payment-form', [
            'selectedSupplier' => $selectedSupplierDocNum === ''
                ? null
                : Supplier::query()->active()->forCompany($context['company_id'])->where('doc_num', $selectedSupplierDocNum)->first(['doc_num', 'name']),
            'cashboxes' => Cashbox::query()->forCompany($context['company_id'])->active()->get(),
            'bankAccounts' => BankAccount::query()->forCompany($context['company_id'])->active()->with(['bank', 'account', 'currency'])->get(),
            'currencies' => $this->currencies(),
            'invoices' => $invoices,
            'selectedInvoice' => $selectedInvoice,
            'selectedPurchaseOrder' => $selectedPurchaseOrderDocNum === ''
                ? null
                : PurchaseOrder::query()
                    ->forCompany($context['company_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->whereIn('status', [PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed])
                    ->where('doc_num', $selectedPurchaseOrderDocNum)
                    ->first(['id', 'doc_num']),
        ]);
    }

    public function storeSupplierPayment(ProcurementWorkflowRequest $request): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();

        return $this->execute($request, fn () => $this->settlement->createSupplierPayment($request->validated()), 'admin.purchases.supplier-payments.show');
    }

    public function showSupplierPayment(string $supplierPayment): View
    {
        $this->assertAdministrativeBranch();
        $record = $this->supplierPayment($supplierPayment, [
            'cashVoucher', 'bankAccount.bank', 'bankAccount.account', 'cheque', 'currency', 'supplier', 'purchaseOrder',
            'allocations.purchaseInvoice', 'allocations.paymentSchedule', 'journalEntry',
        ]);

        return $this->showView('supplier_payment', $record, true);
    }

    public function approveSupplierPayment(Request $request, string $supplierPayment): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();
        $record = $this->supplierPayment($supplierPayment);

        return $this->execute($request, fn () => $this->settlement->approveSupplierPayment($record), 'admin.purchases.supplier-payments.show', $supplierPayment);
    }

    public function cancelSupplierPayment(ProcurementWorkflowRequest $request, string $supplierPayment): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();
        $record = $this->supplierPayment($supplierPayment);

        return $this->execute(
            $request,
            fn () => $this->settlement->cancelSupplierPayment($record, (string) $request->validated('cancel_reason')),
            'admin.purchases.supplier-payments.show',
            $supplierPayment,
        );
    }

    public function allocateSupplierPayment(ProcurementWorkflowRequest $request, string $supplierPayment): JsonResponse|RedirectResponse
    {
        $this->assertAdministrativeBranch();
        $record = $this->supplierPayment($supplierPayment);

        return $this->execute($request, fn () => $this->settlement->allocatePayment($record, $request->validated('allocations')), 'admin.purchases.supplier-payments.show', $supplierPayment);
    }

    public function inquiry(Request $request): RedirectResponse
    {
        $screen = (string) $request->route('procurement_screen', 'procurement_cycle');

        $parentRoute = match ($screen) {
            'purchase_order_lines', 'purchase_order_approvals' => 'admin.purchases.purchase-orders.index',
            'purchase_invoice_lines', 'purchase_invoice_payments', 'purchase_invoice_allocations' => 'admin.purchases.purchase-invoices.index',
            'supplier_payment_allocations' => 'admin.purchases.supplier-payments.index',
            'supplier_debit_notes' => 'admin.purchases.purchase-returns.index',
            default => 'admin.purchases.purchase-requisitions.index',
        };

        return to_route($parentRoute);
    }

    public function report(Request $request): View
    {
        $filters = $this->reportFilters($request);
        $context = $this->context();
        $isAdministrativeBranch = $this->isAdministrativeBranch();
        $showPrices = (bool) request()->user()?->can('purchases.prices.view');
        $reportType = $filters['report_type'];
        $rows = $this->procurementReport->rows($reportType, $filters, $context['company_id'], $context['financial_period_id']);
        $metrics = [
            'matching_rows' => $rows->count(),
            ...($rows->pluck('unit')->filter()->unique()->count() <= 1 ? ['quantity' => $rows->sum(fn (array $row): float => (float) $row['quantity'])] : []),
            ...($showPrices && $rows->pluck('currency')->filter()->unique()->count() <= 1 ? ['amount' => $rows->sum(fn (array $row): float => (float) $row['amount'])] : []),
            ...($showPrices && $rows->pluck('currency')->filter()->unique()->count() <= 1 ? ['outstanding' => $rows->sum(fn (array $row): float => (float) $row['outstanding'])] : []),
            'overdue' => $rows->where('overdue', true)->count(),
        ];
        if ($reportType === ProcurementCycleReport::SupplierStatement) {
            $statementGroups = $rows->groupBy(fn (array $row): string => $row['supplier_doc_num'].':'.$row['currency_doc_num']);
            $metrics = ['matching_rows' => $rows->count()];
            if ($rows->pluck('currency_doc_num')->filter()->unique()->count() === 1) {
                $metrics += [
                    'opening_balance' => $statementGroups->sum(fn ($group): float => (float) $group->first()['balance']),
                    'debit' => $rows->sum('debit'),
                    'credit' => $rows->sum('credit'),
                    'closing_balance' => $statementGroups->sum(fn ($group): float => (float) $group->last()['balance']),
                ];
            }
        }
        if ($reportType === ProcurementCycleReport::PurchaseLedger) {
            unset($metrics['quantity']);
        }

        return view('modules.purchases.procurement.report', [
            'metrics' => $metrics,
            'rows' => $rows,
            'filters' => $filters,
            'reportType' => $reportType,
            'reportTypes' => array_values(array_filter(ProcurementCycleReport::types(), fn (string $type): bool => (bool) $request->user()?->can($this->reportPermission($type, 'view')))),
            'reportPermissionPrefix' => $this->reportPermissionPrefix($reportType),
            'reportPrintPermission' => $this->reportPermission($reportType, 'print'),
            'showPrices' => $showPrices,
            'grniReconciliation' => $reportType === ProcurementCycleReport::GoodsReceivedNotInvoiced
                ? $this->procurementReport->grniReconciliation($context['company_id'], $context['financial_period_id'], $filters['branch_id'])
                : null,
            'suppliers' => Supplier::query()->where('company_id', $context['company_id'])->where('doc_num', $filters['supplier_doc_num'] ?? '')->get(),
            'currencies' => Currency::query()->where('company_id', $context['company_id'])->orderBy('doc_num')->get(),
            'products' => Product::query()->where('company_id', $context['company_id'])->where('doc_num', $filters['product_doc_num'] ?? '')->get(),
            'requisitions' => PurchaseRequisition::query()->where('company_id', $context['company_id'])->when(! $isAdministrativeBranch, fn ($query) => $query->where('branch_id', $context['branch_id']))->latest('id')->limit(200)->get(),
            'orders' => PurchaseOrder::query()->forCompany($context['company_id'])->when(! $isAdministrativeBranch, fn ($query) => $query->where('branch_id', $context['branch_id']))->latest('id')->limit(200)->get(),
            'branches' => $this->operatingContext->allowedBranchQueryForCurrentCompany($request)->when(! $isAdministrativeBranch, fn ($query) => $query->whereKey($context['branch_id']))->orderBy('name')->get(),
            'warehouses' => BranchStore::query()->whereHas('branch', fn ($query) => $query->where('company_id', $context['company_id'])->when(! $isAdministrativeBranch, fn ($query) => $query->whereKey($context['branch_id'])))->whereNull('deleted_at')->orderBy('name')->get(),
            'locationFilters' => [
                'country' => HrCountry::query()->where('doc_num', $filters['country_doc_num'] ?? '')->first(),
                'governorate' => HrGovernorate::query()->where('doc_num', $filters['governorate_doc_num'] ?? '')->first(),
                'city' => HrCity::query()->where('doc_num', $filters['city_doc_num'] ?? '')->first(),
                'area' => HrArea::query()->where('doc_num', $filters['area_doc_num'] ?? '')->first(),
            ],
        ]);
    }

    public function exportReportExcel(Request $request): BinaryFileResponse
    {
        $filters = $this->reportFilters($request, 'export');
        $context = $this->context();
        $rows = $this->procurementReport->rows($filters['report_type'], $filters, $context['company_id'], $context['financial_period_id']);

        return Excel::download(
            new ProcurementCycleReportExport(
                $this->procurementReport,
                $rows,
                (bool) $request->user()?->can('purchases.prices.view'),
                $filters['report_type'],
                $filters['detail_level'] ?? 'summary',
            ),
            'procurement-'.$filters['report_type'].'.xlsx',
        );
    }

    public function printReport(Request $request, ReportPdfService $pdf, CompanyPrintIdentityService $printIdentities): Response
    {
        $filters = $this->reportFilters($request, 'print');
        $context = $this->context();
        $rows = $this->procurementReport->rows($filters['report_type'], $filters, $context['company_id'], $context['financial_period_id']);

        $title = __('Procurement Report').' — '.__('procurement.reports.types.'.$filters['report_type']);
        $identity = $printIdentities->forCompany(Company::query()->findOrFail($context['company_id']));

        return $pdf->stream('modules.purchases.procurement.report-print', [
            'title' => $title,
            'companyName' => $identity['legal_name'] ?: $identity['name'],
            'companyLogoPath' => $identity['logo_source'],
            'companyPrintIdentity' => $identity,
            'rows' => $rows,
            'filters' => $filters,
            'reportType' => $filters['report_type'],
            'showPrices' => (bool) $request->user()?->can('purchases.prices.view'),
            'grniReconciliation' => $filters['report_type'] === ProcurementCycleReport::GoodsReceivedNotInvoiced
                ? $this->procurementReport->grniReconciliation($context['company_id'], $context['financial_period_id'], $filters['branch_id'])
                : null,
        ], 'procurement-'.$filters['report_type'].'.pdf');
    }

    public function printDocument(Request $request, string $type, string $docNum, ReportPdfService $pdf, CompanyPrintIdentityService $printIdentities): Response
    {
        abort_unless((bool) $request->user()?->can($this->printPermissionForType($type)), 403);
        $record = $this->printRecord($type, $docNum);
        $pricesVisible = $this->pricesVisibleFor($type, $request);

        $title = __('procurement.documents.types.'.$type).' — '.$record->doc_num;
        $identity = $printIdentities->forCompany(Company::query()->findOrFail($record->company_id));

        return $pdf->stream('modules.purchases.procurement.print', [
            'title' => $title,
            'companyName' => $identity['legal_name'] ?: $identity['name'],
            'companyLogoPath' => $identity['logo_source'],
            'companyPrintIdentity' => $identity,
            'type' => $type,
            'record' => $record,
            'showPrices' => $pricesVisible,
        ], str($type.'-'.$record->doc_num)->slug().'.pdf');
    }

    private function printRecord(string $type, string $docNum): object
    {
        $companyId = $this->context()['company_id'];

        return match ($type) {
            'purchase-requisition' => PurchaseRequisition::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['lines.product', 'lines.unit', 'branch', 'branchStore'])->firstOrFail(),
            'request-for-quotation' => RequestForQuotation::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['requisition', 'suppliers', 'lines.product', 'lines.unit'])->firstOrFail(),
            'quotation-comparison' => RequestForQuotation::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['requisition', 'quotations.supplier', 'quotations.currency', 'quotations.lines.product', 'quotations.lines.unit'])->firstOrFail(),
            'supplier-quotation' => SupplierQuotation::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['supplier', 'currency', 'requestForQuotation', 'purchaseRequisition', 'purchaseOrder', 'lines.product', 'lines.unit', 'attachmentUsages.file'])->firstOrFail(),
            'supplier-selection' => SupplierSelection::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['requestForQuotation', 'lines.supplier', 'lines.product', 'lines.unit'])->firstOrFail(),
            'purchase-order-change-request' => PurchaseOrderChangeRequest::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with('purchaseOrder.supplier')->firstOrFail(),
            'purchase-order-delivery-schedule' => PurchaseOrder::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['supplier', 'lines.deliverySchedules.purchaseOrderLine.product'])->firstOrFail(),
            'supply-order' => SupplyOrder::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['purchaseOrder', 'purchaseInvoice', 'supplier', 'branchStore', 'lines.product', 'lines.unit', 'receipts'])->firstOrFail(),
            'goods-receipt' => $this->receipt($docNum)->load(['purchaseOrder', 'supplyOrder', 'supplier', 'branchStore', 'inspection', 'sourceInspection', 'lines.product', 'lines.unit', 'lines.purchaseOrderLine', 'lines.supplyOrderLine']),
            'goods-receipt-inspection' => GoodsReceiptInspection::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with([
                'branch', 'receipt.supplier', 'receipts.supplier', 'purchaseOrder.supplier', 'purchaseOrder.branchStore.branch',
                'supplyOrder.supplier', 'supplyOrder.branchStore.branch',
                'lines.product', 'lines.unit', 'lines.purchaseOrderLine', 'lines.supplyOrderLine', 'lines.receiptLine', 'lines.receiptLines.receipt',
                'attachmentUsages.file',
            ])->firstOrFail(),
            'purchase-return' => PurchaseReturn::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['supplier', 'purchaseOrder', 'purchaseInvoice', 'receipt', 'lines.product', 'lines.unit', 'lines.receiptLine.receipt'])->firstOrFail(),
            'supplier-payment' => SupplierPaymentContext::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['cashVoucher', 'bankAccount.bank', 'cheque', 'currency', 'supplier', 'purchaseOrder', 'allocations.purchaseInvoice'])->firstOrFail(),
            default => abort(404),
        };
    }

    private function pricesVisibleFor(string $type, Request $request): bool
    {
        if (in_array($type, ['supply-order', 'goods-receipt', 'goods-receipt-inspection', 'purchase-requisition', 'request-for-quotation', 'purchase-order-delivery-schedule'], true)) {
            return false;
        }

        return (bool) $request->user()?->can('purchases.prices.view');
    }

    private function printPermissionForType(string $type): string
    {
        return match ($type) {
            'purchase-requisition' => 'purchases.purchase_requisitions.print',
            'request-for-quotation' => 'purchases.request_for_quotations.print',
            'quotation-comparison' => 'purchases.supplier_quotation_comparison.print',
            'supplier-quotation' => 'purchases.supplier_quotation_entry.print',
            'supplier-selection' => 'purchases.supplier_selection.print',
            'purchase-order-change-request' => 'purchases.purchase_order_change_requests.print',
            'purchase-order-delivery-schedule' => 'purchases.purchase_order_delivery_schedule.print',
            'supply-order' => 'purchases.supply_orders.print',
            'goods-receipt' => 'purchases.goods_receipt_notes.print',
            'goods-receipt-inspection' => 'purchases.goods_receipt_inspection.print',
            'purchase-return' => 'purchases.purchase_returns.print',
            'supplier-payment' => 'supplier_payments.print',
            default => abort(404),
        };
    }

    public function chooseSource(Request $request): View
    {
        $screen = $request->route('screen');
        if ($screen === 'request_for_quotations') {
            $this->assertAdministrativeBranch();
        }
        if ($screen === 'goods_receipt_inspections') {
            $this->assertInventoryBranch();

            return view('modules.purchases.procurement.inspection-source-picker');
        }
        [$title, $lookup, $destination] = match ($screen) {
            'request_for_quotations' => [__('Create Request for Quotation'), 'requisitions', 'request-for-quotations'],
            'supplier_quotations' => [__('Supplier Quotation Entry'), 'rfqs', 'supplier-quotation-entry'],
            'goods_receipts' => [__('Goods Receipt Note'), 'inspections', 'goods-receipt-notes'],
            default => abort(404),
        };

        return view('modules.purchases.procurement.source-picker', compact('title', 'lookup', 'destination'));
    }

    private function documentIndex(string $screen): View
    {
        $definition = ProcurementDocumentsDataTable::definition($screen);
        $createUrl = route('admin.purchases.'.$definition['route'].(in_array($screen, ['purchase_requisitions', 'purchase_returns', 'supply_orders'], true) ? '.create' : '.choose-source'));
        if ($screen === 'purchase_requisitions') {
            try {
                $this->sourcing->requisitionStore($this->context());
            } catch (DomainException) {
                $createUrl = null;
            }
        }
        $statuses = match ($screen) {
            'purchase_requisitions' => ['draft', 'pending_approval', 'approved', 'rejected', 'partially_converted', 'fully_converted', 'closed', 'cancelled'],
            'goods_receipt_inspections' => ['accepted', 'partially_accepted', 'rejected'],
            'goods_receipts' => ['unposted', 'posted', 'reversed'],
            'purchase_returns' => ['draft', 'posted', 'reversed'],
            'supply_orders' => ['draft', 'issued', 'partially_received', 'fully_received', 'closed', 'cancelled'],
            default => ['draft', 'issued', 'submitted', 'approved', 'cancelled'],
        };

        $isAdministrativeBranch = $this->isAdministrativeBranch();
        if ($isAdministrativeBranch && in_array($screen, ['goods_receipt_inspections', 'goods_receipts', 'purchase_returns'], true)) {
            $createUrl = null;
        }
        if (! $isAdministrativeBranch && in_array($screen, ['request_for_quotations', 'supplier_quotations', 'supply_orders'], true)) {
            $createUrl = null;
        }

        $filterBranches = collect();
        $filterStores = collect();
        if ($screen === 'goods_receipt_inspections') {
            $context = $this->context();
            $filterBranches = Branch::query()
                ->where('company_id', $context['company_id'])
                ->when(! $isAdministrativeBranch, fn (Builder $query) => $query->whereKey($context['branch_id']))
                ->active()
                ->orderBy('name')
                ->get();
            $filterStores = BranchStore::query()
                ->with('branch')
                ->whereHas('branch', fn (Builder $query) => $query
                    ->where('company_id', $context['company_id'])
                    ->when(! $isAdministrativeBranch, fn (Builder $branches) => $branches->whereKey($context['branch_id'])))
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get();
        }

        return view('modules.purchases.procurement.document-index', compact(
            'screen',
            'definition',
            'createUrl',
            'statuses',
            'isAdministrativeBranch',
            'filterBranches',
            'filterStores',
        ));
    }

    public function documentData(Request $request, string $screen, ProcurementDocumentsDataTable $table): JsonResponse
    {
        return $table->json($request, $screen);
    }

    public function restoreDocument(Request $request, string $screen, string $document): JsonResponse
    {
        $definition = ProcurementDocumentsDataTable::definition($screen);
        abort_unless($request->user()?->can('purchases.'.$definition['permission'].'.restore'), 403);
        $context = $this->context();
        DB::transaction(function () use ($definition, $document, $context, $screen): void {
            $record = $definition['model']::onlyTrashed()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('doc_num', $document)->lockForUpdate()->firstOrFail();
            abort_unless($record->status === 'draft' && ($screen !== 'goods_receipts' || $record->posting_status === 'unposted'), 422);
            app(FinancialPeriodService::class)->resolveOpenForPostingDate($context['company_id'], $record->{$definition['date']}->toDateString(), $record->financial_period_id, lockForUpdate: true);
            abort_unless($record->lines()->exists(), 422, __('A purchase request requires at least one line.'));
            $record->restore();
            app(ProcurementAuditService::class)->record($record, 'document.restored');
        });

        return response()->json(['success' => true]);
    }

    private function indexView(string $screen, string $title, LengthAwarePaginator $records, bool $commercial = false): View
    {
        return view('modules.purchases.procurement.index', compact('screen', 'title', 'records', 'commercial'));
    }

    private function showView(string $type, object $record, bool $commercial): View
    {
        $activeBranchId = $this->context()['branch_id'];
        $isAdministrativeBranch = $this->isAdministrativeBranch();

        return view('modules.purchases.procurement.show', compact('type', 'record', 'commercial', 'activeBranchId', 'isAdministrativeBranch'));
    }

    private function execute(Request $request, Closure $operation, string $route, ?string $routeKey = null): JsonResponse|RedirectResponse
    {
        try {
            $record = $operation();
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()->withInput()->withErrors(['document' => $exception->getMessage()]);
        }

        $key = $routeKey ?? (string) ($record->doc_num ?? data_get($record, 'cashVoucher.doc_num'));
        $destination = route($route, $key);
        if ($request->has('submit_action')) {
            $prefix = str($route)->beforeLast('.')->toString();
            $action = $request->string('submit_action')->toString();
            $destination = match ($action) {
                'save_back' => route($prefix.'.index'),
                'save_edit' => Route::has($prefix.'.edit') ? route($prefix.'.edit', $key) : $destination,
                'save' => Route::has($prefix.'.create') && $prefix === 'admin.purchases.purchase-requisitions' ? route($prefix.'.create') : $destination,
                default => $destination,
            };
        }
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'data' => ['doc_num' => $key, 'url' => $destination], 'redirect' => $destination]);
        }

        return redirect()->to($destination)->with('success', __('Document saved successfully.'));
    }

    private function receipt(string $docNum): UnpricedInventoryReceipt
    {
        $context = $this->context();

        return UnpricedInventoryReceipt::query()->where('company_id', $context['company_id'])
            ->when(! $this->isAdministrativeBranch(), fn ($query) => $query->where('branch_id', $context['branch_id']))
            ->where('doc_num', $docNum)->whereNotNull('purchase_order_id')->firstOrFail();
    }

    private function pendingInspectionQuantity(Collection $inspectionLines): float
    {
        return (float) $inspectionLines
            ->filter(fn ($line): bool => $line->inspection?->status === 'finalized'
                && in_array($line->inspection?->source_type, ['purchase_order', 'supply_order'], true))
            ->sum(fn ($line): float => $line->remainingReceiptQuantity());
    }

    /** @param list<string> $relations */
    private function supplierPayment(string $docNum, array $relations = []): SupplierPaymentContext
    {
        return SupplierPaymentContext::query()
            ->where('company_id', $this->context()['company_id'])
            ->when($relations !== [], fn ($query) => $query->with($relations))
            ->where('doc_num', $docNum)
            ->firstOrFail();
    }

    /** @return Collection<int, Product> */
    private function products(): Collection
    {
        return Product::query()->active()->purchasable()->forCompany($this->context()['company_id'])->with('unit')->orderBy('name')->get();
    }

    private function quotationForm(RequestForQuotation|PurchaseRequisition|PurchaseOrder $source, ?SupplierQuotation $draft = null): View
    {
        $this->assertCurrent($source);
        $source->loadMissing($source instanceof RequestForQuotation ? ['lines.product', 'lines.unit', 'suppliers'] : ['lines.product', 'lines.unit']);
        $context = $this->context();
        $selectedSupplier = $draft?->supplier
            ?? ($source instanceof PurchaseOrder ? $source->supplier : ($source instanceof PurchaseRequisition ? $source->suggestedSupplier : null));
        $selectedCurrency = $draft?->currency
            ?? ($source instanceof PurchaseOrder ? $source->currency : null)
            ?? Currency::query()->forCompany($context['company_id'])->where('is_main', true)->first();

        return view('modules.purchases.procurement.quotation-form', [
            'record' => $source,
            'draft' => $draft,
            'sourceType' => match (true) {
                $source instanceof PurchaseRequisition => SupplierQuotation::SourcePurchaseRequisition,
                $source instanceof PurchaseOrder => SupplierQuotation::SourcePurchaseOrder,
                default => SupplierQuotation::SourceRequestForQuotation,
            },
            'currencies' => collect([$selectedCurrency])->filter(),
            'selectedSuppliers' => collect([$selectedSupplier])->filter(),
        ]);
    }

    private function supplierQuotationSource(string $sourceType, string $sourceDocument): RequestForQuotation|PurchaseRequisition|PurchaseOrder
    {
        $context = $this->context();
        $model = match ($sourceType) {
            SupplierQuotation::SourcePurchaseRequisition => PurchaseRequisition::class,
            SupplierQuotation::SourcePurchaseOrder => PurchaseOrder::class,
            SupplierQuotation::SourceRequestForQuotation => RequestForQuotation::class,
            default => abort(404),
        };

        return $model::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->when($model !== PurchaseRequisition::class, fn ($query) => $query->where('branch_id', $context['branch_id']))
            ->where('doc_num', $sourceDocument)
            ->firstOrFail();
    }

    /** @return Collection<int, Currency> */
    private function currencies(): Collection
    {
        return Currency::query()->active()->forCompany($this->context()['company_id'])->orderBy('name')->get();
    }

    /** @return Collection<int, BranchStore> */
    private function stores(): Collection
    {
        return BranchStore::query()->where('branch_id', $this->context()['branch_id'])->whereNull('deleted_at')->orderBy('name')->get();
    }

    private function assertCurrent(object $record): void
    {
        $context = $this->context();
        $branchId = $record->branch_id ?? $record->purchaseOrder?->branch_id ?? $record->receipt?->branch_id;
        $financialPeriodId = $record->financial_period_id
            ?? $record->purchaseOrder?->financial_period_id
            ?? $record->receipt?->financial_period_id;
        $hasBranchAccess = (int) $branchId === $context['branch_id'] || $this->isAdministrativeBranch();
        abort_unless(
            (int) $record->company_id === $context['company_id']
            && (int) $financialPeriodId === $context['financial_period_id']
            && $hasBranchAccess,
            404,
        );
    }

    private function assertRequisitionOrigin(PurchaseRequisition $record): void
    {
        $context = $this->context();
        abort_unless(
            (int) $record->company_id === $context['company_id']
            && (int) $record->branch_id === $context['branch_id']
            && (int) $record->financial_period_id === $context['financial_period_id'],
            404,
        );
    }

    private function assertAdministrativeBranch(): void
    {
        abort_unless($this->isAdministrativeBranch(), 403, __('procurement.ui.administrative_context_required'));
    }

    private function assertRequisitionApprovalBranch(PurchaseRequisition $record): void
    {
        if ($this->isAdministrativeBranch()) {
            $this->assertCurrent($record);

            return;
        }

        $context = $this->context();
        $isOriginFactory = (int) $record->company_id === $context['company_id']
            && (int) $record->financial_period_id === $context['financial_period_id']
            && (int) $record->branch_id === $context['branch_id']
            && Branch::query()
                ->whereKey($context['branch_id'])
                ->where('company_id', $context['company_id'])
                ->where('type', Branch::TypeFactory)
                ->where('status', 'active')
                ->exists();

        abort_unless($isOriginFactory, 403, __('procurement.ui.administrative_context_required'));
    }

    private function assertInventoryBranch(): void
    {
        $context = $this->context();
        abort_unless(Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->whereIn('type', [Branch::TypeFactory, Branch::TypeWarehouse])
            ->exists(), 403, __('procurement.ui.inventory_context_required'));
    }

    private function isAdministrativeBranch(): bool
    {
        $context = $this->context();

        return Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeAdministrative)
            ->exists();
    }

    /** @return array<string, mixed> */
    private function reportFilters(Request $request, string $action = 'view'): array
    {
        $filters = $request->validate([
            'report_type' => ['nullable', Rule::in(ProcurementCycleReport::types())],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'supplier_doc_num' => ['nullable', 'string', 'max:100'],
            'country_doc_num' => ['nullable', 'string', 'max:100'],
            'governorate_doc_num' => ['nullable', 'string', 'max:100'],
            'city_doc_num' => ['nullable', 'string', 'max:100'],
            'area_doc_num' => ['nullable', 'string', 'max:100'],
            'geography_state' => ['nullable', Rule::in(['specified', 'unspecified'])],
            'address_search' => ['nullable', 'string', 'max:255'],
            'contact_search' => ['nullable', 'string', 'max:255'],
            'product_doc_num' => ['nullable', 'string', 'max:100'],
            'purchase_requisition_doc_num' => ['nullable', 'string', 'max:100'],
            'purchase_order_doc_num' => ['nullable', 'string', 'max:100'],
            'currency_doc_num' => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'max:50'],
            'document_type' => ['nullable', 'string', Rule::in(['purchase_invoice', 'purchase_return', 'supplier_payment', 'supplier_cheque_issue', 'supplier_cheque_clearing', 'cash_voucher', 'cheque', 'opening_balance', 'manual'])],
            'category_key' => ['nullable', 'string', 'max:100'],
            'detail_level' => ['nullable', Rule::in(['summary', 'lines'])],
            'status' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'integer'],
            'warehouse_uuid' => ['nullable', 'uuid'],
            'qc_status' => ['nullable', 'string', 'max:50'],
            'production_order_doc_num' => ['nullable', 'string', 'max:100'],
            'work_order_reference' => ['nullable', 'string', 'max:100'],
            'overdue' => ['nullable', Rule::in(['0', '1'])],
            'outstanding' => ['nullable', Rule::in(['0', '1'])],
        ]);
        $filters['report_type'] = $filters['report_type'] ?? ProcurementCycleReport::OpenRequirements;
        abort_unless($request->user()?->can($this->reportPermission($filters['report_type'], 'view')), 403);

        if ($action !== 'view') {
            abort_unless($request->user()?->can($this->reportPermission($filters['report_type'], $action)), 403);
        }
        if (in_array($filters['report_type'], [ProcurementCycleReport::SupplierStatement, ProcurementCycleReport::PurchaseLedger, ProcurementCycleReport::OutstandingSupplierInvoices, ProcurementCycleReport::SupplierAging, ProcurementCycleReport::PurchaseInvoices, ProcurementCycleReport::DueSupplierInstallments, ProcurementCycleReport::UpcomingSupplierPayments, ProcurementCycleReport::GoodsReceivedNotInvoiced], true)) {
            abort_unless($request->user()?->can('purchases.prices.view'), 403);
        }

        $context = $this->context();
        if ($this->isAdministrativeBranch()) {
            $filters['branch_id'] = $filters['branch_id'] ?? null;
            if ($filters['branch_id'] !== null) {
                abort_unless($this->operatingContext->allowedBranchQueryForCurrentCompany($request)->whereKey($filters['branch_id'])->exists(), 422);
            }
        } else {
            abort_if(filled($filters['branch_id'] ?? null) && (int) $filters['branch_id'] !== $context['branch_id'], 422);
            $filters['branch_id'] = $context['branch_id'];
        }

        return $filters;
    }

    private function reportPermissionPrefix(string $reportType): string
    {
        return $reportType === ProcurementCycleReport::SupplierStatement
            ? 'reports.supplier_statement'
            : "reports.purchases.{$reportType}";
    }

    private function reportPermission(string $reportType, string $action): string
    {
        if ($reportType === ProcurementCycleReport::SupplierStatement && $action === 'print') {
            return 'reports.supplier_statement.export';
        }

        return $this->reportPermissionPrefix($reportType).".{$action}";
    }

    /** @return array{company_id: int, financial_period_id: int} */
    private function contextOnlyCompanyPeriod(): array
    {
        $context = $this->context();

        return ['company_id' => $context['company_id'], 'financial_period_id' => $context['financial_period_id']];
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function context(): array
    {
        $context = $this->operatingContext->snapshot(request());
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 404);

        return [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'branch_id' => (int) $context['branch_id'],
        ];
    }
}

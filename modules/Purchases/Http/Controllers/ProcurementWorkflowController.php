<?php

namespace Modules\Purchases\Http\Controllers;

use Closure;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Exports\ProcurementCycleReportExport;
use Modules\Purchases\Http\Requests\ProcurementWorkflowRequest;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderChangeRequest;
use Modules\Purchases\Models\PurchaseOrderDeliverySchedule;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\RequestForQuotation;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Models\SupplierSelection;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProcurementWorkflowController extends Controller
{
    public function __construct(
        private readonly ProcurementSourcingService $sourcing,
        private readonly ProcurementReceivingService $receiving,
        private readonly ProcurementSettlementService $settlement,
        private readonly OperatingContextService $operatingContext,
        private readonly ProcurementCycleReport $procurementReport,
    ) {}

    public function requisitionsIndex(): View
    {
        $context = $this->context();
        $records = PurchaseRequisition::query()->forContext($context['company_id'], $context['financial_period_id'])
            ->with(['branch', 'branchStore'])->withCount('lines')->latest('request_date')->paginate(25);

        return $this->indexView('purchase_requisitions', __('Purchase Requisitions'), $records);
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
        return view('modules.purchases.procurement.requisition-form', [
            'products' => $this->products(),
            'stores' => $this->stores(),
        ]);
    }

    public function storeRequisition(ProcurementWorkflowRequest $request): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->createRequisition($request->validated()), 'admin.purchases.purchase-requisitions.show');
    }

    public function showRequisition(PurchaseRequisition $purchaseRequisition): View
    {
        $this->assertCurrent($purchaseRequisition);
        $purchaseRequisition->load(['lines.product', 'lines.unit', 'branch', 'branchStore', 'requestsForQuotation']);

        return $this->showView('purchase_requisition', $purchaseRequisition, false);
    }

    public function submitRequisition(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->submitRequisition($purchaseRequisition), 'admin.purchases.purchase-requisitions.show');
    }

    public function approveRequisition(ProcurementWorkflowRequest $request, PurchaseRequisition $purchaseRequisition): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->approveRequisition($purchaseRequisition, $request->validated('approved_quantities', [])), 'admin.purchases.purchase-requisitions.show');
    }

    public function rfqsIndex(): View
    {
        $records = RequestForQuotation::query()->forContext(...array_values($this->contextOnlyCompanyPeriod()))
            ->with(['requisition', 'suppliers'])->withCount(['lines', 'quotations'])->latest('issue_date')->paginate(25);

        return $this->indexView('request_for_quotations', __('Requests for Quotation'), $records);
    }

    public function createRfq(PurchaseRequisition $purchaseRequisition): View|RedirectResponse
    {
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
            'suppliers' => $this->suppliers(),
        ]);
    }

    public function storeRfq(ProcurementWorkflowRequest $request, PurchaseRequisition $purchaseRequisition): JsonResponse|RedirectResponse
    {
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
        $context = $this->context();
        $records = SupplierQuotation::query()->forContext($context['company_id'], $context['financial_period_id'])
            ->with(['supplier', 'requestForQuotation'])->withCount('lines')->latest('quotation_date')->paginate(25);

        return $this->indexView('supplier_quotations', __('Supplier Quotations'), $records, true);
    }

    public function quotationLinesIndex(): RedirectResponse
    {
        return to_route('admin.purchases.supplier-quotation-entry.index');
    }

    public function createQuotation(RequestForQuotation $requestForQuotation): View
    {
        $this->assertCurrent($requestForQuotation);
        $requestForQuotation->load(['lines.product', 'lines.unit', 'suppliers']);

        return view('modules.purchases.procurement.quotation-form', [
            'record' => $requestForQuotation,
            'currencies' => $this->currencies(),
        ]);
    }

    public function storeQuotation(ProcurementWorkflowRequest $request, RequestForQuotation $requestForQuotation): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->sourcing->createSupplierQuotation($requestForQuotation, $request->validated()), 'admin.purchases.supplier-quotation-entry.show');
    }

    public function showQuotation(SupplierQuotation $supplierQuotation): View
    {
        $this->assertCurrent($supplierQuotation);
        $supplierQuotation->load(['supplier', 'currency', 'requestForQuotation', 'lines.product', 'lines.unit', 'attachmentUsages.file']);

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

    public function receiptsIndex(): View
    {
        $context = $this->context();
        $records = UnpricedInventoryReceipt::query()->forContext($context['company_id'], $context['financial_period_id'])
            ->whereNotNull('purchase_order_id')->with(['purchaseOrder', 'supplier', 'branchStore'])->withCount('lines')
            ->latest('document_date')->paginate(25);

        return $this->indexView('goods_receipts', __('Goods Receipt Notes'), $records);
    }

    public function receiptLinesIndex(): RedirectResponse
    {
        return to_route('admin.purchases.goods-receipt-notes.index');
    }

    public function createReceipt(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load(['supplier', 'branchStore', 'lines.product', 'lines.unit', 'lines.deliverySchedules']);

        return view('modules.purchases.procurement.receipt-form', ['record' => $purchaseOrder]);
    }

    public function storeReceipt(ProcurementWorkflowRequest $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->receiving->receive($purchaseOrder, $request->validated()), 'admin.purchases.goods-receipt-notes.show');
    }

    public function showReceipt(string $goodsReceiptNote): View
    {
        $receipt = $this->receipt($goodsReceiptNote)->load(['purchaseOrder', 'supplier', 'branchStore', 'lines.product', 'lines.unit', 'inspection']);

        return $this->showView('goods_receipt', $receipt, false);
    }

    public function cancelReceipt(ProcurementWorkflowRequest $request, string $goodsReceiptNote): JsonResponse|RedirectResponse
    {
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
        $context = $this->context();
        $records = GoodsReceiptInspection::query()->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])->with(['receipt.supplier'])->withCount('lines')
            ->latest('inspection_at')->paginate(25);

        return $this->indexView('goods_receipt_inspections', __('Incoming Quality Inspections'), $records);
    }

    public function createInspection(string $goodsReceiptNote): View
    {
        $receipt = $this->receipt($goodsReceiptNote)->load(['supplier', 'purchaseOrder', 'lines.product', 'lines.unit']);

        return view('modules.purchases.procurement.inspection-form', ['record' => $receipt]);
    }

    public function storeInspection(ProcurementWorkflowRequest $request, string $goodsReceiptNote): JsonResponse|RedirectResponse
    {
        $receipt = $this->receipt($goodsReceiptNote);

        return $this->execute($request, fn () => $this->receiving->inspect($receipt, $request->validated()), 'admin.purchases.goods-receipt-inspection.show');
    }

    public function showInspection(GoodsReceiptInspection $goodsReceiptInspection): View
    {
        $goodsReceiptInspection->load(['receipt.supplier', 'receipt.purchaseOrder', 'lines.product', 'lines.receiptLine', 'attachmentUsages.file']);

        return $this->showView('goods_receipt_inspection', $goodsReceiptInspection, false);
    }

    public function returnsIndex(): View
    {
        $context = $this->context();
        $records = PurchaseReturn::query()->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])->with(['supplier', 'purchaseOrder'])->withCount('lines')
            ->latest('return_date')->paginate(25);

        return $this->indexView('purchase_returns', __('Purchase Returns'), $records, true);
    }

    public function returnLinesIndex(): RedirectResponse
    {
        return to_route('admin.purchases.purchase-returns.index');
    }

    public function createReturn(Request $request): View
    {
        $order = filled($request->query('purchase_order'))
            ? PurchaseOrder::query()->forCompany($this->context()['company_id'])->where('doc_num', $request->query('purchase_order'))->first()
            : null;
        $order?->load(['supplier', 'lines.product', 'lines' => fn ($query) => $query->whereHas('purchaseOrder')]);

        return view('modules.purchases.procurement.return-form', [
            'record' => $order,
            'orders' => PurchaseOrder::query()->forCompany($this->context()['company_id'])->whereIn('status', ['approved', 'closed'])->with('supplier')->limit(100)->get(),
            'receiptLines' => $order ? UnpricedInventoryReceiptLine::query()->with(['receipt', 'product', 'unit'])->whereHas('receipt', fn ($query) => $query->where('purchase_order_id', $order->getKey()))->get() : collect(),
            'invoices' => $order ? PurchaseInvoice::query()->where('purchase_order_id', $order->getKey())->whereIn('status', ['approved', 'closed'])->get() : collect(),
        ]);
    }

    public function storeReturn(ProcurementWorkflowRequest $request): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->settlement->createPurchaseReturn($request->validated()), 'admin.purchases.purchase-returns.show');
    }

    public function showReturn(PurchaseReturn $purchaseReturn): View
    {
        $purchaseReturn->load(['supplier', 'purchaseOrder', 'receipt', 'purchaseInvoice', 'lines.product', 'lines.unit']);

        return $this->showView('purchase_return', $purchaseReturn, true);
    }

    public function approveReturn(Request $request, PurchaseReturn $purchaseReturn): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->settlement->approvePurchaseReturn($purchaseReturn), 'admin.purchases.purchase-returns.show');
    }

    public function reverseReturn(ProcurementWorkflowRequest $request, PurchaseReturn $purchaseReturn): JsonResponse|RedirectResponse
    {
        return $this->execute(
            $request,
            fn () => $this->settlement->reversePurchaseReturn($purchaseReturn, (string) $request->validated('reversal_reason')),
            'admin.purchases.purchase-returns.show',
        );
    }

    public function supplierPaymentsIndex(): View
    {
        $context = $this->context();
        $records = SupplierPaymentContext::query()->where('company_id', $context['company_id'])
            ->with(['cashVoucher', 'bankAccount.bank', 'cheque', 'supplier', 'purchaseOrder'])->withCount('allocations')->latest('id')->paginate(25);

        return $this->indexView('supplier_payments', __('Supplier Payments'), $records, true);
    }

    public function supplierAdvancesIndex(): View
    {
        $context = $this->context();
        $records = SupplierPaymentContext::query()->where('company_id', $context['company_id'])->where('is_advance', true)
            ->with(['cashVoucher', 'bankAccount.bank', 'cheque', 'supplier', 'purchaseOrder'])->withCount('allocations')->latest('id')->paginate(25);

        return $this->indexView('supplier_advances', __('Supplier Advances'), $records, true);
    }

    public function createSupplierPayment(): View
    {
        $context = $this->context();

        return view('modules.purchases.procurement.payment-form', [
            'suppliers' => $this->suppliers(),
            'cashboxes' => Cashbox::query()->forCompany($context['company_id'])->active()->get(),
            'bankAccounts' => BankAccount::query()->forCompany($context['company_id'])->active()->with(['bank', 'account', 'currency'])->get(),
            'currencies' => $this->currencies(),
            'invoices' => PurchaseInvoice::query()->where('company_id', $context['company_id'])->whereIn('status', ['approved', 'closed'])->where('remaining_amount', '>', 0)->with(['supplier', 'paymentSchedules'])->get(),
            'orders' => PurchaseOrder::query()->forCompany($context['company_id'])->whereIn('status', ['approved', 'closed'])->get(),
        ]);
    }

    public function storeSupplierPayment(ProcurementWorkflowRequest $request): JsonResponse|RedirectResponse
    {
        return $this->execute($request, fn () => $this->settlement->createSupplierPayment($request->validated()), 'admin.purchases.supplier-payments.show');
    }

    public function showSupplierPayment(string $supplierPayment): View
    {
        $record = $this->supplierPayment($supplierPayment, [
            'cashVoucher', 'bankAccount.bank', 'bankAccount.account', 'cheque', 'currency', 'supplier', 'purchaseOrder',
            'allocations.purchaseInvoice', 'allocations.paymentSchedule', 'journalEntry',
        ]);

        return $this->showView('supplier_payment', $record, true);
    }

    public function approveSupplierPayment(Request $request, string $supplierPayment): JsonResponse|RedirectResponse
    {
        $record = $this->supplierPayment($supplierPayment);

        return $this->execute($request, fn () => $this->settlement->approveSupplierPayment($record), 'admin.purchases.supplier-payments.show', $supplierPayment);
    }

    public function cancelSupplierPayment(ProcurementWorkflowRequest $request, string $supplierPayment): JsonResponse|RedirectResponse
    {
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
        $context = $this->context();
        $showPrices = (bool) request()->user()?->can('purchases.prices.view');
        $filters = $this->reportFilters($request);
        $reportType = $filters['report_type'];
        $rows = $this->procurementReport->rows($reportType, $filters, $context['company_id'], $context['financial_period_id']);
        $metrics = [
            'matching_rows' => $rows->count(),
            'quantity' => $rows->sum(fn (array $row): float => (float) $row['quantity']),
            ...($showPrices ? ['amount' => $rows->sum(fn (array $row): float => (float) $row['amount'])] : []),
            'outstanding' => $rows->sum(fn (array $row): float => (float) $row['outstanding']),
            'overdue' => $rows->where('overdue', true)->count(),
        ];

        return view('modules.purchases.procurement.report', [
            'metrics' => $metrics,
            'rows' => $rows,
            'filters' => $filters,
            'reportType' => $reportType,
            'reportTypes' => ProcurementCycleReport::types(),
            'showPrices' => $showPrices,
            'suppliers' => $this->suppliers(),
            'products' => $this->products(),
            'requisitions' => PurchaseRequisition::query()->forContext($context['company_id'], $context['financial_period_id'])->latest('id')->limit(200)->get(),
            'orders' => PurchaseOrder::query()->forCompany($context['company_id'])->where('financial_period_id', $context['financial_period_id'])->latest('id')->limit(200)->get(),
            'branches' => Branch::query()->where('company_id', $context['company_id'])->whereNull('deleted_at')->orderBy('name')->get(),
            'warehouses' => BranchStore::query()->whereHas('branch', fn ($query) => $query->where('company_id', $context['company_id']))->whereNull('deleted_at')->orderBy('name')->get(),
        ]);
    }

    public function exportReportExcel(Request $request): BinaryFileResponse
    {
        $context = $this->context();
        $filters = $this->reportFilters($request);
        $rows = $this->procurementReport->rows($filters['report_type'], $filters, $context['company_id'], $context['financial_period_id']);

        return Excel::download(
            new ProcurementCycleReportExport(
                $this->procurementReport,
                $rows,
                (bool) $request->user()?->can('purchases.prices.view'),
            ),
            'procurement-'.$filters['report_type'].'.xlsx',
        );
    }

    public function printReport(Request $request, ReportPdfService $pdf, CompanyPrintIdentityService $printIdentities): Response
    {
        $context = $this->context();
        $filters = $this->reportFilters($request);
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
            'supplier-quotation' => SupplierQuotation::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['supplier', 'currency', 'lines.product', 'lines.unit', 'attachmentUsages.file'])->firstOrFail(),
            'supplier-selection' => SupplierSelection::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['requestForQuotation', 'lines.supplier', 'lines.product', 'lines.unit'])->firstOrFail(),
            'purchase-order-change-request' => PurchaseOrderChangeRequest::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with('purchaseOrder.supplier')->firstOrFail(),
            'purchase-order-delivery-schedule' => PurchaseOrder::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['supplier', 'lines.deliverySchedules.purchaseOrderLine.product'])->firstOrFail(),
            'goods-receipt' => $this->receipt($docNum)->load(['purchaseOrder', 'supplier', 'branchStore', 'lines.product', 'lines.unit']),
            'goods-receipt-inspection' => GoodsReceiptInspection::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['receipt.supplier', 'lines.product', 'lines.receiptLine', 'attachmentUsages.file'])->firstOrFail(),
            'purchase-return' => PurchaseReturn::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['supplier', 'purchaseOrder', 'purchaseInvoice', 'lines.product', 'lines.unit'])->firstOrFail(),
            'supplier-payment' => SupplierPaymentContext::query()->where('company_id', $companyId)->where('doc_num', $docNum)->with(['cashVoucher', 'bankAccount.bank', 'cheque', 'currency', 'supplier', 'purchaseOrder', 'allocations.purchaseInvoice'])->firstOrFail(),
            default => abort(404),
        };
    }

    private function pricesVisibleFor(string $type, Request $request): bool
    {
        if (in_array($type, ['goods-receipt', 'goods-receipt-inspection', 'purchase-requisition', 'request-for-quotation', 'purchase-order-delivery-schedule'], true)) {
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
            'goods-receipt' => 'purchases.goods_receipt_notes.print',
            'goods-receipt-inspection' => 'purchases.goods_receipt_inspection.print',
            'purchase-return' => 'purchases.purchase_returns.print',
            'supplier-payment' => 'supplier_payments.print',
            default => abort(404),
        };
    }

    private function indexView(string $screen, string $title, LengthAwarePaginator $records, bool $commercial = false): View
    {
        return view('modules.purchases.procurement.index', compact('screen', 'title', 'records', 'commercial'));
    }

    private function showView(string $type, object $record, bool $commercial): View
    {
        return view('modules.purchases.procurement.show', compact('type', 'record', 'commercial'));
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
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'data' => ['doc_num' => $key, 'url' => route($route, $key)]]);
        }

        return redirect()->route($route, $key)->with('success', __('Document saved successfully.'));
    }

    private function receipt(string $docNum): UnpricedInventoryReceipt
    {
        $context = $this->context();

        return UnpricedInventoryReceipt::query()->forContext($context['company_id'], $context['financial_period_id'])
            ->where('doc_num', $docNum)->whereNotNull('purchase_order_id')->firstOrFail();
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

    /** @return Collection<int, Supplier> */
    private function suppliers(): Collection
    {
        return Supplier::query()->active()->forCompany($this->context()['company_id'])->orderBy('name')->get();
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
        abort_unless((int) $record->company_id === $context['company_id'] && (int) $record->financial_period_id === $context['financial_period_id'], 404);
    }

    /** @return array<string, mixed> */
    private function reportFilters(Request $request): array
    {
        $filters = $request->validate([
            'report_type' => ['nullable', Rule::in(ProcurementCycleReport::types())],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'supplier_doc_num' => ['nullable', 'string', 'max:100'],
            'product_doc_num' => ['nullable', 'string', 'max:100'],
            'purchase_requisition_doc_num' => ['nullable', 'string', 'max:100'],
            'purchase_order_doc_num' => ['nullable', 'string', 'max:100'],
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

        return $filters;
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

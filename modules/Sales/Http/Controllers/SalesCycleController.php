<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\Finance\Services\ChequeService;
use Modules\HR\Models\HrEmployee;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\SalesProductionDemandService;
use Modules\Sales\DataTables\SalesCycleDataTable;
use Modules\Sales\Http\Requests\AllocateCustomerCreditRequest;
use Modules\Sales\Http\Requests\AmendCustomerInvoiceRequest;
use Modules\Sales\Http\Requests\CreateDeliveryRequest;
use Modules\Sales\Http\Requests\CreateProductionDemandRequest;
use Modules\Sales\Http\Requests\InspectSalesReturnRequest;
use Modules\Sales\Http\Requests\RefundCustomerCreditRequest;
use Modules\Sales\Http\Requests\ReleaseSalesStockRequest;
use Modules\Sales\Http\Requests\ReserveSalesStockRequest;
use Modules\Sales\Http\Requests\SalesOrderActionRequest;
use Modules\Sales\Http\Requests\StoreCustomerInvoiceRequest;
use Modules\Sales\Http\Requests\StoreDirectCustomerInvoiceRequest;
use Modules\Sales\Http\Requests\StoreCustomerReceiptRequest;
use Modules\Sales\Http\Requests\StoreSalesOrderRequest;
use Modules\Sales\Http\Requests\StoreSalesReturnRequest;
use Modules\Sales\Http\Requests\UpdateSalesOrderRequest;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCreditRefund;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\CustomerInvoicePaymentSchedule;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnLine;
use Modules\Sales\Services\CreditControlService;
use Modules\Sales\Services\CustomerCreditService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\CustomerReceiptSettlementService;
use Modules\Sales\Services\ElectronicInvoiceService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Modules\Sales\Services\SalesReturnService;
use Modules\Sales\Services\SalesSelect2Service;

class SalesCycleController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function orders(Request $request): View|JsonResponse
    {
        return $this->listing($request, 'sales_orders', SalesOrder::query()->with(['customer', 'lines', 'createdBy'])->latest('order_date'));
    }

    public function createOrder(Request $request): View
    {
        $context = $this->requiredContext($request);
        if ($request->filled('source_request_doc_num')) {
            abort_unless($request->user()?->can('sales_requests.view'), 403);
        }
        $sourceRequest = $request->filled('source_request_doc_num')
            ? SalesRequest::query()->with(['customer', 'currency', 'salesEmployee', 'lines.product.unit', 'lines.product.equivalentUnit', 'lines.unit'])
                ->where('company_id', $context['company_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereIn('status', ['approved', 'partially_converted'])
                ->where('doc_num', $request->string('source_request_doc_num')->toString())
                ->firstOrFail()
            : null;

        return view('modules.sales.cycle.sales-order-form', [
            ...$this->formOptions($request, null, $sourceRequest),
            'mode' => 'create',
            'record' => null,
            'action' => route('admin.sales.sales-orders.store'),
            'method' => 'POST',
        ]);
    }

    public function editOrder(Request $request, SalesOrder $salesOrder): View
    {
        abort_unless($salesOrder->isEditable(), 409, 'Released sales orders must be reopened before amendment.');

        return view('modules.sales.cycle.sales-order-form', [
            ...$this->formOptions($request, $salesOrder),
            'mode' => 'edit',
            'record' => $salesOrder->load(['customer', 'currency', 'branchStore', 'salesEmployee', 'lines.product.unit', 'lines.product.equivalentUnit', 'lines.unit', 'paymentSchedules']),
            'action' => route('admin.sales.sales-orders.update', $salesOrder),
            'method' => 'PUT',
        ]);
    }

    public function invoices(Request $request): View|JsonResponse
    {
        return $this->listing($request, 'customer_invoices', CustomerInvoice::query()->with('customer')->latest('invoice_date'));
    }

    public function receipts(Request $request): View|JsonResponse
    {
        return $this->listing($request, 'customer_receipts', CustomerReceipt::query()->with('customer')->latest('receipt_date'));
    }

    public function returns(Request $request): View|JsonResponse
    {
        return $this->listing($request, 'sales_returns', SalesReturn::query()->with('customer')->latest('return_date'));
    }

    public function createReturn(Request $request): View|RedirectResponse
    {
        $context = $this->requiredContext($request);
        if (! $request->filled('invoice_doc_num')) {
            return view('modules.sales.cycle.return-source');
        }

        $data = $request->validate([
            'invoice_doc_num' => ['required', 'string'],
        ]);

        abort_unless($request->user()?->can('customer_invoices.view'), 403);
        $invoice = CustomerInvoice::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('document_type', CustomerInvoice::TypeInvoice)
            ->where('posting_status', CustomerInvoice::StatusPosted)
            ->where('doc_num', $data['invoice_doc_num'])
            ->firstOrFail();

        return redirect()->to(route('admin.sales.sales-invoices.show', $invoice).'#sales-invoice-return');
    }

    public function deliveries(Request $request): View|JsonResponse
    {
        return $this->listing($request, 'sales_deliveries', InventoryDocument::query()->with('customer')->where('document_type', InventoryDocument::TypeSalesDelivery)->latest('document_date'));
    }

    public function createDelivery(Request $request): View|RedirectResponse
    {
        $context = $this->requiredContext($request);
        if (! $request->filled('invoice_doc_num')) {
            return view('modules.sales.cycle.delivery-source');
        }

        $invoice = CustomerInvoice::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('document_type', CustomerInvoice::TypeInvoice)
            ->where('posting_status', CustomerInvoice::StatusPosted)
            ->where('doc_num', $request->string('invoice_doc_num')->toString())
            ->firstOrFail();

        return redirect()->to(route('admin.sales.sales-invoices.show', $invoice).'#sales-invoice-delivery');
    }

    public function showOrder(
        Request $request,
        SalesOrder $salesOrder,
        CreditControlService $creditControl,
        InventoryAvailabilityService $availability,
    ): View {
        $record = $salesOrder->load([
            'customer', 'branch', 'branchStore', 'currency', 'salesEmployee', 'quotation.currentRevision', 'quotationRevision', 'lines.product', 'lines.unit',
            'lines.reservations', 'lines.productionLines.order', 'paymentSchedules', 'statusHistory.changedBy',
            'deliveries.lines.product', 'deliveries.lines.transactionUnit', 'productionOrders.lines.product',
            'invoices.lines', 'invoices.paymentSchedules', 'creditOverrides',
            'receipts', 'returns.creditNote',
        ]);
        $canViewCredit = (bool) $request->user()?->can('sales_orders.approve')
            || (bool) $request->user()?->can('sales_orders.credit_override');
        $stockStatus = $record->branch_store_id === null
            ? collect()
            : $record->lines->reject->isService()->mapWithKeys(function (SalesOrderLine $line) use ($availability, $record): array {
                $base = $availability->forProduct((int) $record->company_id, (int) $record->branch_store_id, (int) $line->product_id, (int) $line->getKey());
                $factor = (string) $line->conversion_factor;

                return [$line->getKey() => [
                    'on_hand' => bcdiv($base['on_hand'], $factor, 8),
                    'available' => bcdiv($base['available'], $factor, 8),
                    'reserved' => $line->activeReservedQuantity(),
                    'shortage' => bccomp($line->remainingDeliveryQuantity(), bcdiv($base['available'], $factor, 8), 8) > 0
                        ? bcsub($line->remainingDeliveryQuantity(), bcdiv($base['available'], $factor, 8), 8)
                        : '0.00000000',
                ]];
            });

        return view('modules.sales.cycle.show', [
            'kind' => 'sales_order',
            'record' => $record,
            'showPrices' => (bool) $request->user()?->can('sales_orders.view_prices'),
            'creditControl' => $canViewCredit ? $creditControl->evaluate($record) : null,
            'stockStatus' => $stockStatus,
        ]);
    }

    public function destroyOrder(Request $request, SalesOrder $salesOrder, SalesOrderService $service): JsonResponse
    {
        $context = $this->requiredContext($request);
        abort_unless((int) $salesOrder->company_id === $context['company_id'] && (int) $salesOrder->branch_id === $context['branch_id'], 404);
        $service->delete($salesOrder);

        return response()->json(['message' => __('Saved successfully')]);
    }

    public function restoreOrder(Request $request, string $document, SalesOrderService $service): JsonResponse
    {
        $context = $this->requiredContext($request);
        $order = SalesOrder::onlyTrashed()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('doc_num', $document)->firstOrFail();
        $service->restore($order);

        return response()->json(['message' => __('Saved successfully')]);
    }

    public function createInvoice(Request $request): View|RedirectResponse
    {
        $context = $this->requiredContext($request);
        if ($request->filled('sales_order_doc_num')) {
            abort_unless($request->user()?->can('sales_orders.view') && $request->user()?->can('sales_orders.invoice'), 403);
            $order = SalesOrder::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])
                ->where('financial_period_id', $context['financial_period_id'])->where('doc_num', $request->string('sales_order_doc_num')->toString())
                ->whereIn('status', ['approved', 'partially_fulfilled', 'fulfilled'])->firstOrFail();

            return redirect()->to(route('admin.sales.sales-orders.show', $order).'#sales-order-invoice');
        }

        $sourceRequest = null;
        if ($request->filled('source_request_doc_num')) {
            abort_unless($request->user()?->can('sales_requests.view'), 403);
            $sourceRequest = SalesRequest::query()
                ->with(['customer', 'currency', 'salesEmployee', 'lines.product.unit', 'lines.product.equivalentUnit', 'lines.unit'])
                ->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])
                ->whereIn('status', ['approved', 'partially_converted'])
                ->where('doc_num', $request->string('source_request_doc_num')->toString())
                ->firstOrFail();
        }

        if ($request->boolean('direct') || $sourceRequest) {
            return view('modules.sales.cycle.direct-invoice-form', [
                ...$this->formOptions($request, null, $sourceRequest),
                'sourceRequest' => $sourceRequest,
            ]);
        }

        return view('modules.sales.cycle.invoice-source');
    }

    public function storeDirectInvoice(StoreDirectCustomerInvoiceRequest $request, CustomerInvoiceService $service): JsonResponse
    {
        $context = $this->requiredContext($request);
        $sourceRequest = $request->filled('source_request_doc_num')
            ? SalesRequest::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])
                ->where('doc_num', $request->validated('source_request_doc_num'))->firstOrFail()
            : null;
        if ($sourceRequest) {
            abort_unless($request->user()?->can('sales_requests.view'), 403);
        }

        return $this->created($service->createDirect($request->validated(), $sourceRequest), 'admin.sales.sales-invoices.show');
    }

    public function showInvoice(CustomerInvoice $customerInvoice): View
    {
        $record = $customerInvoice->load([
            'customer', 'order', 'delivery', 'deliveries.lines', 'originalInvoice', 'lines.product', 'lines.unit',
            'lines.orderLine', 'lines.deliveryLine.document', 'lines.returnLines.salesReturn', 'paymentSchedules',
            'allocations.receipt', 'journalEntry.lines', 'reversalJournalEntry', 'returns.creditNote', 'creditNotes',
            'creditAllocations.targetInvoice', 'appliedCredits.creditNote', 'creditRefunds.cashbox',
            'creditRefunds.bankAccount', 'electronicInvoiceSubmissions',
        ]);

        return $this->show($record->document_type, $record);
    }

    public function allocateCustomerCredit(
        AllocateCustomerCreditRequest $request,
        CustomerInvoice $customerInvoice,
        CustomerCreditService $service,
    ): JsonResponse {
        $target = CustomerInvoice::query()
            ->where('company_id', $customerInvoice->company_id)
            ->where('doc_num', $request->validated('target_invoice_doc_num'))
            ->firstOrFail();
        $allocation = $service->allocate(
            $customerInvoice,
            $target,
            (string) $request->validated('amount'),
            (string) $request->validated('allocation_date'),
            notes: $request->validated('notes'),
            idempotencyKey: $request->validated('idempotency_key') ?: $this->idempotencyKey([
                'credit-allocation', $customerInvoice->doc_num, $target->doc_num,
                $request->validated('amount'), $request->validated('allocation_date'),
            ]),
        );

        return response()->json(['data' => ['id' => $allocation->getKey(), 'url' => route('admin.sales.sales-invoices.show', $customerInvoice)]]);
    }

    public function refundCustomerCredit(
        RefundCustomerCreditRequest $request,
        CustomerInvoice $customerInvoice,
        CustomerCreditService $service,
    ): JsonResponse {
        $context = $this->requiredContext($request);
        $cashbox = $request->filled('cashbox_doc_num')
            ? Cashbox::query()->forCompany($context['company_id'])->active()->where('doc_num', $request->validated('cashbox_doc_num'))->firstOrFail()
            : null;
        $bank = $request->filled('bank_account_doc_num')
            ? BankAccount::query()->forCompany($context['company_id'])->active()->where('doc_num', $request->validated('bank_account_doc_num'))->firstOrFail()
            : null;
        $refund = $service->refund($customerInvoice, [
            ...$request->validated(), ...$context,
            'idempotency_key' => $request->validated('idempotency_key') ?: $this->idempotencyKey([
                'credit-refund', $customerInvoice->doc_num, $request->validated('amount'),
                $request->validated('refund_date'), $request->validated('payment_method'),
                $request->validated('cashbox_doc_num'), $request->validated('bank_account_doc_num'),
            ]),
            'cashbox_id' => $cashbox?->getKey(),
            'bank_account_id' => $bank?->getKey(), 'currency_id' => $customerInvoice->currency_id,
            'exchange_rate' => $customerInvoice->exchange_rate,
        ]);

        return response()->json(['data' => ['doc_num' => $refund->doc_num, 'url' => route('admin.sales.customer-credit-refunds.print', $refund)]]);
    }

    public function printCustomerCreditRefund(CustomerCreditRefund $customerCreditRefund): Response
    {
        $record = $customerCreditRefund->load(['creditNote.company', 'creditNote.customer', 'cashbox', 'bankAccount', 'journalEntry.lines', 'creditNote.creditAllocations', 'creditNote.creditRefunds']);

        return $this->pdf->stream('reports.sales.customer-credit-refund', [
            'title' => __('Customer Credit Refund').' — '.$record->doc_num,
            'record' => $record,
            'companyPrintIdentity' => $record->creditNote->print_identity_snapshot ?: $this->printIdentity->forCompany($record->creditNote->company),
        ], str('customer-credit-refund-'.$record->doc_num)->slug().'.pdf');
    }

    public function submitElectronicInvoice(CustomerInvoice $customerInvoice, ElectronicInvoiceService $service): JsonResponse
    {
        $submission = $service->queue($customerInvoice);

        return response()->json(['data' => ['status' => $submission->status, 'submission_id' => $submission->getKey()]]);
    }

    public function editInvoice(CustomerInvoice $customerInvoice): View
    {
        abort_unless($customerInvoice->isEditable() && $customerInvoice->document_type === CustomerInvoice::TypeInvoice, 409, 'The invoice is locked.');

        return view('modules.sales.cycle.invoice-form', [
            'record' => $customerInvoice->load(['customer', 'order', 'lines.product', 'lines.unit', 'lines.deliveryLine.document', 'paymentSchedules']),
        ]);
    }

    public function updateInvoice(AmendCustomerInvoiceRequest $request, CustomerInvoice $customerInvoice, CustomerInvoiceService $service): JsonResponse
    {
        $invoice = $service->amend($customerInvoice, $request->validated('lines'), $request->validated('payment_schedules'));

        return response()->json(['data' => ['doc_num' => $invoice->doc_num, 'url' => route('admin.sales.sales-invoices.show', $invoice)]]);
    }

    public function showReceipt(CustomerReceipt $customerReceipt): View
    {
        return $this->show('customer_receipt', $customerReceipt->load(['customer', 'receivedByEmployee', 'currency', 'cashbox', 'bankAccount.bank', 'order', 'cashVoucher', 'cheque', 'allocations.invoice', 'allocations.invoiceSchedule', 'journalEntry.lines']));
    }

    public function showReturn(SalesReturn $salesReturn): View
    {
        return $this->show('sales_return', $salesReturn->load(['customer', 'invoice', 'order', 'delivery', 'returnInventoryDocument.lines', 'creditNote.lines', 'quarantineJournalEntry', 'dispositionJournalEntry', 'lines.product', 'lines.unit', 'statusHistory.changedBy']));
    }

    public function showDelivery(InventoryDocument $inventoryDocument): View
    {
        abort_unless($inventoryDocument->document_type === InventoryDocument::TypeSalesDelivery, 404);

        $relations = ['customer', 'customerInvoices', 'branchStore', 'lines.product', 'lines.unit', 'lines.transactionUnit'];
        if ($inventoryDocument->source_document_type === SalesOrder::class) {
            $relations[] = 'salesOrder.salesEmployee';
        }

        return $this->show('sales_delivery', $inventoryDocument->load($relations));
    }

    public function showProduction(ProductionOrder $productionOrder): View
    {
        $record = $productionOrder->load(['salesOrder.branchStore', 'lines.product', 'lines.unit', 'runs.product']);

        return view('modules.sales.cycle.show', [
            'kind' => 'production_request',
            'record' => $record,
            'showPrices' => false,
            'stores' => BranchStore::query()->where('branch_id', $record->branch_id)->orderBy('position')->get(),
        ]);
    }

    public function storeOrder(StoreSalesOrderRequest $request, SalesOrderService $service, SalesRequestService $requestService): JsonResponse
    {
        $context = $this->requiredContext($request);
        $payload = $this->salesOrderPayload($request->validated(), $context);
        if ($request->filled('source_request_doc_num')) {
            abort_unless($request->user()?->can('sales_requests.view'), 403);
            $sourceRequest = SalesRequest::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])
                ->where('doc_num', $request->validated('source_request_doc_num'))->firstOrFail();
            $order = $requestService->convertToOrder($sourceRequest, $payload);
        } else {
            $payload['lines'] = collect($payload['lines'])->map(fn (array $line): array => collect($line)->except('source_request_line_public_id')->all())->all();
            $order = $service->create(collect($payload)->except('source_request_doc_num')->all());
        }

        return $this->created($order, 'admin.sales.sales-orders.show');
    }

    public function updateOrder(UpdateSalesOrderRequest $request, SalesOrder $salesOrder, SalesOrderService $service): JsonResponse
    {
        $context = $this->requiredContext($request);
        abort_unless((int) $salesOrder->financial_period_id === $context['financial_period_id'], 404);
        $payload = $this->salesOrderPayload($request->validated(), $context, $salesOrder->business_employee_id);
        $payload['lines'] = collect($payload['lines'])->map(fn (array $line): array => collect($line)->except('source_request_line_public_id')->all())->all();
        $order = $service->update($salesOrder, collect($payload)->except('source_request_doc_num')->all());

        return response()->json(['data' => ['doc_num' => $order->doc_num, 'url' => route('admin.sales.sales-orders.show', $order)]]);
    }

    public function submitOrder(SalesOrder $salesOrder, SalesOrderService $service): JsonResponse
    {
        return response()->json(['data' => $service->submit($salesOrder)]);
    }

    public function approveOrder(SalesOrder $salesOrder, SalesOrderService $service): JsonResponse
    {
        return response()->json(['data' => $service->approve($salesOrder)]);
    }

    public function overrideOrder(SalesOrderActionRequest $request, SalesOrder $salesOrder, SalesOrderService $service): JsonResponse
    {
        return response()->json(['data' => $service->overrideCreditHold($salesOrder, (string) $request->validated('reason'))]);
    }

    public function rejectOrder(SalesOrderActionRequest $request, SalesOrder $salesOrder, SalesOrderService $service): JsonResponse
    {
        return response()->json(['data' => $service->reject($salesOrder, (string) $request->validated('reason'))]);
    }

    public function reopenOrder(SalesOrderActionRequest $request, SalesOrder $salesOrder, SalesOrderService $service): JsonResponse
    {
        return response()->json(['data' => $service->reopen($salesOrder, (string) $request->validated('reason'))]);
    }

    public function cancelOrder(SalesOrderActionRequest $request, SalesOrder $salesOrder, SalesOrderService $service): JsonResponse
    {
        return response()->json(['data' => $service->cancel($salesOrder, (string) $request->validated('reason'))]);
    }

    public function deliverInvoice(CreateDeliveryRequest $request, CustomerInvoice $customerInvoice, SalesFulfillmentService $service): JsonResponse
    {
        $data = $request->validated();
        $lines = collect($data['lines'])->map(fn (array $line): array => [
            'customer_invoice_line_id' => CustomerInvoiceLine::query()
                ->where('customer_invoice_id', $customerInvoice->getKey())
                ->where('public_id', $line['invoice_line_public_id'])
                ->firstOrFail()
                ->getKey(),
            'quantity' => $line['quantity'],
        ])->all();

        return $this->created($service->deliverInvoice($customerInvoice, $lines, collect($data)->except('lines')->all()), 'admin.sales.delivery-notes.show');
    }

    public function reserveOrder(ReserveSalesStockRequest $request, SalesOrder $salesOrder, SalesFulfillmentService $service): JsonResponse
    {
        $line = SalesOrderLine::query()->where('sales_order_id', $salesOrder->getKey())->where('public_id', $request->validated('sales_order_line_public_id'))->firstOrFail();

        return response()->json(['data' => $service->reserve($line, (string) $request->validated('quantity'))], 201);
    }

    public function releaseReservation(ReleaseSalesStockRequest $request, SalesOrder $salesOrder, SalesFulfillmentService $service): JsonResponse
    {
        $reservation = InventoryReservation::query()
            ->where('sales_order_id', $salesOrder->getKey())
            ->where('public_id', $request->validated('reservation_public_id'))
            ->firstOrFail();

        return response()->json(['data' => $service->releaseReservation($reservation, (string) $request->validated('reason'))]);
    }

    public function produceOrder(CreateProductionDemandRequest $request, SalesOrder $salesOrder, SalesProductionDemandService $service): JsonResponse
    {
        $lines = collect($request->validated('lines'))->map(fn (array $row): array => ['sales_order_line_id' => SalesOrderLine::query()->where('sales_order_id', $salesOrder->getKey())->where('public_id', $row['sales_order_line_public_id'])->firstOrFail()->getKey(), 'quantity' => $row['quantity']])->all();

        return $this->created($service->create($salesOrder, $lines), 'admin.sales.production-requests.show');
    }

    public function invoiceOrder(StoreCustomerInvoiceRequest $request, SalesOrder $salesOrder, CustomerInvoiceService $service): JsonResponse
    {
        $data = $request->validated();
        $delivery = empty($data['delivery_doc_num']) ? null : InventoryDocument::query()->where('company_id', $salesOrder->company_id)->where('doc_num', $data['delivery_doc_num'])->firstOrFail();
        $lines = collect($data['lines'])->map(function (array $line) use ($salesOrder): array {
            $orderLine = SalesOrderLine::query()->where('sales_order_id', $salesOrder->getKey())->where('public_id', $line['sales_order_line_public_id'])->firstOrFail();
            $deliveryLine = empty($line['delivery_line_public_id']) ? null : InventoryDocumentLine::query()
                ->where('public_id', $line['delivery_line_public_id'])
                ->whereHas('document', fn ($query) => $query
                    ->where('company_id', $salesOrder->company_id)
                    ->where('source_document_id', $salesOrder->getKey())
                    ->where('document_type', InventoryDocument::TypeSalesDelivery)
                    ->where('status', InventoryDocument::StatusPosted))
                ->firstOrFail();

            return ['sales_order_line_id' => $orderLine->getKey(), 'delivery_line_id' => $deliveryLine?->getKey(), 'quantity' => $line['quantity']];
        })->all();

        if ($delivery === null) {
            $deliveryLineId = collect($lines)->pluck('delivery_line_id')->filter()->first();
            $delivery = $deliveryLineId === null
                ? null
                : InventoryDocumentLine::query()->findOrFail($deliveryLineId)->document;
        }

        return $this->created($service->createFromOrder($salesOrder, $lines, $data['payment_schedules'], $delivery, $data['invoice_date'] ?? null), 'admin.sales.customer-invoices.show');
    }

    public function postInvoice(CustomerInvoice $customerInvoice, CustomerInvoiceService $service): JsonResponse
    {
        return response()->json(['data' => $service->post($customerInvoice)]);
    }

    public function createReceipt(Request $request): View
    {
        $context = $this->requiredContext($request);
        $invoice = $request->filled('invoice')
            ? CustomerInvoice::query()->where('company_id', $context['company_id'])->where('doc_num', $request->string('invoice'))->firstOrFail()
            : null;
        $order = $invoice?->order ?? ($request->filled('order')
            ? SalesOrder::query()->where('company_id', $context['company_id'])->where('doc_num', $request->string('order'))->firstOrFail()
            : null);
        $customerId = $invoice?->customer_id ?? $order?->customer_id;
        $selectedCurrencyId = $invoice?->currency_id ?? $order?->currency_id;
        $selectedEmployeeDocNum = $request->old('received_by_employee_doc_num');

        return view('modules.sales.cycle.receipt-form', [
            'customers' => Customer::query()->forCompany($context['company_id'])->where('id', $customerId)->get(),
            'currencies' => Currency::query()->forCompany($context['company_id'])->active()
                ->where(function ($query) use ($selectedCurrencyId): void {
                    $query->where('is_main', true)
                        ->when($selectedCurrencyId, fn ($selected) => $selected->orWhere('id', $selectedCurrencyId));
                })->orderByDesc('is_main')->get(),
            'cashboxes' => Cashbox::query()->forCompany($context['company_id'])->where('doc_num', $request->old('cashbox_doc_num'))->get(),
            'bankAccounts' => BankAccount::query()->forCompany($context['company_id'])->where('doc_num', $request->old('bank_account_doc_num'))->get(),
            'receivedByEmployees' => HrEmployee::withTrashed()->where('company_id', $context['company_id'])->where('doc_num', $selectedEmployeeDocNum)->get(),
            'schedules' => CustomerInvoicePaymentSchedule::query()
                ->with(['invoice.customer'])
                ->whereHas('invoice', fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('posting_status', 'posted')
                    ->when($customerId, fn ($query) => $query->where('customer_id', $customerId)))
                ->whereColumn('amount', '>', DB::raw('collected_amount + credited_amount'))
                ->orderBy('due_date')
                ->get(),
            'selectedInvoice' => $invoice,
            'selectedOrder' => $order,
            'suggestedAmount' => $invoice?->remaining_amount ?? $order?->required_advance_amount,
        ]);
    }

    public function reopenInvoice(SalesOrderActionRequest $request, CustomerInvoice $customerInvoice, CustomerInvoiceService $service): JsonResponse
    {
        return response()->json(['data' => $service->reopen($customerInvoice, (string) $request->validated('reason'))]);
    }

    public function priceSuggestion(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canAny(['sales_orders.create', 'sales_orders.edit', 'sales_requests.create', 'sales_requests.edit', 'quotations.create', 'quotations.edit']), 403);
        $context = $this->requiredContext($request);
        $data = $request->validate(['customer_doc_num' => ['required', 'string'], 'product_doc_num' => ['required', 'string'], 'unit_doc_num' => ['required', 'string'], 'currency_doc_num' => ['required', 'string']]);
        $customerId = Customer::query()->forCompany($context['company_id'])->where('doc_num', $data['customer_doc_num'])->valueOrFail('id');
        $productId = Product::query()->forCompany($context['company_id'])->where('doc_num', $data['product_doc_num'])->valueOrFail('id');
        $unitId = ItemUnit::query()->forCompany($context['company_id'])->where('doc_num', $data['unit_doc_num'])->valueOrFail('id');
        $currencyId = Currency::query()->forCompany($context['company_id'])->where('doc_num', $data['currency_doc_num'])->valueOrFail('id');
        $quotation = Quotation::query()->with('currentRevision.lines')->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])
            ->where('customer_id', $customerId)->where('currency_id', $currencyId)->whereIn('status', ['accepted', 'converted'])
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->whereHas('currentRevision.lines', fn ($query) => $query->where('product_id', $productId)->where('unit_id', $unitId))->latest('quotation_date')->latest('id')->first();
        $quotationLine = $quotation?->currentRevision?->lines->first(fn ($line) => $line->product_id === $productId && $line->unit_id === $unitId);
        if ($quotationLine) {
            return response()->json(['data' => ['unit_price' => $quotationLine->unit_price, 'source' => $quotation->doc_num]]);
        }
        $line = CustomerInvoiceLine::query()->with('invoice')->where('product_id', $productId)->where('unit_id', $unitId)
            ->whereHas('invoice', fn ($query) => $query->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('customer_id', $customerId)->where('currency_id', $currencyId)->where('document_type', 'invoice')->where('posting_status', 'posted'))->latest('id')->first();

        return response()->json(['data' => $line ? ['unit_price' => $line->unit_price, 'source' => $line->invoice->doc_num] : null]);
    }

    public function reverseReceipt(Request $request, CustomerReceipt $customerReceipt): JsonResponse
    {
        $context = $this->requiredContext($request);
        abort_unless((int) $customerReceipt->branch_id === (int) $context['branch_id'], 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        if ($customerReceipt->cash_voucher_id) {
            app(CashVoucherService::class)->cancel(CashVoucher::TypeReceipt, $customerReceipt->cashVoucher, $data['reason']);
        } elseif ($customerReceipt->cheque_id) {
            app(ChequeService::class)->cancel($customerReceipt->cheque, $data['reason']);
        } else {
            app(CustomerReceiptSettlementService::class)->reverse($customerReceipt, $data['reason']);
        }

        return response()->json(['data' => ['doc_num' => $customerReceipt->doc_num]]);
    }

    public function storeReceipt(StoreCustomerReceiptRequest $request, CustomerReceiptService $service): JsonResponse
    {
        $context = $this->requiredContext($request);
        $data = $request->validated();
        $customer = Customer::query()->forCompany($context['company_id'])->where('status', 'active')->where('doc_num', $data['customer_doc_num'])->firstOrFail();
        $order = empty($data['sales_order_doc_num']) ? null : SalesOrder::query()->forCompany($context['company_id'])->where('doc_num', $data['sales_order_doc_num'])->firstOrFail();
        $currency = empty($data['currency_doc_num']) ? null : Currency::query()->forCompany($context['company_id'])->active()->where('doc_num', $data['currency_doc_num'])->firstOrFail();
        $cashbox = empty($data['cashbox_doc_num']) ? null : Cashbox::query()->forCompany($context['company_id'])->active()->where('doc_num', $data['cashbox_doc_num'])->firstOrFail();
        $bank = empty($data['bank_account_doc_num']) ? null : BankAccount::query()->forCompany($context['company_id'])->active()->where('doc_num', $data['bank_account_doc_num'])->firstOrFail();
        $allocations = collect($data['allocations'] ?? [])->map(fn (array $row): array => [
            'customer_invoice_payment_schedule_id' => CustomerInvoicePaymentSchedule::query()
                ->where('public_id', $row['invoice_schedule_public_id'])
                ->whereHas('invoice', fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('customer_id', $customer->getKey())
                    ->where('posting_status', 'posted'))
                ->firstOrFail()
                ->getKey(),
            'amount' => $row['amount'],
        ])->all();
        $receivedByEmployeeId = app(SalesSelect2Service::class)->employeeId(
            $context['company_id'],
            $context['branch_id'],
            $data['received_by_employee_doc_num'],
            attribute: 'received_by_employee_doc_num',
        );
        $receipt = $service->createAndApprove([...collect($data)->except(['customer_doc_num', 'sales_order_doc_num', 'currency_doc_num', 'cashbox_doc_num', 'bank_account_doc_num', 'received_by_employee_doc_num', 'allocations'])->all(), ...$context, 'customer_id' => $customer->getKey(), 'received_by_employee_id' => $receivedByEmployeeId, 'sales_order_id' => $order?->getKey(), 'currency_id' => $currency?->getKey(), 'cashbox_id' => $cashbox?->getKey(), 'bank_account_id' => $bank?->getKey()], $allocations);

        return $this->created($receipt, 'admin.sales.customer-receipts.show', ['unallocated_amount' => $receipt->unallocated_amount]);
    }

    public function storeReturn(StoreSalesReturnRequest $request, CustomerInvoice $customerInvoice, SalesReturnService $service): JsonResponse
    {
        $context = $this->requiredContext($request);
        $data = $request->validated();
        $storeId = empty($data['branch_store_uuid']) ? null : BranchStore::query()
            ->where('branch_id', $context['branch_id'])
            ->where('public_uuid', $data['branch_store_uuid'])
            ->valueOrFail('id');
        $lines = collect($data['lines'])->map(fn (array $row): array => ['customer_invoice_line_id' => CustomerInvoiceLine::query()->where('customer_invoice_id', $customerInvoice->getKey())->where('public_id', $row['invoice_line_public_id'])->firstOrFail()->getKey(), 'quantity' => $row['quantity']])->all();

        return $this->created($service->create($customerInvoice, $data['reason_code'], $data['reason_details'] ?? null, $lines, $storeId), 'admin.sales.sales-returns.show');
    }

    public function authorizeReturn(SalesReturn $salesReturn, SalesReturnService $service): JsonResponse
    {
        return response()->json(['data' => $service->authorize($salesReturn)]);
    }

    public function receiveReturn(SalesReturn $salesReturn, SalesReturnService $service): JsonResponse
    {
        return response()->json(['data' => $service->receive($salesReturn)]);
    }

    public function inspectReturn(InspectSalesReturnRequest $request, SalesReturn $salesReturn, SalesReturnService $service): JsonResponse
    {
        $results = collect($request->validated('results'))->map(function (array $row) use ($salesReturn): array {
            $line = SalesReturnLine::query()->where('sales_return_id', $salesReturn->getKey())->where('public_id', $row['sales_return_line_public_id'])->firstOrFail();

            return [...collect($row)->except('sales_return_line_public_id')->all(), 'sales_return_line_id' => $line->getKey()];
        })->all();

        return response()->json(['data' => $service->inspect($salesReturn, $results)]);
    }

    public function cancelReturn(Request $request, SalesReturn $salesReturn, SalesReturnService $service): JsonResponse
    {
        $context = $this->requiredContext($request);
        abort_unless((int) $salesReturn->branch_id === (int) $context['branch_id'], 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $service->cancel($salesReturn, $data['reason'])]);
    }

    public function closeReturn(SalesReturn $salesReturn, SalesReturnService $service): JsonResponse
    {
        return response()->json(['data' => $service->close($salesReturn)]);
    }

    public function printOrder(SalesOrder $salesOrder): Response
    {
        return $this->print('sales_order', $salesOrder->load(['company', 'customer', 'quotation.currentRevision', 'quotationRevision', 'branch', 'branchStore', 'currency', 'lines.product', 'lines.unit', 'paymentSchedules']), true);
    }

    public function printInvoice(CustomerInvoice $customerInvoice): Response
    {
        return $this->print($customerInvoice->document_type, $customerInvoice->load(['company', 'customer', 'order', 'delivery', 'deliveries', 'originalInvoice', 'salesReturn', 'lines.product', 'lines.unit', 'paymentSchedules']), true);
    }

    public function printReceipt(CustomerReceipt $customerReceipt): Response
    {
        $receipt = $customerReceipt->load([
            'company', 'customer', 'receivedByEmployee', 'currency', 'cashbox', 'bankAccount.bank',
            'cashVoucher.company', 'cashVoucher.cashbox.account', 'cashVoucher.currency', 'cashVoucher.lines.account',
            'cheque.company', 'cheque.bankAccount.bank', 'cheque.bankAccount.account', 'cheque.currency', 'cheque.lines.account',
            'order', 'allocations.invoice', 'allocations.invoiceSchedule',
        ]);

        return $this->print('customer_receipt', $receipt, true);
    }

    public function printReturn(SalesReturn $salesReturn): Response
    {
        return $this->print('sales_return', $salesReturn->load(['company', 'customer', 'invoice', 'delivery', 'returnInventoryDocument', 'creditNote', 'quarantineJournalEntry', 'dispositionJournalEntry', 'inspectedBy', 'lines.product', 'lines.unit']), true);
    }

    public function printDelivery(InventoryDocument $inventoryDocument): Response
    {
        abort_unless($inventoryDocument->document_type === InventoryDocument::TypeSalesDelivery, 404);

        $relations = ['company', 'customer', 'customerInvoices', 'branchStore', 'lines.product', 'lines.unit', 'lines.transactionUnit'];
        if ($inventoryDocument->source_document_type === SalesOrder::class) {
            $relations[] = 'salesOrder.salesEmployee';
        }

        return $this->print('sales_delivery', $inventoryDocument->load($relations), false);
    }

    public function printProduction(ProductionOrder $productionOrder): Response
    {
        return $this->print('production_request', $productionOrder->load(['company', 'salesOrder', 'lines.product', 'lines.unit']), false);
    }

    public function printPaymentSchedule(CustomerInvoice $customerInvoice): Response
    {
        return $this->print('payment_schedule', $customerInvoice->load(['company', 'customer', 'order', 'paymentSchedules']), true);
    }

    public function printQualityDisposition(SalesReturn $salesReturn): Response
    {
        return $this->print('quality_disposition', $salesReturn->load(['company', 'customer', 'invoice', 'returnInventoryDocument', 'quarantineJournalEntry', 'dispositionJournalEntry', 'inspectedBy', 'lines.product', 'lines.unit']), false);
    }

    private function listing(Request $request, string $kind, $query): View|JsonResponse
    {
        $context = $this->requiredContext($request);
        $dateColumn = match ($kind) {
            'sales_orders' => 'order_date', 'customer_invoices' => 'invoice_date',
            'customer_receipts' => 'receipt_date', 'sales_returns' => 'return_date',
            default => 'document_date',
        };
        $query
            ->when($request->filled('document'), fn ($query) => $query->where('doc_num', 'like', '%'.trim($request->string('document')->toString()).'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('customer'), fn ($query) => $query->whereHas('customer', fn ($customer) => $customer
                ->where('doc_num', 'like', '%'.trim($request->string('customer')->toString()).'%')
                ->orWhere('name', 'like', '%'.trim($request->string('customer')->toString()).'%')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate($dateColumn, '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate($dateColumn, '<=', $request->date('date_to')));

        if ($kind === 'sales_orders' && $request->boolean('overdue')) {
            $query->whereDate('expected_delivery_date', '<', now()->toDateString())
                ->whereNotIn('status', [SalesOrder::StatusFulfilled, SalesOrder::StatusClosed, SalesOrder::StatusCancelled]);
        }
        if ($kind === 'sales_orders') {
            $query
                ->when($request->filled('required_from'), fn ($query) => $query->whereDate('expected_delivery_date', '>=', $request->date('required_from')))
                ->when($request->filled('required_to'), fn ($query) => $query->whereDate('expected_delivery_date', '<=', $request->date('required_to')))
                ->when($request->filled('credit_status'), fn ($query) => $query->where('credit_status', $request->string('credit_status')->toString()))
                ->when($request->filled('salesman'), fn ($query) => $query->whereHas('salesEmployee', fn ($employee) => $employee
                    ->where('doc_num', 'like', '%'.trim($request->string('salesman')->toString()).'%')
                    ->orWhere('name', 'like', '%'.trim($request->string('salesman')->toString()).'%')))
                ->when($request->string('fulfillment')->toString() === 'open', fn ($query) => $query->whereHas('lines', fn ($line) => $line
                    ->where('product_classification_snapshot', '<>', Product::ClassificationService)
                    ->whereColumn('delivered_quantity', '<', 'quantity')))
                ->when($request->string('fulfillment')->toString() === 'partial', fn ($query) => $query
                    ->whereHas('lines', fn ($line) => $line->where('delivered_quantity', '>', 0))
                    ->whereHas('lines', fn ($line) => $line
                        ->where('product_classification_snapshot', '<>', Product::ClassificationService)
                        ->whereColumn('delivered_quantity', '<', 'quantity')))
                ->when($request->string('fulfillment')->toString() === 'complete', fn ($query) => $query->whereDoesntHave('lines', fn ($line) => $line
                    ->where('product_classification_snapshot', '<>', Product::ClassificationService)
                    ->whereColumn('delivered_quantity', '<', 'quantity')));
        }

        $query->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('financial_period_id', $context['financial_period_id']);
        if ($request->has('draw')) {
            return app(SalesCycleDataTable::class)->json($request, $query, $kind, $dateColumn);
        }

        return view('modules.sales.cycle.index', ['kind' => $kind]);
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request, ?SalesOrder $record = null, ?SalesRequest $sourceRequest = null): array
    {
        $this->requiredContext($request);

        return app(SalesSelect2Service::class)->formOptions($request, $record, $sourceRequest);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     * @return array<string, mixed>
     */
    private function salesOrderPayload(array $data, array $context, ?int $preservedEmployeeId = null): array
    {
        $customer = Customer::query()->forCompany($context['company_id'])->active()->where('doc_num', $data['customer_doc_num'])->firstOrFail();
        $currency = Currency::query()->forCompany($context['company_id'])->active()->where('doc_num', $data['currency_doc_num'])->firstOrFail();
        $store = empty($data['branch_store_uuid']) ? null : BranchStore::query()->where('branch_id', $context['branch_id'])->where('public_uuid', $data['branch_store_uuid'])->firstOrFail();
        $salesEmployeeId = app(SalesSelect2Service::class)->employeeId($context['company_id'], $context['branch_id'], $data['sales_employee_doc_num'] ?? null, $preservedEmployeeId);
        $lines = collect($data['lines'])->map(function (array $line) use ($context): array {
            $product = Product::query()->forCompany($context['company_id'])->active()->where('doc_num', $line['product_doc_num'])->firstOrFail();
            $unit = empty($line['unit_doc_num']) ? null : ItemUnit::query()->forCompany($context['company_id'])->active()->where('doc_num', $line['unit_doc_num'])->firstOrFail();

            return [
                ...collect($line)->except(['product_doc_num', 'unit_doc_num'])->all(),
                'product_id' => $product->getKey(),
                'unit_id' => $unit?->getKey(),
                'description' => trim((string) ($line['description'] ?? '')) ?: $product->name,
            ];
        })->all();

        return [
            ...collect($data)->except(['customer_doc_num', 'currency_doc_num', 'branch_store_uuid', 'sales_employee_doc_num', 'lines'])->all(),
            ...$context,
            'customer_id' => $customer->getKey(), 'currency_id' => $currency->getKey(),
            'branch_store_id' => $store?->getKey(), 'business_employee_id' => $salesEmployeeId, 'lines' => $lines,
        ];
    }

    /** @param array<string, mixed> $extra */
    private function show(string $kind, object $record, array $extra = []): View
    {
        $pricePermission = match ($kind) {
            'sales_order' => 'sales_orders.view_prices',
            'invoice', 'credit_note' => 'customer_invoices.view_prices',
            default => null,
        };

        return view('modules.sales.cycle.show', [
            'kind' => $kind,
            'record' => $record,
            'showPrices' => $pricePermission === null || (bool) request()->user()?->can($pricePermission),
            ...$extra,
        ]);
    }

    private function print(string $kind, object $record, bool $financial): Response
    {
        $pricePermission = match ($kind) {
            'sales_order' => 'sales_orders.view_prices',
            'invoice', 'credit_note', 'payment_schedule', 'sales_return' => 'customer_invoices.view_prices',
            'customer_receipt' => 'customer_receipts.view',
            default => null,
        };

        $title = match ($kind) {
            'sales_order' => __('Sales Order'),
            'invoice' => __('Sales Invoice'),
            'credit_note' => __('Sales Credit Note'),
            'customer_receipt' => __('Customer Receipt'),
            'sales_return' => __('Sales Return'),
            'sales_delivery' => __('Delivery Note'),
            'production_request' => __('Production Request'),
            'payment_schedule' => __('Payment Schedule'),
            'quality_disposition' => __('Return Quality Disposition'),
            default => __(str($kind)->replace('_', ' ')->title()->toString()),
        };

        $copy = request()->validate(['copy' => ['nullable', Rule::in(['operational', 'legal'])]])['copy'] ?? 'operational';

        return $this->pdf->stream('reports.sales.document', [
            'printIdentityPolicy' => in_array($kind, ['invoice', 'credit_note'], true) ? $copy : 'operational',
            'title' => $title.' — '.$record->doc_num,
            'documentHeaderTitle' => $title,
            'kind' => $kind,
            'record' => $record,
            'showPrices' => $financial
                && ($pricePermission === null || (bool) request()->user()?->can($pricePermission)),
            'companyPrintIdentity' => $record->print_identity_snapshot ?: $this->printIdentity->forCompany($record->company),
            'customerFacing' => true,
        ], str($kind.'-'.$record->doc_num)->slug().'.pdf');
    }

    private function created(object $record, string $route, array $extra = []): JsonResponse
    {
        return response()->json(['data' => ['doc_num' => $record->doc_num, 'url' => route($route, $record), ...$extra]], 201);
    }

    /** @param list<mixed> $parts */
    private function idempotencyKey(array $parts): string
    {
        $hex = md5(collect($parts)->map(fn (mixed $part): string => (string) $part)->implode('|'));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, 'Operating context is required.');

        return ['company_id' => (int) $context['company_id'], 'financial_period_id' => (int) $context['financial_period_id'], 'branch_id' => (int) $context['branch_id']];
    }
}

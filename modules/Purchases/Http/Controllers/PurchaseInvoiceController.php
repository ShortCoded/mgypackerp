<?php

namespace Modules\Purchases\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberSettingsService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\DataTables\PurchaseInvoicesDataTable;
use Modules\Purchases\Http\Requests\BulkDeletePurchaseInvoicesRequest;
use Modules\Purchases\Http\Requests\CancelPurchaseInvoiceRequest;
use Modules\Purchases\Http\Requests\StorePurchaseInvoiceRequest;
use Modules\Purchases\Http\Requests\UpdatePurchaseInvoiceDocumentNumberSettingsRequest;
use Modules\Purchases\Http\Requests\UpdatePurchaseInvoiceRequest;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Services\PurchaseInvoiceMatchingService;
use Modules\Purchases\Services\PurchaseInvoiceService;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private readonly PurchaseInvoiceService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingContextService $operatingContext,
    ) {}

    public function index(DocumentNumberSettingsService $settings): View
    {
        return view('modules.purchases.purchase-invoices.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.purchases.purchase-invoices.index'),
            'documentNumberSettings' => $settings->current('purchase_invoices'),
        ]);
    }

    public function data(Request $request, PurchaseInvoicesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        $input = $request->validate(['purchase_order' => ['nullable', 'string'], 'receipts' => ['nullable', 'array'], 'receipts.*' => ['required', 'string', 'distinct']]);
        if (blank($input['purchase_order'] ?? null) && ! empty($input['receipts'])) {
            $context = $this->operatingContext->snapshot($request);
            $receipt = UnpricedInventoryReceipt::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('doc_num', $input['receipts'][0])->with('purchaseOrder')->firstOrFail();
            $input['purchase_order'] = $receipt->purchaseOrder?->doc_num;
        }
        if (blank($input['purchase_order'] ?? null)) {
            return $this->form('create');
        }
        $context = $this->operatingContext->snapshot($request);
        $order = PurchaseOrder::query()->forCompany($context['company_id'])
            ->where('branch_id', $context['branch_id'])->where('doc_num', $input['purchase_order'])
            ->whereIn('status', [PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed])
            ->with(['supplier', 'currency', 'financialPeriod', 'lines.product', 'lines.unit', 'lines.costCenter'])->firstOrFail();
        $matching = app(PurchaseInvoiceMatchingService::class);
        $sourceLines = collect();
        foreach ($order->lines as $orderLine) {
            $receipts = $orderLine->product?->isService() ? collect([null]) : UnpricedInventoryReceiptLine::query()
                ->where('purchase_order_line_id', $orderLine->getKey())->whereHas('receipt', fn ($query) => $query
                ->where('approved', true)->whereNotIn('status', ['cancelled', 'reversed'])
                ->when(! empty($input['receipts']), fn ($query) => $query->whereIn('doc_num', $input['receipts'])))
                ->with('receipt')->get();
            foreach ($receipts as $receiptLine) {
                $alreadyBilled = (float) PurchaseInvoiceLine::query()->where('purchase_order_line_id', $orderLine->getKey())
                    ->whereHas('purchaseInvoice', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed']))->sum('quantity');
                $quantity = $receiptLine ? $matching->remainingForReceipt($receiptLine) : max(0, (float) $orderLine->ordered_quantity - $alreadyBilled);
                if ($quantity <= 0) {
                    continue;
                }
                $line = new PurchaseInvoiceLine([
                    'product_id' => $orderLine->product_id, 'unit_id' => $orderLine->unit_id,
                    'purchase_order_line_id' => $orderLine->getKey(), 'receipt_line_id' => $receiptLine?->getKey(),
                    'quantity' => $quantity, 'unit_price' => $orderLine->unit_price,
                    'discount_type' => 'fixed', 'discount_value' => (float) $orderLine->discount_amount * $quantity / (float) $orderLine->ordered_quantity,
                    'tax_rate' => $orderLine->tax_rate, 'notes' => $orderLine->notes,
                ]);
                $line->setRelation('product', $orderLine->product)->setRelation('unit', $orderLine->unit)
                    ->setRelation('costCenter', $orderLine->costCenter)->setRelation('purchaseOrderLine', $orderLine)->setRelation('receiptLine', $receiptLine);
                $sourceLines->push($line);
            }
        }
        abort_if($sourceLines->isEmpty(), 422, __('There are no accepted quantities remaining to invoice.'));
        $draft = new PurchaseInvoice([
            'company_id' => $order->company_id, 'financial_period_id' => $context['financial_period_id'], 'branch_id' => $order->branch_id,
            'purchase_order_id' => $order->getKey(), 'supplier_id' => $order->supplier_id, 'currency_id' => $order->currency_id,
            'exchange_rate' => $order->exchange_rate, 'invoice_date' => now()->toDateString(),
            'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        ]);
        $draft->setRelation('purchaseOrder', $order)->setRelation('supplier', $order->supplier)->setRelation('currency', $order->currency)
            ->setRelation('financialPeriod', FinancialPeriod::query()->findOrFail($context['financial_period_id']))->setRelation('lines', new Collection($sourceLines->all()));

        return $this->form('create', $draft);
    }

    public function store(StorePurchaseInvoiceRequest $request): JsonResponse
    {
        try {
            $record = $this->service->create($request->validated())['record'];
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('purchase_invoices.messages.created'),
            ...$this->saveResponse($request, $record, true),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function show(Request $request, PurchaseInvoice $purchaseInvoice): View
    {
        abort_if($purchaseInvoice->trashed() && ! $request->user()?->can('purchase_invoices.view_trashed'), 404);

        return $this->form('view', $purchaseInvoice);
    }

    public function edit(PurchaseInvoice $purchaseInvoice): View
    {
        abort_if($purchaseInvoice->isLockedForEditing(), 403, __('purchase_invoices.messages.document_locked'));

        return $this->form('edit', $purchaseInvoice);
    }

    public function clone(PurchaseInvoice $purchaseInvoice): View
    {
        return $this->form('clone', $purchaseInvoice, (string) Str::uuid());
    }

    public function update(UpdatePurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        try {
            $result = $this->service->update($purchaseInvoice, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('purchase_invoices.messages.updated'),
            ...$this->saveResponse($request, $record, false),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function destroy(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        try {
            $this->service->delete($purchaseInvoice);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('purchase_invoices.messages.deleted')]);
    }

    public function bulkDelete(BulkDeletePurchaseInvoicesRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => __('purchase_invoices.messages.bulk_deleted', [
                'count' => $this->service->bulkDelete($request->validated('doc_nums')),
            ]),
        ]);
    }

    public function restore(string $purchaseInvoice): JsonResponse
    {
        try {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
            $record = PurchaseInvoice::withTrashed()->where('company_id', $companyId)->where('doc_num', $purchaseInvoice)->firstOrFail();
            $this->service->restore($record);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('purchase_invoices.messages.restored')]);
    }

    public function approve(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        try {
            $record = $this->service->approve($purchaseInvoice);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('purchase_invoices.messages.approved'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function close(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        try {
            $record = $this->service->close($purchaseInvoice);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('purchase_invoices.messages.closed'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function cancel(CancelPurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        try {
            $record = $this->service->cancel($purchaseInvoice, (string) $request->validated('cancel_reason'));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('purchase_invoices.messages.cancelled'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function reverse(CancelPurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        try {
            $record = $this->service->reverse($purchaseInvoice, (string) $request->validated('cancel_reason'));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('Purchase Invoice reversed successfully.'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function print(PurchaseInvoice $purchaseInvoice, ReportPdfService $pdf, CompanyPrintIdentityService $printIdentities): Response
    {
        $this->assertDocumentContext($purchaseInvoice);
        $purchaseInvoice->loadMissing($this->service->defaultRelations());
        $identity = $printIdentities->forCompany($purchaseInvoice->company);

        $copy = request()->validate(['copy' => ['nullable', Rule::in(['operational', 'legal'])]])['copy'] ?? 'operational';

        return $pdf->stream('modules.purchases.purchase-invoices.print', [
            'printIdentityPolicy' => $copy,
            'title' => __('purchase_invoices.print_title', ['doc' => $purchaseInvoice->doc_num]),
            'companyName' => $identity['legal_name'] ?: $identity['name'],
            'companyLogoPath' => $identity['logo_source'],
            'companyPrintIdentity' => $identity,
            'record' => $purchaseInvoice,
        ], str('purchase-invoice-'.$purchaseInvoice->doc_num)->slug().'.pdf');
    }

    public function updateDocumentNumberSettings(UpdatePurchaseInvoiceDocumentNumberSettingsRequest $request, DocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('purchase_invoices', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json([
            'success' => true,
            'message' => __('purchase_invoices.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function assertDocumentContext(PurchaseInvoice $record): void
    {
        $context = $this->operatingContext->snapshot(request());
        abort_unless((int) $record->company_id === (int) $context['company_id'] && (int) $record->branch_id === (int) $context['branch_id'], 404);
    }

    private function form(string $mode, ?PurchaseInvoice $record = null, ?string $cloneSourceToken = null): View
    {
        if ($record?->exists) {
            $this->assertDocumentContext($record);
        }
        $record?->loadMissing($this->service->defaultRelations());
        if ($mode === 'view') {
            $record?->loadMissing([
                'purchaseOrder.requisition', 'purchaseOrder.requestForQuotation',
                'lines.receiptLine.receipt', 'paymentAllocations.paymentContext', 'purchaseReturns',
            ]);
        }
        $context = $this->operatingContext->snapshot(request());
        $companyId = (int) ($context['company_id'] ?? 0);
        $purchaseOrders = PurchaseOrder::query()->forCompany($companyId)->where('doc_num', old('purchase_order_doc_num', $record?->purchaseOrder?->doc_num ?? ''))
            ->where('branch_id', $context['branch_id'])
            ->whereIn('status', [PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed])
            ->with(['supplier', 'lines.product', 'lines.unit'])
            ->withSum([
                'purchaseInvoices as invoiced_freight_amount' => fn ($query) => $query
                    ->where('status', '<>', PurchaseInvoice::StatusCancelled)
                    ->when($record, fn ($invoiceQuery) => $invoiceQuery->whereKeyNot($record->getKey())),
            ], 'freight_amount')
            ->latest('document_date')
            ->limit(100)
            ->get();
        $eligibleReceiptLines = UnpricedInventoryReceiptLine::query()->whereIn('public_id', collect(old('lines', []))->pluck('receipt_line_public_id')->merge($record?->lines?->map(fn ($line) => $line->receiptLine?->public_id) ?? []))
            ->with(['receipt', 'product', 'unit', 'purchaseOrderLine.purchaseOrder'])
            ->where('company_id', $companyId)
            ->where('branch_id', $context['branch_id'])
            ->whereHas('receipt', fn ($query) => $query->where('approved', true)->whereNotIn('status', ['cancelled', 'reversed']))
            ->where('inventory_posted_quantity', '>', 0)
            ->whereNotNull('purchase_order_line_id')
            ->latest('id')
            ->limit(500)
            ->get();

        return view('modules.purchases.purchase-invoices.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true)
                ? route('admin.purchases.purchase-invoices.store')
                : route('admin.purchases.purchase-invoices.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('purchase_invoices.document_number.control'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'metadata' => $this->metadata($record),
            'procurementPurchaseOrders' => $purchaseOrders,
            'eligibleReceiptLines' => $eligibleReceiptLines,
        ]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?PurchaseInvoice $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'clone' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.purchases.purchase-invoices.show', $record->doc_num) : null],
                ['label' => __('purchase_invoices.clone')],
            ],
            'edit' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.purchases.purchase-invoices.show', $record->doc_num) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.purchases.purchase-invoices.index', $extra);
    }

    private function urls(PurchaseInvoice $record): array
    {
        return [
            'show' => route('admin.purchases.purchase-invoices.show', $record->doc_num),
            'edit' => route('admin.purchases.purchase-invoices.edit', $record->doc_num),
            'clone' => route('admin.purchases.purchase-invoices.clone', $record->doc_num),
            'update' => route('admin.purchases.purchase-invoices.update', $record->doc_num),
            'destroy' => route('admin.purchases.purchase-invoices.destroy', $record->doc_num),
            'restore' => route('admin.purchases.purchase-invoices.restore', $record->doc_num),
            'approve' => route('admin.purchases.purchase-invoices.approve', $record->doc_num),
            'close' => route('admin.purchases.purchase-invoices.close', $record->doc_num),
            'cancel' => route('admin.purchases.purchase-invoices.cancel', $record->doc_num),
            'reverse' => route('admin.purchases.purchase-invoices.reverse', $record->doc_num),
            'print' => route('admin.purchases.purchase-invoices.print', $record->doc_num),
        ];
    }

    private function saveResponse(Request $request, PurchaseInvoice $record, bool $creating): array
    {
        $action = $this->submitAction($request, $creating);
        $redirect = match ($action) {
            'save_view' => route('admin.purchases.purchase-invoices.show', $record->doc_num),
            'save_edit' => route('admin.purchases.purchase-invoices.edit', $record->doc_num),
            'save_back' => route('admin.purchases.purchase-invoices.index'),
            'save_clone' => route('admin.purchases.purchase-invoices.clone', $record->doc_num),
            default => null,
        };
        $response = ['submit_action' => $action];

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = true;
        }

        return $response;
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        if ($creating && $action === 'save') {
            return 'save_new';
        }

        if ($action === 'save_new' || (! $creating && $action === 'save_edit')) {
            return 'save';
        }

        return in_array($action, ['save', 'save_view', 'save_edit', 'save_back', 'save_clone'], true) ? $action : 'save';
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?PurchaseInvoice $record): array
    {
        $dates = app(DateFormatService::class);

        if (! $record instanceof PurchaseInvoice) {
            return [];
        }

        return [
            'created_by' => $this->auditUserLabel($record->createdBy),
            'created_at' => $record->created_at ? $dates->formatDateTime($record->created_at, '') : null,
            'updated_by' => $this->auditUserLabel($record->updatedBy),
            'updated_at' => $record->updated_at ? $dates->formatDateTime($record->updated_at, '') : null,
            'approved_by' => $this->auditUserLabel($record->approvedBy),
            'approved_at' => $record->approved_at ? $dates->formatDateTime($record->approved_at, '') : null,
            'closed_by' => $this->auditUserLabel($record->closedBy),
            'closed_at' => $record->closed_at ? $dates->formatDateTime($record->closed_at, '') : null,
            'cancelled_by' => $this->auditUserLabel($record->cancelledBy),
            'cancelled_at' => $record->cancelled_at ? $dates->formatDateTime($record->cancelled_at, '') : null,
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        return $user ? trim(implode(' / ', array_filter([$user->doc_num, $user->name]))) : null;
    }
}

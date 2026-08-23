<?php

namespace Modules\Purchases\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberSettingsService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Purchases\DataTables\PurchaseOrdersDataTable;
use Modules\Purchases\Http\Requests\PurchaseOrders\BulkDeletePurchaseOrdersRequest;
use Modules\Purchases\Http\Requests\PurchaseOrders\CancelPurchaseOrderRequest;
use Modules\Purchases\Http\Requests\PurchaseOrders\StorePurchaseOrderRequest;
use Modules\Purchases\Http\Requests\PurchaseOrders\UpdatePurchaseOrderDocumentNumberSettingsRequest;
use Modules\Purchases\Http\Requests\PurchaseOrders\UpdatePurchaseOrderRequest;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Services\PurchaseOrderService;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingContextService $operatingContext,
        private readonly ProductComponentUnitOptionsService $unitOptions,
    ) {}

    public function index(DocumentNumberSettingsService $settings): View
    {
        return view('modules.purchases.purchase-orders.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.purchases.purchase-orders.index'),
            'documentNumberSettings' => $settings->current('purchase_orders'),
        ]);
    }

    public function data(Request $request, PurchaseOrdersDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        try {
            $record = $this->service->create($request->validated())['record'];
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('purchase_orders.messages.created'),
            ...$this->saveResponse($request, $record, true),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): View
    {
        $this->abortUnlessInCurrentContext($purchaseOrder);
        abort_if($purchaseOrder->trashed() && ! $request->user()?->can('purchase_orders.view_trashed'), 404);

        return $this->form('view', $purchaseOrder);
    }

    public function edit(PurchaseOrder $purchaseOrder): View
    {
        $this->abortUnlessInCurrentContext($purchaseOrder);
        abort_if($purchaseOrder->isLockedForEditing(), 403, __('purchase_orders.messages.document_locked'));

        return $this->form('edit', $purchaseOrder);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $result = $this->service->update($purchaseOrder, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('purchase_orders.messages.updated'),
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

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $this->service->delete($purchaseOrder);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('purchase_orders.messages.deleted')]);
    }

    public function bulkDelete(BulkDeletePurchaseOrdersRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => __('purchase_orders.messages.bulk_deleted', [
                'count' => $this->service->bulkDelete($request->validated('doc_nums')),
            ]),
        ]);
    }

    public function restore(string $purchaseOrder): JsonResponse
    {
        try {
            $context = $this->operatingContext->snapshot(request());
            abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 404);
            $record = PurchaseOrder::withTrashed()
                ->where('company_id', (int) $context['company_id'])
                ->where('financial_period_id', (int) $context['financial_period_id'])
                ->where('branch_id', (int) $context['branch_id'])
                ->where('doc_num', $purchaseOrder)
                ->firstOrFail();
            $this->service->restore($record);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('purchase_orders.messages.restored')]);
    }

    public function approve(PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $record = $this->service->approve($purchaseOrder);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('purchase_orders.messages.approved'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function close(PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $record = $this->service->close($purchaseOrder);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('purchase_orders.messages.closed'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $record = $this->service->cancel($purchaseOrder, (string) $request->validated('cancel_reason'));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('purchase_orders.messages.cancelled'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function print(PurchaseOrder $purchaseOrder, CompanyPrintIdentityService $printIdentities): View
    {
        $this->abortUnlessInCurrentContext($purchaseOrder);
        $purchaseOrder->loadMissing($this->service->defaultRelations());

        return view('modules.purchases.purchase-orders.print', [
            'record' => $purchaseOrder,
            'companyPrintIdentity' => $printIdentities->forCompany($purchaseOrder->company),
        ]);
    }

    public function updateDocumentNumberSettings(UpdatePurchaseOrderDocumentNumberSettingsRequest $request, DocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('purchase_orders', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json([
            'success' => true,
            'message' => __('purchase_orders.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function form(string $mode, ?PurchaseOrder $record = null): View
    {
        $record?->loadMissing($this->service->defaultRelations());
        if ($mode === 'view') {
            $record?->loadMissing([
                'requisition', 'requestForQuotation', 'supplierQuotation', 'supplierSelection',
                'deliverySchedules', 'receipts.inspection', 'purchaseInvoices', 'purchaseReturns', 'supplierPayments',
            ]);
        }

        return view('modules.purchases.purchase-orders.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => $mode === 'create'
                ? route('admin.purchases.purchase-orders.store')
                : route('admin.purchases.purchase-orders.update', $record?->doc_num),
            'method' => $mode === 'create' ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('purchase_orders.document_number.control'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'metadata' => $this->metadata($record),
            'context' => $this->operatingContext->snapshot(request()),
            'supplierOption' => $this->supplierOption($record),
            'currencyOption' => $this->currencyOption($record),
            'storeOption' => $this->storeOption($record),
            'lines' => $this->lineRows($record),
        ]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?PurchaseOrder $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'edit' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.purchases.purchase-orders.show', $record->doc_num) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.purchases.purchase-orders.index', $extra);
    }

    private function urls(PurchaseOrder $record): array
    {
        return [
            'show' => route('admin.purchases.purchase-orders.show', $record->doc_num),
            'edit' => route('admin.purchases.purchase-orders.edit', $record->doc_num),
            'update' => route('admin.purchases.purchase-orders.update', $record->doc_num),
            'destroy' => route('admin.purchases.purchase-orders.destroy', $record->doc_num),
            'restore' => route('admin.purchases.purchase-orders.restore', $record->doc_num),
            'approve' => route('admin.purchases.purchase-orders.approve', $record->doc_num),
            'close' => route('admin.purchases.purchase-orders.close', $record->doc_num),
            'cancel' => route('admin.purchases.purchase-orders.cancel', $record->doc_num),
            'print' => route('admin.purchases.purchase-orders.print', $record->doc_num),
        ];
    }

    private function saveResponse(Request $request, PurchaseOrder $record, bool $creating): array
    {
        $action = $this->submitAction($request, $creating);
        $redirect = match ($action) {
            'save_view' => route('admin.purchases.purchase-orders.show', $record->doc_num),
            'save_edit' => route('admin.purchases.purchase-orders.edit', $record->doc_num),
            'save_back' => route('admin.purchases.purchase-orders.index'),
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

        return in_array($action, ['save', 'save_view', 'save_edit', 'save_back'], true) ? $action : 'save';
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?PurchaseOrder $record): array
    {
        $dates = app(DateFormatService::class);

        if (! $record instanceof PurchaseOrder) {
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

    /**
     * @return array{id: string, text: string}|null
     */
    private function supplierOption(?PurchaseOrder $record): ?array
    {
        $supplier = $record?->supplier;

        if (! $supplier) {
            return null;
        }

        return [
            'id' => (string) $supplier->doc_num,
            'text' => trim(implode(' / ', array_filter([$supplier->doc_num, $supplier->name, $supplier->phone ?: $supplier->mobile]))),
        ];
    }

    /**
     * @return array{id: string, text: string, is_main: bool}|null
     */
    private function currencyOption(?PurchaseOrder $record): ?array
    {
        $currency = $record?->currency;

        if (! $currency) {
            return null;
        }

        return [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' / ', array_filter([$currency->code, $currency->name]))),
            'is_main' => (bool) $currency->is_main,
        ];
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function storeOption(?PurchaseOrder $record): ?array
    {
        $store = $record?->branchStore;

        if ($store instanceof BranchStore) {
            return [
                'id' => (string) $store->public_uuid,
                'text' => (string) $store->name,
            ];
        }

        $context = $this->operatingContext->snapshot(request());
        $branchId = $context['branch_id'] ? (int) $context['branch_id'] : 0;

        if ($branchId <= 0) {
            return null;
        }

        $store = BranchStore::query()
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->orderBy('name')
            ->first();

        return $store instanceof BranchStore
            ? ['id' => (string) $store->public_uuid, 'text' => (string) $store->name]
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lineRows(?PurchaseOrder $record): array
    {
        if (! $record instanceof PurchaseOrder || $record->lines->isEmpty()) {
            return [$this->emptyLine()];
        }

        return $record->lines->map(fn (PurchaseOrderLine $line): array => [
            'public_id' => (string) $line->public_id,
            'product_doc_num' => (string) $line->product?->doc_num,
            'product_text' => $this->productLabel($line),
            'product_image_url' => $line->product_snapshot['image_url'] ?? null,
            'unit_doc_num' => (string) $line->unit?->doc_num,
            'unit_text' => $line->product_snapshot['unit_label'] ?? $this->unitLabel($line),
            'unit_options' => $line->product ? $this->unitOptions->options($line->product) : [],
            'ordered_quantity' => $line->ordered_quantity,
            'received_quantity' => $line->received_quantity,
            'remaining_quantity' => $line->remaining_quantity,
            'unit_price' => $line->unit_price,
            'discount_type' => $line->discount_type ?: 'fixed',
            'discount_value' => $line->discount_value,
            'discount_amount' => $line->discount_amount,
            'tax_rate' => $line->tax_rate,
            'tax_amount' => $line->tax_amount,
            'line_total' => $line->line_total,
            'notes' => $line->notes,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyLine(): array
    {
        return [
            'public_id' => null,
            'product_doc_num' => null,
            'product_text' => null,
            'product_image_url' => null,
            'unit_doc_num' => null,
            'unit_text' => null,
            'unit_options' => [],
            'ordered_quantity' => null,
            'received_quantity' => '0',
            'remaining_quantity' => '0',
            'unit_price' => null,
            'discount_type' => 'fixed',
            'discount_value' => '0',
            'discount_amount' => '0',
            'tax_rate' => '0',
            'tax_amount' => '0',
            'line_total' => '0',
            'notes' => null,
        ];
    }

    private function productLabel(PurchaseOrderLine $line): string
    {
        $snapshot = is_array($line->product_snapshot) ? $line->product_snapshot : [];

        return trim(implode(' / ', array_filter([
            $snapshot['doc_num'] ?? $line->product?->doc_num,
            $snapshot['name'] ?? $line->product?->name,
            $snapshot['barcode'] ?? null,
        ])));
    }

    private function unitLabel(PurchaseOrderLine $line): string
    {
        return trim(implode(' / ', array_filter([$line->unit?->doc_num, $line->unit?->name])));
    }

    private function auditUserLabel(?User $user): ?string
    {
        return $user ? trim(implode(' / ', array_filter([$user->doc_num, $user->name]))) : null;
    }

    private function abortUnlessInCurrentContext(PurchaseOrder $record): void
    {
        $context = $this->operatingContext->snapshot(request());

        abort_unless(
            $context['company_id']
            && $context['financial_period_id']
            && $context['branch_id']
            && (int) $record->company_id === (int) $context['company_id']
            && (int) $record->financial_period_id === (int) $context['financial_period_id']
            && (int) $record->branch_id === (int) $context['branch_id'],
            404
        );
    }
}

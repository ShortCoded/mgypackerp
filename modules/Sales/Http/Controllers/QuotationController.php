<?php

namespace Modules\Sales\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberSettingsService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\SettingService;
use Modules\HR\Models\HrEmployee;
use Modules\Sales\DataTables\QuotationsDataTable;
use Modules\Sales\Http\Requests\BulkDeleteQuotationsRequest;
use Modules\Sales\Http\Requests\ConvertQuotationRequest;
use Modules\Sales\Http\Requests\StoreQuotationRequest;
use Modules\Sales\Http\Requests\UpdateQuotationDocumentNumberSettingsRequest;
use Modules\Sales\Http\Requests\UpdateQuotationRequest;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\QuotationAttachment;
use Modules\Sales\Models\QuotationExecutionScheduleLine;
use Modules\Sales\Models\QuotationPaymentMilestone;
use Modules\Sales\Models\QuotationRevision;
use Modules\Sales\Models\QuotationRevisionLine;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\CustomerTermsService;
use Modules\Sales\Services\PriceListPricingService;
use Modules\Sales\Services\QuotationService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Modules\Sales\Services\SalesSelect2Service;
use Throwable;

class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
        private readonly NumericFormatService $numbers,
        private readonly PriceListPricingService $priceLists,
    ) {}

    public function index(DocumentNumberSettingsService $settings): View
    {
        return view('modules.sales.quotations.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.sales.quotations.index'),
            'documentNumberSettings' => $settings->current('quotations'),
        ]);
    }

    public function data(Request $request, QuotationsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        $sourceRequest = null;
        if ($request->filled('source_request_doc_num')) {
            abort_unless($request->user()?->can('sales_requests.view'), 403);
            $context = app(OperatingContextService::class)->snapshot($request);
            $sourceRequest = SalesRequest::query()
                ->with(['customer', 'currency', 'salesEmployee', 'lines.product.unit', 'lines.product.equivalentUnit', 'lines.unit'])
                ->where('company_id', $context['company_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereIn('status', ['approved', 'partially_converted'])
                ->where('doc_num', $request->string('source_request_doc_num')->toString())
                ->firstOrFail();
        }

        return $this->form('create', sourceRequest: $sourceRequest);
    }

    public function show(Request $request, Quotation $quotation): View
    {
        abort_if($quotation->trashed() && ! $request->user()?->can('quotations.view_trashed'), 404);

        return $this->form('view', $quotation);
    }

    public function edit(Quotation $quotation): View
    {
        return $this->form('edit', $quotation);
    }

    public function clone(Quotation $quotation): View
    {
        return $this->form('clone', $quotation, (string) Str::uuid());
    }

    public function store(StoreQuotationRequest $request, SalesRequestService $salesRequests): JsonResponse
    {
        try {
            $payload = $this->pricedPayload($request, $request->validated());
            if ($request->filled('source_request_doc_num')) {
                abort_unless($request->user()?->can('sales_requests.view'), 403);
                $context = app(OperatingContextService::class)->snapshot($request);
                $sourceRequest = SalesRequest::query()
                    ->where('company_id', $context['company_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->where('doc_num', $request->validated('source_request_doc_num'))
                    ->firstOrFail();
                $record = $salesRequests->convertToQuotation($sourceRequest, $payload);
            } else {
                $record = $this->service->create($payload, $request)['record'];
            }
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->logActivity($request, 'quotations.create', ActivityLogProperties::crudCreated(
            'quotations',
            $record->label(),
            $record->doc_num,
            $this->submitActionProperties($request, creating: true),
        ));

        return response()->json([
            'success' => true,
            'message' => __('quotations.messages.created'),
            ...$this->saveResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function update(UpdateQuotationRequest $request, Quotation $quotation): JsonResponse
    {
        try {
            $result = $this->service->update($quotation, $this->pricedPayload($request, $request->validated()));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        /** @var Quotation $record */
        $record = $result['record'];
        $this->logActivity($request, 'quotations.update', ActivityLogProperties::crudUpdated(
            'quotations',
            $record->label(),
            $record->doc_num,
            [],
            $this->submitActionProperties($request),
        ));

        return response()->json([
            'success' => true,
            'message' => __('quotations.messages.updated'),
            ...$this->saveResponse($request, $record, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function destroy(Request $request, Quotation $quotation): JsonResponse
    {
        $this->service->delete($quotation);
        $this->logActivity($request, 'quotations.delete', ActivityLogProperties::crudDeleted('quotations', $quotation->label(), $quotation->doc_num));

        return response()->json(['success' => true, 'message' => __('quotations.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteQuotationsRequest $request): JsonResponse
    {
        $docNums = $request->validated('doc_nums');
        $deleted = $this->service->bulkDelete($docNums);

        $this->logActivity($request, 'quotations.bulk_delete', ActivityLogProperties::bulkDeleted('quotations', $deleted, $docNums));

        return response()->json([
            'success' => true,
            'message' => __('quotations.messages.bulk_deleted', ['count' => $deleted]),
        ]);
    }

    public function restore(Request $request, string $quotation): JsonResponse
    {
        try {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
            $record = $this->service->restore(Quotation::withTrashed()->forCompany($companyId)->where('doc_num', $quotation)->firstOrFail());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->logActivity($request, 'quotations.restore', ActivityLogProperties::crudRestored('quotations', $record->label(), $record->doc_num));

        return response()->json(['success' => true, 'message' => __('quotations.messages.restored')]);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('quotations.restore'), 403);

        $docNums = collect($request->input('doc_nums', []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
        $restored = 0;

        foreach (Quotation::onlyTrashed()->forCompany($companyId)->whereIn('doc_num', $docNums)->get() as $record) {
            $this->service->restore($record);
            $restored++;
        }

        return response()->json([
            'success' => true,
            'message' => __('quotations.messages.bulk_restored', ['count' => $restored]),
        ]);
    }

    public function updateDocumentNumberSettings(UpdateQuotationDocumentNumberSettingsRequest $request, DocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('quotations', $request->validated('prefix'), (int) $request->validated('padding'));

        $this->logActivity($request, 'quotations.document_number_settings.update', ActivityLogProperties::settingsUpdated('quotations', [
            'prefix' => ['old' => $result['old']['prefix'], 'new' => $result['new']['prefix']],
            'padding' => ['old' => $result['old']['padding'], 'new' => $result['new']['padding']],
        ]));

        return response()->json([
            'success' => true,
            'message' => __('quotations.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    public function createRevision(Request $request, Quotation $quotation): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('quotations.revisions.create'), 403);

        $data = $request->validate([
            'change_reason' => ['nullable', 'string'],
        ]);

        try {
            $revision = $this->service->createNewRevision($quotation, $data['change_reason'] ?? null);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $quotation->refresh();
        $this->logActivity($request, 'quotations.revisions.create', ActivityLogProperties::crudUpdated(
            'quotations',
            $quotation->label(),
            $quotation->doc_num,
            ['current_revision' => ['old' => null, 'new' => $revision->revision_code]],
        ));

        return response()->json([
            'success' => true,
            'message' => __('quotations.messages.revision_created'),
            'redirect' => route('admin.sales.quotations.edit', $quotation->doc_num),
            'data' => ['revision_code' => $revision->revision_code],
        ]);
    }

    public function showRevision(Request $request, Quotation $quotation, QuotationRevision $revision): View
    {
        abort_unless((int) $revision->quotation_id === (int) $quotation->getKey(), 404);
        abort_unless((bool) $request->user()?->can('quotations.revisions.view'), 403);

        return $this->form('view', $quotation, selectedRevision: $revision);
    }

    public function markSent(Request $request, Quotation $quotation): JsonResponse
    {
        return $this->statusAction($request, $quotation, 'mark_sent', fn (): Quotation => $this->service->markSent($quotation), __('quotations.messages.marked_sent'));
    }

    public function accept(Request $request, Quotation $quotation): JsonResponse
    {
        return $this->statusAction($request, $quotation, 'accept', fn (): Quotation => $this->service->accept($quotation), __('quotations.messages.accepted'));
    }

    public function reject(Request $request, Quotation $quotation): JsonResponse
    {
        return $this->statusAction($request, $quotation, 'reject', fn (): Quotation => $this->service->reject($quotation), __('quotations.messages.rejected'));
    }

    public function cancel(Request $request, Quotation $quotation): JsonResponse
    {
        return $this->statusAction($request, $quotation, 'cancel', fn (): Quotation => $this->service->cancel($quotation), __('quotations.messages.cancelled'));
    }

    public function convert(ConvertQuotationRequest $request, Quotation $quotation, SalesOrderService $orders, OperatingContextService $context): JsonResponse
    {
        $snapshot = $context->snapshot($request);
        if (! $snapshot['company_id'] || ! $snapshot['financial_period_id'] || ! $snapshot['branch_id']) {
            return response()->json(['success' => false, 'message' => __('quotations.messages.operating_context_required')], 422);
        }

        try {
            $order = $orders->createFromQuotation($quotation, [
                'company_id' => (int) $snapshot['company_id'],
                'financial_period_id' => (int) $snapshot['financial_period_id'],
                'branch_id' => (int) $snapshot['branch_id'],
            ], $request->validated('lines'));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->logActivity($request, 'quotations.convert', [
            'quotation_doc_num' => $quotation->doc_num,
            'sales_order_doc_num' => $order->doc_num,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('quotations.messages.converted'),
            'redirect' => route('admin.sales.sales-orders.show', $order),
            'data' => ['doc_num' => $order->doc_num, 'url' => route('admin.sales.sales-orders.show', $order)],
        ], 201);
    }

    public function print(Quotation $quotation, CompanyPrintIdentityService $printIdentity, ReportPdfService $pdf): Response
    {
        $quotation->load(['company', 'branch', 'customer', 'currency', 'salesPerson', 'currentRevision.lines.product', 'currentRevision.lines.unit', 'currentRevision.paymentMilestones']);

        return $this->printView($quotation, $quotation->currentRevision, $printIdentity, $pdf);
    }

    public function printRevision(Quotation $quotation, QuotationRevision $revision, CompanyPrintIdentityService $printIdentity, ReportPdfService $pdf): Response
    {
        abort_unless((int) $revision->quotation_id === (int) $quotation->getKey(), 404);
        $quotation->load(['company', 'branch', 'customer', 'currency', 'salesPerson']);
        $revision->load(['lines.product', 'lines.unit', 'paymentMilestones']);

        return $this->printView($quotation, $revision, $printIdentity, $pdf);
    }

    public function destroyAttachment(Request $request, QuotationAttachment $attachment): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('quotations.attachments.manage'), 403);
        $attachment->loadMissing('quotation');
        abort_unless($attachment->quotation instanceof Quotation && (int) $attachment->quotation->company_id === app(OperatingCompanyContextService::class)->requireCompanyId(), 404);

        $this->service->destroyAttachment($attachment);

        return response()->json(['success' => true, 'message' => __('quotations.messages.attachment_deleted')]);
    }

    public function customers(Request $request, SalesSelect2Service $select2): JsonResponse
    {
        abort_unless($this->canUseQuotations($request), 403);

        return response()->json($select2->customers($request));
    }

    public function products(Request $request, SalesSelect2Service $select2): JsonResponse
    {
        abort_unless($this->canUseQuotations($request), 403);

        return response()->json($select2->products($request));
    }

    public function customerTerms(Request $request, CustomerTermsService $terms): JsonResponse
    {
        abort_unless($this->canUseQuotations($request), 403);

        $customer = Customer::query()
            ->forCompany(app(OperatingCompanyContextService::class)->requireCompanyId($request))
            ->where('doc_num', $request->string('customer_doc_num')->toString())
            ->firstOrFail();

        return response()->json(['data' => $terms->quotationDefaults($customer)]);
    }

    private function form(string $mode, ?Quotation $record = null, ?string $cloneSourceToken = null, ?QuotationRevision $selectedRevision = null, ?SalesRequest $sourceRequest = null): View
    {
        $record?->loadMissing($this->service->defaultRelations());
        $revision = $selectedRevision ?: $record?->currentRevision;
        $revision?->loadMissing(['lines.product', 'lines.unit', 'paymentMilestones', 'executionScheduleLines']);
        $isCreateLike = in_array($mode, ['create', 'clone'], true);
        $mainCurrencyOption = $this->mainCurrencyOption();

        return view('modules.sales.quotations.form', [
            'mode' => $mode,
            'record' => $record,
            'revision' => $revision,
            'action' => $isCreateLike ? route('admin.sales.quotations.store') : route('admin.sales.quotations.update', $record?->doc_num),
            'method' => $isCreateLike ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('quotations.document_number.control'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'isRevisionLocked' => $record instanceof Quotation && ! $record->canEditCurrentRevision(),
            'sourceRequest' => $sourceRequest,
            'customerOption' => $this->customerOption($record, $sourceRequest),
            'currencyOption' => $this->currencyOption($record, $sourceRequest) ?? $mainCurrencyOption,
            'mainCurrencyOption' => $mainCurrencyOption,
            'salesPersonOption' => $this->salesPersonOption($record, $sourceRequest),
            'lines' => $this->lines($revision, $mode, $sourceRequest),
            'paymentMilestones' => $this->paymentMilestones($revision),
            'executionScheduleLines' => $this->executionScheduleLines($revision),
            'metadata' => $this->metadata($record),
        ]);
    }

    private function printView(Quotation $quotation, ?QuotationRevision $revision, CompanyPrintIdentityService $printIdentity, ReportPdfService $pdf): Response
    {
        abort_unless($revision instanceof QuotationRevision, 404);

        return $pdf->stream('reports.sales.quotation', [
            'title' => __('quotations.print.title'),
            'documentHeaderTitle' => __('quotations.print.title'),
            'customerFacing' => true,
            'record' => $quotation,
            'revision' => $revision,
            'companyPrintIdentity' => $quotation->print_identity_snapshot ?: $printIdentity->forCompany($quotation->company),
        ], str('sales-quotation-'.$quotation->doc_num.'-'.$revision->revision_code)->slug().'.pdf');
    }

    private function statusAction(Request $request, Quotation $quotation, string $action, callable $callback, string $message): JsonResponse
    {
        $oldStatus = $quotation->status;

        try {
            $record = $callback();
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->logActivity($request, "quotations.{$action}", ActivityLogProperties::statusChanged(
            'quotations',
            $record->label(),
            $record->doc_num,
            $oldStatus,
            $record->status,
        ));

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?Quotation $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'clone' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.sales.quotations.show', $record->doc_num) : null],
                ['label' => __('common.actions.clone_record')],
            ],
            'edit' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.sales.quotations.show', $record->doc_num) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.sales.quotations.index', $extra);
    }

    private function urls(Quotation $record): array
    {
        return [
            'show' => route('admin.sales.quotations.show', $record->doc_num),
            'edit' => route('admin.sales.quotations.edit', $record->doc_num),
            'clone' => route('admin.sales.quotations.clone', $record->doc_num),
            'update' => route('admin.sales.quotations.update', $record->doc_num),
            'destroy' => route('admin.sales.quotations.destroy', $record->doc_num),
            'revisions_create' => route('admin.sales.quotations.revisions.create', $record->doc_num),
            'mark_sent' => route('admin.sales.quotations.mark-sent', $record->doc_num),
            'accept' => route('admin.sales.quotations.accept', $record->doc_num),
            'reject' => route('admin.sales.quotations.reject', $record->doc_num),
            'cancel' => route('admin.sales.quotations.cancel', $record->doc_num),
        ];
    }

    private function saveResponse(Request $request, Quotation $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.sales.quotations.show', $record->doc_num),
            'save_back' => route('admin.sales.quotations.index'),
            'save_clone' => route('admin.sales.quotations.clone', $record->doc_num),
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

        if ($action === 'save_new' || $action === 'save_edit') {
            return 'save';
        }

        return in_array($action, ['save', 'save_view', 'save_back', 'save_clone'], true) ? $action : 'save';
    }

    /**
     * @return array<string, mixed>
     */
    private function submitActionProperties(Request $request, bool $creating = false): array
    {
        return [
            'submit_action' => $this->submitAction($request, $creating),
        ];
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function customerOption(?Quotation $record, ?SalesRequest $sourceRequest = null): ?array
    {
        $customer = $record?->customer ?? $sourceRequest?->customer;

        return $customer instanceof Customer
            ? ['id' => (string) $customer->doc_num, 'text' => trim(implode(' / ', array_filter([$customer->doc_num, $customer->name])))]
            : null;
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function currencyOption(?Quotation $record, ?SalesRequest $sourceRequest = null): ?array
    {
        $currency = $record?->currency ?? $sourceRequest?->currency;

        return $currency instanceof Currency
            ? ['id' => (string) $currency->doc_num, 'text' => trim(implode(' / ', array_filter([$currency->code, $currency->name])))]
            : null;
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function mainCurrencyOption(): ?array
    {
        $currency = Currency::query()
            ->forCompany(app(OperatingCompanyContextService::class)->requireCompanyId())
            ->active()
            ->where('is_main', true)
            ->first();

        return $currency instanceof Currency
            ? ['id' => (string) $currency->doc_num, 'text' => trim(implode(' / ', array_filter([$currency->code, $currency->name])))]
            : null;
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function salesPersonOption(?Quotation $record, ?SalesRequest $sourceRequest = null): ?array
    {
        $employee = $record?->salesPerson ?? $sourceRequest?->salesEmployee;

        return $employee instanceof HrEmployee
            ? ['id' => (string) $employee->doc_num, 'text' => trim(implode(' / ', array_filter([$employee->name, $employee->doc_num])))]
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(?QuotationRevision $revision, string $mode, ?SalesRequest $sourceRequest = null): array
    {
        $lines = old('lines');

        if (! is_array($lines)) {
            $lines = $revision?->lines?->map(function (QuotationRevisionLine $line): array {
                $product = $line->product;
                $unit = $line->unit;
                $productLabel = trim(implode(' / ', array_filter([
                    $product?->doc_num,
                    $line->product_name_snapshot ?: $product?->name,
                ])));
                $unitLabel = trim(implode(' / ', array_filter([
                    $unit?->doc_num,
                    $line->unit_name_snapshot ?: $unit?->name,
                ])));

                return [
                    'product_doc_num' => $product?->doc_num,
                    'product_label' => $productLabel !== '' ? $productLabel : null,
                    'description' => $line->description,
                    'unit_doc_num' => $unit?->doc_num,
                    'unit_label' => $unitLabel !== '' ? $unitLabel : null,
                    'quantity' => $this->numbers->format($line->quantity),
                    'base_quantity' => $this->numbers->format($line->base_quantity),
                    'conversion_factor' => $this->numbers->format($line->conversion_factor),
                    'unit_price' => $this->numbers->format($line->unit_price),
                    'discount_type' => $line->discount_type,
                    'discount_value' => $this->numbers->format($line->discount_value),
                    'tax_rate' => $this->numbers->format($line->tax_rate),
                    'line_total' => $this->numbers->format($line->line_total),
                    'requested_date' => $line->requested_date ? app(DateFormatService::class)->formatDate($line->requested_date, '') : null,
                    'specifications' => $line->specifications ?? [],
                    'warehouse_notes' => $line->warehouse_notes,
                    'production_notes' => $line->production_notes,
                    'notes' => $line->notes,
                ];
            })->values()->all() ?? [];

            if ($lines === [] && $sourceRequest) {
                $lines = $sourceRequest->lines
                    ->filter(fn ($line): bool => bccomp($line->remainingQuantity(), '0', 8) > 0)
                    ->map(fn ($line): array => [
                        'source_request_line_public_id' => $line->public_id,
                        'product_doc_num' => $line->product?->doc_num,
                        'product_label' => trim(implode(' / ', array_filter([$line->product?->doc_num, $line->product?->name]))),
                        'description' => $line->description,
                        'unit_doc_num' => $line->unit?->doc_num,
                        'unit_label' => trim(implode(' / ', array_filter([$line->unit?->doc_num, $line->unit?->name]))),
                        'quantity' => $this->numbers->format($line->remainingQuantity()),
                        'unit_price' => $line->unit_price === null ? null : $this->numbers->format($line->unit_price),
                        'discount_type' => null,
                        'discount_value' => '0',
                        'tax_rate' => '0',
                        'line_total' => '0',
                        'requested_date' => $sourceRequest->required_delivery_date ? app(DateFormatService::class)->formatDate($sourceRequest->required_delivery_date, '') : null,
                        'specifications' => $line->specifications ?? [],
                        'warehouse_notes' => null,
                        'production_notes' => null,
                        'notes' => $line->notes,
                    ])->values()->all();
            }
        }

        if ($lines === [] && $mode !== 'view') {
            return [[
                'product_doc_num' => null,
                'product_label' => null,
                'description' => null,
                'unit_doc_num' => null,
                'unit_label' => null,
                'quantity' => '1',
                'base_quantity' => '1',
                'conversion_factor' => '1',
                'unit_price' => null,
                'discount_type' => null,
                'discount_value' => '0',
                'tax_rate' => '0',
                'line_total' => '0',
                'requested_date' => null,
                'specifications' => [],
                'warehouse_notes' => null,
                'production_notes' => null,
                'notes' => null,
            ]];
        }

        $products = Product::withTrashed()->with(['unit', 'equivalentUnit'])->forCompany(app(OperatingCompanyContextService::class)->requireCompanyId())
            ->whereIn('doc_num', array_column($lines, 'product_doc_num'))->get()->keyBy('doc_num');
        foreach ($lines as &$line) {
            $product = $products->get($line['product_doc_num'] ?? '');
            $line['product_label'] = $line['product_label'] ?? $product?->name;
            $line['units'] = collect([$product?->unit, $product?->equivalentUnit])->filter()->unique('id')->map(fn ($unit): array => ['id' => $unit->doc_num, 'text' => $unit->name])->values()->all();
        }
        unset($line);

        return array_values($lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paymentMilestones(?QuotationRevision $revision): array
    {
        $rows = old('payment_milestones');

        if (! is_array($rows)) {
            $rows = $revision?->paymentMilestones?->map(fn (QuotationPaymentMilestone $row): array => [
                'title' => $row->title,
                'description' => $row->description,
                'percentage' => $this->numbers->format($row->percentage),
                'amount' => $this->numbers->format($row->amount),
                'due_type' => $row->due_type,
                'due_date' => $row->due_date ? app(DateFormatService::class)->formatDate($row->due_date, '') : null,
                'notes' => $row->notes,
            ])->values()->all() ?? [];
        }

        return array_values($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function executionScheduleLines(?QuotationRevision $revision): array
    {
        $rows = old('execution_schedule_lines');

        if (! is_array($rows)) {
            $rows = $revision?->executionScheduleLines?->map(fn (QuotationExecutionScheduleLine $row): array => [
                'phase_name' => $row->phase_name,
                'description' => $row->description,
                'start_date' => $row->start_date ? app(DateFormatService::class)->formatDate($row->start_date, '') : null,
                'end_date' => $row->end_date ? app(DateFormatService::class)->formatDate($row->end_date, '') : null,
                'duration_days' => $row->duration_days,
                'responsibility' => $row->responsibility,
                'notes' => $row->notes,
            ])->values()->all() ?? [];
        }

        return array_values($rows);
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?Quotation $record): array
    {
        if (! $record instanceof Quotation) {
            return [
                'created_by' => null,
                'created_at' => null,
                'updated_by' => null,
                'updated_at' => null,
                'deleted_by' => null,
                'deleted_at' => null,
                'restored_by' => null,
                'restored_at' => null,
            ];
        }

        $users = User::query()
            ->whereIn('id', array_filter([$record->created_by, $record->updated_by, $record->deleted_by, $record->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($record->created_by)),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($record->updated_by)),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($users->get($record->deleted_by)),
            'deleted_at' => $settings->formatDateTime($record->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($users->get($record->restored_by)),
            'restored_at' => $settings->formatDateTime($record->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        return $user instanceof User ? trim(implode(' / ', array_filter([$user->name, $user->doc_num]))) : null;
    }

    private function canUseQuotations(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user?->can('quotations.view')
            || (bool) $user?->can('quotations.create')
            || (bool) $user?->can('quotations.edit')
            || (bool) $user?->canAny(['sales_requests.view', 'sales_requests.create', 'sales_requests.edit', 'sales_orders.view', 'sales_orders.create', 'sales_orders.edit', 'customer_receipts.create', 'customer_invoices.create', 'price_lists.view', 'price_lists.create', 'price_lists.edit', 'reports.sales.sales_orders.view']);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function pricedPayload(Request $request, array $data): array
    {
        $context = app(OperatingContextService::class)->snapshot($request);
        $customerId = Customer::query()->forCompany($context['company_id'])->active()->where('doc_num', $data['customer_doc_num'])->valueOrFail('id');
        $currencyId = Currency::query()->forCompany($context['company_id'])->active()->where('doc_num', $data['currency_doc_num'])->valueOrFail('id');
        $lines = collect($data['lines'])->map(function (array $line) use ($context): array {
            $product = Product::query()->forCompany($context['company_id'])->active()->where('doc_num', $line['product_doc_num'])->firstOrFail();
            $unitId = empty($line['unit_doc_num']) ? $product->item_unit_id : ItemUnit::query()->forCompany($context['company_id'])->active()->where('doc_num', $line['unit_doc_num'])->valueOrFail('id');

            return [...$line, 'product_id' => $product->getKey(), 'unit_id' => $unitId];
        })->all();

        return [
            ...$data,
            'discount_type' => null,
            'discount_value' => 0,
            'lines' => $this->priceLists->applyToLines($lines, $context['company_id'], $customerId, $currencyId, $data['quotation_date'], 'quotation'),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = [], string $status = 'success'): void
    {
        try {
            $this->activityLogger->log($request, 'sales', $action, $status, [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

<?php

namespace Modules\Sales\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Core\Models\Currency;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberSettingsService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Sales\DataTables\QuotationsDataTable;
use Modules\Sales\Http\Requests\BulkDeleteQuotationsRequest;
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
use Modules\Sales\Services\QuotationService;
use Modules\Sales\Services\SalesSelect2Service;
use Throwable;

class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
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

    public function create(): View
    {
        return $this->form('create');
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

    public function store(StoreQuotationRequest $request): JsonResponse
    {
        try {
            $record = $this->service->create($request->validated(), $request)['record'];
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
            $result = $this->service->update($quotation, $request->validated());
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

    private function form(string $mode, ?Quotation $record = null, ?string $cloneSourceToken = null, ?QuotationRevision $selectedRevision = null): View
    {
        $record?->loadMissing($this->service->defaultRelations());
        $revision = $selectedRevision ?: $record?->currentRevision;
        $revision?->loadMissing(['lines.product', 'lines.unit', 'paymentMilestones', 'executionScheduleLines']);
        $isCreateLike = in_array($mode, ['create', 'clone'], true);

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
            'customerOption' => $this->customerOption($record),
            'currencyOption' => $this->currencyOption($record),
            'salesPersonOption' => $this->salesPersonOption($record),
            'lines' => $this->lines($revision, $mode),
            'paymentMilestones' => $this->paymentMilestones($revision),
            'executionScheduleLines' => $this->executionScheduleLines($revision),
            'metadata' => $this->metadata($record),
        ]);
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
    private function customerOption(?Quotation $record): ?array
    {
        return $record?->customer instanceof Customer
            ? ['id' => (string) $record->customer->doc_num, 'text' => trim(implode(' / ', array_filter([$record->customer->doc_num, $record->customer->name])))]
            : null;
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function currencyOption(?Quotation $record): ?array
    {
        return $record?->currency instanceof Currency
            ? ['id' => (string) $record->currency->doc_num, 'text' => trim(implode(' / ', array_filter([$record->currency->code, $record->currency->name])))]
            : null;
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function salesPersonOption(?Quotation $record): ?array
    {
        return $record?->salesPerson instanceof User
            ? ['id' => (string) $record->salesPerson->doc_num, 'text' => trim(implode(' / ', array_filter([$record->salesPerson->name, $record->salesPerson->doc_num])))]
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(?QuotationRevision $revision, string $mode): array
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
                    'quantity' => $this->formatDecimal($line->quantity),
                    'unit_price' => $this->formatDecimal($line->unit_price),
                    'discount_type' => $line->discount_type,
                    'discount_value' => $this->formatDecimal($line->discount_value),
                    'tax_rate' => $this->formatDecimal($line->tax_rate),
                    'line_total' => $this->formatDecimal($line->line_total),
                    'notes' => $line->notes,
                ];
            })->values()->all() ?? [];
        }

        if ($lines === [] && $mode !== 'view') {
            return [[
                'product_doc_num' => null,
                'product_label' => null,
                'description' => null,
                'unit_doc_num' => null,
                'unit_label' => null,
                'quantity' => '1',
                'unit_price' => null,
                'discount_type' => null,
                'discount_value' => '0',
                'tax_rate' => '0',
                'line_total' => '0',
                'notes' => null,
            ]];
        }

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
                'percentage' => $this->formatDecimal($row->percentage),
                'amount' => $this->formatDecimal($row->amount),
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

    private function formatDecimal(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') ?: '0';
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
            || (bool) $user?->can('quotations.edit');
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

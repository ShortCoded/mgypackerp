<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\FinancialPeriodClosingService;
use Modules\Core\DataTables\FinancialPeriodsDataTable;
use Modules\Core\Http\Requests\BulkDeleteFinancialPeriodsRequest;
use Modules\Core\Http\Requests\StoreFinancialPeriodRequest;
use Modules\Core\Http\Requests\UpdateFinancialPeriodDocumentNumberSettingsRequest;
use Modules\Core\Http\Requests\UpdateFinancialPeriodRequest;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodDocumentNumberSettingsService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Throwable;

class FinancialPeriodController extends Controller
{
    public function __construct(
        private readonly FinancialPeriodService $records,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    public function index(Request $request, FinancialPeriodDocumentNumberSettingsService $documentNumberSettings): View
    {
        return view('modules.core.financial-periods.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.financial-periods.index'),
            'documentNumberSettings' => $documentNumberSettings->current(),
        ]);
    }

    public function data(Request $request, FinancialPeriodsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->formView('create');
    }

    public function show(Request $request, string $financialPeriod): View
    {
        $financialPeriod = $this->recordByDocNum($request, $financialPeriod, withTrashed: true);
        $this->abortIfTrashedRecordIsNotViewable($request, $financialPeriod);

        $this->logActivity($request, 'financial_periods.view', $this->recordPublicProperties($financialPeriod));

        return $this->formView('view', $financialPeriod);
    }

    public function edit(Request $request, string $financialPeriod): View
    {
        $financialPeriod = $this->recordByDocNum($request, $financialPeriod);

        return $this->formView('edit', $financialPeriod);
    }

    public function clone(Request $request, string $financialPeriod): View
    {
        $financialPeriod = $this->recordByDocNum($request, $financialPeriod);
        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $financialPeriod->doc_num);

        return $this->formView('clone', $financialPeriod, $cloneSourceToken);
    }

    public function store(StoreFinancialPeriodRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request);

        try {
            $result = $this->records->create($request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $record = $result['record'];

        if ($cloneSource instanceof FinancialPeriod) {
            $this->logActivity($request, 'financial_periods.clone', ActivityLogProperties::crudCloned(
                'financial_periods',
                ActivityLogProperties::record('financial_periods', $cloneSource->name, $cloneSource->doc_num),
                $record->name,
                $record->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        } else {
            $this->logActivity($request, 'financial_periods.create', ActivityLogProperties::crudCreated(
                'financial_periods',
                $record->name,
                $record->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        }

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof FinancialPeriod ? __('financial_periods.messages.cloned') : __('financial_periods.messages.created'),
            ...$this->saveActionResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->recordUrls($record),
            ],
        ]);
    }

    public function update(UpdateFinancialPeriodRequest $request, string $financialPeriod): JsonResponse
    {
        $financialPeriod = $this->recordByDocNum($request, $financialPeriod);
        $this->authorizeSubmitAction($request, $this->submitAction($request));

        try {
            $result = $this->records->update($financialPeriod, $request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $record = $result['record'];

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
            ]);
        }

        $this->logActivity($request, 'financial_periods.update', ActivityLogProperties::crudUpdated(
            'financial_periods',
            $record->name,
            $record->doc_num,
            $result['changes'],
            $this->submitActionProperties($request),
        ));

        if (in_array('doc_number', $result['changed_fields'], true)) {
            $this->logActivity($request, 'financial_periods.doc_number.changed', ActivityLogProperties::documentNumberChanged(
                'financial_periods',
                $record->name,
                $result['old_doc_number'],
                $result['old_doc_num'],
                $record->doc_number,
                $record->doc_num,
            ));
        }

        return response()->json([
            'success' => true,
            'message' => __('financial_periods.messages.updated'),
            ...$this->saveActionResponse($request, $record, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_number' => $record->doc_number,
                'doc_num' => $record->doc_num,
                'urls' => $this->recordUrls($record),
            ],
        ]);
    }

    public function destroy(Request $request, string $financialPeriod): JsonResponse
    {
        $financialPeriod = $this->recordByDocNum($request, $financialPeriod);

        try {
            $this->records->delete($financialPeriod);
        } catch (DomainException $exception) {
            $this->logActivity($request, 'financial_periods.delete_blocked', $this->recordPublicProperties($financialPeriod), 'blocked');

            return $this->domainError($exception);
        }

        $this->logActivity($request, 'financial_periods.delete', ActivityLogProperties::crudDeleted(
            'financial_periods',
            $financialPeriod->name,
            $financialPeriod->doc_num,
        ));

        return response()->json([
            'success' => true,
            'message' => __('financial_periods.messages.deleted'),
        ]);
    }

    public function close(Request $request, string $financialPeriod, FinancialPeriodClosingService $closing): RedirectResponse
    {
        $financialPeriod = $this->recordByDocNum($request, $financialPeriod);

        try {
            $result = $closing->close($financialPeriod);
        } catch (DomainException $exception) {
            $this->logActivity($request, 'financial_periods.close_blocked', [
                ...$this->recordPublicProperties($financialPeriod),
                'reason' => $exception->getMessage(),
            ], 'blocked');

            return back()->withErrors(['period_close' => $exception->getMessage()]);
        }

        $this->logActivity($request, 'financial_periods.close', [
            ...$this->recordPublicProperties($result['period']),
            'journal_entry_doc_num' => $result['journal_entry']?->doc_num,
            'already_closed' => $result['already_closed'],
        ]);

        return redirect()
            ->route('admin.financial-periods.show', $result['period']->doc_num)
            ->with('success', $result['already_closed']
                ? __('financial_periods.messages.already_closed')
                : __('financial_periods.messages.closed'));
    }

    public function reopen(Request $request, string $financialPeriod, FinancialPeriodClosingService $closing): RedirectResponse
    {
        $financialPeriod = $this->recordByDocNum($request, $financialPeriod);

        try {
            $result = $closing->reopen($financialPeriod);
        } catch (DomainException $exception) {
            $this->logActivity($request, 'financial_periods.reopen_blocked', [
                ...$this->recordPublicProperties($financialPeriod),
                'reason' => $exception->getMessage(),
            ], 'blocked');

            return back()->withErrors(['period_close' => $exception->getMessage()]);
        }

        $this->logActivity($request, 'financial_periods.reopen', [
            ...$this->recordPublicProperties($result['period']),
            'reversal_journal_entry_doc_num' => $result['reversal_entry']?->doc_num,
            'already_open' => $result['already_open'],
        ]);

        return redirect()
            ->route('admin.financial-periods.show', $result['period']->doc_num)
            ->with('success', $result['already_open']
                ? __('financial_periods.messages.already_open')
                : __('financial_periods.messages.reopened'));
    }

    public function bulkDelete(BulkDeleteFinancialPeriodsRequest $request): JsonResponse
    {
        try {
            $docNums = $request->validated()['doc_nums'];
            $deleted = $this->records->bulkDelete($docNums);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $this->logActivity($request, 'financial_periods.bulk_delete', ActivityLogProperties::bulkDeleted(
            'financial_periods',
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('financial_periods.messages.bulk_deleted', ['count' => $deleted]),
            'data' => [
                'deleted' => $deleted,
            ],
        ]);
    }

    public function restore(Request $request, string $financialPeriod): JsonResponse
    {
        $record = $this->restoreRecordByDocNum($request, $financialPeriod);

        try {
            $record = $this->records->restore($record);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $this->logActivity($request, 'financial_periods.restore', ActivityLogProperties::crudRestored(
            'financial_periods',
            $record->name,
            $record->doc_num,
            $this->recordRestoreProperties($request, $record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('financial_periods.messages.restored'),
        ]);
    }

    public function updateDocumentNumberSettings(
        UpdateFinancialPeriodDocumentNumberSettingsRequest $request,
        FinancialPeriodDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse {
        $result = $documentNumberSettings->update(
            $request->validated('prefix'),
            (int) $request->validated('padding'),
        );

        $this->logActivity($request, 'financial_periods.document_number_settings.update', ActivityLogProperties::settingsUpdated(
            'financial_periods',
            [
                'prefix' => [
                    'old' => $result['old']['prefix'],
                    'new' => $result['new']['prefix'],
                ],
                'padding' => [
                    'old' => $result['old']['padding'],
                    'new' => $result['new']['padding'],
                ],
            ],
        ));

        return response()->json([
            'success' => true,
            'message' => __('financial_periods.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function formView(string $mode, ?FinancialPeriod $record = null, ?string $cloneSourceToken = null): View
    {
        $documentNumberSettings = app(FinancialPeriodDocumentNumberSettingsService::class)->current();

        return view('modules.core.financial-periods.form', [
            'mode' => $mode,
            'financialPeriod' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.financial-periods.store') : route('admin.financial-periods.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'documentNumberPrefix' => $documentNumberSettings['prefix'],
            'documentNumberPadding' => $documentNumberSettings['padding'],
            'canControlDocumentNumber' => (bool) auth()->user()?->can('financial_periods.document_number.control'),
            'metadata' => $this->metadata($record),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function recordUrls(FinancialPeriod $record): array
    {
        return [
            'show' => route('admin.financial-periods.show', $record->doc_num),
            'clone' => route('admin.financial-periods.clone', $record->doc_num),
            'edit' => route('admin.financial-periods.edit', $record->doc_num),
            'update' => route('admin.financial-periods.update', $record->doc_num),
            'destroy' => route('admin.financial-periods.destroy', $record->doc_num),
            'restore' => route('admin.financial-periods.restore', $record->doc_num),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saveActionResponse(Request $request, FinancialPeriod $record, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = ['submit_action' => $action];

        $redirect = match ($action) {
            'save_view' => route('admin.financial-periods.show', $record->doc_num),
            'save_edit' => route('admin.financial-periods.edit', $record->doc_num),
            'save_back' => route('admin.financial-periods.index'),
            'save_new' => $operation === 'store' ? null : route('admin.financial-periods.create'),
            'save_clone' => route('admin.financial-periods.clone', $record->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $record) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';

            if ($request->user()?->can('financial_periods.document_number.control')) {
                $response['next_doc_number'] = app(DocumentNumberService::class)->nextNumberForCompany(
                    'financial_periods',
                    FinancialPeriod::class,
                    $this->companyContext->requireCompanyId($request),
                );
            }
        }

        return $response;
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view' => 'financial_periods.view',
            'save_edit' => 'financial_periods.edit',
            'save_back' => 'financial_periods.view',
            'save_new' => $cloning ? 'financial_periods.clone' : 'financial_periods.create',
            'save_clone' => 'financial_periods.clone',
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('financial_periods.messages.action_forbidden'));
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        if ($creating && $action === 'save') {
            return 'save_new';
        }

        if (! $creating && in_array($action, ['save_new', 'save_edit'], true)) {
            return 'save';
        }

        return $action;
    }

    /**
     * @return array{submit_action?: string, company_doc_num?: string|null, company_name?: string|null}
     */
    private function submitActionProperties(Request $request, bool $creating = false): array
    {
        $companyContext = $this->companyContext->companyPublicContext($request);
        $rawAction = $request->string('submit_action')->trim()->toString();

        if ($rawAction === '' && ! $creating) {
            return $companyContext;
        }

        return [
            'submit_action' => $this->submitAction($request, creating: $creating),
            ...$companyContext,
        ];
    }

    private function redirectAfterStore(Request $request, FinancialPeriod $record): string
    {
        if ($request->user()?->can('financial_periods.edit')) {
            return route('admin.financial-periods.edit', $record->doc_num);
        }

        if ($request->user()?->can('financial_periods.view')) {
            return route('admin.financial-periods.show', $record->doc_num);
        }

        return route('admin.financial-periods.index');
    }

    private function cloneSourceFromRequest(StoreFinancialPeriodRequest $request): ?FinancialPeriod
    {
        $cloneSourceToken = $request->string('clone_source_token')->trim()->toString();

        if ($cloneSourceToken === '') {
            return null;
        }

        abort_unless((bool) $request->user()?->can('financial_periods.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($cloneSourceToken), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'name' => __('financial_periods.messages.clone_not_allowed'),
            ]);
        }

        $sourceRecord = FinancialPeriod::query()
            ->forCompany($this->companyContext->requireCompanyId($request))
            ->where('doc_num', $sourceDocNum)
            ->first();

        if (! $sourceRecord instanceof FinancialPeriod) {
            throw ValidationException::withMessages([
                'name' => __('financial_periods.messages.clone_not_allowed'),
            ]);
        }

        return $sourceRecord;
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'financial_periods.clone_sources.'.$token;
    }

    private function restoreRecordByDocNum(Request $request, string $docNum): FinancialPeriod
    {
        $companyId = $this->companyContext->requireCompanyId($request);

        return FinancialPeriod::onlyTrashed()
            ->forCompany($companyId)
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? FinancialPeriod::query()
                ->forCompany($companyId)
                ->where('doc_num', $docNum)
                ->firstOrFail();
    }

    private function abortIfTrashedRecordIsNotViewable(Request $request, FinancialPeriod $record): void
    {
        abort_if(
            $record->trashed() && ! $request->user()?->can('financial_periods.view_trashed'),
            404,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function recordRestoreProperties(Request $request, FinancialPeriod $record): array
    {
        $user = $request->user();

        return [
            ...$this->recordPublicProperties($record),
            'restored_by_user_doc_num' => $user instanceof User ? $user->doc_num : null,
            'restored_at' => $record->restored_at?->toJSON() ?? now()->toJSON(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?FinancialPeriod $record): array
    {
        if (! $record) {
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

        $record->loadMissing(['createdBy:id,name,doc_num', 'updatedBy:id,name,doc_num', 'deletedBy:id,name,doc_num', 'restoredBy:id,name,doc_num']);
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($record->createdBy),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->auditUserLabel($record->updatedBy),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($record->deletedBy),
            'deleted_at' => $settings->formatDateTime($record->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($record->restoredBy),
            'restored_at' => $settings->formatDateTime($record->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }

    /**
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    private function breadcrumbs(string $mode, ?FinancialPeriod $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.financial-periods.show', $record->doc_num) : null,
                ],
                ['label' => __('financial_periods.titles.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.financial-periods.show', $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.financial-periods.index', $extra);
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
        ], 422);
    }

    private function throwValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();
        $map = [
            'doc_number' => ['financial_periods_doc_number_unique_active', 'financial_periods_doc_num_unique_active', 'financial_periods_company_doc_number_unique_active', 'financial_periods_company_doc_num_unique_active'],
            'name' => ['financial_periods_name_unique_active', 'financial_periods_company_name_unique_active'],
        ];

        foreach ($map as $field => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($message, $needle)) {
                    throw ValidationException::withMessages([
                        $field => __("financial_periods.validation.{$field}_unique"),
                    ]);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function recordPublicProperties(FinancialPeriod $record): array
    {
        return [
            'doc_num' => $record->doc_num,
            'name' => $record->name,
            'from_date' => $record->from_date,
            'to_date' => $record->to_date,
            'is_closed' => $record->is_closed,
            'company_doc_num' => $record->company?->doc_num,
            'company_name' => $record->company?->name,
        ];
    }

    private function recordByDocNum(Request $request, string $docNum, bool $withTrashed = false): FinancialPeriod
    {
        $query = FinancialPeriod::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query
            ->forCompany($this->companyContext->requireCompanyId($request))
            ->where('doc_num', $docNum)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = [], string $status = 'success'): void
    {
        try {
            $this->activityLogger->log($request, 'core', $action, $status, [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

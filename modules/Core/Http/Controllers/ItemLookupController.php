<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\DataTables\ItemLookupDataTable;
use Modules\Core\Exceptions\ItemLookupRestoreBlockedException;
use Modules\Core\Http\Requests\BulkDeleteItemLookupRequest;
use Modules\Core\Http\Requests\StoreItemLookupRequest;
use Modules\Core\Http\Requests\UpdateItemLookupDocumentNumberSettingsRequest;
use Modules\Core\Http\Requests\UpdateItemLookupRequest;
use Modules\Core\Models\ItemLookup;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\ItemLookupDefinition;
use Modules\Core\Services\ItemLookupDocumentNumberSettingsService;
use Modules\Core\Services\ItemLookupRegistry;
use Modules\Core\Services\ItemLookupService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Throwable;

class ItemLookupController extends Controller
{
    public function __construct(
        private readonly ItemLookupRegistry $registry,
        private readonly ItemLookupService $lookups,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    public function index(Request $request, ItemLookupDocumentNumberSettingsService $documentNumberSettings): View
    {
        $definition = $this->definition($request);

        return view("{$definition->viewPath}.index", [
            'definition' => $definition,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($definition->route('index')),
            'documentNumberSettings' => $documentNumberSettings->current($definition),
        ]);
    }

    public function data(Request $request, ItemLookupDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request, $this->definition($request));
    }

    public function create(Request $request): View
    {
        return $this->formView($request, 'create');
    }

    public function show(Request $request, string $record): View
    {
        $definition = $this->definition($request);
        $record = $this->recordByDocNum($request, $definition, $record, withTrashed: true);

        $this->abortIfTrashedRecordIsNotViewable($request, $definition, $record);

        return $this->formView($request, 'view', $record);
    }

    public function edit(Request $request, string $record): View
    {
        $definition = $this->definition($request);

        return $this->formView($request, 'edit', $this->recordByDocNum($request, $definition, $record));
    }

    public function clone(Request $request, string $record): View
    {
        $definition = $this->definition($request);
        $record = $this->recordByDocNum($request, $definition, $record);
        $token = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($definition, $token), $record->doc_num);

        return $this->formView($request, 'clone', $record, $token);
    }

    public function store(StoreItemLookupRequest $request): JsonResponse
    {
        $definition = $this->definition($request);
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $definition, $submitAction, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request, $definition);

        try {
            $record = $this->lookups->create($definition, $request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        if ($cloneSource instanceof ItemLookup) {
            $this->logActivity($request, $definition->activity('clone'), ActivityLogProperties::crudCloned(
                $definition->permissionPrefix,
                ActivityLogProperties::record($definition->permissionPrefix, $cloneSource->name, $cloneSource->doc_num),
                $record->name,
                $record->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        } else {
            $this->logActivity($request, $definition->activity('create'), ActivityLogProperties::crudCreated(
                $definition->permissionPrefix,
                $record->name,
                $record->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        }

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof ItemLookup ? __('item_lookups.messages.cloned') : __('item_lookups.messages.created'),
            ...$this->saveActionResponse($request, $definition, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->recordUrls($definition, $record),
            ],
        ]);
    }

    public function update(UpdateItemLookupRequest $request, string $record): JsonResponse
    {
        $definition = $this->definition($request);
        $record = $this->recordByDocNum($request, $definition, $record);
        $this->authorizeSubmitAction($request, $definition, $this->submitAction($request));

        try {
            $result = $this->lookups->update($definition, $record, $request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        $record = $result['record'];

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
            ]);
        }

        $this->logActivity($request, $definition->activity('update'), ActivityLogProperties::crudUpdated(
            $definition->permissionPrefix,
            $record->name,
            $record->doc_num,
            $result['changes'],
            $this->submitActionProperties($request),
        ));

        if (in_array('doc_number', $result['changed_fields'], true)) {
            $this->logActivity($request, $definition->activity('doc_number.changed'), ActivityLogProperties::documentNumberChanged(
                $definition->permissionPrefix,
                $record->name,
                $result['old_doc_number'],
                $result['old_doc_num'],
                $record->doc_number,
                $record->doc_num,
            ));
        }

        return response()->json([
            'success' => true,
            'message' => __('item_lookups.messages.updated'),
            ...$this->saveActionResponse($request, $definition, $record, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_number' => $record->doc_number,
                'doc_num' => $record->doc_num,
                'urls' => $this->recordUrls($definition, $record),
            ],
        ]);
    }

    public function destroy(Request $request, string $record): JsonResponse
    {
        $definition = $this->definition($request);
        $record = $this->recordByDocNum($request, $definition, $record);
        $this->lookups->delete($record);
        $this->logActivity($request, $definition->activity('delete'), ActivityLogProperties::crudDeleted(
            $definition->permissionPrefix,
            $record->name,
            $record->doc_num,
        ));

        return response()->json([
            'success' => true,
            'message' => __('item_lookups.messages.deleted'),
        ]);
    }

    public function bulkDelete(BulkDeleteItemLookupRequest $request): JsonResponse
    {
        $definition = $this->definition($request);
        $docNums = $request->validated()['doc_nums'];
        $deleted = $this->lookups->bulkDelete($definition, $docNums);

        $this->logActivity($request, $definition->activity('bulk_delete'), ActivityLogProperties::bulkDeleted(
            $definition->permissionPrefix,
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('item_lookups.messages.bulk_deleted', ['count' => $deleted]),
            'data' => [
                'deleted' => $deleted,
            ],
        ]);
    }

    public function restore(Request $request, string $record): JsonResponse
    {
        $definition = $this->definition($request);
        $record = $this->restoreRecordByDocNum($request, $definition, $record);

        try {
            $record = $this->lookups->restore($definition, $record);
        } catch (ItemLookupRestoreBlockedException $exception) {
            if ($exception->isConflict()) {
                $this->logRestoreBlocked($request, $definition, $record, $exception);
            }

            return $this->restoreError($exception);
        }

        $restoredAt = $record->restored_at?->toJSON() ?? now()->toJSON();

        $this->logActivity($request, $definition->activity('restore'), ActivityLogProperties::crudRestored(
            $definition->permissionPrefix,
            $record->name,
            $record->doc_num,
            $this->recordRestoreProperties($request, $record, $restoredAt),
        ));

        return response()->json([
            'success' => true,
            'message' => __('item_lookups.messages.restored_successfully'),
        ]);
    }

    public function updateDocumentNumberSettings(
        UpdateItemLookupDocumentNumberSettingsRequest $request,
        ItemLookupDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse {
        $definition = $this->definition($request);
        $result = $documentNumberSettings->update(
            $definition,
            $request->validated('prefix'),
            (int) $request->validated('padding'),
        );

        $this->logActivity($request, $definition->activity('document_number_settings.update'), ActivityLogProperties::settingsUpdated(
            $definition->permissionPrefix,
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
            'message' => __('common.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function formView(Request $request, string $mode, ?ItemLookup $record = null, ?string $cloneSourceToken = null): View
    {
        $definition = $this->definition($request);
        $settings = app(ItemLookupDocumentNumberSettingsService::class)->current($definition);

        if ($definition->key === 'item_units' && $record instanceof ItemLookup) {
            $record->loadMissing('equivalentUnit');
        }

        return view("{$definition->viewPath}.form", [
            'definition' => $definition,
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route($definition->route('store')) : route($definition->route('update'), $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'documentNumberPrefix' => $settings['prefix'],
            'documentNumberPadding' => $settings['padding'],
            'canControlDocumentNumber' => (bool) auth()->user()?->can($definition->permission('document_number.control')),
            'metadata' => $this->metadata($record),
            'breadcrumbs' => $this->breadcrumbs($definition, $mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function recordUrls(ItemLookupDefinition $definition, ItemLookup $record): array
    {
        return [
            'show' => route($definition->route('show'), $record->doc_num),
            'clone' => route($definition->route('clone'), $record->doc_num),
            'edit' => route($definition->route('edit'), $record->doc_num),
            'update' => route($definition->route('update'), $record->doc_num),
            'destroy' => route($definition->route('destroy'), $record->doc_num),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saveActionResponse(Request $request, ItemLookupDefinition $definition, ItemLookup $record, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = [
            'submit_action' => $action,
        ];
        $redirect = match ($action) {
            'save_view' => route($definition->route('show'), $record->doc_num),
            'save_edit' => route($definition->route('edit'), $record->doc_num),
            'save_back' => route($definition->route('index')),
            'save_new' => $operation === 'store' ? null : route($definition->route('create')),
            'save_clone' => route($definition->route('clone'), $record->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $definition, $record) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';

            if ($request->user()?->can($definition->permission('document_number.control'))) {
                $response['next_doc_number'] = app(DocumentNumberService::class)->nextNumberForCompany(
                    $definition->documentKey,
                    $definition->modelClass,
                    $this->companyContext->requireCompanyId($request),
                );
            }
        }

        return $response;
    }

    private function authorizeSubmitAction(Request $request, ItemLookupDefinition $definition, string $action, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view' => $definition->permission('view'),
            'save_edit' => $definition->permission('edit'),
            'save_back' => $definition->permission('view'),
            'save_new' => $cloning ? $definition->permission('clone') : $definition->permission('create'),
            'save_clone' => $definition->permission('clone'),
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('item_lookups.messages.action_forbidden'));
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

    private function redirectAfterStore(Request $request, ItemLookupDefinition $definition, ItemLookup $record): string
    {
        if ($request->user()?->can($definition->permission('edit'))) {
            return route($definition->route('edit'), $record->doc_num);
        }

        if ($request->user()?->can($definition->permission('view'))) {
            return route($definition->route('show'), $record->doc_num);
        }

        return route($definition->route('index'));
    }

    private function cloneSourceFromRequest(StoreItemLookupRequest $request, ItemLookupDefinition $definition): ?ItemLookup
    {
        $token = $request->string('clone_source_token')->trim()->toString();

        if ($token === '') {
            return null;
        }

        abort_unless((bool) $request->user()?->can($definition->permission('clone')), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($definition, $token), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'name' => __('item_lookups.messages.clone_not_allowed'),
            ]);
        }

        /** @var ItemLookup|null $source */
        $source = $definition->modelClass::query()
            ->forCompany($this->companyContext->requireCompanyId($request))
            ->where('doc_num', $sourceDocNum)
            ->first();

        if (! $source instanceof ItemLookup) {
            throw ValidationException::withMessages([
                'name' => __('item_lookups.messages.clone_not_allowed'),
            ]);
        }

        return $source;
    }

    private function cloneSourceSessionKey(ItemLookupDefinition $definition, string $token): string
    {
        return "item_lookup.{$definition->routeKey}.clone_sources.{$token}";
    }

    private function restoreError(ItemLookupRestoreBlockedException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
            'errors' => [
                'restore' => [$exception->getMessage()],
            ],
            'data' => [
                'conflict_type' => $exception->conflictType,
                'conflict_fields' => $exception->conflictFields,
            ],
        ], 422);
    }

    private function logRestoreBlocked(Request $request, ItemLookupDefinition $definition, ItemLookup $record, ItemLookupRestoreBlockedException $exception): void
    {
        $this->logActivity($request, $definition->activity('restore_blocked'), [
            ...$this->recordPublicProperties($record),
            'conflict_type' => $exception->conflictType,
            'conflict_fields' => $exception->conflictFields,
        ], 'blocked');
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?ItemLookup $record): array
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
        if (! $user) {
            return null;
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }

    /**
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    private function breadcrumbs(ItemLookupDefinition $definition, string $mode, ?ItemLookup $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route($definition->route('show'), $record->doc_num) : null,
                ],
                ['label' => __('item_lookups.titles.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route($definition->route('show'), $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute($definition->route('index'), $extra);
    }

    private function throwValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();

        if (str_contains($message, '_doc_number_unique_active')
            || str_contains($message, '_doc_num_unique_active')
            || str_contains($message, '_company_doc_number_unique_active')
            || str_contains($message, '_company_doc_num_unique_active')) {
            throw ValidationException::withMessages([
                'doc_number' => __('item_lookups.validation.doc_number_unique'),
            ]);
        }

        if (str_contains($message, '_name_unique_active') || str_contains($message, '_company_name_unique_active')) {
            throw ValidationException::withMessages([
                'name' => __('item_lookups.validation.name_unique'),
            ]);
        }
    }

    private function recordByDocNum(Request $request, ItemLookupDefinition $definition, string $docNum, bool $withTrashed = false): ItemLookup
    {
        $query = $definition->modelClass::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        /** @var ItemLookup $record */
        $record = $query
            ->forCompany($this->companyContext->requireCompanyId($request))
            ->where('doc_num', $docNum)
            ->firstOrFail();

        return $record;
    }

    private function restoreRecordByDocNum(Request $request, ItemLookupDefinition $definition, string $docNum): ItemLookup
    {
        $companyId = $this->companyContext->requireCompanyId($request);

        /** @var ItemLookup $record */
        $record = $definition->modelClass::onlyTrashed()
            ->forCompany($companyId)
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? $definition->modelClass::query()
                ->forCompany($companyId)
                ->where('doc_num', $docNum)
                ->firstOrFail();

        return $record;
    }

    private function abortIfTrashedRecordIsNotViewable(Request $request, ItemLookupDefinition $definition, ItemLookup $record): void
    {
        abort_if(
            $record->trashed() && ! $request->user()?->can($definition->permission('view_trashed')),
            404,
            __('item_lookups.trash.view_forbidden'),
        );
    }

    /**
     * @return array{doc_num: string|null, name: string|null}
     */
    private function recordPublicProperties(ItemLookup $record): array
    {
        return [
            'doc_num' => $record->doc_num,
            'name' => $record->name,
            'company_doc_num' => $record->company?->doc_num,
            'company_name' => $record->company?->name,
        ];
    }

    /**
     * @return array{doc_num: string|null, name: string|null, restored_by_user_doc_num: string|null, restored_at: string}
     */
    private function recordRestoreProperties(Request $request, ItemLookup $record, string $restoredAt): array
    {
        $user = $request->user();

        return [
            ...$this->recordPublicProperties($record),
            'restored_by_user_doc_num' => $user instanceof User ? $user->doc_num : null,
            'restored_at' => $restoredAt,
        ];
    }

    private function definition(Request $request): ItemLookupDefinition
    {
        $key = (string) $request->route('itemLookup');

        if ($key !== '') {
            return $this->registry->get($key);
        }

        return $this->registry->fromRouteName($request->route()?->getName());
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

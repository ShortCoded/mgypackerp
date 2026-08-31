<?php

namespace Modules\HR\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\HR\DataTables\HrFoundationDataTable;
use Modules\HR\Exceptions\HrLookupRestoreBlockedException;
use Modules\HR\Http\Requests\Foundation\BulkDeleteHrFoundationRequest;
use Modules\HR\Http\Requests\Foundation\StoreHrFoundationRequest;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationDocumentNumberSettingsRequest;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrFoundationModel;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationDocumentNumberSettingsService;
use Modules\HR\Services\HrFoundationRegistry;
use Modules\HR\Services\HrFoundationService;
use Modules\HR\Services\HrSelect2InlineSupport;
use Throwable;

abstract class HrFoundationController extends Controller
{
    public function __construct(
        protected readonly HrFoundationService $records,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    abstract protected function definition(): HrFoundationDefinition;

    public function index(Request $request, HrFoundationDocumentNumberSettingsService $documentNumberSettings): View
    {
        $definition = $this->definition();

        return view('modules.hr.foundation.index', [
            'definition' => $definition,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($definition->route('index')),
            'documentNumberSettings' => $documentNumberSettings->current($definition),
        ]);
    }

    public function data(Request $request, HrFoundationDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->formView('create');
    }

    protected function showRecord(Request $request, HrFoundationModel $record): View
    {
        $definition = $this->definition();

        $this->abortIfTrashedRecordIsNotViewable($request, $definition, $record);
        $this->logActivity($request, $definition->activity('view'), $this->recordPublicProperties($record));

        return $this->formView('view', $record);
    }

    protected function editRecord(HrFoundationModel $record): View
    {
        return $this->formView('edit', $record);
    }

    protected function cloneRecord(HrFoundationModel $record): View
    {
        $token = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($token), $record->doc_num);

        return $this->formView('clone', $record, $token);
    }

    public function store(StoreHrFoundationRequest $request): JsonResponse
    {
        $definition = $this->definition();
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request);

        try {
            $record = $this->records->create($definition, $request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        if ($cloneSource instanceof HrFoundationModel) {
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
            'message' => $cloneSource instanceof HrFoundationModel ? __('hr.messages.cloned') : __('hr.messages.created'),
            ...$this->saveActionResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->recordUrls($record),
            ],
        ]);
    }

    protected function updateRecord(UpdateHrFoundationRequest $request, HrFoundationModel $record): JsonResponse
    {
        $definition = $this->definition();
        $this->authorizeSubmitAction($request, $this->submitAction($request));

        try {
            $result = $this->records->update($definition, $record, $request->validated());
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
            'message' => __('hr.messages.updated'),
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

    protected function destroyRecord(Request $request, HrFoundationModel $record): JsonResponse
    {
        $definition = $this->definition();

        $this->records->delete($record);
        $this->logActivity($request, $definition->activity('delete'), ActivityLogProperties::crudDeleted(
            $definition->permissionPrefix,
            $record->name,
            $record->doc_num,
        ));

        return response()->json([
            'success' => true,
            'message' => __('hr.messages.deleted'),
        ]);
    }

    public function bulkDelete(BulkDeleteHrFoundationRequest $request): JsonResponse
    {
        $definition = $this->definition();
        $docNums = $request->validated()['doc_nums'];
        $deleted = $this->records->bulkDelete($definition, $docNums);

        $this->logActivity($request, $definition->activity('bulk_delete'), ActivityLogProperties::bulkDeleted(
            $definition->permissionPrefix,
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('hr.messages.bulk_deleted', ['count' => $deleted]),
            'data' => [
                'deleted' => $deleted,
            ],
        ]);
    }

    public function restore(Request $request, string $record): JsonResponse
    {
        $definition = $this->definition();
        $record = $this->restoreRecordByDocNum($definition, $record);

        try {
            $record = $this->records->restore($definition, $record);
        } catch (HrLookupRestoreBlockedException $exception) {
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
            'message' => __('hr.messages.restored_successfully'),
        ]);
    }

    public function updateDocumentNumberSettings(
        UpdateHrFoundationDocumentNumberSettingsRequest $request,
        HrFoundationDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse {
        $definition = $this->definition();
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

    private function formView(string $mode, ?HrFoundationModel $record = null, ?string $cloneSourceToken = null): View
    {
        $definition = $this->definition();
        $settings = app(HrFoundationDocumentNumberSettingsService::class)->current($definition);

        if ($definition->hasTaxBrackets && $record !== null) {
            $record->load('brackets');
        }

        if ($definition->hasInsuranceComponents && $record !== null) {
            $record->load('components');
        }

        return view('modules.hr.foundation.form', [
            'definition' => $definition,
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route($definition->route('store')) : route($definition->route('update'), $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'documentNumberPrefix' => $settings['prefix'],
            'documentNumberPadding' => $settings['padding'],
            'canControlDocumentNumber' => (bool) auth()->user()?->can($definition->permission('document_number.control')),
            'metadata' => $this->metadata($record),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'selectedRelations' => $this->selectedRelations($definition, $record),
            'relationInlineMeta' => $this->relationInlineMeta($definition),
        ]);
    }

    /**
     * @return array<string, array{can_create: bool, inline_url: string|null}>
     */
    private function relationInlineMeta(HrFoundationDefinition $definition): array
    {
        $registry = app(HrFoundationRegistry::class);
        $meta = [];

        foreach ($definition->fields as $field) {
            if (($field['type'] ?? null) !== 'relation') {
                continue;
            }

            $name = (string) $field['name'];
            $select2Key = (string) ($field['select2'] ?? '');

            if ($select2Key === '') {
                $meta[$name] = [
                    'can_create' => false,
                    'inline_url' => null,
                ];

                continue;
            }

            try {
                $related = $registry->get($select2Key);
            } catch (\InvalidArgumentException) {
                $meta[$name] = [
                    'can_create' => false,
                    'inline_url' => null,
                ];

                continue;
            }

            $canCreate = HrSelect2InlineSupport::foundationSupportsQuickCreate($related)
                && (bool) auth()->user()?->can($related->permission('create'));

            $meta[$name] = [
                'can_create' => $canCreate,
                'inline_url' => $canCreate ? route('admin.hr.select2.inline.foundation.store', $select2Key) : null,
            ];
        }

        return $meta;
    }

    /**
     * @return array<string, array{id: string, text: string}|null>
     */
    private function selectedRelations(HrFoundationDefinition $definition, ?HrFoundationModel $record): array
    {
        if (! $record) {
            return [];
        }

        $selected = [];

        foreach ($definition->fields as $field) {
            if (($field['type'] ?? null) !== 'relation') {
                continue;
            }

            $column = (string) ($field['column'] ?? '');
            $id = ($field['virtual'] ?? false) === true && $record instanceof HrDepartment
                ? $record->defaultCostCenterForCompany(app(OperatingCompanyContextService::class)->requireCompanyId())?->getKey()
                : $record->getAttribute($column);

            if (! $id) {
                $selected[(string) $field['name']] = null;

                continue;
            }

            /** @var class-string<HrFoundationModel> $model */
            $model = $field['model'];
            $related = $model::withTrashed()->whereKey($id)->first(['doc_num', 'name']);
            $selected[(string) $field['name']] = $related ? [
                'id' => (string) $related->doc_num,
                'text' => trim($related->name.' / '.$related->doc_num),
            ] : null;
        }

        return $selected;
    }

    /**
     * @return array<string, string>
     */
    private function recordUrls(HrFoundationModel $record): array
    {
        $definition = $this->definition();

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
    private function saveActionResponse(Request $request, HrFoundationModel $record, string $operation): array
    {
        $definition = $this->definition();
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
            default => $operation === 'store' ? $this->redirectAfterStore($request, $record) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';

            if ($request->user()?->can($definition->permission('document_number.control'))) {
                $response['next_doc_number'] = app(DocumentNumberService::class)->nextNumber($definition->documentKey, $definition->modelClass);
            }
        }

        return $response;
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false): void
    {
        $definition = $this->definition();
        $permission = match ($action) {
            'save_view' => $definition->permission('view'),
            'save_edit' => $definition->permission('edit'),
            'save_back' => $definition->permission('view'),
            'save_new' => $cloning ? $definition->permission('clone') : $definition->permission('create'),
            'save_clone' => $definition->permission('clone'),
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('hr.messages.action_forbidden'));
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
     * @return array{submit_action?: string}
     */
    private function submitActionProperties(Request $request, bool $creating = false): array
    {
        $rawAction = $request->string('submit_action')->trim()->toString();

        if ($rawAction === '' && ! $creating) {
            return [];
        }

        return ['submit_action' => $this->submitAction($request, creating: $creating)];
    }

    private function redirectAfterStore(Request $request, HrFoundationModel $record): string
    {
        $definition = $this->definition();

        if ($request->user()?->can($definition->permission('edit'))) {
            return route($definition->route('edit'), $record->doc_num);
        }

        if ($request->user()?->can($definition->permission('view'))) {
            return route($definition->route('show'), $record->doc_num);
        }

        return route($definition->route('index'));
    }

    private function cloneSourceFromRequest(StoreHrFoundationRequest $request): ?HrFoundationModel
    {
        $token = $request->string('clone_source_token')->trim()->toString();

        if ($token === '') {
            return null;
        }

        abort_unless((bool) $request->user()?->can($this->definition()->permission('clone')), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($token), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'name' => __('hr.messages.clone_not_allowed'),
            ]);
        }

        /** @var HrFoundationModel|null $source */
        $source = $this->definition()->modelClass::query()
            ->when($this->definition()->companyScoped, fn ($query) => app(OperatingCompanyContextService::class)->applyCompanyScope($query, $this->definition()->table))
            ->where('doc_num', $sourceDocNum)
            ->first();

        if (! $source instanceof HrFoundationModel) {
            throw ValidationException::withMessages([
                'name' => __('hr.messages.clone_not_allowed'),
            ]);
        }

        return $source;
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return "hr.{$this->definition()->routeKey}.clone_sources.{$token}";
    }

    private function restoreError(HrLookupRestoreBlockedException $exception): JsonResponse
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

    private function logRestoreBlocked(Request $request, HrFoundationDefinition $definition, HrFoundationModel $record, HrLookupRestoreBlockedException $exception): void
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
    private function metadata(?HrFoundationModel $record): array
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
    private function breadcrumbs(string $mode, ?HrFoundationModel $record): array
    {
        $definition = $this->definition();
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route($definition->route('show'), $record->doc_num) : null,
                ],
                ['label' => __('hr.titles.clone')],
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

        if (str_contains($message, '_doc_number_unique_active') || str_contains($message, '_doc_num_unique_active')) {
            throw ValidationException::withMessages([
                'doc_number' => __('hr.validation.doc_number_unique'),
            ]);
        }

        if (str_contains($message, '_name_unique_active')) {
            throw ValidationException::withMessages([
                'name' => __('hr.validation.name_unique'),
            ]);
        }

        if (str_contains($message, '_device_uid_unique_active')
            || str_contains($message, 'hr_biometric_devices.company_id, hr_biometric_devices.device_uid')) {
            throw ValidationException::withMessages([
                'device_uid' => __('validation.unique', [
                    'attribute' => __('hr.foundation.attributes.device_uid'),
                ]),
            ]);
        }
    }

    private function restoreRecordByDocNum(HrFoundationDefinition $definition, string $docNum): HrFoundationModel
    {
        $trashedQuery = $definition->modelClass::onlyTrashed();
        $activeQuery = $definition->modelClass::query();

        if ($definition->companyScoped) {
            app(OperatingCompanyContextService::class)->applyCompanyScope($trashedQuery, $definition->table);
            app(OperatingCompanyContextService::class)->applyCompanyScope($activeQuery, $definition->table);
        }

        return $trashedQuery
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? $activeQuery
                ->where('doc_num', $docNum)
                ->firstOrFail();
    }

    private function abortIfTrashedRecordIsNotViewable(Request $request, HrFoundationDefinition $definition, HrFoundationModel $record): void
    {
        abort_if(
            $record->trashed() && ! $request->user()?->can($definition->permission('view_trashed')),
            404,
            __('hr.trash.view_forbidden'),
        );
    }

    /**
     * @return array{doc_num: string|null, name: string|null}
     */
    private function recordPublicProperties(HrFoundationModel $record): array
    {
        return [
            'doc_num' => $record->doc_num,
            'name' => $record->name,
        ];
    }

    /**
     * @return array{doc_num: string|null, name: string|null, restored_by_user_doc_num: string|null, restored_at: string}
     */
    private function recordRestoreProperties(Request $request, HrFoundationModel $record, string $restoredAt): array
    {
        $user = $request->user();

        return [
            ...$this->recordPublicProperties($record),
            'restored_by_user_doc_num' => $user instanceof User ? $user->doc_num : null,
            'restored_at' => $restoredAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = [], string $status = 'success'): void
    {
        try {
            $this->activityLogger->log($request, 'hr', $action, $status, [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

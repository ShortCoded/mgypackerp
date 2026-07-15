<?php

namespace Modules\HR\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\SettingService;
use Modules\HR\Exceptions\HrLookupRestoreBlockedException;
use Modules\HR\Http\Requests\BulkDeleteHrLookupRequest;
use Modules\HR\Http\Requests\StoreHrLookupRequest;
use Modules\HR\Http\Requests\UpdateHrLookupDocumentNumberSettingsRequest;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrLookupModel;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupDocumentNumberSettingsService;
use Modules\HR\Services\HrLookupService;
use Throwable;

abstract class HrLookupController extends Controller
{
    public function __construct(
        protected readonly HrLookupService $lookups,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    abstract protected function definition(): HrLookupDefinition;

    public function index(Request $request, HrLookupDocumentNumberSettingsService $documentNumberSettings): View
    {
        $definition = $this->definition();

        return view("{$definition->viewPath}.index", [
            'definition' => $definition,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($definition->route('index')),
            'documentNumberSettings' => $documentNumberSettings->current($definition),
        ]);
    }

    public function create(): View
    {
        return $this->formView('create');
    }

    protected function showRecord(Request $request, HrLookupModel $record): View
    {
        $definition = $this->definition();

        $this->abortIfTrashedRecordIsNotViewable($request, $definition, $record);

        $this->logActivity($request, $definition->activity('view'), $this->recordPublicProperties($record));

        return $this->formView('view', $record);
    }

    protected function editRecord(HrLookupModel $record): View
    {
        return $this->formView('edit', $record);
    }

    protected function cloneRecord(HrLookupModel $record): View
    {
        $token = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($token), $record->doc_num);

        return $this->formView('clone', $record, $token);
    }

    public function store(StoreHrLookupRequest $request): JsonResponse|RedirectResponse
    {
        $definition = $this->definition();
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request);

        try {
            $record = $this->lookups->create($definition, $request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        if ($cloneSource instanceof HrLookupModel) {
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

        if (! $this->wantsLookupJsonResponse($request)) {
            $message = $cloneSource instanceof HrLookupModel ? __('hr.messages.cloned') : __('hr.messages.created');

            return redirect()->to($this->lookupHtmlRedirectUrl($request, $record, 'store'))
                ->with('success', $message);
        }

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof HrLookupModel ? __('hr.messages.cloned') : __('hr.messages.created'),
            ...$this->saveActionResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->recordUrls($record),
            ],
        ]);
    }

    protected function updateRecord(UpdateHrLookupRequest $request, HrLookupModel $record): JsonResponse|RedirectResponse
    {
        $definition = $this->definition();
        $this->authorizeSubmitAction($request, $this->submitAction($request));

        try {
            $result = $this->lookups->update($definition, $record, $request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        $record = $result['record'];

        if (! $result['changed']) {
            if (! $this->wantsLookupJsonResponse($request)) {
                return redirect()->back()->with('warning', __('common.messages.no_changes'));
            }

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

        if (! $this->wantsLookupJsonResponse($request)) {
            return redirect()->to($this->lookupHtmlRedirectUrl($request, $record, 'update'))
                ->with('success', __('hr.messages.updated'));
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

    protected function destroyRecord(Request $request, HrLookupModel $record): JsonResponse
    {
        $definition = $this->definition();
        $this->lookups->delete($record);
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

    public function bulkDelete(BulkDeleteHrLookupRequest $request): JsonResponse
    {
        $definition = $this->definition();
        $docNums = $request->validated()['doc_nums'];
        $deleted = $this->lookups->bulkDelete($definition, $docNums);

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
            $record = $this->lookups->restore($definition, $record);
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
        UpdateHrLookupDocumentNumberSettingsRequest $request,
        HrLookupDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse|RedirectResponse {
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

        if (! $this->wantsLookupJsonResponse($request)) {
            return redirect()->route($definition->route('index'))
                ->with('success', __('common.document_number_settings.updated_successfully'));
        }

        return response()->json([
            'success' => true,
            'message' => __('common.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function formView(string $mode, ?HrLookupModel $record = null, ?string $cloneSourceToken = null): View
    {
        $definition = $this->definition();
        $settings = app(HrLookupDocumentNumberSettingsService::class)->current($definition);

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
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function recordUrls(HrLookupModel $record): array
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
    private function wantsLookupJsonResponse(Request $request): bool
    {
        return $request->ajax() || $request->wantsJson();
    }

    private function lookupHtmlRedirectUrl(Request $request, HrLookupModel $record, string $operation): string
    {
        $definition = $this->definition();
        $action = $this->submitAction($request, creating: $operation === 'store');

        return match ($action) {
            'save_view' => route($definition->route('show'), $record->doc_num),
            'save_edit' => route($definition->route('edit'), $record->doc_num),
            'save_back' => route($definition->route('index')),
            'save_new' => route($definition->route('create')),
            'save_clone' => route($definition->route('clone'), $record->doc_num),
            default => $operation === 'store'
                ? $this->redirectAfterStore($request, $record)
                : route($definition->route('edit'), $record->doc_num),
        };
    }

    private function saveActionResponse(Request $request, HrLookupModel $record, string $operation): array
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

    private function redirectAfterStore(Request $request, HrLookupModel $record): string
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

    private function cloneSourceFromRequest(StoreHrLookupRequest $request): ?HrLookupModel
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

        /** @var HrLookupModel|null $source */
        $source = $this->definition()->modelClass::query()
            ->where('doc_num', $sourceDocNum)
            ->first();

        if (! $source instanceof HrLookupModel) {
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

    private function logRestoreBlocked(Request $request, HrLookupDefinition $definition, HrLookupModel $record, HrLookupRestoreBlockedException $exception): void
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
    private function metadata(?HrLookupModel $record): array
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
    private function breadcrumbs(string $mode, ?HrLookupModel $record): array
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
    }

    private function restoreRecordByDocNum(HrLookupDefinition $definition, string $docNum): HrLookupModel
    {
        return $definition->modelClass::onlyTrashed()
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? $definition->modelClass::query()
                ->where('doc_num', $docNum)
                ->firstOrFail();
    }

    private function abortIfTrashedRecordIsNotViewable(Request $request, HrLookupDefinition $definition, HrLookupModel $record): void
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
    private function recordPublicProperties(HrLookupModel $record): array
    {
        return [
            'doc_num' => $record->doc_num,
            'name' => $record->name,
        ];
    }

    /**
     * @return array{doc_num: string|null, name: string|null, restored_by_user_doc_num: string|null, restored_at: string}
     */
    private function recordRestoreProperties(Request $request, HrLookupModel $record, string $restoredAt): array
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

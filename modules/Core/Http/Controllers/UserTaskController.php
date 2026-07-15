<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\DataTables\UserTasksDataTable;
use Modules\Core\Http\Requests\BulkDeleteUserTasksRequest;
use Modules\Core\Http\Requests\StoreUserTaskRequest;
use Modules\Core\Http\Requests\UpdateUserTaskDocumentNumberSettingsRequest;
use Modules\Core\Http\Requests\UpdateUserTaskRequest;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\SettingService;
use Modules\Core\Services\UserSelect2Service;
use Modules\Core\Services\UserTaskDocumentNumberSettingsService;
use Modules\Core\Services\UserTaskService;
use Throwable;

class UserTaskController extends Controller
{
    public function __construct(
        private readonly UserTaskService $tasks,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request, UserTaskDocumentNumberSettingsService $documentNumberSettings): View
    {
        return view('modules.core.user-tasks.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.tasks.index'),
            'documentNumberSettings' => $documentNumberSettings->current(),
        ]);
    }

    public function data(Request $request, UserTasksDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->formView('create');
    }

    public function show(Request $request, UserTask $userTask): View
    {
        $this->abortIfTrashedRecordIsNotViewable($request, $userTask);
        $this->logActivity($request, 'user_tasks.view', $this->tasks->publicProperties($userTask));

        return $this->formView('view', $userTask);
    }

    public function edit(UserTask $userTask): View
    {
        return $this->formView('edit', $userTask);
    }

    public function clone(UserTask $userTask): View
    {
        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $userTask->doc_num);

        return $this->formView('clone', $userTask, $cloneSourceToken);
    }

    public function store(StoreUserTaskRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request);

        try {
            $result = $this->tasks->create($request->validated(), $user);
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        $record = $result['record'];

        if ($cloneSource instanceof UserTask) {
            $this->logActivity($request, 'user_tasks.clone', ActivityLogProperties::crudCloned(
                'user_tasks',
                ActivityLogProperties::record('user_tasks', $cloneSource->title, $cloneSource->doc_num),
                $record->title,
                $record->doc_num,
                $this->tasks->publicProperties($record),
            ));
        } else {
            $this->logActivity($request, 'user_tasks.create', ActivityLogProperties::crudCreated(
                'user_tasks',
                $record->title,
                $record->doc_num,
                $this->tasks->publicProperties($record),
            ));
        }

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof UserTask ? __('user_tasks.messages.cloned') : __('user_tasks.messages.created'),
            ...$this->saveActionResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->recordUrls($record),
            ],
        ]);
    }

    public function update(UpdateUserTaskRequest $request, UserTask $userTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeSubmitAction($request, $this->submitAction($request));

        try {
            $result = $this->tasks->update($userTask, $request->validated(), $user);
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

        $this->logActivity($request, 'user_tasks.update', ActivityLogProperties::crudUpdated(
            'user_tasks',
            $record->title,
            $record->doc_num,
            $result['changes'],
            $this->tasks->publicProperties($record),
        ));

        if (in_array('doc_number', $result['changed_fields'], true)) {
            $this->logActivity($request, 'user_tasks.doc_number.changed', ActivityLogProperties::documentNumberChanged(
                'user_tasks',
                $record->title,
                $result['old_doc_number'],
                $result['old_doc_num'],
                $record->doc_number,
                $record->doc_num,
            ));
        }

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.updated'),
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

    public function destroy(Request $request, UserTask $userTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $properties = $this->tasks->publicProperties($userTask);
        $this->tasks->delete($userTask, $user);

        $this->logActivity($request, 'user_tasks.delete', ActivityLogProperties::crudDeleted(
            'user_tasks',
            $properties['title'] ?? $userTask->title,
            $properties['task_doc_num'] ?? $userTask->doc_num,
            $properties,
        ));

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.deleted'),
        ]);
    }

    public function bulkDelete(BulkDeleteUserTasksRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $docNums = $request->validated('doc_nums');
        $deleted = $this->tasks->bulkDelete($docNums, $user);

        $this->logActivity($request, 'user_tasks.bulk_delete', ActivityLogProperties::bulkDeleted(
            'user_tasks',
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.bulk_deleted', ['count' => $deleted]),
            'data' => [
                'deleted' => $deleted,
            ],
        ]);
    }

    public function restore(Request $request, string $userTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->restoreRecordByDocNum($userTask);

        try {
            $record = $this->tasks->restore($record, $user);
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $this->logActivity($request, 'user_tasks.restore', ActivityLogProperties::crudRestored(
            'user_tasks',
            $record->title,
            $record->doc_num,
            $this->tasks->publicProperties($record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.restored'),
        ]);
    }

    public function updateDocumentNumberSettings(
        UpdateUserTaskDocumentNumberSettingsRequest $request,
        UserTaskDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse {
        $result = $documentNumberSettings->update(
            $request->validated('prefix'),
            (int) $request->validated('padding'),
        );

        $this->logActivity($request, 'user_tasks.document_number_settings.update', ActivityLogProperties::settingsUpdated(
            'user_tasks',
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
            'message' => __('user_tasks.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function formView(string $mode, ?UserTask $record = null, ?string $cloneSourceToken = null): View
    {
        $documentNumberSettings = app(UserTaskDocumentNumberSettingsService::class)->current();
        $record?->loadMissing(['assignedTo:id,name,doc_num,email', 'assignedBy:id,name,doc_num', 'createdBy:id,name,doc_num', 'updatedBy:id,name,doc_num', 'deletedBy:id,name,doc_num', 'restoredBy:id,name,doc_num']);

        return view('modules.core.user-tasks.form', [
            'mode' => $mode,
            'task' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.tasks.store') : route('admin.tasks.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'documentNumberPrefix' => $documentNumberSettings['prefix'],
            'documentNumberPadding' => $documentNumberSettings['padding'],
            'canControlDocumentNumber' => (bool) auth()->user()?->can('tasks.document_number.control'),
            'metadata' => $this->metadata($record),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'selectedAssigneeOption' => $record?->assignedTo instanceof User
                ? app(UserSelect2Service::class)->item($record->assignedTo)
                : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function recordUrls(UserTask $record): array
    {
        return [
            'show' => route('admin.tasks.show', $record->doc_num),
            'clone' => route('admin.tasks.clone', $record->doc_num),
            'edit' => route('admin.tasks.edit', $record->doc_num),
            'update' => route('admin.tasks.update', $record->doc_num),
            'destroy' => route('admin.tasks.destroy', $record->doc_num),
            'restore' => route('admin.tasks.restore', $record->doc_num),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saveActionResponse(Request $request, UserTask $record, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = ['submit_action' => $action];

        $redirect = match ($action) {
            'save_view' => route('admin.tasks.show', $record->doc_num),
            'save_edit' => route('admin.tasks.edit', $record->doc_num),
            'save_back' => route('admin.tasks.index'),
            'save_new' => $operation === 'store' ? null : route('admin.tasks.create'),
            'save_clone' => route('admin.tasks.clone', $record->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $record) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';

            if ($request->user()?->can('tasks.document_number.control')) {
                $response['next_doc_number'] = app(DocumentNumberService::class)->nextNumber('user_tasks', UserTask::class);
            }
        }

        return $response;
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view' => 'tasks.view',
            'save_edit' => 'tasks.edit',
            'save_back' => 'tasks.view',
            'save_new' => $cloning ? 'tasks.clone' : 'tasks.create',
            'save_clone' => 'tasks.clone',
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('user_tasks.messages.action_forbidden'));
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        if ($creating && $action === 'save') {
            return 'save_new';
        }

        return $action;
    }

    private function redirectAfterStore(Request $request, UserTask $record): string
    {
        if ($request->user()?->can('tasks.edit')) {
            return route('admin.tasks.edit', $record->doc_num);
        }

        if ($request->user()?->can('tasks.view')) {
            return route('admin.tasks.show', $record->doc_num);
        }

        return route('admin.tasks.index');
    }

    private function cloneSourceFromRequest(StoreUserTaskRequest $request): ?UserTask
    {
        $cloneSourceToken = $request->string('clone_source_token')->trim()->toString();

        if ($cloneSourceToken === '') {
            return null;
        }

        abort_unless((bool) $request->user()?->can('tasks.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($cloneSourceToken), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'title' => __('user_tasks.messages.clone_not_allowed'),
            ]);
        }

        $sourceRecord = UserTask::query()
            ->where('doc_num', $sourceDocNum)
            ->first();

        if (! $sourceRecord instanceof UserTask) {
            throw ValidationException::withMessages([
                'title' => __('user_tasks.messages.clone_not_allowed'),
            ]);
        }

        return $sourceRecord;
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'user_tasks.clone_sources.'.$token;
    }

    private function restoreRecordByDocNum(string $docNum): UserTask
    {
        return UserTask::onlyTrashed()
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? UserTask::query()
                ->where('doc_num', $docNum)
                ->firstOrFail();
    }

    private function abortIfTrashedRecordIsNotViewable(Request $request, UserTask $record): void
    {
        abort_if(
            $record->trashed() && ! $request->user()?->can('tasks.view_trashed'),
            404,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?UserTask $record): array
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
     * @return array<int, array{label: string, url?: string|null}>
     */
    private function breadcrumbs(string $mode, ?UserTask $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.tasks.show', $record->doc_num) : null,
                ],
                ['label' => __('user_tasks.titles.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.tasks.show', $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.tasks.index', $extra);
    }

    private function throwValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();
        $map = [
            'doc_number' => ['user_tasks_doc_number_unique_active', 'user_tasks_doc_num_unique_active'],
        ];

        foreach ($map as $field => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($message, $needle)) {
                    throw ValidationException::withMessages([
                        $field => __("user_tasks.validation.{$field}_unique"),
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = []): void
    {
        try {
            $this->activityLogger->log($request, 'core', $action, 'success', [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

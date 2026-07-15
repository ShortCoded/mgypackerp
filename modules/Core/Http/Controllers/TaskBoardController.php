<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Core\DataTables\TaskBoardsDataTable;
use Modules\Core\Http\Requests\BulkTaskBoardsRequest;
use Modules\Core\Http\Requests\StoreTaskBoardRequest;
use Modules\Core\Http\Requests\UpdateTaskBoardRequest;
use Modules\Core\Models\TaskBoard;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\SettingService;
use Modules\Core\Services\TaskBoardService;
use Throwable;

class TaskBoardController extends Controller
{
    public function __construct(
        private readonly TaskBoardService $taskBoards,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(): View
    {
        return view('modules.core.task-boards.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.task-boards.index'),
        ]);
    }

    public function data(Request $request, TaskBoardsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->formView('create');
    }

    public function store(StoreTaskBoardRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->taskBoards->create($request->validated(), $user, $request);
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);
            throw $exception;
        }

        $record = $result['record'];
        $this->logActivity($request, 'task_boards.create', ActivityLogProperties::crudCreated(
            'task_boards',
            $record->name,
            $record->doc_num,
            $this->taskBoards->publicProperties($record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('task_boards.messages.created'),
            ...$this->saveActionResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'display_url' => $record->displayUrl(),
                'user_display_url' => $record->userDisplayUrl(),
                'urls' => $this->recordUrls($record),
            ],
        ]);
    }

    public function show(Request $request, string $taskBoard): View
    {
        $record = $this->recordByDocNum($request, $taskBoard, withTrashed: true);

        abort_if($record->trashed() && ! $request->user()?->can('task_boards.view_trashed'), 404);

        return $this->formView('view', $record);
    }

    public function edit(Request $request, string $taskBoard): View
    {
        return $this->formView('edit', $this->recordByDocNum($request, $taskBoard));
    }

    public function update(UpdateTaskBoardRequest $request, string $taskBoard): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->recordByDocNum($request, $taskBoard);

        try {
            $result = $this->taskBoards->update($record, $request->validated(), $user, $request);
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

        $this->logActivity($request, 'task_boards.update', ActivityLogProperties::crudUpdated(
            'task_boards',
            $record->name,
            $record->doc_num,
            $result['changes'],
            $this->taskBoards->publicProperties($record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('task_boards.messages.updated'),
            ...$this->saveActionResponse($request, $record, 'update'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'display_url' => $record->displayUrl(),
                'user_display_url' => $record->userDisplayUrl(),
                'urls' => $this->recordUrls($record),
            ],
        ]);
    }

    public function destroy(Request $request, string $taskBoard): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->recordByDocNum($request, $taskBoard);
        $properties = $this->taskBoards->publicProperties($record);

        $this->taskBoards->delete($record, $user, $request);
        $this->logActivity($request, 'task_boards.delete', ActivityLogProperties::crudDeleted(
            'task_boards',
            $properties['name'] ?? $record->name,
            $properties['task_board_doc_num'] ?? $record->doc_num,
            $properties,
        ));

        return response()->json([
            'success' => true,
            'message' => __('task_boards.messages.deleted'),
        ]);
    }

    public function bulkDelete(BulkTaskBoardsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $docNums = $request->validated('doc_nums');
        $deleted = $this->taskBoards->bulkDelete($docNums, $user, $request);

        $this->logActivity($request, 'task_boards.bulk_delete', ActivityLogProperties::bulkDeleted(
            'task_boards',
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('task_boards.messages.bulk_deleted', ['count' => $deleted]),
            'data' => [
                'deleted' => $deleted,
            ],
        ]);
    }

    public function bulkActivate(BulkTaskBoardsRequest $request): JsonResponse
    {
        return $this->bulkSetActive($request, true, 'task_boards.bulk_activate', 'bulk_activate', __('task_boards.messages.bulk_activated'));
    }

    public function bulkDeactivate(BulkTaskBoardsRequest $request): JsonResponse
    {
        return $this->bulkSetActive($request, false, 'task_boards.bulk_deactivate', 'bulk_deactivate', __('task_boards.messages.bulk_deactivated'));
    }

    public function bulkRestore(BulkTaskBoardsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $docNums = $request->validated('doc_nums');
        $restored = $this->taskBoards->bulkRestore($docNums, $user, $request);

        $this->logActivity($request, 'task_boards.bulk_restore', $this->bulkActionProperties('bulk_restore', $restored, $docNums));

        return response()->json([
            'success' => true,
            'message' => __('task_boards.messages.bulk_restored', ['count' => $restored]),
            'data' => [
                'restored' => $restored,
            ],
        ]);
    }

    public function restore(Request $request, string $taskBoard): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->restoreRecordByDocNum($request, $taskBoard);
        $record = $this->taskBoards->restore($record, $user, $request);

        $this->logActivity($request, 'task_boards.restore', ActivityLogProperties::crudRestored(
            'task_boards',
            $record->name,
            $record->doc_num,
            $this->taskBoards->publicProperties($record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('task_boards.messages.restored'),
        ]);
    }

    public function regeneratePublicUrl(Request $request, string $taskBoard): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->recordByDocNum($request, $taskBoard);
        $record = $this->taskBoards->regeneratePublicToken($record, $user, $request);

        $this->logActivity($request, 'task_boards.regenerate_public_url', ActivityLogProperties::statusChanged(
            'task_boards',
            $record->name,
            $record->doc_num,
            null,
            'regenerated',
            $this->taskBoards->publicProperties($record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('task_boards.messages.public_url_regenerated'),
            'data' => [
                'display_url' => $record->displayUrl(),
                'user_display_url' => $record->userDisplayUrl(),
            ],
        ]);
    }

    private function formView(string $mode, ?TaskBoard $record = null): View
    {
        $record?->loadMissing(['users:id,name,doc_num', 'roles:id,name,doc_num', 'createdBy:id,name,doc_num', 'updatedBy:id,name,doc_num', 'deletedBy:id,name,doc_num', 'restoredBy:id,name,doc_num']);

        return view('modules.core.task-boards.form', [
            'mode' => $mode,
            'board' => $record,
            'action' => $mode === 'create' ? route('admin.task-boards.store') : route('admin.task-boards.update', $record?->doc_num),
            'method' => $mode === 'create' ? 'POST' : 'PUT',
            'selectedUserOptions' => $record?->users?->map(fn (User $user): array => [
                'id' => (string) $user->doc_num,
                'text' => trim(implode(' / ', array_filter([$user->name, $user->doc_num]))),
            ])->values()->all() ?? [],
            'selectedRoleOptions' => $record?->roles?->map(fn ($role): array => [
                'id' => (string) $role->doc_num,
                'text' => (string) $role->name,
            ])->values()->all() ?? [],
            'metadata' => $this->metadata($record),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function recordUrls(TaskBoard $record): array
    {
        return [
            'show' => route('admin.task-boards.show', $record->doc_num),
            'edit' => route('admin.task-boards.edit', $record->doc_num),
            'update' => route('admin.task-boards.update', $record->doc_num),
            'destroy' => route('admin.task-boards.destroy', $record->doc_num),
            'regenerate_public_url' => route('admin.task-boards.regenerate-public-url', $record->doc_num),
            'display' => $record->displayUrl(),
            'user_display' => $record->userDisplayUrl(),
        ];
    }

    private function saveActionResponse(Request $request, TaskBoard $record, string $operation): array
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';
        $response = ['submit_action' => $action];
        $redirect = match ($action) {
            'save_view' => route('admin.task-boards.show', $record->doc_num),
            'save_edit' => route('admin.task-boards.edit', $record->doc_num),
            'save_back' => route('admin.task-boards.index'),
            default => $operation === 'store' && $action !== 'save_new' ? $this->redirectAfterStore($request, $record) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($operation === 'store' && $action === 'save_new') {
            $response['reset_form'] = true;
        }

        return $response;
    }

    private function redirectAfterStore(Request $request, TaskBoard $record): string
    {
        if ($request->user()?->can('task_boards.update')) {
            return route('admin.task-boards.edit', $record->doc_num);
        }

        if ($request->user()?->can('task_boards.view')) {
            return route('admin.task-boards.show', $record->doc_num);
        }

        return route('admin.task-boards.index');
    }

    private function recordByDocNum(Request $request, string $docNum, bool $withTrashed = false): TaskBoard
    {
        $query = TaskBoard::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        $record = $this->taskBoards
            ->scopeToCurrentContext($query, $request)
            ->where('doc_num', $docNum)
            ->firstOrFail();

        $this->taskBoards->abortUnlessCanAccess($request, $record, 'task_boards.view');

        return $record;
    }

    private function restoreRecordByDocNum(Request $request, string $docNum): TaskBoard
    {
        $record = $this->taskBoards
            ->scopeToCurrentContext(TaskBoard::onlyTrashed(), $request)
            ->where('doc_num', $docNum)
            ->firstOrFail();

        $this->taskBoards->abortUnlessCanAccess($request, $record, 'task_boards.restore');

        return $record;
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?TaskBoard $record): array
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
        return $user ? trim(implode(' / ', array_filter([$user->name, $user->doc_num]))) : null;
    }

    /**
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    private function breadcrumbs(string $mode, ?TaskBoard $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'edit' => [['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.task-boards.show', $record->doc_num) : null], ['label' => __('breadcrumb.edit')]],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.task-boards.index', $extra);
    }

    private function throwValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();

        foreach (['task_boards_company_doc_number_unique_active', 'task_boards_company_doc_num_unique_active'] as $needle) {
            if (str_contains($message, $needle)) {
                throw ValidationException::withMessages(['name' => __('task_boards.validation.doc_number_unique')]);
            }
        }
    }

    private function bulkSetActive(BulkTaskBoardsRequest $request, bool $active, string $permission, string $actionType, string $message): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $docNums = $request->validated('doc_nums');
        $updated = $this->taskBoards->bulkSetActive($docNums, $active, $user, $request, $permission);

        $this->logActivity($request, "task_boards.{$actionType}", $this->bulkActionProperties($actionType, $updated, $docNums));

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'updated' => $updated,
            ],
        ]);
    }

    /**
     * @param  list<string>  $docNums
     * @return array<string, mixed>
     */
    private function bulkActionProperties(string $actionType, int $count, array $docNums): array
    {
        return [
            'action' => [
                'type' => $actionType,
                'label_key' => "task_boards.actions.{$actionType}",
            ],
            'bulk' => [
                'count' => $count,
                'doc_nums' => array_values($docNums),
            ],
        ];
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

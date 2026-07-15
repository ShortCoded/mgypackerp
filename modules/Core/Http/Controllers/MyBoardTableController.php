<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\DataTables\MyBoardTableDataTable;
use Modules\Core\Http\Requests\MoveUserTaskRequest;
use Modules\Core\Http\Requests\StoreUserTaskRequest;
use Modules\Core\Http\Requests\UpdateUserTaskRequest;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\BoardList;
use Modules\Core\Models\MyBoardTaskView;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SettingService;
use Modules\Core\Services\UserSelect2Service;
use Modules\Core\Services\UserTaskAccessService;
use Modules\Core\Services\UserTaskService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MyBoardTableController extends Controller
{
    /**
     * @var Collection<int, BoardList>|null
     */
    private ?Collection $boardLists = null;

    public function __construct(
        private readonly MyBoardTableDataTable $dataTable,
        private readonly UserTaskService $tasks,
        private readonly UserTaskAccessService $access,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly DateFormatService $dates,
        private readonly NotificationService $notifications,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $boardUser = $user;

        abort_unless($this->access->canViewBoard($user, $boardUser), 403, __('user_tasks.messages.manage_board_forbidden'));

        return view('modules.core.my-board.table', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.my-board.table'),
            'boardConfig' => $this->tableConfig($request, $boardUser),
            'boardUser' => $boardUser,
        ]);
    }

    public function tasksData(Request $request): JsonResponse
    {
        return $this->data($request, UserTask::TypeTask);
    }

    public function notesData(Request $request): JsonResponse
    {
        return $this->data($request, UserTask::TypeNote);
    }

    public function createTask(Request $request): View
    {
        return $this->formView($request, 'create', UserTask::TypeTask);
    }

    public function createNote(Request $request): View
    {
        return $this->formView($request, 'create', UserTask::TypeNote);
    }

    public function storeTask(StoreUserTaskRequest $request): JsonResponse
    {
        return $this->store($request, UserTask::TypeTask);
    }

    public function storeNote(StoreUserTaskRequest $request): JsonResponse
    {
        return $this->store($request, UserTask::TypeNote);
    }

    public function showTask(Request $request, UserTask $userTask): JsonResponse|View
    {
        return $this->show($request, $userTask, UserTask::TypeTask);
    }

    public function showNote(Request $request, UserTask $userTask): JsonResponse|View
    {
        return $this->show($request, $userTask, UserTask::TypeNote);
    }

    public function editTask(Request $request, UserTask $userTask): View
    {
        return $this->formView($request, 'edit', UserTask::TypeTask, $userTask);
    }

    public function editNote(Request $request, UserTask $userTask): View
    {
        return $this->formView($request, 'edit', UserTask::TypeNote, $userTask);
    }

    public function updateTask(UpdateUserTaskRequest $request, UserTask $userTask): JsonResponse
    {
        return $this->update($request, $userTask, UserTask::TypeTask);
    }

    public function updateNote(UpdateUserTaskRequest $request, UserTask $userTask): JsonResponse
    {
        return $this->update($request, $userTask, UserTask::TypeNote);
    }

    public function updateTaskStatus(MoveUserTaskRequest $request, UserTask $userTask): JsonResponse
    {
        return $this->updateStatus($request, $userTask, UserTask::TypeTask);
    }

    public function updateNoteStatus(MoveUserTaskRequest $request, UserTask $userTask): JsonResponse
    {
        return $this->updateStatus($request, $userTask, UserTask::TypeNote);
    }

    public function destroyTask(Request $request, UserTask $userTask): JsonResponse
    {
        return $this->destroy($request, $userTask, UserTask::TypeTask);
    }

    public function destroyNote(Request $request, UserTask $userTask): JsonResponse
    {
        return $this->destroy($request, $userTask, UserTask::TypeNote);
    }

    public function cloneTask(Request $request, UserTask $userTask): JsonResponse
    {
        return $this->duplicate($request, $userTask, UserTask::TypeTask);
    }

    public function cloneNote(Request $request, UserTask $userTask): JsonResponse
    {
        return $this->duplicate($request, $userTask, UserTask::TypeNote);
    }

    public function restoreTask(Request $request, string $userTask): JsonResponse
    {
        return $this->restore($request, $userTask, UserTask::TypeTask);
    }

    public function restoreNote(Request $request, string $userTask): JsonResponse
    {
        return $this->restore($request, $userTask, UserTask::TypeNote);
    }

    public function showTaskAttachment(Request $request, UserTask $userTask, ArchiveFile $file): StreamedResponse
    {
        return $this->taskAttachmentResponse($request, $userTask, $file, inline: true);
    }

    public function downloadTaskAttachment(Request $request, UserTask $userTask, ArchiveFile $file): StreamedResponse
    {
        return $this->taskAttachmentResponse($request, $userTask, $file, inline: false);
    }

    public function destroyTaskAttachment(Request $request, UserTask $userTask, ArchiveFile $file): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->abortUnlessType($userTask, UserTask::TypeTask);
        $this->abortUnlessTableBoardRecord($user, $userTask);
        $this->abortUnlessTaskAttachment($userTask, $file);
        abort_unless($this->access->canEdit($user, $userTask), 403, __('user_tasks.messages.forbidden'));
        abort_unless($user->can('quick_tasks.manage_attachments'), 403, __('user_tasks.validation.manage_attachments_forbidden'));

        $this->tasks->deleteAttachment($userTask, $file, $user);

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.attachment_deleted'),
        ]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'doc_nums' => ['nullable', 'array'],
            'doc_nums.*' => ['required', 'string', 'distinct'],
            'type' => ['required', 'string', 'in:'.implode(',', UserTask::Types)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $docNums = $this->validatedDocNums($data['doc_nums'] ?? []);

        $this->assertSelectedBoardRecords($user, $docNums, (string) $data['type']);

        $deleted = $this->tasks->bulkDelete($docNums, $user);

        $this->logActivity($request, 'user_tasks.bulk_delete', ActivityLogProperties::bulkDeleted(
            'user_tasks',
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.bulk_deleted'),
            'data' => [
                'deleted' => $deleted,
            ],
        ]);
    }

    public function bulkActiveState(Request $request): JsonResponse
    {
        $data = $request->validate([
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => ['required', 'string', 'distinct'],
            'type' => ['required', 'string', 'in:'.implode(',', UserTask::Types)],
            'is_active' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $docNums = $this->validatedDocNums($data['doc_nums']);
        $active = (bool) $data['is_active'];

        $this->assertSelectedBoardRecords($user, $docNums, (string) $data['type']);

        $updated = $this->tasks->bulkSetActive($docNums, (string) $data['type'], $active, $user);

        $this->logActivity($request, $active ? 'user_tasks.bulk_activate' : 'user_tasks.bulk_deactivate', [
            'action' => [
                'type' => $active ? 'bulk_activate' : 'bulk_deactivate',
                'label_key' => $active ? 'user_tasks.actions.activate_selected' : 'user_tasks.actions.deactivate_selected',
            ],
            'bulk' => [
                'count' => $updated,
                'doc_nums' => $docNums,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => $active
                ? __('user_tasks.messages.bulk_activated')
                : __('user_tasks.messages.bulk_deactivated'),
            'data' => [
                'updated' => $updated,
            ],
        ]);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => ['required', 'string', 'distinct'],
            'type' => ['required', 'string', 'in:'.implode(',', UserTask::Types)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $docNums = $this->validatedDocNums($data['doc_nums']);

        $this->assertSelectedBoardRecords($user, $docNums, (string) $data['type'], onlyTrashed: true);

        $restored = $this->tasks->bulkRestore($docNums, (string) $data['type'], $user);

        $this->logActivity($request, 'user_tasks.bulk_restore', [
            'action' => [
                'type' => 'bulk_restore',
                'label_key' => 'user_tasks.actions.restore_selected',
            ],
            'bulk' => [
                'count' => $restored,
                'doc_nums' => $docNums,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.bulk_restored', ['count' => $restored]),
            'data' => [
                'restored' => $restored,
            ],
        ]);
    }

    private function data(Request $request, string $type): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $boardUser = $user;

        abort_unless($this->access->canViewBoard($user, $boardUser), 403, __('user_tasks.messages.manage_board_forbidden'));

        return $this->dataTable->json($request, $boardUser, $user, $type);
    }

    private function store(StoreUserTaskRequest $request, string $type): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $data['type'] = $type;
        $boardUser = $this->tableBoardUser($request, $user, null, $data);
        $data = $this->boardValidatedData($data, $user, $boardUser);

        abort_unless($this->access->canViewBoard($user, $boardUser), 403, __('user_tasks.messages.manage_board_forbidden'));

        $result = $this->tasks->create($data, $user, $request->userTaskAttachmentFileDocNums(), $request);
        $record = $result['record'];
        $record->refresh()->load($this->itemRelations());
        $this->notifications->notifyTaskAssigned($record, $record->assignees, $user);

        $this->logActivity($request, 'user_tasks.create', ActivityLogProperties::crudCreated(
            'user_tasks',
            $record->title,
            $record->doc_num,
            $this->tasks->publicProperties($record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.created'),
            ...$this->saveActionResponse($request, $record, $type, 'store'),
            'data' => [
                'item' => $this->itemPayload($record, $user),
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->recordUrls($record, $type),
            ],
        ], 201);
    }

    private function show(Request $request, UserTask $userTask, string $type): JsonResponse|View
    {
        /** @var User $user */
        $user = $request->user();
        $this->abortUnlessType($userTask, $type);
        $this->abortUnlessTableBoardRecord($user, $userTask);
        abort_unless($this->access->canView($user, $userTask), 404);

        if ($userTask->trashed()) {
            abort_unless($user->can('my_board.view_trashed'), 404);
        } else {
            $this->recordTaskView($userTask, $user);
        }

        if (! $request->expectsJson()) {
            return $this->formView($request, 'view', $type, $userTask);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'item' => $this->itemPayload($userTask->load($this->itemRelations()), $user),
            ],
        ]);
    }

    private function update(UpdateUserTaskRequest $request, UserTask $userTask, string $type): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->abortUnlessType($userTask, $type);
        $this->abortUnlessTableBoardRecord($user, $userTask);
        $data = $request->validated();
        $data['type'] = $type;
        $boardUser = $this->tableBoardUser($request, $user, $userTask, $data);
        $data = $this->boardValidatedData($data, $user, $boardUser, $userTask);
        $result = $this->tasks->update($userTask, $data, $user, $request->userTaskAttachmentFileDocNums(), $request);
        $record = $result['record'];

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
                'data' => [
                    'item' => $this->itemPayload($record->load($this->itemRelations()), $user),
                ],
            ]);
        }

        $this->logActivity($request, 'user_tasks.update', ActivityLogProperties::crudUpdated(
            'user_tasks',
            $record->title,
            $record->doc_num,
            $result['changes'],
            $this->tasks->publicProperties($record),
        ));

        $addedAssignees = User::query()
            ->whereIn('id', $result['added_assignee_user_ids'] ?? [])
            ->get();

        $this->notifications->notifyTaskAssigned($record, $addedAssignees, $user);
        $this->notifications->notifyTaskUpdated($record, $record->assignees, $user);

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.updated'),
            ...$this->saveActionResponse($request, $record, $type, 'update'),
            'data' => [
                'item' => $this->itemPayload($record->load($this->itemRelations()), $user),
                'old_doc_number' => $result['old_doc_number'] ?? null,
                'old_doc_num' => $result['old_doc_num'] ?? null,
                'doc_number' => $record->doc_number,
                'doc_num' => $record->doc_num,
                'urls' => $this->recordUrls($record, $type),
            ],
        ]);
    }

    private function updateStatus(MoveUserTaskRequest $request, UserTask $userTask, string $type): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->abortUnlessType($userTask, $type);
        $this->abortUnlessTableBoardRecord($user, $userTask);
        $result = $this->tasks->move($userTask, $request->validated(), $user);
        $record = $result['record'];

        if ($result['changed']) {
            $this->logActivity($request, 'user_tasks.move', ActivityLogProperties::crudUpdated(
                'user_tasks',
                $record->title,
                $record->doc_num,
                $result['changes'],
                $this->tasks->publicProperties($record),
            ));
        }

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.status_updated'),
            'data' => [
                'item' => $this->itemPayload($record->load($this->itemRelations()), $user),
            ],
        ]);
    }

    private function duplicate(Request $request, UserTask $userTask, string $type): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->abortUnlessType($userTask, $type);
        $this->abortUnlessTableBoardRecord($user, $userTask);

        abort_unless($this->access->canClone($user, $userTask), 403, __('user_tasks.messages.forbidden'));

        $result = $this->tasks->duplicateForTable($userTask, $user);
        $record = $result['record'];

        $this->logActivity($request, 'user_tasks.clone', ActivityLogProperties::crudCloned(
            'user_tasks',
            $userTask,
            $record->title,
            $record->doc_num,
            $this->tasks->publicProperties($record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.record_duplicated'),
            'data' => [
                'item' => $this->itemPayload($record->load($this->itemRelations()), $user),
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->recordUrls($record, $type),
            ],
        ], 201);
    }

    private function restore(Request $request, string $docNum, string $type): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = UserTask::onlyTrashed()
            ->where('doc_num', $docNum)
            ->where('type', $type)
            ->with('assignees:id')
            ->firstOrFail();

        $this->abortUnlessTableBoardRecord($user, $record);

        abort_unless($this->access->canRestore($user, $record), 403, __('user_tasks.messages.forbidden'));

        $record = $this->tasks->restore($record, $user);

        $this->logActivity($request, 'user_tasks.restore', ActivityLogProperties::crudRestored(
            'user_tasks',
            $record->title,
            $record->doc_num,
            $this->tasks->publicProperties($record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.record_restored'),
            'data' => [
                'item' => $this->itemPayload($record->load($this->itemRelations()), $user),
            ],
        ]);
    }

    private function destroy(Request $request, UserTask $userTask, string $type): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->abortUnlessType($userTask, $type);
        $this->abortUnlessTableBoardRecord($user, $userTask);
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
            'message' => __('user_tasks.messages.record_deleted'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tableConfig(Request $request, User $boardUser): array
    {
        $user = $request->user();
        $canAssign = $user instanceof User && $this->access->canAssign($user);
        $isTeamRoute = $this->isTeamBoardRoute($request);
        $routePrefix = $isTeamRoute ? 'admin.tools.team-board' : 'admin.my-board';

        return [
            'mode' => $isTeamRoute ? 'team' : 'personal',
            'direction' => config('languages.available.'.app()->getLocale().'.dir', 'ltr'),
            'actor' => $user instanceof User ? $this->boardUserPayload($user) : null,
            'boardUser' => $this->boardUserPayload($boardUser),
            'boards' => [
                UserTask::TypeTask => $this->boardLists(UserTask::TypeTask)->map(fn (BoardList $list): array => $this->listPayload($list))->values()->all(),
                UserTask::TypeNote => $this->boardLists(UserTask::TypeNote)->map(fn (BoardList $list): array => $this->listPayload($list))->values()->all(),
            ],
            'can' => [
                'create' => (bool) $user?->can('my_board.create'),
                'edit' => (bool) $user?->can('my_board.edit'),
                'delete' => (bool) $user?->can('my_board.delete'),
                'clone' => (bool) $user?->can('my_board.clone'),
                'restore' => (bool) $user?->can('my_board.restore'),
                'viewTrashed' => (bool) $user?->can('my_board.view_trashed'),
                'reorder' => (bool) $user?->can('my_board.reorder'),
                'assign' => $canAssign,
                'viewAny' => $isTeamRoute && $user instanceof User && $this->access->canSwitchBoardUser($user),
                'manageAny' => $isTeamRoute && (bool) $user?->can('my_board.manage_any'),
                'viewAllTasks' => $isTeamRoute && (bool) $user?->can('my_board.tasks.view_all'),
                'viewAllNotes' => $isTeamRoute && (bool) $user?->can('my_board.notes.view_all'),
                'manageAttachments' => (bool) $user?->can('quick_tasks.manage_attachments'),
                'useFileManager' => (bool) $user?->can('file_manager.view'),
            ],
            'urls' => [
                'index' => route($isTeamRoute ? 'admin.tools.team-board.index' : 'admin.my-board.table'),
                UserTask::TypeTask => [
                    'data' => route($isTeamRoute ? 'admin.tools.team-board.tasks.data' : 'admin.my-board.tasks.datatable'),
                    'create' => route("{$routePrefix}.tasks.create"),
                    'store' => route("{$routePrefix}.tasks.store"),
                    'show' => route("{$routePrefix}.tasks.show", ['userTask' => '__TASK__']),
                    'edit' => route("{$routePrefix}.tasks.edit", ['userTask' => '__TASK__']),
                    'update' => route("{$routePrefix}.tasks.update", ['userTask' => '__TASK__']),
                    'destroy' => route("{$routePrefix}.tasks.destroy", ['userTask' => '__TASK__']),
                    'status' => route("{$routePrefix}.tasks.status", ['userTask' => '__TASK__']),
                    'clone' => route("{$routePrefix}.tasks.clone", ['userTask' => '__TASK__']),
                    'restore' => route("{$routePrefix}.tasks.restore", ['userTask' => '__TASK__']),
                ],
                UserTask::TypeNote => [
                    'data' => route($isTeamRoute ? 'admin.tools.team-board.notes.data' : 'admin.my-board.notes.datatable'),
                    'create' => route("{$routePrefix}.notes.create"),
                    'store' => route("{$routePrefix}.notes.store"),
                    'show' => route("{$routePrefix}.notes.show", ['userTask' => '__TASK__']),
                    'edit' => route("{$routePrefix}.notes.edit", ['userTask' => '__TASK__']),
                    'update' => route("{$routePrefix}.notes.update", ['userTask' => '__TASK__']),
                    'destroy' => route("{$routePrefix}.notes.destroy", ['userTask' => '__TASK__']),
                    'status' => route("{$routePrefix}.notes.status", ['userTask' => '__TASK__']),
                    'clone' => route("{$routePrefix}.notes.clone", ['userTask' => '__TASK__']),
                    'restore' => route("{$routePrefix}.notes.restore", ['userTask' => '__TASK__']),
                ],
                'bulkDelete' => route($isTeamRoute ? 'admin.tools.team-board.bulk-delete' : 'admin.my-board.table.bulk-delete'),
                'bulkActiveState' => route($isTeamRoute ? 'admin.tools.team-board.bulk-active-state' : 'admin.my-board.table.bulk-active-state'),
                'bulkRestore' => route($isTeamRoute ? 'admin.tools.team-board.bulk-restore' : 'admin.my-board.table.bulk-restore'),
                'users' => route('admin.select2.users'),
            ],
            'text' => [
                'createTaskTitle' => __('user_tasks.titles.table_create_task'),
                'editTaskTitle' => __('user_tasks.titles.table_edit_task'),
                'viewTaskTitle' => __('user_tasks.titles.table_view_task'),
                'createNoteTitle' => __('user_tasks.titles.table_create_note'),
                'editNoteTitle' => __('user_tasks.titles.table_edit_note'),
                'viewNoteTitle' => __('user_tasks.titles.table_view_note'),
                'all' => __('user_tasks.filters.all'),
                'save' => __('user_tasks.actions.save'),
                'cancel' => __('common.actions.cancel'),
                'confirm' => __('common.actions.confirm'),
            ],
            'messages' => [
                'created' => __('user_tasks.messages.created'),
                'updated' => __('user_tasks.messages.updated'),
                'deleted' => __('user_tasks.messages.record_deleted'),
                'restored' => __('user_tasks.messages.record_restored'),
                'duplicated' => __('user_tasks.messages.record_duplicated'),
                'statusUpdated' => __('user_tasks.messages.status_updated'),
                'deleteConfirmTitle' => __('user_tasks.messages.record_delete_confirm_title'),
                'deleteConfirmText' => __('user_tasks.messages.record_delete_confirm_text'),
                'deleteConfirmYes' => __('user_tasks.messages.record_delete_confirm_yes'),
                'bulkDeleteConfirmTitle' => __('user_tasks.messages.bulk_delete_confirm_title'),
                'bulkDeleteConfirmText' => __('user_tasks.messages.bulk_delete_confirm_text'),
                'bulkDeleteConfirmYes' => __('user_tasks.messages.record_delete_confirm_yes'),
                'bulkActivateConfirmTitle' => __('user_tasks.messages.bulk_activate_confirm_title'),
                'bulkActivateConfirmText' => __('user_tasks.messages.bulk_activate_confirm_text'),
                'bulkActivateConfirmYes' => __('user_tasks.actions.activate_selected'),
                'bulkDeactivateConfirmTitle' => __('user_tasks.messages.bulk_deactivate_confirm_title'),
                'bulkDeactivateConfirmText' => __('user_tasks.messages.bulk_deactivate_confirm_text'),
                'bulkDeactivateConfirmYes' => __('user_tasks.actions.deactivate_selected'),
                'bulkRestoreConfirmTitle' => __('user_tasks.messages.bulk_restore_confirm_title'),
                'bulkRestoreConfirmText' => __('user_tasks.messages.bulk_restore_confirm_text'),
                'bulkRestoreConfirmYes' => __('user_tasks.actions.restore_selected'),
                'restoreConfirmTitle' => __('user_tasks.messages.restore_confirm_title'),
                'restoreConfirmText' => __('user_tasks.messages.restore_confirm_text'),
                'restoreConfirmYes' => __('common.actions.restore'),
                'bulkDeleted' => __('user_tasks.messages.bulk_deleted', ['count' => ':count']),
                'bulkActivated' => __('user_tasks.messages.bulk_activated'),
                'bulkDeactivated' => __('user_tasks.messages.bulk_deactivated'),
                'bulkRestored' => __('user_tasks.messages.bulk_restored', ['count' => ':count']),
                'attachmentDeleted' => __('user_tasks.messages.attachment_deleted'),
                'attachmentDuplicate' => __('user_tasks.messages.attachment_duplicate'),
                'attachmentDeleteConfirmTitle' => __('user_tasks.messages.attachment_delete_confirm_title'),
                'attachmentDeleteConfirmText' => __('user_tasks.messages.attachment_delete_confirm_text'),
                'attachmentDeleteConfirmYes' => __('user_tasks.messages.attachment_delete_confirm_yes'),
                'removeAttachment' => __('user_tasks.actions.remove_attachment'),
                'noRowsSelected' => __('user_tasks.messages.no_records_selected'),
                'validationFailed' => __('common.messages.validation_failed'),
                'unexpectedError' => __('common.messages.unexpected_error'),
                'noChanges' => __('common.messages.no_changes'),
                'no' => __('common.actions.no'),
            ],
        ];
    }

    private function formView(Request $request, string $mode, string $type, ?UserTask $record = null): View
    {
        /** @var User $user */
        $user = $request->user();
        $this->abortUnlessType($record, $type);
        $boardUser = $this->tableBoardUser($request, $user, $record);

        if ($record instanceof UserTask) {
            $this->abortUnlessTableBoardRecord($user, $record);
        }

        if ($mode === 'view' && $record instanceof UserTask) {
            abort_unless($this->access->canView($user, $record), 404);
            abort_if($record->trashed() && ! $user->can('my_board.view_trashed'), 404);
        }

        if ($mode === 'edit' && $record instanceof UserTask) {
            abort_unless($this->access->canEdit($user, $record), 403, __('user_tasks.messages.forbidden'));
        }

        if (! $this->isTeamBoardRoute($request)) {
            abort_unless($this->access->canViewBoard($user, $boardUser), 403, __('user_tasks.messages.manage_board_forbidden'));
        }

        $record?->loadMissing($this->formRelations());

        return view('modules.core.my-board.table-form', [
            'action' => $mode === 'create' ? $this->routeFor($type, 'store', request: $request) : $this->routeFor($type, 'update', $record, request: $request),
            'boardConfig' => $this->tableConfig($request, $boardUser),
            'boardLists' => $this->boardLists($type),
            'boardUser' => $boardUser,
            'breadcrumbs' => $this->formBreadcrumbs($type, $mode, $record),
            'method' => $mode === 'create' ? 'POST' : 'PUT',
            'metadata' => $this->metadata($record),
            'mode' => $mode,
            'record' => $record,
            'selectedAssignees' => $this->selectedAssignees($record, $boardUser),
            'title' => $this->formTitle($type, $mode),
            'type' => $type,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function saveActionResponse(Request $request, UserTask $record, string $type, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = ['submit_action' => $action];

        $redirect = match ($action) {
            'save_view' => $this->routeFor($type, 'show', $record, request: $request),
            'save_edit' => $this->routeFor($type, 'edit', $record, request: $request),
            'save_back' => $this->indexRoute($request),
            'save_new' => $operation === 'store' ? null : $this->routeFor($type, 'create', request: $request),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $record, $type) : null,
        };

        if ($redirect !== null) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';
        }

        return $response;
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        if ($creating && $action === 'save') {
            return 'save_new';
        }

        return $action;
    }

    private function redirectAfterStore(Request $request, UserTask $record, string $type): string
    {
        if ($request->user()?->can('my_board.edit')) {
            return $this->routeFor($type, 'edit', $record, request: $request);
        }

        if ($request->user()?->can('my_board.view')) {
            return $this->routeFor($type, 'show', $record, request: $request);
        }

        return $this->indexRoute($request);
    }

    /**
     * @return array<string, string>
     */
    private function recordUrls(UserTask $record, string $type): array
    {
        return [
            'show' => $this->routeFor($type, 'show', $record),
            'edit' => $this->routeFor($type, 'edit', $record),
            'update' => $this->routeFor($type, 'update', $record),
            'destroy' => $this->routeFor($type, 'destroy', $record),
            'status' => $this->routeFor($type, 'status', $record),
            'clone' => $this->routeFor($type, 'clone', $record),
            'restore' => $this->routeFor($type, 'restore', $record),
        ];
    }

    private function indexRoute(?Request $request = null): string
    {
        return route($this->isTeamBoardRoute($request) ? 'admin.tools.team-board.index' : 'admin.my-board.table');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function routeFor(string $type, string $action, ?UserTask $task = null, array $parameters = [], ?Request $request = null): string
    {
        $segment = $type === UserTask::TypeNote ? 'notes' : 'tasks';
        $routePrefix = $this->isTeamBoardRoute($request) ? 'admin.tools.team-board' : 'admin.my-board';

        if ($task instanceof UserTask) {
            $parameters['userTask'] = $task->doc_num;
        }

        return route("{$routePrefix}.{$segment}.{$action}", $parameters);
    }

    private function isTeamBoardRoute(?Request $request = null): bool
    {
        $request ??= request();

        return $request->routeIs('admin.tools.team-board.*');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function tableBoardUser(Request $request, User $actor, ?UserTask $record = null, array $data = []): User
    {
        if (! $this->isTeamBoardRoute($request)) {
            return $actor;
        }

        if ($record instanceof UserTask) {
            $record->loadMissing('assignedTo:id,name,doc_num,email');

            if ($record->assignedTo instanceof User) {
                return $record->assignedTo;
            }
        }

        return $actor;
    }

    /**
     * @return list<string>
     */
    private function formRelations(): array
    {
        return [
            'assignees:id,name,doc_num,email',
            'assignedTo:id,name,doc_num,email',
            'assignedBy:id,name,doc_num',
            'createdBy:id,name,doc_num',
            'updatedBy:id,name,doc_num',
            'deletedBy:id,name,doc_num',
            'restoredBy:id,name,doc_num',
            'boardList:id,doc_num,type,name,slug,status,color,position',
            'attachmentUsages.file:id,doc_num,original_name,disk,path,mime_type,extension,size_bytes',
            'attachmentUsages.createdBy:id,name,doc_num',
        ];
    }

    /**
     * @return list<array{id: string, text: string}>
     */
    private function selectedAssignees(?UserTask $record, User $boardUser): array
    {
        if (! $record instanceof UserTask) {
            return [app(UserSelect2Service::class)->item($boardUser)];
        }

        $record->loadMissing(['assignees:id,name,doc_num,email', 'assignedTo:id,name,doc_num,email']);
        $users = $record->assignees->isNotEmpty()
            ? $record->assignees
            : collect($record->assignedTo instanceof User ? [$record->assignedTo] : [$boardUser]);

        return $users
            ->filter(fn (User $user): bool => $user->doc_num !== null)
            ->map(fn (User $user): array => app(UserSelect2Service::class)->item($user))
            ->values()
            ->all();
    }

    private function formTitle(string $type, string $mode): string
    {
        $entity = $type === UserTask::TypeNote ? 'note' : 'task';

        return __('user_tasks.titles.table_'.$mode.'_'.$entity);
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?UserTask $record): array
    {
        if (! $record instanceof UserTask) {
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
            'created_by' => $this->userLabel($record->createdBy),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->userLabel($record->updatedBy),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
            'deleted_by' => $this->userLabel($record->deletedBy),
            'deleted_at' => $settings->formatDateTime($record->deleted_at, ''),
            'restored_by' => $this->userLabel($record->restoredBy),
            'restored_at' => $settings->formatDateTime($record->restored_at, ''),
        ];
    }

    /**
     * @return array<int, array{label: string, url?: string|null}>
     */
    private function formBreadcrumbs(string $type, string $mode, ?UserTask $record): array
    {
        $extra = [];

        if ($record instanceof UserTask) {
            $extra[] = [
                'label' => (string) $record->doc_num,
                'url' => $mode === 'view' ? null : $this->routeFor($type, 'show', $record),
            ];
        }

        if ($mode !== 'view') {
            $extra[] = [
                'label' => $mode === 'create' ? __('breadcrumb.create') : __('breadcrumb.edit'),
            ];
        }

        return $this->breadcrumbs->forMenuRoute(
            $this->isTeamBoardRoute() ? 'admin.tools.team-board.index' : 'admin.my-board.table',
            $extra,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function boardValidatedData(array $data, User $actor, User $boardUser, ?UserTask $existing = null): array
    {
        if ($this->isTeamBoardRoute()) {
            unset($data['board_user_doc_num'], $data['start_at'], $data['completed_at']);
            $data['start_at'] = null;
            $data['completed_at'] = null;

            $type = (string) ($data['type'] ?? $existing?->type ?? UserTask::TypeTask);
            $actorDocNum = trim((string) $actor->doc_num);
            $existing?->loadMissing(['assignedTo:id,doc_num', 'assignees:id,doc_num']);

            $existingAssigneeDocNums = $existing instanceof UserTask
                ? $existing->assignees
                    ->pluck('doc_num')
                    ->map(fn (mixed $docNum): string => trim((string) $docNum))
                    ->filter()
                    ->values()
                    ->all()
                : [];
            $existingAssignedToDocNum = trim((string) $existing?->assignedTo?->doc_num);
            $selectedAssigneeDocNums = collect($data['assignee_doc_nums'] ?? [])
                ->map(fn (mixed $docNum): string => trim((string) $docNum))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! $this->access->canAssign($actor)) {
                $selectedAssigneeDocNums = $existing instanceof UserTask
                    ? ($existingAssigneeDocNums ?: array_values(array_filter([$existingAssignedToDocNum])))
                    : array_values(array_filter([$actorDocNum]));
            }

            if ($selectedAssigneeDocNums === []) {
                $selectedAssigneeDocNums = $existingAssigneeDocNums ?: array_values(array_filter([$existingAssignedToDocNum, $actorDocNum]));
            }

            $primaryDocNum = $selectedAssigneeDocNums[0] ?? $actorDocNum;
            $data['assigned_to_doc_num'] = $primaryDocNum;
            $data['assignee_doc_nums'] = $type === UserTask::TypeNote
                ? collect([$primaryDocNum, ...$selectedAssigneeDocNums])->filter()->unique()->values()->all()
                : $selectedAssigneeDocNums;

            return $data;
        }

        $selectedBoardUserDocNum = trim((string) ($data['board_user_doc_num'] ?? ''));
        unset($data['board_user_doc_num']);

        $type = (string) ($data['type'] ?? $existing?->type ?? UserTask::TypeTask);
        $boardUserDocNum = $selectedBoardUserDocNum !== ''
            ? $selectedBoardUserDocNum
            : trim((string) $boardUser->doc_num);

        if ($boardUserDocNum === '') {
            $boardUserDocNum = (string) $actor->doc_num;
        }

        $existing?->loadMissing(['assignedTo:id,doc_num', 'assignees:id,doc_num']);
        $existingAssignedToDocNum = trim((string) $existing?->assignedTo?->doc_num);
        $primaryDocNum = $existing instanceof UserTask && $existingAssignedToDocNum !== '' && $selectedBoardUserDocNum === ''
            ? $existingAssignedToDocNum
            : $boardUserDocNum;
        $existingAssigneeDocNums = $existing instanceof UserTask
            ? $existing->assignees
                ->pluck('doc_num')
                ->map(fn (mixed $docNum): string => trim((string) $docNum))
                ->filter()
                ->values()
                ->all()
            : [];

        if ($existingAssigneeDocNums === [] && $primaryDocNum !== '') {
            $existingAssigneeDocNums = [$primaryDocNum];
        }

        if ($type === UserTask::TypeNote) {
            $data['assigned_to_doc_num'] = $primaryDocNum;
            $data['assignee_doc_nums'] = collect([$primaryDocNum, $boardUserDocNum, ...$existingAssigneeDocNums])
                ->filter()
                ->unique()
                ->values()
                ->all();

            return $data;
        }

        if ($this->access->canAssign($actor)) {
            $selectedAssigneeDocNums = collect($data['assignee_doc_nums'] ?? [])
                ->map(fn (mixed $docNum): string => trim((string) $docNum))
                ->filter()
                ->values()
                ->all();
            $assigneeDocNums = collect([
                $existing instanceof UserTask ? $primaryDocNum : $boardUserDocNum,
                $boardUserDocNum,
                ...$selectedAssigneeDocNums,
            ])
                ->filter()
                ->unique()
                ->values()
                ->all();

            $data['assignee_doc_nums'] = $assigneeDocNums;
            $data['assigned_to_doc_num'] = $existing instanceof UserTask ? $primaryDocNum : $boardUserDocNum;
        } else {
            $data['assigned_to_doc_num'] = $primaryDocNum;
            $data['assignee_doc_nums'] = $existing instanceof UserTask
                ? collect([$primaryDocNum, ...$existingAssigneeDocNums])->filter()->unique()->values()->all()
                : [$boardUserDocNum];
        }

        return $data;
    }

    private function abortUnlessTableBoardRecord(User $actor, UserTask $record): void
    {
        if ($this->isTeamBoardRoute()) {
            abort_unless($this->access->canView($actor, $record), 404);

            return;
        }

        $record->loadMissing('assignees:id');
        $actorId = (int) $actor->getKey();

        $belongsToCurrentBoard = (int) $record->assigned_to === $actorId
            || $record->assignees->contains(fn (User $assignee): bool => (int) $assignee->getKey() === $actorId);

        abort_unless($belongsToCurrentBoard, 404);
    }

    private function taskAttachmentResponse(Request $request, UserTask $task, ArchiveFile $file, bool $inline): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->abortUnlessType($task, UserTask::TypeTask);
        $this->abortUnlessTableBoardRecord($user, $task);
        abort_unless($this->access->canView($user, $task), 404);
        abort_if($task->trashed() && ! $user->can('my_board.view_trashed'), 404);
        $this->abortUnlessTaskAttachment($task, $file);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        if ($inline && $file->isPreviewable()) {
            return response()->stream(function () use ($file): void {
                echo Storage::disk($file->disk)->get($file->path);
            }, 200, array_filter([
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
            ]));
        }

        return Storage::disk($file->disk)->download(
            $file->path,
            $file->original_name,
            array_filter(['Content-Type' => $file->mime_type ?: 'application/octet-stream']),
        );
    }

    private function abortUnlessTaskAttachment(UserTask $task, ArchiveFile $file): void
    {
        $isAttached = $task->attachmentUsages()
            ->where('archive_file_id', $file->getKey())
            ->exists();

        abort_unless($isAttached, 404);
    }

    /**
     * @return Collection<int, BoardList>
     */
    private function boardLists(string $type): Collection
    {
        $this->boardLists ??= BoardList::query()
            ->whereIn('type', BoardList::Types)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'doc_num', 'type', 'name', 'slug', 'status', 'color', 'position']);

        return $this->boardLists
            ->filter(fn (BoardList $list): bool => $list->type === $type)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(BoardList $list): array
    {
        return [
            'doc_num' => $list->doc_num,
            'type' => $list->type,
            'name' => $list->name,
            'status' => $list->status,
            'status_label' => __("user_tasks.statuses.{$list->status}"),
            'color' => $list->color ?: 'primary',
            'position' => (int) $list->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(UserTask $task, User $viewer): array
    {
        $task->loadMissing($this->itemRelations());

        $assignees = $task->assignees
            ->map(fn (User $user): array => $this->userPayload($user))
            ->values();

        return [
            'doc_num' => $task->doc_num,
            'title' => $task->title,
            'description' => (string) ($task->description ?: ''),
            'description_text' => Str::limit(trim(strip_tags((string) $task->description)), 140),
            'type' => $task->type,
            'type_label' => __("user_tasks.types.{$task->type}"),
            'status' => $task->status,
            'status_label' => __("user_tasks.statuses.{$task->status}"),
            'is_active' => (bool) $task->is_active,
            'board_list_doc_num' => $task->boardList?->doc_num,
            'board_list_label' => $task->boardList?->name ?: __("user_tasks.statuses.{$task->status}"),
            'priority' => $task->priority,
            'priority_label' => $task->priority ? __("user_tasks.priorities.{$task->priority}") : '',
            'color' => $task->color ?: 'primary',
            'assigned_to_doc_num' => $task->assignedTo?->doc_num,
            'assigned_to_label' => $assignees->pluck('label')->implode(', ') ?: ($task->assignedTo instanceof User ? app(UserSelect2Service::class)->label($task->assignedTo) : ''),
            'assignee_doc_nums' => $assignees->pluck('doc_num')->all(),
            'assignees' => $assignees->all(),
            'created_by_label' => $this->userLabel($task->createdBy),
            'assigned_by_label' => $this->userLabel($task->assignedBy),
            'due_at' => $this->dates->formatDateTime($task->due_at, ''),
            'created_at' => $this->dates->formatDateTime($task->created_at, ''),
            'updated_at' => $this->dates->formatDateTime($task->updated_at, ''),
            'attachments_count' => $task->relationLoaded('attachmentUsages')
                ? $task->attachmentUsages->count()
                : null,
            'can_edit' => $this->access->canEdit($viewer, $task),
            'can_delete' => $this->access->canDelete($viewer, $task),
            'can_move' => $this->access->canMove($viewer, $task),
        ];
    }

    /**
     * @return list<string>
     */
    private function itemRelations(): array
    {
        return [
            'assignees:id,name,doc_num,email,avatar',
            'assignedTo:id,name,doc_num,email,avatar',
            'assignedBy:id,name,doc_num',
            'createdBy:id,name,doc_num',
            'boardList:id,doc_num,type,name,slug,status,color,position',
            'attachmentUsages.file:id,doc_num,original_name,disk,path,mime_type,extension,size_bytes',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function userPayload(User $user): array
    {
        return [
            'doc_num' => (string) $user->doc_num,
            'name' => (string) $user->name,
            'label' => app(UserSelect2Service::class)->label($user),
        ];
    }

    private function userLabel(?User $user): string
    {
        if (! $user instanceof User) {
            return '';
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }

    /**
     * @return array<string, string>
     */
    private function boardUserPayload(User $user): array
    {
        return [
            'doc_num' => (string) $user->doc_num,
            'label' => trim(implode(' / ', array_filter([$user->name, $user->doc_num]))),
        ];
    }

    private function recordTaskView(UserTask $task, User $user): void
    {
        if ((int) $task->created_by === (int) $user->getKey()) {
            return;
        }

        MyBoardTaskView::query()->updateOrCreate(
            [
                'user_task_id' => $task->getKey(),
                'user_id' => $user->getKey(),
            ],
            [
                'viewed_at' => now(),
            ],
        );
    }

    private function abortUnlessType(?UserTask $userTask, string $type): void
    {
        if (! $userTask instanceof UserTask) {
            return;
        }

        abort_unless($userTask->type === $type, 404);
    }

    /**
     * @param  array<int, mixed>  $docNums
     * @return list<string>
     */
    private function validatedDocNums(array $docNums): array
    {
        $values = collect($docNums)
            ->map(fn (mixed $docNum): string => trim((string) $docNum))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($values === []) {
            throw ValidationException::withMessages([
                'doc_nums' => __('user_tasks.messages.no_records_selected'),
            ]);
        }

        return $values;
    }

    /**
     * @param  list<string>  $docNums
     */
    private function assertSelectedBoardRecords(User $user, array $docNums, string $type, bool $onlyTrashed = false): void
    {
        $query = $onlyTrashed ? UserTask::onlyTrashed() : UserTask::query();
        $records = $query
            ->whereIn('doc_num', $docNums)
            ->where('type', $type)
            ->with('assignees:id')
            ->get(['id', 'doc_num', 'type', 'assigned_to']);

        if ($records->pluck('doc_num')->unique()->count() !== count($docNums)) {
            throw ValidationException::withMessages([
                'doc_nums' => __('user_tasks.messages.selected_records_unavailable'),
            ]);
        }

        $records->each(function (UserTask $record) use ($user): void {
            if ($this->isTeamBoardRoute()) {
                abort_unless($this->access->canView($user, $record), 404);

                return;
            }

            $this->abortUnlessTableBoardRecord($user, $record);
        });
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

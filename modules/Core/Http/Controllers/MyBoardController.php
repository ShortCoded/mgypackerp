<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\DataTables\TeamBoardReportDataTable;
use Modules\Core\Http\Requests\MoveUserTaskRequest;
use Modules\Core\Http\Requests\StoreUserTaskRequest;
use Modules\Core\Http\Requests\UpdateUserTaskRequest;
use Modules\Core\Models\BoardList;
use Modules\Core\Models\MyBoardTaskComment;
use Modules\Core\Models\MyBoardTaskView;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\UserSelect2Service;
use Modules\Core\Services\UserTaskAccessService;
use Modules\Core\Services\UserTaskService;
use Throwable;

class MyBoardController extends Controller
{
    /**
     * @var Collection<int, BoardList>|null
     */
    private ?Collection $boardLists = null;

    public function __construct(
        private readonly UserTaskService $tasks,
        private readonly UserTaskAccessService $access,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
        private readonly DateFormatService $dates,
        private readonly DocumentNumberService $documentNumbers,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $boardUser = $this->boardUser($request, $user);

        return view('modules.core.my-board.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.my-board.index'),
            'boardConfig' => $this->boardConfig($request, $boardUser),
            'boardUser' => $boardUser,
        ]);
    }

    public function teamBoard(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $this->access->isAdminUserGroup($user)
                && ((bool) $user->can('my_board.tasks.view_all') || (bool) $user->can('my_board.notes.view_all')),
            403,
        );

        return view('modules.core.my-board.team-board', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.tools.team-board.index'),
            'boardConfig' => $this->boardConfig($request, $user, 'team'),
        ]);
    }

    public function teamTasksData(Request $request, TeamBoardReportDataTable $dataTable): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $this->access->isAdminUserGroup($user) && (bool) $user->can('my_board.tasks.view_all'),
            403,
        );

        return $dataTable->json($request, UserTask::TypeTask);
    }

    public function teamNotesData(Request $request, TeamBoardReportDataTable $dataTable): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $this->access->isAdminUserGroup($user) && (bool) $user->can('my_board.notes.view_all'),
            403,
        );

        return $dataTable->json($request, UserTask::TypeNote);
    }

    public function data(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $boardUser = $this->boardUser($request, $user);

        abort_unless($this->access->canViewBoard($user, $boardUser), 403, __('user_tasks.messages.manage_board_forbidden'));

        $items = $this->access
            ->boardQuery($boardUser)
            ->withCount(['comments', 'views'])
            ->get()
            ->map(fn (UserTask $task): array => $this->boardItemPayload($task, $user))
            ->groupBy(fn (array $item): string => (string) ($item['board_list_doc_num'] ?: $item['status']));

        return response()->json([
            'success' => true,
            'data' => [
                'board_user' => $this->boardUserPayload($boardUser),
                'boards' => [
                    UserTask::TypeTask => $this->columns(UserTask::TypeTask, $items),
                    UserTask::TypeNote => $this->columns(UserTask::TypeNote, $items),
                ],
            ],
        ]);
    }

    public function store(StoreUserTaskRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $boardUser = $this->boardUser($request, $user);
        $data = $this->boardValidatedData($request->validated(), $user, $boardUser);

        $result = $this->tasks->create($data, $user);
        $record = $result['record'];
        $record->refresh()->load(['assignees:id,name,doc_num,email,avatar', 'assignedTo:id,name,doc_num,email,avatar', 'assignedBy:id,name,doc_num', 'createdBy:id,name,doc_num', 'boardList:id,doc_num,type,name,slug,status,color,position']);
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
            'data' => [
                'item' => $this->boardItemPayload($record, $user),
            ],
        ], 201);
    }

    public function show(Request $request, UserTask $userTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->canView($user, $userTask), 404);
        $this->recordTaskView($userTask, $user);

        return response()->json([
            'success' => true,
            'data' => [
                'item' => $this->boardItemPayload(
                    $userTask->load([
                        'assignees:id,name,doc_num,email,avatar',
                        'assignedTo:id,name,doc_num,email,avatar',
                        'assignedBy:id,name,doc_num',
                        'createdBy:id,name,doc_num',
                        'boardList:id,doc_num,type,name,slug,status,color,position',
                        'comments.user:id,name,doc_num,avatar',
                        'views.user:id,name,doc_num,avatar',
                    ]),
                    $user,
                    detailed: true,
                ),
            ],
        ]);
    }

    public function update(UpdateUserTaskRequest $request, UserTask $userTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $boardUser = $this->boardUser($request, $user);
        $data = $this->boardValidatedData($request->validated(), $user, $boardUser, $userTask);
        $result = $this->tasks->update($userTask, $data, $user);
        $record = $result['record'];
        $changed = $result['changed'];

        if (! $changed) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
                'data' => [
                    'item' => $this->boardItemPayload($record, $user, detailed: true),
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
            'data' => [
                'item' => $this->boardItemPayload($record, $user, detailed: true),
            ],
        ]);
    }

    public function move(MoveUserTaskRequest $request, UserTask $userTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
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
            'message' => __('user_tasks.messages.board_updated'),
            'data' => [
                'item' => $this->boardItemPayload($record, $user),
            ],
        ]);
    }

    public function destroy(Request $request, UserTask $userTask): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('my_board.delete') || (bool) $request->user()?->can('my_board.manage_any'), 403);

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

    public function storeList(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->isAdminUserGroup($user) && (bool) $user->can('my_board.lists.create'), 403, __('user_tasks.messages.create_list_forbidden'));

        $data = $this->validatedListData($request);
        $list = DB::transaction(function () use ($data, $request): BoardList {
            $document = $this->documentNumbers->next('board_lists', BoardList::class);
            $position = ((int) BoardList::query()->forType($data['type'])->max('position')) + 1;

            return BoardList::query()->create([
                ...$document,
                ...$data,
                'slug' => $this->uniqueListSlug($data['type'], $data['name']),
                'position' => $position,
                'created_by' => $request->user()?->getKey(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.list_saved'),
            'data' => [
                'list' => $this->listPayload($list),
            ],
        ], 201);
    }

    public function updateList(Request $request, BoardList $boardList): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->isAdminUserGroup($user) && (bool) $user->can('my_board.lists.edit'), 403, __('user_tasks.messages.create_list_forbidden'));

        $data = $this->validatedListData($request, $boardList);
        $boardList->forceFill([
            ...$data,
            'slug' => $boardList->is_system ? $boardList->slug : $this->uniqueListSlug($data['type'], $data['name'], $boardList),
            'updated_by' => $request->user()?->getKey(),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.list_saved'),
            'data' => [
                'list' => $this->listPayload($boardList->refresh()),
            ],
        ]);
    }

    public function destroyList(Request $request, BoardList $boardList): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->isAdminUserGroup($user) && (bool) $user->can('my_board.lists.delete'), 403, __('user_tasks.messages.create_list_forbidden'));

        $deleted = DB::transaction(function () use ($boardList, $request): bool {
            /** @var BoardList $lockedList */
            $lockedList = BoardList::query()
                ->whereKey($boardList->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedList->tasks()->exists()) {
                return false;
            }

            $lockedList->forceFill(['deleted_by' => $request->user()?->getKey()])->save();
            $lockedList->delete();

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => __('user_tasks.messages.list_delete_blocked'),
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.list_deleted'),
        ]);
    }

    public function reorderLists(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->isAdminUserGroup($user) && (bool) $user->can('my_board.lists.reorder'), 403);

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(BoardList::Types)],
            'ordered_doc_nums' => ['required', 'array'],
            'ordered_doc_nums.*' => ['string', Rule::exists('board_lists', 'doc_num')->whereNull('deleted_at')],
        ]);

        foreach (array_values($data['ordered_doc_nums']) as $position => $docNum) {
            BoardList::query()
                ->where('type', $data['type'])
                ->where('doc_num', $docNum)
                ->update([
                    'position' => $position,
                    'updated_by' => $request->user()?->getKey(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.board_updated'),
        ]);
    }

    public function allTasks(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->isAdminUserGroup($user) && (bool) $user->can('my_board.tasks.view_all'), 403);

        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in(UserTask::Statuses)],
            'board_list_doc_num' => ['nullable', 'string', Rule::exists('board_lists', 'doc_num')->whereNull('deleted_at')],
            'board_owner_doc_num' => ['nullable', 'string', Rule::exists('users', 'doc_num')->where('status', 'active')->whereNull('deleted_at')],
            'assigned_user_doc_num' => ['nullable', 'string', Rule::exists('users', 'doc_num')->where('status', 'active')->whereNull('deleted_at')],
            'creator_doc_num' => ['nullable', 'string', Rule::exists('users', 'doc_num')->where('status', 'active')->whereNull('deleted_at')],
        ]);

        $boardOwner = $this->userFromDocNum($data['board_owner_doc_num'] ?? null);
        $assignedUser = $this->userFromDocNum($data['assigned_user_doc_num'] ?? null);
        $creator = $this->userFromDocNum($data['creator_doc_num'] ?? null);

        $tasks = UserTask::query()
            ->with([
                'assignees:id,name,doc_num,email,avatar',
                'assignedTo:id,name,doc_num,email,avatar',
                'assignedBy:id,name,doc_num',
                'createdBy:id,name,doc_num',
                'boardList:id,doc_num,type,name,slug,status,color,position',
            ])
            ->withCount(['comments', 'views'])
            ->where('type', UserTask::TypeTask)
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($data['board_list_doc_num'] ?? null, function ($query, string $docNum): void {
                $query->whereHas('boardList', fn ($boardList) => $boardList->where('doc_num', $docNum));
            })
            ->when($boardOwner instanceof User, fn ($query) => $query->where('assigned_to', $boardOwner->getKey()))
            ->when($assignedUser instanceof User, function ($query) use ($assignedUser): void {
                $query->where(function ($inner) use ($assignedUser): void {
                    $inner->where('assigned_to', $assignedUser->getKey())
                        ->orWhereHas('assignees', fn ($assignees) => $assignees->whereKey($assignedUser->getKey()));
                });
            })
            ->when($creator instanceof User, fn ($query) => $query->where('created_by', $creator->getKey()))
            ->latest('updated_at')
            ->latest('created_at')
            ->limit(250)
            ->get()
            ->map(function (UserTask $task) use ($user): array {
                return [
                    ...$this->boardItemPayload($task, $user),
                    'comments_count' => (int) ($task->comments_count ?? 0),
                    'views_count' => (int) ($task->views_count ?? 0),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $tasks,
            ],
        ]);
    }

    public function allNotes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->isAdminUserGroup($user) && (bool) $user->can('my_board.notes.view_all'), 403);

        $data = $request->validate([
            'board_list_doc_num' => ['nullable', 'string', Rule::exists('board_lists', 'doc_num')->whereNull('deleted_at')],
            'board_owner_doc_num' => ['nullable', 'string', Rule::exists('users', 'doc_num')->where('status', 'active')->whereNull('deleted_at')],
            'creator_doc_num' => ['nullable', 'string', Rule::exists('users', 'doc_num')->where('status', 'active')->whereNull('deleted_at')],
        ]);

        $boardOwner = $this->userFromDocNum($data['board_owner_doc_num'] ?? null);
        $creator = $this->userFromDocNum($data['creator_doc_num'] ?? null);

        $notes = UserTask::query()
            ->with([
                'assignees:id,name,doc_num,email,avatar',
                'assignedTo:id,name,doc_num,email,avatar',
                'assignedBy:id,name,doc_num',
                'createdBy:id,name,doc_num',
                'boardList:id,doc_num,type,name,slug,status,color,position',
            ])
            ->withCount(['comments', 'views'])
            ->where('type', UserTask::TypeNote)
            ->when($data['board_list_doc_num'] ?? null, function ($query, string $docNum): void {
                $query->whereHas('boardList', fn ($boardList) => $boardList->where('doc_num', $docNum));
            })
            ->when($boardOwner instanceof User, fn ($query) => $query->where('assigned_to', $boardOwner->getKey()))
            ->when($creator instanceof User, fn ($query) => $query->where('created_by', $creator->getKey()))
            ->latest('updated_at')
            ->latest('created_at')
            ->limit(250)
            ->get()
            ->map(function (UserTask $note) use ($user): array {
                return [
                    ...$this->boardItemPayload($note, $user),
                    'comments_count' => (int) ($note->comments_count ?? 0),
                    'views_count' => (int) ($note->views_count ?? 0),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $notes,
            ],
        ]);
    }

    public function storeComment(Request $request, UserTask $userTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->canView($user, $userTask), 404);
        abort_unless((bool) $user->can('my_board.comments.create'), 403);

        $data = $request->validate([
            'body_html' => ['required', 'string', 'max:20000'],
        ]);
        $body = $this->tasks->sanitizeRichTextContent($data['body_html']);

        if ($body === null) {
            throw ValidationException::withMessages([
                'body_html' => __('validation.required', ['attribute' => __('user_tasks.attributes.comment')]),
            ]);
        }

        $comment = MyBoardTaskComment::query()->create([
            'user_task_id' => $userTask->getKey(),
            'user_id' => $user->getKey(),
            'body_html' => $body,
        ]);

        $userTask->touch();

        $this->logActivity($request, 'user_tasks.comment.create', ActivityLogProperties::crudUpdated(
            'user_tasks',
            $userTask->title,
            $userTask->doc_num,
            ['comment' => ['old' => null, 'new' => strip_tags($body)]],
            $this->tasks->publicProperties($userTask),
        ));

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.comment_created'),
            'data' => [
                'comment' => $this->commentPayload($comment->load('user:id,name,doc_num,avatar'), $user),
            ],
        ], 201);
    }

    public function destroyComment(Request $request, UserTask $userTask, MyBoardTaskComment $comment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->canView($user, $userTask), 404);
        abort_unless((int) $comment->user_task_id === (int) $userTask->getKey(), 404);

        $canDelete = (int) $comment->user_id === (int) $user->getKey()
            || (bool) $user->can('my_board.comments.delete')
            || ($this->access->isAdminUserGroup($user) && (bool) $user->can('my_board.manage_any'));

        abort_unless($canDelete, 403);

        $comment->forceFill(['deleted_by' => $user->getKey()])->save();
        $comment->delete();
        $userTask->touch();

        return response()->json([
            'success' => true,
            'message' => __('user_tasks.messages.comment_deleted'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function boardConfig(Request $request, User $boardUser, string $mode = 'personal'): array
    {
        $user = $request->user();
        $canAssign = $user instanceof User && $this->access->canAssign($user);
        $canSwitchBoardUser = $user instanceof User && $this->access->canSwitchBoardUser($user);

        return [
            'mode' => $mode,
            'direction' => config('languages.available.'.app()->getLocale().'.dir', 'ltr'),
            'actor' => $user instanceof User ? $this->boardUserPayload($user) : null,
            'boardUser' => $this->boardUserPayload($boardUser),
            'boards' => [
                UserTask::TypeTask => $this->boardLists(UserTask::TypeTask)->map(fn (BoardList $list): array => $this->listPayload($list))->values()->all(),
                UserTask::TypeNote => $this->boardLists(UserTask::TypeNote)->map(fn (BoardList $list): array => $this->listPayload($list))->values()->all(),
            ],
            'can' => [
                'view' => (bool) $user?->can('my_board.view'),
                'create' => (bool) $user?->can('my_board.create'),
                'edit' => (bool) $user?->can('my_board.edit'),
                'delete' => (bool) $user?->can('my_board.delete'),
                'clone' => (bool) $user?->can('my_board.clone'),
                'restore' => (bool) $user?->can('my_board.restore'),
                'viewTrashed' => (bool) $user?->can('my_board.view_trashed'),
                'reorder' => (bool) $user?->can('my_board.reorder'),
                'assign' => $canAssign,
                'viewAny' => $canSwitchBoardUser,
                'manageAny' => $canSwitchBoardUser && (bool) $user?->can('my_board.manage_any'),
                'listCreate' => $canSwitchBoardUser && (bool) $user?->can('my_board.lists.create'),
                'listEdit' => $canSwitchBoardUser && (bool) $user?->can('my_board.lists.edit'),
                'listDelete' => $canSwitchBoardUser && (bool) $user?->can('my_board.lists.delete'),
                'listReorder' => $canSwitchBoardUser && (bool) $user?->can('my_board.lists.reorder'),
                'commentCreate' => (bool) $user?->can('my_board.comments.create'),
                'commentDelete' => (bool) $user?->can('my_board.comments.delete'),
                'viewAllTasks' => $canSwitchBoardUser && (bool) $user?->can('my_board.tasks.view_all'),
                'viewAllNotes' => $canSwitchBoardUser && (bool) $user?->can('my_board.notes.view_all'),
            ],
            'urls' => [
                'data' => route('admin.my-board.data'),
                'allTasks' => route('admin.my-board.all-tasks'),
                'allNotes' => route('admin.my-board.all-notes'),
                'store' => route('admin.my-board.store'),
                'show' => route('admin.my-board.show', ['userTask' => '__TASK__']),
                'update' => route('admin.my-board.update', ['userTask' => '__TASK__']),
                'destroy' => route('admin.my-board.destroy', ['userTask' => '__TASK__']),
                'move' => route('admin.my-board.move', ['userTask' => '__TASK__']),
                'commentStore' => route('admin.my-board.comments.store', ['userTask' => '__TASK__']),
                'commentDestroy' => route('admin.my-board.comments.destroy', ['userTask' => '__TASK__', 'comment' => '__COMMENT__']),
                'listStore' => route('admin.my-board.lists.store'),
                'listUpdate' => route('admin.my-board.lists.update', ['boardList' => '__LIST__']),
                'listDestroy' => route('admin.my-board.lists.destroy', ['boardList' => '__LIST__']),
                'listReorder' => route('admin.my-board.lists.reorder'),
                'users' => route('admin.select2.users'),
            ],
            'text' => [
                'addTask' => __('user_tasks.add_task'),
                'addNote' => __('user_tasks.add_note'),
                'addList' => __('user_tasks.add_list'),
                'createTitle' => __('user_tasks.titles.create_modal'),
                'editTitle' => __('user_tasks.titles.edit_modal'),
                'viewTitle' => __('user_tasks.titles.view_modal'),
                'listTitle' => __('user_tasks.titles.list_modal'),
                'delete' => __('user_tasks.actions.delete'),
                'deleteTask' => __('user_tasks.actions.delete_task'),
                'deleteNote' => __('user_tasks.actions.delete_note'),
                'deleteList' => __('user_tasks.actions.delete_list'),
                'hideListTasks' => __('user_tasks.actions.hide_list_tasks'),
                'showListTasks' => __('user_tasks.actions.show_list_tasks'),
                'edit' => __('common.actions.edit'),
                'save' => __('user_tasks.actions.save'),
                'saveTask' => __('user_tasks.actions.save_task'),
                'saveNote' => __('user_tasks.actions.save_note'),
                'cancel' => __('common.actions.cancel'),
                'refresh' => __('user_tasks.actions.refresh'),
                'assignees' => __('user_tasks.attributes.assignees'),
                'comments' => __('user_tasks.attributes.comments'),
                'viewers' => __('user_tasks.attributes.viewers'),
                'teamBoard' => __('user_tasks.team_board'),
                'allTasks' => __('user_tasks.all_tasks'),
                'allNotes' => __('user_tasks.all_notes'),
                'all' => __('user_tasks.filters.all'),
                'boardOwner' => __('user_tasks.attributes.board_owner'),
                'creator' => __('user_tasks.attributes.creator'),
                'createdAt' => __('common.fields.created_at'),
                'updatedAt' => __('common.fields.updated_at'),
                'addComment' => __('user_tasks.actions.add_comment'),
                'saveComment' => __('user_tasks.actions.save_comment'),
                'deleteComment' => __('user_tasks.actions.delete_comment'),
            ],
            'messages' => [
                'emptyTask' => __('user_tasks.empty.tasks'),
                'emptyNote' => __('user_tasks.empty.notes'),
                'hiddenTaskList' => __('user_tasks.empty.hidden_task_list'),
                'emptyComments' => __('user_tasks.empty.comments'),
                'emptyAllTasks' => __('user_tasks.empty.all_tasks'),
                'emptyAllNotes' => __('user_tasks.empty.all_notes'),
                'emptyViewers' => __('user_tasks.empty.viewers'),
                'created' => __('user_tasks.messages.created'),
                'updated' => __('user_tasks.messages.updated'),
                'deleted' => __('user_tasks.messages.deleted'),
                'commentCreated' => __('user_tasks.messages.comment_created'),
                'commentDeleted' => __('user_tasks.messages.comment_deleted'),
                'listDeleted' => __('user_tasks.messages.list_deleted'),
                'boardUpdated' => __('user_tasks.messages.board_updated'),
                'deleteConfirmTitle' => __('user_tasks.messages.delete_confirm_title'),
                'deleteConfirmText' => __('user_tasks.messages.delete_confirm_text'),
                'deleteConfirmYes' => __('user_tasks.messages.delete_confirm_yes'),
                'listDeleteConfirmTitle' => __('user_tasks.messages.list_delete_confirm_title'),
                'listDeleteConfirmText' => __('user_tasks.messages.list_delete_confirm_text'),
                'listDeleteBlocked' => __('user_tasks.messages.list_delete_blocked'),
                'listDeleteBlockedTooltip' => __('user_tasks.messages.list_delete_blocked_tooltip'),
                'validationFailed' => __('common.messages.validation_failed'),
                'unexpectedError' => __('common.messages.unexpected_error'),
                'noChanges' => __('common.messages.no_changes'),
                'summernoteMissing' => __('user_tasks.messages.summernote_missing'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function boardValidatedData(array $data, User $actor, User $boardUser, ?UserTask $existing = null): array
    {
        unset($data['board_user_doc_num']);

        $type = (string) ($data['type'] ?? $existing?->type ?? UserTask::TypeTask);

        if ($type === UserTask::TypeNote) {
            $data['assigned_to_doc_num'] = $boardUser->doc_num;
            $data['assignee_doc_nums'] = [$boardUser->doc_num];
        } elseif (! $this->access->canAssign($actor)) {
            $data['assigned_to_doc_num'] = $existing?->assignedTo?->doc_num ?: $actor->doc_num;
            $data['assignee_doc_nums'] = $existing instanceof UserTask
                ? $existing->assignees->pluck('doc_num')->values()->all()
                : [$actor->doc_num];
        } elseif (isset($data['assignee_doc_nums']) && is_array($data['assignee_doc_nums'])) {
            $assigneeDocNums = collect($data['assignee_doc_nums'])
                ->map(fn (mixed $docNum): string => trim((string) $docNum))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($assigneeDocNums === []) {
                $assigneeDocNums = [$boardUser->doc_num];
            }

            $data['assignee_doc_nums'] = $assigneeDocNums;
            $data['assigned_to_doc_num'] = $assigneeDocNums[0] ?? $boardUser->doc_num;
        } elseif (trim((string) ($data['assigned_to_doc_num'] ?? '')) === '') {
            $data['assigned_to_doc_num'] = $boardUser->doc_num;
            $data['assignee_doc_nums'] = [$boardUser->doc_num];
        } else {
            $data['assignee_doc_nums'] = [$data['assigned_to_doc_num']];
        }

        return $data;
    }

    private function boardUser(Request $request, User $actor): User
    {
        return $this->access->resolveBoardUser(
            $actor,
            $request->input('board_user_doc_num') ?: $request->query('board_user_doc_num'),
            $request->input('board_user_id') ?: $request->query('board_user_id') ?: $request->input('user_id') ?: $request->query('user_id') ?: $request->input('owner_id') ?: $request->query('owner_id'),
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groupedItems
     * @return list<array<string, mixed>>
     */
    private function columns(string $type, $groupedItems): array
    {
        return $this->boardLists($type)
            ->map(fn (BoardList $list): array => [
                ...$this->listPayload($list),
                'items' => $groupedItems->get($list->doc_num, collect())
                    ->merge($groupedItems->get($list->status, collect()))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, BoardList>
     */
    private function boardLists(string $type): Collection
    {
        $this->boardLists ??= BoardList::query()
            ->withCount('tasks')
            ->whereIn('type', BoardList::Types)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $this->boardLists
            ->filter(fn (BoardList $list): bool => $list->type === $type)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(BoardList $list): array
    {
        $tasksCount = $list->getAttribute('tasks_count');

        return [
            'doc_num' => $list->doc_num,
            'type' => $list->type,
            'name' => $this->listLabel($list),
            'raw_name' => $list->name,
            'slug' => $list->slug,
            'status' => $list->status,
            'status_label' => __("user_tasks.statuses.{$list->status}"),
            'color' => $list->color ?: 'primary',
            'position' => (int) $list->position,
            'is_system' => (bool) $list->is_system,
            'items_count' => is_numeric($tasksCount) ? (int) $tasksCount : $list->tasks()->count(),
        ];
    }

    private function listLabel(BoardList $list): string
    {
        return $list->name;
    }

    /**
     * @return array<string, mixed>
     */
    private function boardItemPayload(UserTask $task, User $user, bool $detailed = false): array
    {
        $task->loadMissing([
            'assignees:id,name,doc_num,email,avatar',
            'assignedTo:id,name,doc_num,email,avatar',
            'assignedBy:id,name,doc_num',
            'createdBy:id,name,doc_num',
            'boardList:id,doc_num,type,name,slug,status,color,position',
        ]);

        if ($detailed) {
            $task->loadMissing([
                'comments.user:id,name,doc_num,avatar',
                'views.user:id,name,doc_num,avatar',
            ]);
        }

        $description = (string) ($task->description ?: '');
        $assignees = $task->assignees
            ->map(fn (User $assignee): array => $this->userAvatarPayload($assignee))
            ->values();
        $assigneeLabel = $assignees->pluck('label')->implode(', ');
        $boardOwnerLabel = $task->assignedTo instanceof User ? app(UserSelect2Service::class)->label($task->assignedTo) : '';

        return [
            'doc_num' => $task->doc_num,
            'title' => $task->title,
            'description' => $description,
            'description_text' => Str::limit(trim(strip_tags($description)), 140),
            'type' => $task->type,
            'type_label' => __("user_tasks.types.{$task->type}"),
            'status' => $task->status,
            'status_label' => __("user_tasks.statuses.{$task->status}"),
            'board_list_doc_num' => $task->boardList?->doc_num,
            'board_list_label' => $task->boardList instanceof BoardList ? $this->listLabel($task->boardList) : __("user_tasks.statuses.{$task->status}"),
            'priority' => $task->priority,
            'priority_label' => $task->priority ? __("user_tasks.priorities.{$task->priority}") : '',
            'priority_class' => $task->priority ? $this->priorityColor((string) $task->priority) : 'secondary',
            'color' => $task->color ?: 'primary',
            'board_owner_doc_num' => $task->assignedTo?->doc_num,
            'board_owner_label' => $boardOwnerLabel,
            'assigned_to_doc_num' => $task->assignedTo?->doc_num,
            'assigned_to_label' => $assigneeLabel ?: $boardOwnerLabel,
            'assignee_doc_nums' => $assignees->pluck('doc_num')->all(),
            'assignees' => $assignees->all(),
            'assignee_count' => $assignees->count(),
            'assigned_by_label' => $this->userLabel($task->assignedBy),
            'created_by_label' => $this->userLabel($task->createdBy),
            'start_at' => $this->dates->formatDateTime($task->start_at, ''),
            'due_at' => $this->dates->formatDateTime($task->due_at, ''),
            'completed_at' => $this->dates->formatDateTime($task->completed_at, ''),
            'created_at' => $this->dates->formatDateTime($task->created_at, ''),
            'updated_at' => $this->dates->formatDateTime($task->updated_at, ''),
            'position' => (int) $task->position,
            'can_edit' => $this->access->canEdit($user, $task),
            'can_delete' => $this->access->canDelete($user, $task),
            'can_move' => $this->access->canMove($user, $task),
            'details' => $detailed ? $this->tasks->publicProperties($task) : [],
            'comments' => $detailed ? $task->comments->sortBy('created_at')->map(fn (MyBoardTaskComment $comment): array => $this->commentPayload($comment, $user))->values()->all() : [],
            'viewers' => $detailed ? $task->views->sortByDesc('viewed_at')->map(fn (MyBoardTaskView $view): ?array => $this->viewerPayload($view))->filter()->values()->all() : [],
            'comments_count' => (int) ($task->comments_count ?? $task->comments()->count()),
            'views_count' => (int) ($task->views_count ?? $task->views()->count()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commentPayload(MyBoardTaskComment $comment, User $viewer): array
    {
        $comment->loadMissing('user:id,name,doc_num,avatar');
        $author = $comment->user;

        return [
            'id' => (string) $comment->getKey(),
            'body_html' => (string) $comment->body_html,
            'body_text' => Str::limit(trim(strip_tags((string) $comment->body_html)), 160),
            'created_at' => $this->dates->formatDateTime($comment->created_at, ''),
            'updated_at' => $this->dates->formatDateTime($comment->updated_at, ''),
            'user' => $author instanceof User ? $this->userAvatarPayload($author) : null,
            'can_delete' => (int) $comment->user_id === (int) $viewer->getKey()
                || (bool) $viewer->can('my_board.comments.delete')
                || ($this->access->isAdminUserGroup($viewer) && (bool) $viewer->can('my_board.manage_any')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function viewerPayload(MyBoardTaskView $view): ?array
    {
        $view->loadMissing('user:id,name,doc_num,avatar');

        if (! $view->user instanceof User) {
            return null;
        }

        return [
            ...$this->userAvatarPayload($view->user),
            'viewed_at' => $this->dates->formatDateTime($view->viewed_at, ''),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function userAvatarPayload(User $user): array
    {
        return [
            'doc_num' => (string) $user->doc_num,
            'name' => (string) $user->name,
            'label' => app(UserSelect2Service::class)->label($user),
            'initials' => $this->initials($user->name),
            'avatar_url' => $user->avatar ? asset($user->avatar) : null,
        ];
    }

    private function initials(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $initials = collect($parts)
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : 'U';
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

    private function userFromDocNum(?string $docNum): ?User
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return User::query()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where('doc_num', $docNum)
            ->first();
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

    /**
     * @return array<string, mixed>
     */
    private function validatedListData(Request $request, ?BoardList $list = null): array
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(BoardList::Types)],
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', 'string', Rule::in(UserTask::Statuses)],
            'color' => ['nullable', 'string', Rule::in(UserTask::Colors)],
        ]);

        if ($list instanceof BoardList) {
            $data['type'] = $list->type;
        }

        $data['color'] = $data['color'] ?: 'primary';

        return $data;
    }

    private function uniqueListSlug(string $type, string $name, ?BoardList $ignore = null): string
    {
        $base = Str::slug($name) ?: 'list';
        $slug = $base;
        $counter = 2;

        while (BoardList::query()
            ->where('type', $type)
            ->where('slug', $slug)
            ->when($ignore instanceof BoardList, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function priorityColor(string $priority): string
    {
        return match ($priority) {
            UserTask::PriorityUrgent => 'danger',
            UserTask::PriorityHigh => 'warning',
            UserTask::PriorityLow => 'secondary',
            default => 'info',
        };
    }

    private function userLabel(?User $user): string
    {
        if (! $user instanceof User) {
            return '';
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
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

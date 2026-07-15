<?php

namespace Modules\Core\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\SettingService;
use Modules\Core\Services\UserTaskAccessService;
use Yajra\DataTables\Facades\DataTables;

class MyBoardTableDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly UserTaskAccessService $access,
    ) {}

    public function json(Request $request, User $boardUser, User $viewer, string $type): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $searchColumns = $this->searchColumns();
        $recordFilter = $this->recordFilter($request);
        $query = $this->baseQuery($boardUser, $type, $recordFilter);

        return DataTables::eloquent($query)
            ->filter(function (Builder $query) use ($request, $searchColumns): void {
                $search = $request->input('search.value');
                $terms = $this->searchService->terms(is_string($search) ? $search : null);

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, $searchColumns);
            })
            ->addColumn('checkbox', fn (UserTask $task): string => view('modules.core.my-board.partials.table-checkbox', [
                'task' => $task,
                'viewer' => $viewer,
            ])->render())
            ->editColumn('doc_num', fn (UserTask $task): string => $this->docNumColumn($task, $type, $this->access->canView($viewer, $task)))
            ->editColumn('title', fn (UserTask $task): string => $this->ellipsisText($task->title))
            ->addColumn('description_text', fn (UserTask $task): string => $this->ellipsisText(Str::limit(trim(strip_tags((string) $task->description)), 90)))
            ->addColumn('board_list', fn (UserTask $task): string => $this->plainText($this->boardListLabel($task)))
            ->editColumn('status', fn (UserTask $task): string => $this->badge(__("user_tasks.statuses.{$task->status}"), $this->statusColor((string) $task->status)))
            ->editColumn('priority', fn (UserTask $task): string => $task->priority ? $this->badge(__("user_tasks.priorities.{$task->priority}"), $this->priorityColor((string) $task->priority)) : '')
            ->editColumn('color', fn (UserTask $task): string => $task->color ? $this->badge(__("user_tasks.colors.{$task->color}"), (string) $task->color) : '')
            ->addColumn('assignees', fn (UserTask $task): string => $this->assigneeLabels($task))
            ->editColumn('due_at', fn (UserTask $task): string => $this->plainText($task->due_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('completed_at', fn (UserTask $task): string => $this->plainText($task->completed_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('created_by', fn (UserTask $task): string => $this->ellipsisText($task->created_by_name))
            ->editColumn('created_at', fn (UserTask $task): string => $this->plainText($task->created_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('updated_by', fn (UserTask $task): string => $this->ellipsisText($task->updated_by_name))
            ->editColumn('updated_at', fn (UserTask $task): string => $this->plainText($task->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (UserTask $task): string => view('modules.core.my-board.partials.table-actions', [
                'task' => $task,
                'type' => $type,
                'canView' => $this->access->canView($viewer, $task),
                'canEdit' => $this->access->canEdit($viewer, $task),
                'canDelete' => $this->access->canDelete($viewer, $task),
                'canMove' => $this->access->canMove($viewer, $task),
                'canClone' => $this->access->canClone($viewer, $task),
                'canRestore' => $this->access->canRestore($viewer, $task),
                'routes' => [
                    'show' => $this->routeFor($type, 'show', $task),
                    'edit' => $this->routeFor($type, 'edit', $task),
                    'update' => $this->routeFor($type, 'update', $task),
                    'destroy' => $this->routeFor($type, 'destroy', $task),
                    'status' => $this->routeFor($type, 'status', $task),
                    'clone' => $this->routeFor($type, 'clone', $task),
                    'restore' => $this->routeFor($type, 'restore', $task),
                ],
            ])->render())
            ->orderColumn('doc_num', 'user_tasks.doc_number $1')
            ->orderColumn('title', 'user_tasks.title $1')
            ->orderColumn('description_text', 'user_tasks.description $1')
            ->orderColumn('board_list', 'board_lists.position $1, board_lists.name $1')
            ->orderColumn('status', 'user_tasks.status $1')
            ->orderColumn('priority', 'user_tasks.priority $1')
            ->orderColumn('color', 'user_tasks.color $1')
            ->orderColumn('assignees', 'assigned_users.name $1, assigned_users.doc_num $1')
            ->orderColumn('due_at', 'user_tasks.due_at $1')
            ->orderColumn('completed_at', 'user_tasks.completed_at $1')
            ->orderColumn('created_by', 'created_users.name $1, created_users.doc_num $1')
            ->orderColumn('created_at', 'user_tasks.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1, updated_users.doc_num $1')
            ->orderColumn('updated_at', 'user_tasks.updated_at $1')
            ->removeColumn('id')
            ->removeColumn('doc_number')
            ->removeColumn('description')
            ->removeColumn('board_list_id')
            ->removeColumn('is_active')
            ->removeColumn('assigned_to')
            ->removeColumn('assigned_by')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->removeColumn('deleted_at')
            ->removeColumn('restored_at')
            ->rawColumns(['checkbox', 'doc_num', 'title', 'description_text', 'status', 'priority', 'color', 'assignees', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    /**
     * @return Builder<UserTask>
     */
    private function baseQuery(User $boardUser, string $type, string $recordFilter): Builder
    {
        $query = $this->access
            ->boardQuery($boardUser, $recordFilter)
            ->reorder()
            ->where('user_tasks.type', $type)
            ->leftJoin('board_lists', 'board_lists.id', '=', 'user_tasks.board_list_id')
            ->leftJoin('users as assigned_users', 'assigned_users.id', '=', 'user_tasks.assigned_to')
            ->leftJoin('users as assigned_by_users', 'assigned_by_users.id', '=', 'user_tasks.assigned_by')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'user_tasks.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'user_tasks.updated_by')
            ->select([
                'user_tasks.id',
                'user_tasks.doc_number',
                'user_tasks.doc_num',
                'user_tasks.title',
                'user_tasks.description',
                'user_tasks.type',
                'user_tasks.status',
                'user_tasks.is_active',
                'user_tasks.board_list_id',
                'user_tasks.priority',
                'user_tasks.color',
                'user_tasks.assigned_to',
                'user_tasks.assigned_by',
                'user_tasks.created_by',
                'user_tasks.updated_by',
                'user_tasks.deleted_by',
                'user_tasks.restored_by',
                'user_tasks.due_at',
                'user_tasks.start_at',
                'user_tasks.completed_at',
                'user_tasks.created_at',
                'user_tasks.updated_at',
                'user_tasks.deleted_at',
                'user_tasks.restored_at',
                'board_lists.name as board_list_name',
                'board_lists.status as board_list_status',
                'assigned_users.name as assigned_to_name',
                'assigned_users.doc_num as assigned_to_doc_num',
                'assigned_by_users.name as assigned_by_name',
                'assigned_by_users.doc_num as assigned_by_doc_num',
                'created_users.name as created_by_name',
                'created_users.doc_num as created_by_doc_num',
                'updated_users.name as updated_by_name',
                'updated_users.doc_num as updated_by_doc_num',
            ]);

        return $query;
    }

    private function recordFilter(Request $request): string
    {
        $filter = $request->string('record_filter')->trim()->toString();
        $allowed = ['active', 'inactive'];

        if ($request->user()?->can('my_board.view_trashed')) {
            $allowed[] = 'trashed';
            $allowed[] = 'all';
        }

        return in_array($filter, $allowed, true) ? $filter : 'active';
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(): array
    {
        return [
            'text' => [
                'user_tasks.doc_num',
                'user_tasks.title',
                'user_tasks.description',
                'user_tasks.status',
                'user_tasks.priority',
                'user_tasks.color',
                'board_lists.name',
                'assigned_users.name',
                'assigned_users.doc_num',
                'assigned_by_users.name',
                'assigned_by_users.doc_num',
                'created_users.name',
                'created_users.doc_num',
                'updated_users.name',
                'updated_users.doc_num',
            ],
            'dates' => [
                'user_tasks.due_at',
                'user_tasks.completed_at',
                'user_tasks.created_at',
                'user_tasks.updated_at',
            ],
            'date_text' => [
                'user_tasks.due_at',
                'user_tasks.completed_at',
                'user_tasks.created_at',
                'user_tasks.updated_at',
            ],
        ];
    }

    private function docNumColumn(UserTask $task, string $type, bool $canView): string
    {
        if (! $canView) {
            return sprintf(
                '<span class="fw-semibold text-700 dt-code-value" dir="ltr">%s</span>',
                e((string) $task->doc_num),
            );
        }

        return sprintf(
            '<a class="fw-semibold dt-code-value" href="%s" dir="ltr">%s</a>',
            e($this->routeFor($type, 'show', $task)),
            e((string) $task->doc_num),
        );
    }

    private function boardListLabel(UserTask $task): string
    {
        $label = trim((string) ($task->getAttribute('board_list_name') ?: $task->boardList?->name));

        return $label !== '' ? $label : __("user_tasks.statuses.{$task->status}");
    }

    private function assigneeLabels(UserTask $task): string
    {
        $task->loadMissing(['assignees:id,name,doc_num', 'assignedTo:id,name,doc_num']);

        $users = $task->assignees->isNotEmpty()
            ? $task->assignees
            : collect($task->assignedTo ? [$task->assignedTo] : []);

        return $users
            ->map(fn (User $user): string => sprintf(
                '<div class="lh-sm"><span class="fw-semibold">%s</span><span class="text-600 fs-11 dt-code-value ms-1" dir="ltr">%s</span></div>',
                e((string) $user->name),
                e((string) $user->doc_num),
            ))
            ->implode('');
    }

    private function routeFor(string $type, string $action, UserTask $task): string
    {
        $segment = $type === UserTask::TypeNote ? 'notes' : 'tasks';

        return route("admin.my-board.{$segment}.{$action}", ['userTask' => $task->doc_num]);
    }

    private function badge(string $label, string $color): string
    {
        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($label).'</span>';
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            UserTask::StatusDone => 'success',
            UserTask::StatusInProgress => 'primary',
            UserTask::StatusWaiting => 'warning',
            default => 'secondary',
        };
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
}

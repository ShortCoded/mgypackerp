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
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\SettingService;
use Modules\Core\Services\UserTaskAccessService;
use Yajra\DataTables\Facades\DataTables;

class TeamBoardReportDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly DateFormatService $dates,
        private readonly UserTaskAccessService $access,
    ) {}

    public function json(Request $request, string $type): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $searchColumns = $this->searchColumns();
        $query = $this->baseQuery($request, $type);

        return DataTables::eloquent($query)
            ->filter(function (Builder $query) use ($request, $searchColumns): void {
                $search = $request->input('search.value');
                $terms = $this->searchService->terms(is_string($search) ? $search : null);

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, $searchColumns);
            })
            ->addColumn('checkbox', fn (UserTask $task): string => $this->checkboxColumn($task, $viewer))
            ->editColumn('doc_num', fn (UserTask $task): string => $this->docNumColumn($task, $type, $this->access->canView($viewer, $task)))
            ->editColumn('title', fn (UserTask $task): string => $this->ellipsisText($task->title))
            ->addColumn('description_text', fn (UserTask $task): string => $this->ellipsisText(Str::limit(trim(strip_tags((string) $task->description)), 120)))
            ->addColumn('assignees', fn (UserTask $task): string => $this->assigneeLabels($task))
            ->addColumn('board_list', fn (UserTask $task): string => $this->plainText($this->boardListLabel($task)))
            ->editColumn('status', fn (UserTask $task): string => $this->badge(__("user_tasks.statuses.{$task->status}"), $this->statusColor((string) $task->status)))
            ->editColumn('priority', fn (UserTask $task): string => $task->priority ? $this->badge(__("user_tasks.priorities.{$task->priority}"), $this->priorityColor((string) $task->priority)) : '')
            ->editColumn('color', fn (UserTask $task): string => $task->color ? $this->badge(__("user_tasks.colors.{$task->color}"), (string) $task->color) : '')
            ->editColumn('due_at', fn (UserTask $task): string => $this->plainText($task->due_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('attachments_count', fn (UserTask $task): string => $this->plainText((string) (int) ($task->attachments_count ?? 0)))
            ->editColumn('created_by', fn (UserTask $task): string => $this->userCell($task->created_by_name, $task->created_by_doc_num))
            ->editColumn('created_at', fn (UserTask $task): string => $this->plainText($task->created_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('updated_by', fn (UserTask $task): string => $this->userCell($task->updated_by_name, $task->updated_by_doc_num))
            ->editColumn('updated_at', fn (UserTask $task): string => $this->plainText($task->updated_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('comments_count', fn (UserTask $task): string => $this->plainText((string) (int) ($task->comments_count ?? 0)))
            ->editColumn('views_count', fn (UserTask $task): string => $this->plainText((string) (int) ($task->views_count ?? 0)))
            ->addColumn('actions', fn (UserTask $task): string => view('modules.core.my-board.partials.table-actions', [
                'task' => $task,
                'type' => $type,
                'canView' => $this->access->canView($viewer, $task),
                'canEdit' => $this->access->canEdit($viewer, $task),
                'canDelete' => $this->access->canDelete($viewer, $task),
                'canMove' => false,
                'canClone' => false,
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
            ->orderColumn('assignees', 'assigned_users.name $1, assigned_users.doc_num $1')
            ->orderColumn('board_list', 'board_lists.position $1, board_lists.name $1')
            ->orderColumn('status', 'user_tasks.status $1')
            ->orderColumn('priority', 'user_tasks.priority $1')
            ->orderColumn('color', 'user_tasks.color $1')
            ->orderColumn('due_at', 'user_tasks.due_at $1')
            ->orderColumn('attachments_count', 'attachments_count $1')
            ->orderColumn('created_by', 'created_users.name $1, created_users.doc_num $1')
            ->orderColumn('created_at', 'user_tasks.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1, updated_users.doc_num $1')
            ->orderColumn('updated_at', 'user_tasks.updated_at $1')
            ->orderColumn('comments_count', 'comments_count $1')
            ->orderColumn('views_count', 'views_count $1')
            ->removeColumn('id')
            ->removeColumn('doc_number')
            ->removeColumn('description')
            ->removeColumn('board_list_id')
            ->removeColumn('assigned_to')
            ->removeColumn('assigned_by')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->removeColumn('deleted_at')
            ->removeColumn('restored_at')
            ->rawColumns(['checkbox', 'doc_num', 'title', 'description_text', 'assignees', 'status', 'priority', 'color', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    /**
     * @return Builder<UserTask>
     */
    private function baseQuery(Request $request, string $type): Builder
    {
        $recordFilter = $this->recordFilter($request);
        $query = match ($recordFilter) {
            'trashed' => UserTask::onlyTrashed(),
            'all' => UserTask::withTrashed(),
            default => UserTask::query(),
        };

        $query = $query
            ->with(['assignees:id,name,doc_num,email'])
            ->where('user_tasks.type', $type)
            ->when($recordFilter === 'active', fn (Builder $query): Builder => $query->where('user_tasks.is_active', true))
            ->when($recordFilter === 'inactive', fn (Builder $query): Builder => $query->where('user_tasks.is_active', false))
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
            ])
            ->withCount([
                'comments',
                'views',
                'archiveFileUsages as attachments_count' => fn (Builder $query): Builder => $query
                    ->where('collection', UserTask::AttachmentCollection)
                    ->whereNull('role'),
            ]);

        $this->applyFilters($request, $query, $type);

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
     * @param  Builder<UserTask>  $query
     */
    private function applyFilters(Request $request, Builder $query, string $type): void
    {
        $this->applyUserFilter($query, 'creator_doc_num', 'user_tasks.created_by', $request);

        $status = $request->string('status')->toString();

        if (in_array($status, UserTask::Statuses, true)) {
            $query->where('user_tasks.status', $status);
        }

        $boardListDocNum = trim($request->string('board_list_doc_num')->toString());

        if ($boardListDocNum !== '') {
            $query->where('board_lists.doc_num', $boardListDocNum);
        }

        $priority = $request->string('priority')->toString();

        if ($type === UserTask::TypeTask && in_array($priority, UserTask::Priorities, true)) {
            $query->where('user_tasks.priority', $priority);
        }

        $color = $request->string('color')->toString();

        if (in_array($color, UserTask::Colors, true)) {
            $query->where('user_tasks.color', $color);
        }

        $completion = $request->string('completion')->toString();

        if ($type === UserTask::TypeTask && $completion === 'completed') {
            $query->where('user_tasks.status', UserTask::StatusDone);
        } elseif ($type === UserTask::TypeTask && $completion === 'open') {
            $query->where('user_tasks.status', '!=', UserTask::StatusDone);
        }

        $assignedUserDocNum = trim($request->string('assigned_user_doc_num')->toString());

        if ($type === UserTask::TypeTask && $assignedUserDocNum !== '') {
            $assignedUserId = User::query()
                ->where('doc_num', $assignedUserDocNum)
                ->value('id');

            if ($assignedUserId !== null) {
                $query->where(function (Builder $query) use ($assignedUserId): void {
                    $query
                        ->where('user_tasks.assigned_to', $assignedUserId)
                        ->orWhereHas('assignees', fn (Builder $assignees): Builder => $assignees->whereKey($assignedUserId));
                });
            }
        }

        $this->applyDateRange($query, $request, 'due_at', 'user_tasks.due_at');
        $this->applyDateRange($query, $request, 'created_at', 'user_tasks.created_at');
        $this->applyDateRange($query, $request, 'updated_at', 'user_tasks.updated_at');
    }

    /**
     * @param  Builder<UserTask>  $query
     */
    private function applyUserFilter(Builder $query, string $requestKey, string $column, Request $request): void
    {
        $docNum = trim($request->string($requestKey)->toString());

        if ($docNum === '') {
            return;
        }

        $userId = User::query()
            ->where('doc_num', $docNum)
            ->value('id');

        if ($userId !== null) {
            $query->where($column, $userId);
        }
    }

    /**
     * @param  Builder<UserTask>  $query
     */
    private function applyDateRange(Builder $query, Request $request, string $field, string $column): void
    {
        $from = $this->dates->parseDate($request->string($field.'_from')->toString());
        $to = $this->dates->parseDate($request->string($field.'_to')->toString());

        if ($from !== null) {
            $query->where($column, '>=', $from->startOfDay());
        }

        if ($to !== null) {
            $query->where($column, '<=', $to->endOfDay());
        }
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
                'user_tasks.created_at',
                'user_tasks.updated_at',
            ],
            'date_text' => [
                'user_tasks.due_at',
                'user_tasks.created_at',
                'user_tasks.updated_at',
            ],
        ];
    }

    private function boardListLabel(UserTask $task): string
    {
        $label = trim((string) ($task->getAttribute('board_list_name') ?: $task->boardList?->name));

        return $label !== '' ? $label : __("user_tasks.statuses.{$task->status}");
    }

    private function checkboxColumn(UserTask $task, User $viewer): string
    {
        $canSelect = $task->doc_num !== null
            && ($task->trashed()
                ? $this->access->canRestore($viewer, $task)
                : $this->access->canDelete($viewer, $task));

        if (! $canSelect) {
            return '';
        }

        return sprintf(
            '<div class="form-check mb-0 d-flex align-items-center justify-content-center"><input class="form-check-input js-team-board-row-checkbox js-record-select" type="checkbox" value="%1$s" data-doc-num="%1$s" aria-label="%2$s"></div>',
            e((string) $task->doc_num),
            e(__('user_tasks.select_record', ['record' => $task->doc_num])),
        );
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

    private function assigneeLabels(UserTask $task): string
    {
        $task->loadMissing(['assignees:id,name,doc_num']);

        $users = $task->assignees->isNotEmpty()
            ? $task->assignees
            : collect();

        if ($users->isEmpty()) {
            return $this->userCell($task->assigned_to_name, $task->assigned_to_doc_num);
        }

        return $users
            ->map(fn (User $user): string => $this->userCell($user->name, $user->doc_num))
            ->implode('');
    }

    private function userCell(mixed $name, mixed $docNum): string
    {
        $name = trim((string) $name);
        $docNum = trim((string) $docNum);

        if ($name === '' && $docNum === '') {
            return '';
        }

        return sprintf(
            '<div class="lh-sm"><span class="fw-semibold">%s</span><span class="text-600 fs-11 dt-code-value ms-1" dir="ltr">%s</span></div>',
            e($name),
            e($docNum),
        );
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

    private function routeFor(string $type, string $action, UserTask $task): string
    {
        $segment = $type === UserTask::TypeNote ? 'notes' : 'tasks';

        return route("admin.tools.team-board.{$segment}.{$action}", ['userTask' => $task->doc_num]);
    }
}

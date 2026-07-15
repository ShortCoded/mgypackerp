<?php

namespace Modules\Core\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\QuickTask;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\QuickTaskService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class QuickTasksDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly QuickTaskService $quickTasks,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $searchColumns = $this->searchColumns();
        $trashFilter = $this->trashFilter($request);
        $canView = (bool) $request->user()?->can('quick_tasks.view');
        $actor = $request->user();

        $query = $this->filteredQuery($request, $trashFilter)
            ->leftJoin('task_boards', 'task_boards.id', '=', 'quick_tasks.task_board_id')
            ->leftJoin('users as assigned_users', 'assigned_users.id', '=', 'quick_tasks.assigned_to')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'quick_tasks.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'quick_tasks.updated_by')
            ->leftJoin('users as deleted_users', 'deleted_users.id', '=', 'quick_tasks.deleted_by')
            ->select([
                'quick_tasks.id',
                'quick_tasks.company_id',
                'quick_tasks.branch_id',
                'quick_tasks.task_board_id',
                'quick_tasks.doc_number',
                'quick_tasks.doc_num',
                'quick_tasks.title',
                'quick_tasks.summary',
                'quick_tasks.details',
                'quick_tasks.status',
                'quick_tasks.priority',
                'quick_tasks.assigned_to as assigned_to_id',
                'quick_tasks.created_by as created_by_id',
                'quick_tasks.updated_by as updated_by_id',
                'quick_tasks.deleted_by as deleted_by_id',
                'quick_tasks.created_at',
                'quick_tasks.updated_at',
                'quick_tasks.deleted_at',
                'task_boards.name as task_board_name',
                'task_boards.doc_num as task_board_doc_num',
                'assigned_users.name as assigned_to_name',
                'assigned_users.doc_num as assigned_to_doc_num',
                'created_users.name as created_by_name',
                'created_users.doc_num as created_by_doc_num',
                'updated_users.name as updated_by_name',
                'updated_users.doc_num as updated_by_doc_num',
                'deleted_users.name as deleted_by_name',
                'deleted_users.doc_num as deleted_by_doc_num',
            ])
            ->withCount('attachments');

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $searchColumns): void {
                $terms = $this->searchService->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms !== []) {
                    $this->searchService->applyMultiTermSearch($query, $terms, $searchColumns);
                }
            })
            ->addColumn('checkbox', fn (QuickTask $task): string => view('modules.core.quick-tasks.partials.checkbox', ['task' => $task])->render())
            ->editColumn('doc_num', fn (QuickTask $task): string => $this->docNumColumn($task, $canView))
            ->editColumn('title', fn (QuickTask $task): string => $this->ellipsisText($task->title))
            ->editColumn('summary', fn (QuickTask $task): string => $this->ellipsisText($task->summary))
            ->addColumn('task_board', fn (QuickTask $task): string => $this->taskBoardLabel($task->task_board_name, $task->task_board_doc_num))
            ->editColumn('status', fn (QuickTask $task): string => $this->badge(__("quick_tasks.statuses.{$task->status}"), $this->statusColor((string) $task->status)))
            ->editColumn('priority', fn (QuickTask $task): string => $this->badge(__("quick_tasks.priorities.{$task->priority}"), $this->priorityColor((string) $task->priority)))
            ->addColumn('assigned_to', fn (QuickTask $task): string => $this->userLabel($task->assigned_to_name, $task->assigned_to_doc_num))
            ->addColumn('attachments_count', fn (QuickTask $task): string => $this->attachmentsCount($task))
            ->addColumn('created_by', fn (QuickTask $task): string => $this->userLabel($task->created_by_name, $task->created_by_doc_num))
            ->editColumn('created_at', fn (QuickTask $task): string => $this->plainText($task->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (QuickTask $task): string => $this->userLabel($task->updated_by_name, $task->updated_by_doc_num))
            ->editColumn('updated_at', fn (QuickTask $task): string => $this->plainText($task->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('deleted_by', fn (QuickTask $task): string => $this->userLabel($task->deleted_by_name, $task->deleted_by_doc_num))
            ->editColumn('deleted_at', fn (QuickTask $task): string => $this->plainText($task->deleted_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (QuickTask $task): string => view('modules.core.quick-tasks.partials.actions', [
                'task' => $task,
                'statusActions' => $actor instanceof User ? $this->quickTasks->statusActionOptions($task, $actor) : [],
            ])->render())
            ->orderColumn('doc_num', 'quick_tasks.doc_number $1')
            ->orderColumn('title', 'quick_tasks.title $1')
            ->orderColumn('summary', 'quick_tasks.summary $1')
            ->orderColumn('task_board', 'task_boards.name $1, task_boards.doc_num $1')
            ->orderColumn('status', 'quick_tasks.status $1')
            ->orderColumn('priority', 'quick_tasks.priority $1')
            ->orderColumn('assigned_to', 'assigned_users.name $1, assigned_users.doc_num $1')
            ->orderColumn('created_by', 'created_users.name $1, created_users.doc_num $1')
            ->orderColumn('created_at', 'quick_tasks.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1, updated_users.doc_num $1')
            ->orderColumn('updated_at', 'quick_tasks.updated_at $1')
            ->orderColumn('deleted_by', 'deleted_users.name $1, deleted_users.doc_num $1')
            ->orderColumn('deleted_at', 'quick_tasks.deleted_at $1')
            ->removeColumn(
                'id',
                'company_id',
                'branch_id',
                'task_board_id',
                'doc_number',
                'details',
                'assigned_to_id',
                'created_by_id',
                'updated_by_id',
                'deleted_by_id',
                'task_board_name',
                'task_board_doc_num',
                'assigned_to_name',
                'assigned_to_doc_num',
                'created_by_name',
                'created_by_doc_num',
                'updated_by_name',
                'updated_by_doc_num',
                'deleted_by_name',
                'deleted_by_doc_num',
            )
            ->rawColumns(['checkbox', 'doc_num', 'title', 'summary', 'task_board', 'status', 'priority', 'assigned_to', 'attachments_count', 'created_by', 'updated_by', 'deleted_by', 'actions'])
            ->toJson();
    }

    /**
     * @return Builder<QuickTask>
     */
    private function filteredQuery(Request $request, string $trashFilter): Builder
    {
        $query = match ($trashFilter) {
            'trashed' => QuickTask::onlyTrashed(),
            'all' => QuickTask::withTrashed(),
            default => QuickTask::query(),
        };

        $query = $this->quickTasks->scopeToCurrentContext($query, $request);

        if ($request->user()) {
            $this->quickTasks->scopeToVisibleTaskBoards($query, $request->user());
        }

        $status = $request->string('status_filter')->trim()->toString();

        if (in_array($status, QuickTask::Statuses, true)) {
            $query->where('quick_tasks.status', $status);
        }

        $priority = $request->string('priority_filter')->trim()->toString();

        if (in_array($priority, QuickTask::Priorities, true)) {
            $query->where('quick_tasks.priority', $priority);
        }

        $assignedUserDocNum = $request->string('assigned_user_doc_num')->trim()->toString();

        if ($assignedUserDocNum !== '') {
            $query->where('assigned_users.doc_num', $assignedUserDocNum);
        }

        if ($from = $this->dateFilter($request->input('created_from'))) {
            $query->whereDate('quick_tasks.created_at', '>=', $from->toDateString());
        }

        if ($to = $this->dateFilter($request->input('created_to'))) {
            $query->whereDate('quick_tasks.created_at', '<=', $to->toDateString());
        }

        return $query;
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('quick_tasks.restore')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function dateFilter(mixed $value): ?Carbon
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        return app(DateFormatService::class)->parseDate($value);
    }

    private function docNumColumn(QuickTask $task, bool $canView): string
    {
        $docNum = (string) $task->doc_num;

        if ($docNum === '') {
            return '';
        }

        if (! $canView) {
            return sprintf('<span class="dt-code-value" dir="ltr">%s</span>', e($docNum));
        }

        return sprintf(
            '<a class="fw-semibold dt-code-value" dir="ltr" href="%s">%s</a>',
            e(route('admin.quick-tasks.show', $docNum)),
            e($docNum),
        );
    }

    private function attachmentsCount(QuickTask $task): string
    {
        $count = (int) ($task->attachments_count ?? 0);

        if ($count <= 0) {
            return '<span class="text-600">0</span>';
        }

        return sprintf(
            '<span class="badge rounded-pill badge-subtle-info"><span class="fas fa-paperclip me-1"></span>%d</span>',
            $count,
        );
    }

    private function userLabel(mixed $name, mixed $docNum): string
    {
        $name = trim((string) $name);
        $docNum = trim((string) $docNum);

        if ($name === '' && $docNum === '') {
            return '';
        }

        return sprintf(
            '<div class="lh-sm"><div class="fw-semibold">%s</div><div class="text-600 fs-11 dt-code-value" dir="ltr">%s</div></div>',
            e($name ?: $docNum),
            e($docNum),
        );
    }

    private function taskBoardLabel(mixed $name, mixed $docNum): string
    {
        return $this->userLabel($name, $docNum);
    }

    private function badge(string $label, string $color): string
    {
        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($label).'</span>';
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            QuickTask::StatusInProgress => 'primary',
            QuickTask::StatusReady => 'info',
            QuickTask::StatusDone => 'success',
            QuickTask::StatusCancelled => 'danger',
            default => 'secondary',
        };
    }

    private function priorityColor(string $priority): string
    {
        return match ($priority) {
            QuickTask::PriorityUrgent => 'danger',
            QuickTask::PriorityHigh => 'warning',
            QuickTask::PriorityLow => 'secondary',
            default => 'info',
        };
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(): array
    {
        return [
            'text' => [
                'quick_tasks.doc_num',
                'quick_tasks.title',
                'quick_tasks.summary',
                'quick_tasks.details',
                'quick_tasks.status',
                'quick_tasks.priority',
                'task_boards.name',
                'task_boards.doc_num',
                'assigned_users.name',
                'assigned_users.doc_num',
                'created_users.name',
                'created_users.doc_num',
                'updated_users.name',
                'updated_users.doc_num',
                'deleted_users.name',
                'deleted_users.doc_num',
            ],
            'dates' => [
                'quick_tasks.created_at',
                'quick_tasks.updated_at',
                'quick_tasks.deleted_at',
            ],
            'date_text' => [
                'quick_tasks.created_at',
                'quick_tasks.updated_at',
                'quick_tasks.deleted_at',
            ],
        ];
    }
}

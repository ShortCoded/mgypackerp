<?php

namespace Modules\Core\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class UserTasksDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $searchColumns = $this->searchColumns();
        $trashFilter = $this->trashFilter($request);

        $query = $this->baseQuery($trashFilter)
            ->leftJoin('users as assigned_users', 'assigned_users.id', '=', 'user_tasks.assigned_to')
            ->leftJoin('users as assigned_by_users', 'assigned_by_users.id', '=', 'user_tasks.assigned_by')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'user_tasks.created_by')
            ->select([
                'user_tasks.id',
                'user_tasks.doc_number',
                'user_tasks.doc_num',
                'user_tasks.title',
                'user_tasks.description',
                'user_tasks.type',
                'user_tasks.status',
                'user_tasks.priority',
                'user_tasks.color',
                'user_tasks.due_at',
                'user_tasks.start_at',
                'user_tasks.completed_at',
                'user_tasks.created_at',
                'user_tasks.updated_at',
                'user_tasks.deleted_at',
                'assigned_users.name as assigned_to_name',
                'assigned_users.doc_num as assigned_to_doc_num',
                'assigned_by_users.name as assigned_by_name',
                'assigned_by_users.doc_num as assigned_by_doc_num',
                'created_users.name as created_by_name',
                'created_users.doc_num as created_by_doc_num',
            ]);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $searchColumns): void {
                $search = $request->input('search.value');
                $terms = $this->searchService->terms(is_string($search) ? $search : null);

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, $searchColumns);
            })
            ->addColumn('checkbox', fn (UserTask $task): string => view('modules.core.user-tasks.partials.checkbox', ['task' => $task])->render())
            ->editColumn('doc_num', fn (UserTask $task): string => sprintf(
                '<a class="fw-semibold dt-code-value" dir="ltr" href="%s">%s</a>',
                e(route('admin.tasks.show', $task->doc_num)),
                e((string) $task->doc_num),
            ))
            ->editColumn('title', fn (UserTask $task): string => $this->ellipsisText($task->title))
            ->editColumn('type', fn (UserTask $task): string => $this->badge(__("user_tasks.types.{$task->type}"), $task->type === UserTask::TypeNote ? 'info' : 'primary'))
            ->editColumn('status', fn (UserTask $task): string => $this->badge(__("user_tasks.statuses.{$task->status}"), $this->statusColor((string) $task->status)))
            ->editColumn('priority', fn (UserTask $task): string => $this->badge(__("user_tasks.priorities.{$task->priority}"), $this->priorityColor((string) $task->priority)))
            ->addColumn('assigned_to', fn (UserTask $task): string => $this->userLabel($task->assigned_to_name, $task->assigned_to_doc_num))
            ->addColumn('assigned_by', fn (UserTask $task): string => $this->userLabel($task->assigned_by_name ?: $task->created_by_name, $task->assigned_by_doc_num ?: $task->created_by_doc_num))
            ->editColumn('due_at', fn (UserTask $task): string => $this->plainText($task->due_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('created_at', fn (UserTask $task): string => $this->plainText($task->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (UserTask $task): string => view('modules.core.user-tasks.partials.actions', ['task' => $task])->render())
            ->orderColumn('doc_num', 'user_tasks.doc_number $1')
            ->orderColumn('title', 'user_tasks.title $1')
            ->orderColumn('type', 'user_tasks.type $1')
            ->orderColumn('status', 'user_tasks.status $1')
            ->orderColumn('priority', 'user_tasks.priority $1')
            ->orderColumn('assigned_to', 'assigned_users.name $1, assigned_users.doc_num $1')
            ->orderColumn('assigned_by', 'assigned_by_users.name $1, created_users.name $1')
            ->orderColumn('due_at', 'user_tasks.due_at $1')
            ->orderColumn('created_at', 'user_tasks.created_at $1')
            ->removeColumn('id')
            ->rawColumns(['checkbox', 'doc_num', 'title', 'type', 'status', 'priority', 'assigned_to', 'assigned_by', 'actions'])
            ->toJson();
    }

    /**
     * @return Builder<UserTask>
     */
    private function baseQuery(string $trashFilter): Builder
    {
        return match ($trashFilter) {
            'trashed' => UserTask::onlyTrashed(),
            'all' => UserTask::withTrashed(),
            default => UserTask::query(),
        };
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('tasks.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
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
                'user_tasks.type',
                'user_tasks.status',
                'user_tasks.priority',
                'assigned_users.name',
                'assigned_users.doc_num',
                'assigned_users.email',
                'assigned_by_users.name',
                'assigned_by_users.doc_num',
                'created_users.name',
                'created_users.doc_num',
            ],
            'dates' => [
                'user_tasks.due_at',
                'user_tasks.start_at',
                'user_tasks.completed_at',
                'user_tasks.created_at',
            ],
            'date_text' => [
                'user_tasks.due_at',
                'user_tasks.start_at',
                'user_tasks.completed_at',
                'user_tasks.created_at',
            ],
        ];
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

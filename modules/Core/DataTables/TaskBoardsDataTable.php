<?php

namespace Modules\Core\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\TaskBoard;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\SettingService;
use Modules\Core\Services\TaskBoardAccessService;
use Modules\Core\Services\TaskBoardService;
use Yajra\DataTables\Facades\DataTables;

class TaskBoardsDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly TaskBoardService $taskBoards,
        private readonly TaskBoardAccessService $access,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $searchColumns = $this->searchColumns();
        $recordFilter = $this->recordFilter($request);
        $canView = (bool) $request->user()?->can('task_boards.view');

        $query = $this->filteredQuery($request, $recordFilter)
            ->leftJoin('users as created_users', 'created_users.id', '=', 'task_boards.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'task_boards.updated_by')
            ->leftJoin('users as deleted_users', 'deleted_users.id', '=', 'task_boards.deleted_by')
            ->select([
                'task_boards.id',
                'task_boards.company_id',
                'task_boards.branch_id',
                'task_boards.doc_number',
                'task_boards.doc_num',
                'task_boards.name',
                'task_boards.description',
                'task_boards.is_active',
                'task_boards.is_public',
                'task_boards.requires_password',
                'task_boards.display_theme',
                'task_boards.public_token',
                'task_boards.last_public_access_at',
                'task_boards.created_by as created_by_id',
                'task_boards.updated_by as updated_by_id',
                'task_boards.deleted_by as deleted_by_id',
                'task_boards.created_at',
                'task_boards.updated_at',
                'task_boards.deleted_at',
                'created_users.name as created_by_name',
                'created_users.doc_num as created_by_doc_num',
                'updated_users.name as updated_by_name',
                'updated_users.doc_num as updated_by_doc_num',
                'deleted_users.name as deleted_by_name',
                'deleted_users.doc_num as deleted_by_doc_num',
            ])
            ->withCount(['tasks', 'users', 'roles']);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $searchColumns): void {
                $terms = $this->searchService->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms !== []) {
                    $this->searchService->applyMultiTermSearch($query, $terms, $searchColumns);
                }
            })
            ->addColumn('checkbox', fn (TaskBoard $board): string => view('modules.core.task-boards.partials.checkbox', ['board' => $board])->render())
            ->editColumn('doc_num', fn (TaskBoard $board): string => $this->docNumColumn($board, $canView))
            ->editColumn('name', fn (TaskBoard $board): string => $this->ellipsisText($board->name))
            ->editColumn('description', fn (TaskBoard $board): string => $this->ellipsisText($board->description))
            ->addColumn('public_status', fn (TaskBoard $board): string => $this->publicStatus($board))
            ->addColumn('access_code_status', fn (TaskBoard $board): string => $this->accessCodeStatus($board))
            ->addColumn('assignments', fn (TaskBoard $board): string => $this->assignments($board))
            ->addColumn('tasks_count', fn (TaskBoard $board): string => $this->countBadge((int) ($board->tasks_count ?? 0), 'info'))
            ->addColumn('operational_status', fn (TaskBoard $board): string => $this->operationalStatus($board))
            ->addColumn('created_by', fn (TaskBoard $board): string => $this->auditUser($board->created_by_name, $board->created_by_doc_num))
            ->editColumn('created_at', fn (TaskBoard $board): string => $this->plainText($board->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (TaskBoard $board): string => $this->auditUser($board->updated_by_name, $board->updated_by_doc_num))
            ->editColumn('updated_at', fn (TaskBoard $board): string => $this->plainText($board->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('deleted_by', fn (TaskBoard $board): string => $this->auditUser($board->deleted_by_name, $board->deleted_by_doc_num))
            ->editColumn('deleted_at', fn (TaskBoard $board): string => $this->plainText($board->deleted_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (TaskBoard $board): string => view('modules.core.task-boards.partials.actions', ['board' => $board])->render())
            ->orderColumn('doc_num', 'task_boards.doc_number $1')
            ->orderColumn('name', 'task_boards.name $1')
            ->orderColumn('description', 'task_boards.description $1')
            ->orderColumn('operational_status', 'task_boards.is_active $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'task_boards.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'task_boards.updated_at $1')
            ->orderColumn('deleted_by', 'deleted_users.name $1')
            ->orderColumn('deleted_at', 'task_boards.deleted_at $1')
            ->removeColumn(
                'id',
                'company_id',
                'branch_id',
                'doc_number',
                'is_active',
                'is_public',
                'requires_password',
                'display_theme',
                'public_token',
                'last_public_access_at',
                'created_by_id',
                'updated_by_id',
                'deleted_by_id',
                'created_by_name',
                'created_by_doc_num',
                'updated_by_name',
                'updated_by_doc_num',
                'deleted_by_name',
                'deleted_by_doc_num',
                'users_count',
                'roles_count',
            )
            ->rawColumns(['checkbox', 'doc_num', 'name', 'description', 'public_status', 'access_code_status', 'assignments', 'tasks_count', 'operational_status', 'created_by', 'updated_by', 'deleted_by', 'actions'])
            ->toJson();
    }

    /**
     * @return Builder<TaskBoard>
     */
    private function filteredQuery(Request $request, string $recordFilter): Builder
    {
        $query = match ($recordFilter) {
            'trashed' => TaskBoard::onlyTrashed(),
            'all' => TaskBoard::withTrashed(),
            default => TaskBoard::query(),
        };

        $query = $this->taskBoards->scopeToCurrentContext($query, $request);

        if ($recordFilter === 'active') {
            $query->where('task_boards.is_active', true);
        }

        if ($recordFilter === 'inactive') {
            $query->where('task_boards.is_active', false);
        }

        $user = $request->user();

        if ($user instanceof User) {
            $query = $this->access->scopeVisibleBoards($query, $user, 'task_boards.view');
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function recordFilter(Request $request): string
    {
        $filter = $request->string('record_filter')->trim()->toString();
        $allowed = ['active', 'inactive'];

        if ($request->user()?->can('task_boards.view_trashed')) {
            $allowed[] = 'trashed';
            $allowed[] = 'all';
        }

        return in_array($filter, $allowed, true) ? $filter : 'active';
    }

    private function docNumColumn(TaskBoard $board, bool $canView): string
    {
        $docNum = (string) $board->doc_num;

        if ($docNum === '') {
            return '';
        }

        if (! $canView) {
            return sprintf('<span class="dt-code-value" dir="ltr">%s</span>', e($docNum));
        }

        return sprintf(
            '<a class="fw-semibold dt-code-value" dir="ltr" href="%s">%s</a>',
            e(route('admin.task-boards.show', $docNum)),
            e($docNum),
        );
    }

    private function publicStatus(TaskBoard $board): string
    {
        return $board->is_public
            ? $this->badge(__('task_boards.statuses.public'), 'success')
            : $this->badge(__('task_boards.statuses.private'), 'secondary');
    }

    private function accessCodeStatus(TaskBoard $board): string
    {
        return $board->requires_password
            ? $this->badge(__('task_boards.statuses.requires_access_code'), 'warning')
            : $this->badge(__('task_boards.statuses.no_access_code_required'), 'info');
    }

    private function assignments(TaskBoard $board): string
    {
        return sprintf(
            '<div class="d-flex flex-wrap gap-1">%s%s</div>',
            $this->countBadge((int) ($board->users_count ?? 0), 'primary', __('task_boards.attributes.users')),
            $this->countBadge((int) ($board->roles_count ?? 0), 'info', __('task_boards.attributes.user_groups')),
        );
    }

    private function operationalStatus(TaskBoard $board): string
    {
        return $board->is_active
            ? $this->badge(__('task_boards.statuses.active'), 'success')
            : $this->badge(__('task_boards.statuses.inactive'), 'secondary');
    }

    private function auditUser(mixed $name, mixed $docNum): string
    {
        $label = trim(implode(' / ', array_filter([
            is_string($name) ? $name : null,
            is_string($docNum) ? $docNum : null,
        ])));

        return $this->ellipsisText($label);
    }

    private function countBadge(int $count, string $color, ?string $label = null): string
    {
        $text = $label ? "{$label}: {$count}" : (string) $count;

        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($text).'</span>';
    }

    private function badge(string $label, string $color): string
    {
        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($label).'</span>';
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(): array
    {
        return [
            'text' => [
                'task_boards.doc_num',
                'task_boards.name',
                'task_boards.description',
                'created_users.name',
                'created_users.doc_num',
                'updated_users.name',
                'updated_users.doc_num',
                'deleted_users.name',
                'deleted_users.doc_num',
            ],
            'dates' => [
                'task_boards.created_at',
                'task_boards.updated_at',
                'task_boards.deleted_at',
                'task_boards.last_public_access_at',
            ],
            'date_text' => [
                'task_boards.created_at',
                'task_boards.updated_at',
                'task_boards.deleted_at',
                'task_boards.last_public_access_at',
            ],
        ];
    }
}

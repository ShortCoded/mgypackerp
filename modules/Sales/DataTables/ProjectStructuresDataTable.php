<?php

namespace Modules\Sales\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Sales\Models\ProjectStructure;
use Yajra\DataTables\Facades\DataTables;

class ProjectStructuresDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $query = $this->baseQuery($request)
            ->leftJoin('project_structures as parent_structures', 'parent_structures.id', '=', 'project_structures.parent_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'project_structures.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'project_structures.updated_by')
            ->leftJoin('users as deleted_users', 'deleted_users.id', '=', 'project_structures.deleted_by')
            ->select([
                'project_structures.id',
                'project_structures.company_id',
                'project_structures.parent_id',
                'project_structures.doc_number',
                'project_structures.doc_num',
                'project_structures.name',
                'project_structures.code',
                'project_structures.status',
                'project_structures.notes',
                'project_structures.sort_order',
                'project_structures.created_at',
                'project_structures.updated_at',
                'project_structures.deleted_at',
                'project_structures.restored_at',
                'parent_structures.code as parent_code',
                'parent_structures.name as parent_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
                'deleted_users.name as deleted_by_name',
            ]);
        $canView = (bool) $request->user()?->can('project_structures.view');

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms === []) {
                    return;
                }

                $this->search->applyMultiTermSearch($query, $terms, [
                    'text' => [
                        'project_structures.doc_num',
                        'project_structures.code',
                        'project_structures.name',
                        'project_structures.status',
                        'parent_structures.code',
                        'parent_structures.name',
                        'created_users.name',
                        'updated_users.name',
                        'deleted_users.name',
                    ],
                    'dates' => ['project_structures.created_at', 'project_structures.updated_at', 'project_structures.deleted_at'],
                    'date_text' => ['project_structures.created_at', 'project_structures.updated_at', 'project_structures.deleted_at'],
                ]);
            })
            ->addColumn('checkbox', fn (ProjectStructure $record): string => view('modules.sales.project-structures.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (ProjectStructure $record): string => $this->docNumColumn($record, $canView))
            ->editColumn('name', fn (ProjectStructure $record): string => $this->ellipsisText($record->name))
            ->editColumn('code', fn (ProjectStructure $record): string => '<span class="dt-code-value" dir="ltr">'.e($record->code).'</span>')
            ->addColumn('parent', fn (ProjectStructure $record): string => $this->ellipsisText($this->parentName($record)))
            ->editColumn('status', fn (ProjectStructure $record): string => $this->statusBadge((string) $record->status))
            ->addColumn('created_by', fn (ProjectStructure $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (ProjectStructure $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (ProjectStructure $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->editColumn('updated_at', fn (ProjectStructure $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('deleted_by', fn (ProjectStructure $record): string => $this->ellipsisText($record->deleted_by_name ?: __('common.empty_value')))
            ->editColumn('deleted_at', fn (ProjectStructure $record): string => $this->plainText($record->deleted_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (ProjectStructure $record): string => view('modules.sales.project-structures.partials.actions', ['record' => $record])->render())
            ->orderColumn('doc_num', 'project_structures.doc_number $1')
            ->orderColumn('name', 'project_structures.name $1')
            ->orderColumn('code', 'project_structures.code $1')
            ->orderColumn('parent', 'parent_structures.code $1, parent_structures.name $1')
            ->orderColumn('status', 'project_structures.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'project_structures.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'project_structures.updated_at $1')
            ->orderColumn('deleted_by', 'deleted_users.name $1')
            ->orderColumn('deleted_at', 'project_structures.deleted_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('parent_id')
            ->removeColumn('doc_number')
            ->removeColumn('notes')
            ->removeColumn('sort_order')
            ->removeColumn('restored_at')
            ->removeColumn('parent_code')
            ->removeColumn('parent_name')
            ->removeColumn('created_by_name')
            ->removeColumn('updated_by_name')
            ->removeColumn('deleted_by_name')
            ->rawColumns(['checkbox', 'doc_num', 'name', 'code', 'parent', 'status', 'created_by', 'updated_by', 'deleted_by', 'actions'])
            ->toJson();
    }

    private function baseQuery(Request $request)
    {
        $companyId = $this->companies->currentCompanyId($request);
        $query = match ($this->trashFilter($request)) {
            'trashed' => ProjectStructure::onlyTrashed(),
            'all' => ProjectStructure::withTrashed(),
            default => ProjectStructure::query(),
        };

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->forCompany($companyId);
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('project_structures.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->trim()->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(ProjectStructure $record, bool $canView): string
    {
        if (! $canView) {
            return '<span class="fw-semibold dt-code-value" dir="ltr">'.e((string) $record->doc_num).'</span>';
        }

        return '<a class="fw-semibold dt-code-value" dir="ltr" href="'.e(route('admin.sales.project-structures.show', $record->doc_num)).'">'.e((string) $record->doc_num).'</a>';
    }

    private function parentName(ProjectStructure $record): string
    {
        return ProjectStructure::codeNameLabelFor(
            $record->getAttribute('parent_code'),
            $record->getAttribute('parent_name'),
        ) ?: __('project_structures.attributes.no_parent');
    }

    private function statusBadge(string $status): string
    {
        $class = $status === 'active' ? 'success' : 'secondary';

        return '<span class="badge rounded-pill badge-subtle-'.$class.'">'.e(__("project_structures.statuses.{$status}")).'</span>';
    }
}

<?php

namespace Modules\Sales\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Sales\Models\ProjectStructureModel;
use Yajra\DataTables\Facades\DataTables;

class ProjectStructureModelsDataTable
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
            ->leftJoin('users as created_users', 'created_users.id', '=', 'project_structure_models.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'project_structure_models.updated_by')
            ->leftJoin('users as deleted_users', 'deleted_users.id', '=', 'project_structure_models.deleted_by')
            ->select([
                'project_structure_models.id',
                'project_structure_models.company_id',
                'project_structure_models.doc_number',
                'project_structure_models.doc_num',
                'project_structure_models.name',
                'project_structure_models.code',
                'project_structure_models.short_name',
                'project_structure_models.status',
                'project_structure_models.notes',
                'project_structure_models.created_at',
                'project_structure_models.updated_at',
                'project_structure_models.deleted_at',
                'project_structure_models.restored_at',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
                'deleted_users.name as deleted_by_name',
            ]);
        $canView = (bool) $request->user()?->can('project_structure_models.view');

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms === []) {
                    return;
                }

                $this->search->applyMultiTermSearch($query, $terms, [
                    'text' => [
                        'project_structure_models.doc_num',
                        'project_structure_models.name',
                        'project_structure_models.code',
                        'project_structure_models.short_name',
                        'project_structure_models.status',
                        'created_users.name',
                        'updated_users.name',
                        'deleted_users.name',
                    ],
                    'dates' => ['project_structure_models.created_at', 'project_structure_models.updated_at', 'project_structure_models.deleted_at'],
                    'date_text' => ['project_structure_models.created_at', 'project_structure_models.updated_at', 'project_structure_models.deleted_at'],
                ]);
            })
            ->addColumn('checkbox', fn (ProjectStructureModel $record): string => view('modules.sales.project-structure-models.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (ProjectStructureModel $record): string => $this->docNumColumn($record, $canView))
            ->editColumn('name', fn (ProjectStructureModel $record): string => $this->ellipsisText($record->name))
            ->editColumn('code', fn (ProjectStructureModel $record): string => '<span class="dt-code-value" dir="ltr">'.e($record->code).'</span>')
            ->editColumn('short_name', fn (ProjectStructureModel $record): string => '<span class="dt-code-value" dir="ltr">'.e($record->short_name).'</span>')
            ->editColumn('status', fn (ProjectStructureModel $record): string => $this->statusBadge((string) $record->status))
            ->addColumn('created_by', fn (ProjectStructureModel $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (ProjectStructureModel $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (ProjectStructureModel $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->editColumn('updated_at', fn (ProjectStructureModel $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('deleted_by', fn (ProjectStructureModel $record): string => $this->ellipsisText($record->deleted_by_name ?: __('common.empty_value')))
            ->editColumn('deleted_at', fn (ProjectStructureModel $record): string => $this->plainText($record->deleted_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (ProjectStructureModel $record): string => view('modules.sales.project-structure-models.partials.actions', ['record' => $record])->render())
            ->orderColumn('doc_num', 'project_structure_models.doc_number $1')
            ->orderColumn('name', 'project_structure_models.name $1')
            ->orderColumn('code', 'project_structure_models.code $1')
            ->orderColumn('short_name', 'project_structure_models.short_name $1')
            ->orderColumn('status', 'project_structure_models.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'project_structure_models.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'project_structure_models.updated_at $1')
            ->orderColumn('deleted_by', 'deleted_users.name $1')
            ->orderColumn('deleted_at', 'project_structure_models.deleted_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('doc_number')
            ->removeColumn('notes')
            ->removeColumn('restored_at')
            ->removeColumn('created_by_name')
            ->removeColumn('updated_by_name')
            ->removeColumn('deleted_by_name')
            ->rawColumns(['checkbox', 'doc_num', 'name', 'code', 'short_name', 'status', 'created_by', 'updated_by', 'deleted_by', 'actions'])
            ->toJson();
    }

    private function baseQuery(Request $request)
    {
        $companyId = $this->companies->currentCompanyId($request);
        $query = match ($this->trashFilter($request)) {
            'trashed' => ProjectStructureModel::onlyTrashed(),
            'all' => ProjectStructureModel::withTrashed(),
            default => ProjectStructureModel::query(),
        };

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->forCompany($companyId);
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('project_structure_models.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->trim()->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(ProjectStructureModel $record, bool $canView): string
    {
        if (! $canView) {
            return '<span class="fw-semibold dt-code-value" dir="ltr">'.e((string) $record->doc_num).'</span>';
        }

        return '<a class="fw-semibold dt-code-value" dir="ltr" href="'.e(route('admin.sales.project-structure-models.show', $record->doc_num)).'">'.e((string) $record->doc_num).'</a>';
    }

    private function statusBadge(string $status): string
    {
        $class = $status === 'active' ? 'success' : 'secondary';

        return '<span class="badge rounded-pill badge-subtle-'.$class.'">'.e(__("project_structure_models.statuses.{$status}")).'</span>';
    }
}

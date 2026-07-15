<?php

namespace Modules\Core\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\Branch;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class BranchesDataTable
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
        $canView = (bool) $request->user()?->can('branches.view');

        $query = $this->baseQuery($trashFilter)
            ->leftJoin('companies', 'companies.id', '=', 'branches.company_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'branches.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'branches.updated_by')
            ->select([
                'branches.doc_number',
                'branches.doc_num',
                'branches.deleted_at',
                'branches.company_id',
                'branches.name',
                'branches.type',
                'branches.phone',
                'branches.mobile',
                'branches.email',
                'branches.hotline',
                'branches.status',
                'branches.created_at',
                'branches.updated_at',
                'companies.name as company_name',
                'companies.doc_num as company_doc_num',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
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
            ->addColumn('checkbox', fn (Branch $branch): string => view('modules.core.branches.partials.checkbox', ['branch' => $branch])->render())
            ->editColumn('doc_num', fn (Branch $branch): string => $this->docNumColumn($branch, $canView))
            ->editColumn('name', fn (Branch $branch): string => $this->ellipsisText($branch->name === null ? null : (string) $branch->name))
            ->addColumn('company', fn (Branch $branch): string => $this->ellipsisText(trim(implode(' / ', array_filter([$branch->company_name, $branch->company_doc_num])))))
            ->editColumn('type', fn (Branch $branch): string => $this->badge(__("branches.types.{$branch->type}"), 'info'))
            ->addColumn('contact', fn (Branch $branch): string => $this->ellipsisText(trim(implode(' / ', array_filter([$branch->phone, $branch->mobile, $branch->email])))))
            ->editColumn('status', fn (Branch $branch): string => $this->badge(__("branches.statuses.{$branch->status}"), $branch->status === 'active' ? 'success' : 'secondary'))
            ->addColumn('created_by', fn (Branch $branch): string => $this->ellipsisText($branch->created_by_name))
            ->editColumn('created_at', fn (Branch $branch): string => $this->plainText($branch->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (Branch $branch): string => $this->ellipsisText($branch->updated_by_name))
            ->editColumn('updated_at', fn (Branch $branch): string => $this->plainText($branch->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (Branch $branch): string => view('modules.core.branches.partials.actions', ['branch' => $branch])->render())
            ->orderColumn('doc_num', 'branches.doc_number $1')
            ->orderColumn('name', 'branches.name $1')
            ->orderColumn('company', 'companies.name $1, companies.doc_number $1')
            ->orderColumn('type', 'branches.type $1')
            ->orderColumn('contact', 'branches.phone $1, branches.mobile $1, branches.email $1')
            ->orderColumn('status', 'branches.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'branches.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'branches.updated_at $1')
            ->removeColumn('id')
            ->rawColumns(['checkbox', 'doc_num', 'name', 'company', 'type', 'contact', 'status', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    /**
     * @return Builder<Branch>
     */
    private function baseQuery(string $trashFilter): Builder
    {
        $query = Branch::query();

        return match ($trashFilter) {
            'trashed' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => $query,
        };
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('branches.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->trim()->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(Branch $branch, bool $canView): string
    {
        $docNum = (string) $branch->doc_num;

        if ($docNum === '') {
            return '';
        }

        if (! $canView) {
            return sprintf('<span class="fw-semibold text-700">%s</span>', e($docNum));
        }

        return sprintf(
            '<a class="fw-semibold" href="%s">%s</a>',
            e(route('admin.branches.show', $docNum)),
            e($docNum),
        );
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(): array
    {
        return [
            'text' => [
                'branches.doc_num',
                'branches.name',
                'companies.name',
                'companies.doc_num',
                'branches.type',
                'branches.phone',
                'branches.mobile',
                'branches.email',
                'branches.hotline',
                'branches.contact_person',
                'branches.notes',
                'branches.status',
                'created_users.name',
                'updated_users.name',
            ],
            'dates' => [
                'branches.created_at',
                'branches.updated_at',

            ],
            'date_text' => [
                'branches.created_at',
                'branches.updated_at',

            ],
        ];
    }

    private function badge(string $label, string $color): string
    {
        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($label).'</span>';
    }
}

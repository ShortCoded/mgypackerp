<?php

namespace Modules\Auth\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\RoleService;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class RolesDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly RoleService $roles,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $searchColumns = $this->searchColumns();
        $trashFilter = $this->trashFilter($request);
        $canView = (bool) $request->user()?->can('roles.view');

        $query = $this->baseQuery($trashFilter)
            ->leftJoin('users as created_users', 'created_users.id', '=', 'roles.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'roles.updated_by')
            ->select([
                'roles.id',
                'roles.name',
                'roles.notes',
                'roles.guard_name',
                'roles.doc_number',
                'roles.doc_num',
                'roles.created_at',
                'roles.updated_at',
                'roles.deleted_at',
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
            ->addColumn('checkbox', fn (Role $role): string => view('modules.auth.roles.partials.checkbox', [
                'role' => $role,
                'isProtectedRole' => $this->roles->isProtectedRole($role),
            ])->render())
            ->editColumn('doc_num', fn (Role $role): string => $this->docNumColumn($role, $canView))
            ->editColumn('name', fn (Role $role): string => $this->ellipsisText($role->name))
            ->editColumn('notes', fn (Role $role): string => $this->ellipsisText($role->notes))
            ->addColumn('created_by', fn (Role $role): string => $this->ellipsisText($role->created_by_name))
            ->editColumn('created_at', fn (Role $role): string => $this->plainText($role->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (Role $role): string => $this->ellipsisText($role->updated_by_name))
            ->editColumn('updated_at', fn (Role $role): string => $this->plainText($role->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (Role $role): string => view('modules.auth.roles.partials.actions', [
                'role' => $role,
                'isProtectedRole' => $this->roles->isProtectedRole($role),
            ])->render())
            ->orderColumn('doc_num', 'roles.doc_number $1')
            ->orderColumn('name', 'roles.name $1')
            ->orderColumn('notes', 'roles.notes $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'roles.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'roles.updated_at $1')
            ->removeColumn('id')
            ->removeColumn('guard_name')
            ->rawColumns(['checkbox', 'doc_num', 'name', 'notes', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function baseQuery(string $trashFilter): Builder
    {
        $query = Role::query();

        return match ($trashFilter) {
            'trashed' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => $query,
        };
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('roles.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->trim()->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(Role $role, bool $canView): string
    {
        if (! $canView) {
            return sprintf(
                '<span class="fw-semibold text-700">%s</span>',
                e((string) $role->doc_num),
            );
        }

        return sprintf(
            '<a class="fw-semibold" href="%s">%s</a>',
            e(route('admin.roles.show', $role->doc_num)),
            e((string) $role->doc_num),
        );
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(): array
    {
        return [
            'text' => [
                'roles.doc_num',
                'roles.name',
                'roles.notes',
                'created_users.name',
                'updated_users.name',
            ],
            'dates' => [
                'roles.created_at',
                'roles.updated_at',
            ],
            'date_text' => [
                'roles.created_at',
                'roles.updated_at',
            ],
        ];
    }
}

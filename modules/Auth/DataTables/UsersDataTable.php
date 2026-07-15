<?php

namespace Modules\Auth\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class UsersDataTable
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
        $canView = (bool) $request->user()?->can('users.view');

        $query = $this->baseQuery($trashFilter)
            ->with(['roles:id,name,doc_num'])
            ->leftJoin('users as created_users', 'created_users.id', '=', 'users.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'users.updated_by')
            ->select([
                'users.id',
                'users.name',
                'users.username',
                'users.email',
                'users.phone',
                'users.status',
                'users.notes',
                'users.doc_number',
                'users.doc_num',
                'users.created_at',
                'users.updated_at',
                'users.deleted_at',
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
            ->addColumn('checkbox', fn (User $user): string => view('modules.auth.users.partials.checkbox', compact('user'))->render())
            ->editColumn('doc_num', fn (User $user): string => $this->docNumColumn($user, $canView))
            ->editColumn('name', fn (User $user): string => $this->ellipsisText($user->name))
            ->editColumn('username', fn (User $user): string => $this->ellipsisText($user->username))
            ->editColumn('email', fn (User $user): string => trim(view('components.contact.email-link', [
                'email' => $user->email,
                'class' => 'dt-ellipsis-content',
            ])->render()))
            ->editColumn('phone', fn (User $user): string => trim(view('components.contact.phone-actions', [
                'phone' => $user->phone,
                'class' => 'dt-ellipsis-content',
            ])->render()))
            ->addColumn('roles', fn (User $user): string => view('modules.auth.users.partials.roles-badges', compact('user'))->render())
            ->editColumn('status', fn (User $user): string => view('modules.auth.users.partials.status', compact('user'))->render())
            ->addColumn('created_by', fn (User $user): string => $this->ellipsisText($user->created_by_name))
            ->editColumn('created_at', fn (User $user): string => $this->plainText($user->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (User $user): string => $this->ellipsisText($user->updated_by_name))
            ->editColumn('updated_at', fn (User $user): string => $this->plainText($user->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (User $user): string => view('modules.auth.users.partials.actions', compact('user'))->render())
            ->orderColumn('doc_num', 'users.doc_number $1')
            ->orderColumn('name', 'users.name $1')
            ->orderColumn('username', 'users.username $1')
            ->orderColumn('email', 'users.email $1')
            ->orderColumn('phone', 'users.phone $1')
            ->orderColumn('roles', false)
            ->orderColumn('status', 'users.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'users.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'users.updated_at $1')
            ->removeColumn('id')
            ->rawColumns(['checkbox', 'doc_num', 'name', 'username', 'email', 'phone', 'roles', 'status', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function baseQuery(string $trashFilter): Builder
    {
        $query = User::query();

        return match ($trashFilter) {
            'trashed' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => $query,
        };
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('users.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->trim()->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(User $user, bool $canView): string
    {
        if (! $canView) {
            return sprintf(
                '<span class="fw-semibold text-700">%s</span>',
                e((string) $user->doc_num),
            );
        }

        return sprintf(
            '<a class="fw-semibold" href="%s">%s</a>',
            e(route('admin.users.show', $user->doc_num)),
            e((string) $user->doc_num),
        );
    }

    /**
     * @return array{text: list<string>, exists: list<array<string, mixed>>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(): array
    {
        return [
            'text' => [
                'users.doc_num',
                'users.name',
                'users.username',
                'users.email',
                'users.phone',
                'users.status',
                'users.notes',
                'created_users.name',
                'updated_users.name',
            ],
            'exists' => [
                [
                    'table' => 'model_has_roles',
                    'first' => 'model_has_roles.model_id',
                    'operator' => '=',
                    'second' => 'users.id',
                    'type' => 'exists',
                    'where' => [
                        ['model_has_roles.model_type', '=', User::class],
                    ],
                    'join' => [
                        'table' => 'roles',
                        'first' => 'roles.id',
                        'operator' => '=',
                        'second' => 'model_has_roles.role_id',
                    ],
                    'columns' => [
                        'roles.name',
                        'roles.doc_num',
                    ],
                ],
            ],
            'dates' => [
                'users.created_at',
                'users.updated_at',
            ],
            'date_text' => [
                'users.created_at',
                'users.updated_at',
            ],
        ];
    }
}

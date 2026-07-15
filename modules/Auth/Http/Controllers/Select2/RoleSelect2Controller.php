<?php

namespace Modules\Auth\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Auth\Models\Role;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\Select2ResponseService;

class RoleSelect2Controller extends Controller
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(
            (bool) $request->user()?->can('roles.view')
                || (bool) $request->user()?->can('users.roles.manage')
                || (bool) $request->user()?->can('task_boards.create')
                || (bool) $request->user()?->can('task_boards.update')
                || (bool) $request->user()?->can('task_boards.public_settings'),
            403
        );

        $search = $request->input('q', $request->input('term'));

        $query = Role::query()
            ->select([
                'roles.doc_num',
                'roles.name',
                'roles.notes',
                'roles.doc_number',
            ])
            ->orderBy('roles.name')
            ->orderBy('roles.doc_number');

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'roles.doc_num',
                    'roles.name',
                    'roles.notes',
                ],
            ]);
        }

        return response()->json($this->select2->paginated($query, $request, fn (Role $role): array => [
            'id' => (string) $role->doc_num,
            'text' => (string) $role->name,
        ]));
    }
}

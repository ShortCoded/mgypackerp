<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\BranchSelect2Service;

class BranchSelect2Controller extends Controller
{
    public function __construct(
        private readonly BranchSelect2Service $branches,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->canUseBranches($request), 403);

        return response()->json($this->branches->paginated($request));
    }

    private function canUseBranches(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ($request->string('access_scope')->trim()->toString() === 'operating_scope') {
            return true;
        }

        return (bool) $user?->can('roles.operating_scope.manage')
            || (bool) $user?->can('roles.company_access.manage')
            || (bool) $user?->can('branches.view')
            || (bool) $user?->can('branches.create')
            || (bool) $user?->can('branches.edit')
            || (bool) $user?->can('hr.employees.view')
            || (bool) $user?->can('hr.employees.create')
            || (bool) $user?->can('hr.employees.edit');
    }
}

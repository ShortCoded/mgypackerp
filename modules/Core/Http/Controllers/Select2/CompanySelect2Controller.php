<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\CompanySelect2Service;

class CompanySelect2Controller extends Controller
{
    public function __construct(
        private readonly CompanySelect2Service $companies,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->canUseCompanies($request), 403);

        return response()->json($this->companies->paginated($request));
    }

    private function canUseCompanies(Request $request): bool
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
            || (bool) $user?->can('companies.view')
            || (bool) $user?->can('companies.create')
            || (bool) $user?->can('companies.edit')
            || (bool) $user?->can('branches.view')
            || (bool) $user?->can('branches.create')
            || (bool) $user?->can('branches.edit')
            || (bool) $user?->can('hr.employees.view')
            || (bool) $user?->can('hr.employees.create')
            || (bool) $user?->can('hr.employees.edit');
    }
}

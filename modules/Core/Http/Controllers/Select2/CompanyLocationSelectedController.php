<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyLocationSelect2Service;

class CompanyLocationSelectedController extends Controller
{
    public function __construct(
        private readonly CompanyLocationSelect2Service $locations,
    ) {}

    public function __invoke(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            (bool) $user?->can('companies.view')
            || (bool) $user?->can('companies.edit')
            || (bool) $user?->can('companies.clone'),
            403
        );

        return response()->json($this->locations->selectedForCompany($company));
    }
}

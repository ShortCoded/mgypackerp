<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\CompanyLocationSelect2Service;

class CompanyLocationSelect2Controller extends Controller
{
    public function __construct(
        private readonly CompanyLocationSelect2Service $locations,
    ) {}

    public function countries(Request $request): JsonResponse
    {
        return $this->response($request, 'countries');
    }

    public function governorates(Request $request): JsonResponse
    {
        return $this->response($request, 'governorates');
    }

    public function cities(Request $request): JsonResponse
    {
        return $this->response($request, 'cities');
    }

    public function areas(Request $request): JsonResponse
    {
        return $this->response($request, 'areas');
    }

    private function response(Request $request, string $type): JsonResponse
    {
        abort_unless($this->canUseCompanyLocations($request), 403);

        return response()->json($this->locations->paginated($type, $request));
    }

    private function canUseCompanyLocations(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user?->can('companies.create')
            || (bool) $user?->can('companies.edit')
            || (bool) $user?->can('companies.view')
            || (bool) $user?->can('customers.view')
            || (bool) $user?->can('customers.create')
            || (bool) $user?->can('customers.edit')
            || (bool) $user?->can('suppliers.view')
            || (bool) $user?->can('suppliers.create')
            || (bool) $user?->can('suppliers.edit')
            || (bool) $user?->can('reports.customers.view')
            || (bool) $user?->can('reports.suppliers.view')
            || (bool) $user?->can('reports.sales.sales_orders.view')
            || (bool) $user?->can('reports.purchases.view');
    }
}

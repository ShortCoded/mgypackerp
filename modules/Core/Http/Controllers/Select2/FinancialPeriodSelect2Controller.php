<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\FinancialPeriodSelect2Service;

class FinancialPeriodSelect2Controller extends Controller
{
    public function __construct(
        private readonly FinancialPeriodSelect2Service $periods,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->canUsePeriods($request), 403);

        return response()->json($this->periods->paginated($request));
    }

    private function canUsePeriods(Request $request): bool
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
            || (bool) $user?->can('financial_periods.view')
            || (bool) $user?->can('financial_periods.create')
            || (bool) $user?->can('financial_periods.edit');
    }
}

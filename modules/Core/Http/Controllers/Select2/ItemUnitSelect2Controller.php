<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\ItemUnitSelect2Service;

class ItemUnitSelect2Controller extends Controller
{
    public function __construct(
        private readonly ItemUnitSelect2Service $units,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->canUseItemUnits($request), 403);

        return response()->json($this->units->paginated($request));
    }

    private function canUseItemUnits(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user?->can('item_units.view')
            || (bool) $user?->can('branches.view')
            || (bool) $user?->can('branches.create')
            || (bool) $user?->can('branches.edit')
            || (bool) $user?->can('quotations.view')
            || (bool) $user?->can('quotations.create')
            || (bool) $user?->can('quotations.edit');
    }
}

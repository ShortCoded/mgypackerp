<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\ItemLookupRegistry;
use Modules\Core\Services\ItemLookupSelect2Service;

class ItemLookupSelect2Controller extends Controller
{
    public function __construct(
        private readonly ItemLookupRegistry $registry,
        private readonly ItemLookupSelect2Service $lookups,
    ) {}

    public function __invoke(Request $request, string $lookup): JsonResponse
    {
        $definition = $this->registry->get(str_replace('-', '_', $lookup));

        abort_unless($this->canUseLookup($request, $definition->permissionPrefix), 403);

        return response()->json($this->lookups->paginated($definition, $request));
    }

    private function canUseLookup(Request $request, string $permissionPrefix): bool
    {
        $user = $request->user();

        return (bool) $user?->can("{$permissionPrefix}.view")
            || (bool) $user?->can('products.view')
            || (bool) $user?->can('products.create')
            || (bool) $user?->can('products.edit')
            || (bool) $user?->can('raw_materials.view')
            || (bool) $user?->can('raw_materials.create')
            || (bool) $user?->can('raw_materials.edit')
            || (bool) $user?->can('packaging_materials.view')
            || (bool) $user?->can('packaging_materials.create')
            || (bool) $user?->can('packaging_materials.edit');
    }
}

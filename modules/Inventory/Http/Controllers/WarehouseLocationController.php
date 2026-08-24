<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\WarehouseLocation;

class WarehouseLocationController extends Controller
{
    public function __construct(private readonly OperatingContextService $context) {}

    public function index(Request $request): View
    {
        $context = $this->requiredContext($request);

        return view('modules.inventory.warehouse-locations.index', [
            'stores' => BranchStore::query()->where('branch_id', $context['branch_id'])->orderBy('position')->get(),
            'locations' => WarehouseLocation::query()
                ->whereHas('branchStore', fn ($query) => $query->where('branch_id', $context['branch_id']))
                ->with('branchStore')
                ->orderBy('branch_store_id')
                ->orderBy('position')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $data = $request->validate([
            'branch_store_id' => ['required', 'integer', Rule::exists('branch_stores', 'id')->where('branch_id', $context['branch_id'])],
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'zone_code' => ['nullable', 'string', 'max:80'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
        $request->validate(['code' => [Rule::unique('warehouse_locations')->where('branch_store_id', $data['branch_store_id'])]]);
        $location = WarehouseLocation::query()->create([...$data, 'created_by' => auth()->id()]);

        if ($request->expectsJson()) {
            return response()->json(['data' => ['public_id' => $location->public_id, 'code' => $location->code]], 201);
        }

        return redirect()->route('admin.inventory.warehouse-locations.index')->with('success', __('Warehouse location created.'));
    }

    public function status(Request $request, WarehouseLocation $warehouseLocation): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $warehouseLocation->update(['is_active' => $data['is_active'], 'updated_by' => auth()->id()]);

        if ($request->expectsJson()) {
            return response()->json(['data' => ['public_id' => $warehouseLocation->public_id, 'is_active' => $warehouseLocation->is_active]]);
        }

        return redirect()->route('admin.inventory.warehouse-locations.index')->with('success', __('Warehouse location status updated.'));
    }

    /** @return array<string, mixed> */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['branch_id'], 422, 'Operating context is required.');

        return $context;
    }
}

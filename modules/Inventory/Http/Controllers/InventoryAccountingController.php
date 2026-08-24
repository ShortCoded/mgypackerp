<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Accounting\Services\AccountSelect2Service;
use Modules\Accounting\Services\CostCenterSelect2Service;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Http\Requests\ConfigureInventoryAccountingMappingRequest;
use Modules\Inventory\Services\InventoryAccountingMappingService;

class InventoryAccountingController extends Controller
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly InventoryAccountingMappingService $mappings,
    ) {}

    public function index(): View
    {
        $mapping = $this->mappings->forCompany($this->companies->requireCompanyId());

        return view('modules.inventory.accounting.index', compact('mapping'));
    }

    public function store(ConfigureInventoryAccountingMappingRequest $request): RedirectResponse
    {
        try {
            $this->mappings->save($this->companies->requireCompanyId(), $request->validated());
        } catch (DomainException $exception) {
            return back()->withErrors(['mapping' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', __('inventory_accounting.messages.saved'));
    }

    public function accounts(Request $request, AccountSelect2Service $select2): JsonResponse
    {
        abort_unless($request->user()?->can('inventory.accounting.view'), 403);
        $request->merge(['postable' => true]);

        return response()->json($select2->accounts($request));
    }

    public function costCenters(Request $request, CostCenterSelect2Service $select2): JsonResponse
    {
        abort_unless($request->user()?->can('inventory.accounting.view'), 403);
        $request->merge(['postable' => true]);

        return response()->json($select2->costCenters($request));
    }
}

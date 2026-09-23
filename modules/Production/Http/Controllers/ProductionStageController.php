<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Http\Requests\SaveProductionStageRequest;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Services\ProductionRoutingService;

class ProductionStageController extends Controller
{
    public function __construct(private readonly ProductionRoutingService $routing) {}

    public function index(): View
    {
        return view('modules.production.stages.index');
    }

    public function data(Request $request, ProductionExecutionDataTable $dataTable): JsonResponse
    {
        return $dataTable->stages($request);
    }

    public function create(): View
    {
        return view('modules.production.stages.form', ['stage' => null, 'mode' => 'create']);
    }

    public function edit(ProductionStage $productionStage): View
    {
        return view('modules.production.stages.form', ['stage' => $productionStage, 'mode' => 'edit']);
    }

    public function show(ProductionStage $productionStage): View
    {
        return view('modules.production.stages.form', ['stage' => $productionStage, 'mode' => 'view']);
    }

    public function store(SaveProductionStageRequest $request): RedirectResponse
    {
        return $this->save(fn () => $this->routing->createStage($request->validated()), __('production_execution.messages.stage_created'));
    }

    public function update(SaveProductionStageRequest $request, ProductionStage $productionStage): RedirectResponse
    {
        return $this->save(fn () => $this->routing->updateStage($productionStage, $request->validated()), __('production_execution.messages.stage_updated'));
    }

    public function destroy(ProductionStage $productionStage): JsonResponse
    {
        try {
            $this->routing->deleteStage($productionStage);

            return response()->json(['success' => true, 'message' => __('production_execution.messages.stage_deleted')]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function restore(string $productionStage): JsonResponse
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
        $stage = ProductionStage::onlyTrashed()->forCompany($companyId)->where('public_id', $productionStage)->firstOrFail();
        $stage->restore();
        $stage->update(['deleted_by' => null, 'restored_by' => auth()->id(), 'restored_at' => now()]);

        return response()->json(['success' => true, 'message' => __('production_execution.messages.stage_restored')]);
    }

    private function save(callable $callback, string $message): RedirectResponse
    {
        try {
            $stage = $callback();

            $action = request()->string('submit_action')->toString() ?: request()->string('submit_intent')->toString();
            $redirect = match ($action) {
                'save_view' => redirect()->route('admin.production.stages.show', $stage),
                'save_edit', 'save_and_edit' => redirect()->route('admin.production.stages.edit', $stage),
                'save_new', 'save_and_new' => redirect()->route('admin.production.stages.create'),
                'save_back', 'save_and_back' => redirect()->route('admin.production.stages.index'),
                default => request()->user()?->can('production.stages.edit')
                    ? redirect()->route('admin.production.stages.edit', $stage)
                    : (request()->user()?->can('production.stages.view')
                        ? redirect()->route('admin.production.stages.show', $stage)
                        : redirect()->route('admin.production.stages.index')),
            };

            return $redirect->with('success', $message);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['stage' => $exception->getMessage()]);
        }
    }
}

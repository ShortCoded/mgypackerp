<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\Models\Branch;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Http\Requests\SaveProductionStageRequest;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Services\ProductionRoutingService;

class ProductionStageController extends Controller
{
    public function __construct(
        private readonly ProductionRoutingService $routing,
        private readonly OperatingContextService $context,
    ) {}

    public function index(Request $request): View
    {
        $this->requiredFactoryContext($request);

        return view('modules.production.stages.index');
    }

    public function data(Request $request, ProductionExecutionDataTable $dataTable): JsonResponse
    {
        $this->requiredFactoryContext($request);

        return $dataTable->stages($request);
    }

    public function create(Request $request): View
    {
        $this->requiredFactoryContext($request);

        return view('modules.production.stages.form', ['stage' => null, 'mode' => 'create']);
    }

    public function edit(Request $request, ProductionStage $productionStage): View
    {
        $this->requiredFactoryContext($request);

        return view('modules.production.stages.form', ['stage' => $productionStage, 'mode' => 'edit']);
    }

    public function show(Request $request, ProductionStage $productionStage): View
    {
        $this->requiredFactoryContext($request);

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

    public function restore(Request $request, string $productionStage): JsonResponse
    {
        $context = $this->requiredFactoryContext($request);
        $stage = ProductionStage::onlyTrashed()
            ->forCompany($context['company_id'])
            ->visibleInBranch($context['branch_id'])
            ->where('public_id', $productionStage)
            ->firstOrFail();
        $stage->restore();
        $stage->update([
            'branch_id' => $stage->branch_id ?? $context['branch_id'],
            'deleted_by' => null,
            'restored_by' => auth()->id(),
            'restored_at' => now(),
        ]);

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

    /** @return array{company_id: int, branch_id: int} */
    private function requiredFactoryContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['branch_id'], 409, __('production_execution.messages.operating_context_required'));
        abort_unless(Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeFactory)
            ->exists(), 403, __('production_execution.messages.factory_context_required'));

        return ['company_id' => $context['company_id'], 'branch_id' => $context['branch_id']];
    }
}

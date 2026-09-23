<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Http\Requests\SaveProductProductionRouteRequest;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Models\ProductProductionStage;
use Modules\Production\Services\ProductionRoutingService;

class ProductProductionStageController extends Controller
{
    public function index(Request $request, ProductionExecutionDataTable $dataTable): View|JsonResponse
    {
        if ($request->expectsJson()) {
            return $dataTable->productStages($request);
        }

        $context = $this->requiredFactoryContext($request);

        return view('modules.production.product-stages.index', [
            'stages' => ProductionStage::query()
                ->forCompany($context['company_id'])
                ->visibleInBranch($context['branch_id'])
                ->where('status', ProductionStage::StatusActive)
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function products(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $companyId = $this->requiredFactoryContext($request)['company_id'];
        $query = Product::query()
            ->forCompany($companyId)
            ->active()
            ->whereIn('item_classification', [Product::ClassificationFinishedProduct, Product::ClassificationSemiFinished, Product::ClassificationPackaging])
            ->orderBy('name');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['products.doc_num', 'products.name', 'products.barcode']]);
        }

        return response()->json($select2->paginated($query, $request, fn (Product $product): array => [
            'id' => route('admin.production.product-stages.edit', $product),
            'text' => trim($product->doc_num.' — '.$product->name),
        ]));
    }

    public function edit(Request $request, Product $product): View
    {
        $context = $this->requiredFactoryContext($request);
        abort_unless((int) $product->company_id === $context['company_id'], 404);

        return view('modules.production.product-stages.form', [
            'product' => $product,
            'stages' => ProductionStage::query()->forCompany($context['company_id'])->visibleInBranch($context['branch_id'])->where('status', ProductionStage::StatusActive)->orderBy('display_order')->get(),
            'routeStages' => ProductProductionStage::query()
                ->forCompany($context['company_id'])
                ->where('product_id', $product->getKey())
                ->whereHas('stage', fn ($stages) => $stages->visibleInBranch($context['branch_id']))
                ->with('stage')
                ->orderBy('sequence')
                ->get(),
            'components' => $product->components()->with(['componentProduct', 'productionStage'])->orderBy('id')->get(),
        ]);
    }

    public function update(SaveProductProductionRouteRequest $request, Product $product, ProductionRoutingService $routing): RedirectResponse
    {
        abort_unless((int) $product->company_id === $this->requiredFactoryContext($request)['company_id'], 404);
        try {
            $data = $request->validated();
            $rows = collect($data['selected_stage_ids'])
                ->map(fn (int|string $id): array => ['production_stage_id' => (int) $id, 'sequence' => (int) ($data['stage_sequences'][$id] ?? 1)])
                ->sortBy('sequence')
                ->map(fn (array $row): array => ['production_stage_id' => $row['production_stage_id']])
                ->values()->all();
            $routing->replaceProductRoute($product, $rows, $data['component_stage_ids'] ?? []);

            return redirect()->route('admin.production.product-stages.index')->with('success', __('production_execution.messages.product_route_saved'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['route' => $exception->getMessage()]);
        }
    }

    /** @return array{company_id: int, branch_id: int} */
    private function requiredFactoryContext(Request $request): array
    {
        $context = app(OperatingContextService::class)->snapshot($request);
        abort_unless($context['company_id'] && $context['branch_id'], 409, __('production_execution.messages.operating_context_required'));
        abort_unless(Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeFactory)
            ->exists(), 403, __('production_execution.messages.factory_context_required'));

        return ['company_id' => $context['company_id'], 'branch_id' => $context['branch_id']];
    }
}

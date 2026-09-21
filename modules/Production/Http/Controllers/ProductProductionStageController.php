<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
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

        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($request);

        return view('modules.production.product-stages.index', [
            'stages' => ProductionStage::query()->forCompany($companyId)->where('status', ProductionStage::StatusActive)->orderBy('display_order')->orderBy('name')->get(),
        ]);
    }

    public function products(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($request);
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

    public function edit(Product $product): View
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
        abort_unless((int) $product->company_id === $companyId, 404);

        return view('modules.production.product-stages.form', [
            'product' => $product,
            'stages' => ProductionStage::query()->forCompany($companyId)->where('status', ProductionStage::StatusActive)->orderBy('display_order')->get(),
            'routeStages' => ProductProductionStage::query()->forCompany($companyId)->where('product_id', $product->getKey())->with('stage')->orderBy('sequence')->get(),
            'components' => $product->components()->with(['componentProduct', 'productionStage'])->orderBy('id')->get(),
        ]);
    }

    public function update(SaveProductProductionRouteRequest $request, Product $product, ProductionRoutingService $routing): RedirectResponse
    {
        abort_unless((int) $product->company_id === app(OperatingCompanyContextService::class)->requireCompanyId($request), 404);
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
}

<?php

namespace Modules\Production\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Production\Http\Requests\StoreProductionMaterialSubstitutionRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionMaterialSubstitutionService;

final class ProductionMaterialSubstitutionController
{
    public function __construct(private readonly ProductionMaterialSubstitutionService $substitutions) {}

    public function index(Request $request, ProductionRun $productionRun): View
    {
        $input = $request->validate(['requirement' => ['nullable', 'uuid']]);

        return view('modules.production.runs.material-substitution', $this->substitutions->preview($productionRun, $input['requirement'] ?? null));
    }

    public function products(Request $request, ProductionRun $productionRun, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $input = $request->validate(['requirement' => ['required', 'uuid']]);
        $query = $this->substitutions->replacementQuery($productionRun, $input['requirement']);
        $terms = $search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'name']]);
        }

        return response()->json($select2->paginated($query, $request, fn ($product): array => ['id' => $product->doc_num, 'text' => $product->doc_num.' — '.$product->name]));
    }

    public function store(StoreProductionMaterialSubstitutionRequest $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        try {
            $proposal = $this->substitutions->prepare($productionRun, $request->validated());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['substitution' => $exception->getMessage()]);
        }

        return $this->response($request, $productionRun, $proposal->id, 'prepared');
    }

    public function approve(Request $request, ProductionRun $productionRun, int $substitution): JsonResponse|RedirectResponse
    {
        $data = $request->validate(
            ['recipe_approved' => ['required', 'accepted'], 'recipe_approval_evidence' => ['required', 'string', 'max:2000']],
            [],
            ['recipe_approved' => __('production_material_substitution.technical_approval'), 'recipe_approval_evidence' => __('production_material_substitution.evidence')],
        );
        try {
            $this->substitutions->approve($productionRun, $substitution, $data['recipe_approval_evidence']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['substitution' => $exception->getMessage()]);
        }

        return $this->response($request, $productionRun, $substitution, 'approved');
    }

    public function reject(Request $request, ProductionRun $productionRun, int $substitution): JsonResponse|RedirectResponse
    {
        try {
            $this->substitutions->reject($productionRun, $substitution);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['substitution' => $exception->getMessage()]);
        }

        return $this->response($request, $productionRun, $substitution, 'rejected');
    }

    private function response(Request $request, ProductionRun $run, int $id, string $status): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['success' => true, 'data' => ['substitution_id' => $id, 'status' => $status]])
            : redirect()->route('admin.production.runs.material-substitutions.index', $run)->with('success', __('production_material_substitution.'.$status));
    }
}

<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Http\Requests\StoreProductionMaterialRequest;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionMaterialRequestService;

class ProductionMaterialRequestController extends Controller
{
    public function index(Request $request, ProductionExecutionDataTable $dataTable): View|JsonResponse
    {
        if ($request->expectsJson()) {
            return $dataTable->materialRequests($request);
        }

        $context = app(OperatingContextService::class)->snapshot($request);
        $hasContext = $context['company_id'] && $context['financial_period_id'] && $context['branch_id'];

        $runs = ProductionRun::query()->when($hasContext, fn ($query) => $query
            ->where('company_id', $context['company_id'])->where('financial_period_id', $context['financial_period_id'])->where('branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled])->with('requirements.product')->latest()->get();
        $selectedRun = $request->filled('run') ? $runs->firstWhere('id', (int) $request->integer('run')) : null;

        return view('modules.production.material-requests.index', [
            'runs' => $runs,
            'selectedRun' => $selectedRun,
            'stores' => BranchStore::query()->when($context['branch_id'], fn ($query) => $query->where('branch_id', $context['branch_id']), fn ($query) => $query->whereRaw('1 = 0'))->orderBy('position')->get(),
        ]);
    }

    public function store(StoreProductionMaterialRequest $request, ProductionMaterialRequestService $service): RedirectResponse
    {
        $data = $request->validated();
        $run = ProductionRun::query()->findOrFail($data['production_run_id']);
        $quantities = collect($data['lines'] ?? [])->filter(fn (array $line): bool => filled($line['quantity'] ?? null))->mapWithKeys(fn (array $line): array => [$line['requirement_id'] => $line['quantity']])->all();

        return $this->guard(fn () => $service->create($run, (int) $data['branch_store_id'], $quantities, (bool) ($data['additional'] ?? false), $data['reason'] ?? null, $data['required_by_date'] ?? null));
    }

    public function approve(ProductionMaterialRequest $productionMaterialRequest, ProductionMaterialRequestService $service): JsonResponse
    {
        return $this->jsonGuard(fn () => $service->approve($productionMaterialRequest));
    }

    public function issue(ProductionMaterialRequest $productionMaterialRequest, ProductionMaterialRequestService $service): JsonResponse
    {
        return $this->jsonGuard(fn () => $service->issue($productionMaterialRequest));
    }

    public function allocateShortage(ProductionMaterialRequest $productionMaterialRequest, ProductionMaterialRequestService $service): JsonResponse
    {
        return $this->jsonGuard(fn () => $service->allocateShortage($productionMaterialRequest));
    }

    private function guard(callable $callback): RedirectResponse
    {
        try {
            $callback();

            return redirect()->route('admin.production.material-requests.index')->with('success', __('production_execution.messages.material_request_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['request' => $exception->getMessage()]);
        }
    }

    private function jsonGuard(callable $callback): JsonResponse
    {
        try {
            $record = $callback();

            return response()->json(['success' => true, 'status' => $record->status, 'message' => __('production_execution.messages.operation_completed')]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}

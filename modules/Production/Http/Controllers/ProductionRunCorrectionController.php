<?php

namespace Modules\Production\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Production\Http\Requests\ProposeProductionRunCorrectionRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionRunCorrectionService;

final class ProductionRunCorrectionController
{
    public function __construct(private readonly ProductionRunCorrectionService $corrections) {}

    public function index(ProductionRun $productionRun): View
    {
        return view('modules.production.runs.correction', $this->corrections->preview($productionRun));
    }

    public function store(ProposeProductionRunCorrectionRequest $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        try {
            $proposal = $this->corrections->propose($productionRun, $data['output'], $data['reason'], $data['fingerprint'], $data['posting_date'], $data['receipt_dates'] ?? [], $data['receipt_date_evidence'] ?? null, $data['correction_mode'] ?? 'original_period');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $productionRun, $proposal->id, 'prepared');
    }

    public function approve(Request $request, ProductionRun $productionRun, int $correction): JsonResponse|RedirectResponse
    {
        try {
            $this->corrections->approve($productionRun, $correction);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $productionRun, $correction, 'approved');
    }

    public function reject(Request $request, ProductionRun $productionRun, int $correction): JsonResponse|RedirectResponse
    {
        try {
            $this->corrections->reject($productionRun, $correction);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $productionRun, $correction, 'rejected');
    }

    private function response(Request $request, ProductionRun $run, int $id, string $status): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'data' => ['correction_id' => $id, 'status' => $status]])
            : redirect()->route('admin.production.runs.corrections.index', $run)->with('success', __('production_run_correction.'.$status));
    }
}

<?php

namespace Modules\Production\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\DateFormatService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionReceiptCancellationService;

final class ProductionReceiptCancellationController
{
    public function __construct(private readonly ProductionReceiptCancellationService $cancellations) {}

    public function index(ProductionRun $productionRun, string $receipt): View
    {
        return view('modules.production.runs.receipt-cancellation', $this->cancellations->preview($productionRun, $receipt));
    }

    public function store(Request $request, ProductionRun $productionRun, string $receipt): JsonResponse|RedirectResponse
    {
        if (is_string($request->input('posting_date'))) {
            $request->merge(['posting_date' => app(DateFormatService::class)->normalizeForStorage($request->input('posting_date')) ?? $request->input('posting_date')]);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'fingerprint' => ['required', 'string', 'size:64'],
            'posting_date' => ['required', 'date_format:Y-m-d'], 'correction_mode' => ['required', 'in:original_period,later_period']]);
        try {
            $proposal = $this->cancellations->prepare($productionRun, $receipt, $data['reason'], $data['fingerprint'], $data['posting_date'], $data['correction_mode']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['cancellation' => $exception->getMessage()]);
        }

        return $this->response($request, $productionRun, $receipt, (int) $proposal->id, 'prepared');
    }

    public function approve(Request $request, ProductionRun $productionRun, string $receipt, int $correction): JsonResponse|RedirectResponse
    {
        try {
            $proposal = $this->cancellations->approve($productionRun, $correction, $receipt);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['cancellation' => $exception->getMessage()]);
        }

        return $this->response($request, $productionRun, $receipt, (int) $proposal->id, 'approved');
    }

    private function response(Request $request, ProductionRun $run, string $receipt, int $id, string $status): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'data' => ['correction_id' => $id, 'status' => $status]])
            : redirect()->route('admin.production.runs.receipt-cancellations.index', [$run, $receipt])->with('success', __('production_run_correction.'.$status));
    }
}

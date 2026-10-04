<?php

namespace Modules\Sales\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Sales\Http\Requests\StoreSalesReturnCorrectionRequest;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnCorrection;
use Modules\Sales\Services\SalesReturnCorrectionService;

final class SalesReturnCorrectionController
{
    public function __construct(private readonly SalesReturnCorrectionService $corrections) {}

    public function index(SalesReturn $salesReturn): View
    {
        try {
            $plan = $this->corrections->preview($salesReturn);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return view('modules.sales.cycle.return-corrections', $plan);
    }

    public function store(StoreSalesReturnCorrectionRequest $request, SalesReturn $salesReturn): JsonResponse|RedirectResponse
    {
        try {
            $proposal = $this->corrections->prepare($salesReturn, $request->validated());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $salesReturn, $proposal);
    }

    public function approve(Request $request, SalesReturn $salesReturn, int $correction): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['approval_reason' => ['required', 'string', 'max:3000']]);
        try {
            $proposal = $this->corrections->approve($salesReturn, $correction, $data['approval_reason']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $salesReturn, $proposal);
    }

    public function reject(Request $request, SalesReturn $salesReturn, int $correction): JsonResponse|RedirectResponse
    {
        try {
            $this->corrections->reject($salesReturn, $correction);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['success' => true])
            : redirect()->route('admin.sales.sales-returns.corrections.index', $salesReturn)->with('success', __('sales_return_plan.rejected'));
    }

    private function response(Request $request, SalesReturn $return, SalesReturnCorrection $proposal): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'data' => ['proposal_id' => $proposal->id, 'status' => $proposal->status, 'replacement_return_id' => $proposal->replacement_return_id]])
            : redirect()->route('admin.sales.sales-returns.corrections.index', $return)->with('success', __('sales_return_plan.'.$proposal->status));
    }
}

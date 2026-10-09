<?php

namespace Modules\Purchases\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Production\Services\ProductionInvoiceCostCorrectionService;
use Modules\Purchases\Http\Requests\PurchaseInvoiceStageCostRequest;
use Modules\Purchases\Models\PurchaseInvoice;

final class PurchaseInvoiceStageCostController
{
    public function __construct(private readonly ProductionInvoiceCostCorrectionService $corrections) {}

    public function index(Request $request, PurchaseInvoice $purchaseInvoice): View
    {
        $data = $request->validate(['reverse' => ['nullable', 'boolean']]);
        $reverse = isset($data['reverse']) ? (bool) $data['reverse'] : in_array($purchaseInvoice->status, [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed], true);

        return view('modules.purchases.purchase-invoices.stage-cost-correction', $this->corrections->preview($purchaseInvoice, $reverse));
    }

    public function store(PurchaseInvoiceStageCostRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        try {
            $row = $this->corrections->prepare($purchaseInvoice, (bool) $data['reverse'], $data['reason'], $data['price_evidence'], $data['posting_date'], $data['fingerprint']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $purchaseInvoice, (int) $row->id, 'prepared');
    }

    public function approve(Request $request, PurchaseInvoice $purchaseInvoice, int $correction): JsonResponse|RedirectResponse
    {
        try {
            $this->corrections->approve($purchaseInvoice, $correction);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $purchaseInvoice, $correction, 'approved');
    }

    public function reject(Request $request, PurchaseInvoice $purchaseInvoice, int $correction): JsonResponse|RedirectResponse
    {
        try {
            $this->corrections->reject($purchaseInvoice, $correction);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $purchaseInvoice, $correction, 'rejected');
    }

    private function response(Request $request, PurchaseInvoice $invoice, int $id, string $status): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'data' => ['correction_id' => $id, 'status' => $status]])
            : redirect()->route('admin.purchases.purchase-invoices.stage-cost.index', $invoice)->with('success', __('production_invoice_cost.'.$status));
    }
}

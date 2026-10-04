<?php

namespace Modules\Sales\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Sales\Http\Requests\StoreCustomerInvoiceCorrectionRequest;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceCorrection;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;

final class CustomerInvoiceCorrectionController
{
    public function __construct(private readonly CustomerInvoiceCorrectionService $corrections) {}

    public function index(CustomerInvoice $customerInvoice): View
    {
        try {
            $plan = $this->corrections->preview($customerInvoice);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return view('modules.sales.cycle.invoice-corrections', $plan);
    }

    public function store(StoreCustomerInvoiceCorrectionRequest $request, CustomerInvoice $customerInvoice): JsonResponse|RedirectResponse
    {
        try {
            $proposal = $this->corrections->prepare($customerInvoice, $request->validated());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $customerInvoice, $proposal);
    }

    public function approve(Request $request, CustomerInvoice $customerInvoice, int $correction): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['approval_reason' => ['required', 'string', 'max:3000']]);
        try {
            $proposal = $this->corrections->approve($customerInvoice, $correction, $data['approval_reason']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $customerInvoice, $proposal);
    }

    public function reject(Request $request, CustomerInvoice $customerInvoice, int $correction): JsonResponse|RedirectResponse
    {
        try {
            $this->corrections->reject($customerInvoice, $correction);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['success' => true])
            : redirect()->route('admin.sales.sales-invoices.corrections.index', $customerInvoice)->with('success', __('invoice_correction.rejected'));
    }

    private function response(Request $request, CustomerInvoice $invoice, CustomerInvoiceCorrection $proposal): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'data' => ['proposal_id' => $proposal->id, 'status' => $proposal->status]])
            : redirect()->route('admin.sales.sales-invoices.corrections.index', $invoice)->with('success', __('invoice_correction.'.$proposal->status));
    }
}

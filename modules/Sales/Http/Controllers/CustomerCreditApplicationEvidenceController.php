<?php

namespace Modules\Sales\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Sales\Http\Requests\StoreCustomerCreditApplicationEvidenceRequest;
use Modules\Sales\Models\CustomerCreditApplicationEvidence;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Services\CustomerCreditApplicationEvidenceService;

final class CustomerCreditApplicationEvidenceController
{
    public function __construct(private readonly CustomerCreditApplicationEvidenceService $evidence) {}

    public function index(CustomerInvoice $customerInvoice): View
    {
        try {
            $source = $this->evidence->preview($customerInvoice);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['evidence' => $exception->getMessage()]);
        }

        return view('modules.sales.cycle.credit-application-evidence', $source + ['history' => CustomerCreditApplicationEvidence::query()->with(['preparer', 'approver'])
            ->where('company_id', $customerInvoice->company_id)->where('credit_note_id', $customerInvoice->id)->latest('id')->paginate(20)]);
    }

    public function store(StoreCustomerCreditApplicationEvidenceRequest $request, CustomerInvoice $customerInvoice): JsonResponse|RedirectResponse
    {
        try {
            $evidence = $this->evidence->prepare($customerInvoice, $request->validated());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['evidence' => $exception->getMessage()]);
        }

        return $this->response($request, $customerInvoice, $evidence);
    }

    public function approve(Request $request, CustomerInvoice $customerInvoice, int $evidence): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['approval_reason' => ['required', 'string', 'max:3000']]);
        try {
            $approved = $this->evidence->approve($customerInvoice, $evidence, $data['approval_reason']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['evidence' => $exception->getMessage()]);
        }

        return $this->response($request, $customerInvoice, $approved);
    }

    private function response(Request $request, CustomerInvoice $credit, CustomerCreditApplicationEvidence $evidence): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'data' => ['evidence_id' => $evidence->id, 'status' => $evidence->status]])
            : redirect()->route('admin.sales.sales-invoices.application-evidence.index', $credit)
                ->with('success', __('credit_application_evidence.'.$evidence->status));
    }
}

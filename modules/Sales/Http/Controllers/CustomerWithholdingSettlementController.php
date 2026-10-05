<?php

namespace Modules\Sales\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Sales\Http\Requests\StoreCustomerWithholdingSettlementRequest;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\CustomerWithholdingSettlement;
use Modules\Sales\Services\CustomerWithholdingSettlementService;

final class CustomerWithholdingSettlementController
{
    public function __construct(private readonly CustomerWithholdingSettlementService $settlements) {}

    public function index(Request $request): View
    {
        $company = app(OperatingCompanyContextService::class)->currentCompany();
        $context = app(OperatingContextService::class)->snapshot($request);
        if ($company) {
            try {
                $this->settlements->assertEvidence((int) $company->id);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['withholding' => $exception->getMessage()]);
            }
        }
        $periods = app(OperatingScopeAccessService::class)->allowedFinancialPeriodQuery($request->user(), [$company?->doc_num])
            ->select('financial_periods.id');
        $history = CustomerWithholdingSettlement::query()->with('invoice.customer')
            ->where('company_id', $context['company_id'] ?? 0)->where('branch_id', $context['branch_id'] ?? 0)
            ->whereHas('invoice', fn ($query) => $query->whereIn('financial_period_id', $periods))
            ->latest('id')->paginate(20);

        return view('modules.sales.cycle.withholding-settlements', ['invoice' => null, 'history' => $history, 'source' => null, 'receipts' => collect()]);
    }

    public function show(Request $request, CustomerInvoice $customerInvoice): View
    {
        try {
            $invoice = $this->settlements->forView($customerInvoice);
            $source = null;
            $sourceError = null;
            $selection = $request->validate(['payment_schedule_id' => ['nullable', 'integer', 'min:1'], 'customer_receipt_id' => ['nullable', 'integer', 'min:1']]);
            if (! empty($selection['payment_schedule_id']) && ! empty($selection['customer_receipt_id'])) {
                try {
                    $source = $this->settlements->preview($invoice, (int) $selection['payment_schedule_id'], (int) $selection['customer_receipt_id']);
                } catch (DomainException $exception) {
                    $sourceError = $exception->getMessage();
                }
            }
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['withholding' => $exception->getMessage()]);
        }
        $receipts = CustomerReceipt::query()->where('company_id', $invoice->company_id)->where('branch_id', $invoice->branch_id)
            ->where('customer_id', $invoice->customer_id)->where('currency_id', $invoice->currency_id)
            ->where('status', CustomerReceipt::StatusApproved)->where('receipt_type', CustomerReceipt::TypeCollection)
            ->whereHas('allocations', fn ($query) => $query->where('customer_invoice_id', $invoice->id))
            ->latest('id')->limit(100)->get();
        $history = $invoice->withholdingSettlements()->with(['preparer', 'approver', 'certificate'])->latest('id')->paginate(20);

        return view('modules.sales.cycle.withholding-settlements', compact('invoice', 'source', 'sourceError', 'receipts', 'history'));
    }

    public function store(StoreCustomerWithholdingSettlementRequest $request, CustomerInvoice $customerInvoice): JsonResponse|RedirectResponse
    {
        try {
            $settlement = $this->settlements->prepare($customerInvoice, $request->validated());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['withholding' => $exception->getMessage()]);
        }

        return $this->response($request, $customerInvoice, $settlement);
    }

    public function approve(Request $request, CustomerInvoice $customerInvoice, int $settlement): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['approval_reason' => ['required', 'string', 'max:3000']]);
        try {
            $record = $this->settlements->approve($customerInvoice, $settlement, $data['approval_reason']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['withholding' => $exception->getMessage()]);
        }

        return $this->response($request, $customerInvoice, $record);
    }

    public function reverse(Request $request, CustomerInvoice $customerInvoice, int $settlement): JsonResponse|RedirectResponse
    {
        $request->merge(['posting_date' => app(DateFormatService::class)->normalizeForStorage($request->string('posting_date')->toString())]);
        $data = $request->validate(['posting_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'recovery_reference' => ['required', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:3000'],
            'attachment_doc_nums' => ['required', 'array', 'size:1'], 'attachment_doc_nums.*' => ['required', 'string', 'max:255']]);
        try {
            $record = $this->settlements->reverse($customerInvoice, $settlement, $data);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['withholding' => $exception->getMessage()]);
        }

        return $this->response($request, $customerInvoice, $record);
    }

    public function print(CustomerInvoice $customerInvoice, int $settlement): Response
    {
        $invoice = $this->settlements->forView($customerInvoice);
        $record = $invoice->withholdingSettlements()->with(['preparer', 'approver', 'certificate'])->findOrFail($settlement);

        return app(ReportPdfService::class)->stream('reports.sales.withholding-settlement',
            ['title' => __('sales_ui.wht.title'), 'invoice' => $invoice, 'settlement' => $record], 'withholding-'.$record->doc_num.'.pdf');
    }

    private function response(Request $request, CustomerInvoice $invoice, CustomerWithholdingSettlement $settlement): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['success' => true, 'data' => ['settlement_id' => $settlement->id, 'status' => $settlement->status]])
            : redirect()->route('admin.sales.sales-invoices.withholding.index', $invoice)->with('success', __('sales_ui.wht.'.$settlement->status));
    }
}

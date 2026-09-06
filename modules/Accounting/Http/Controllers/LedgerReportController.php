<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Exports\LedgerReportExport;
use Modules\Accounting\Http\Requests\LedgerReportRequest;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\LedgerQueryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCreditAllocation;
use Modules\Sales\Models\CustomerCreditRefund;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LedgerReportController extends Controller
{
    public function __construct(
        private readonly LedgerQueryService $ledger,
        private readonly OperatingContextService $operatingContext,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function accountLedger(LedgerReportRequest $request): View
    {
        return $this->report($request, 'account_ledger');
    }

    public function customerStatement(LedgerReportRequest $request): View
    {
        return $this->report($request, 'customer_statement');
    }

    public function supplierStatement(LedgerReportRequest $request): View
    {
        return $this->report($request, 'supplier_statement');
    }

    public function export(LedgerReportRequest $request, ReportPdfService $pdf): BinaryFileResponse|Response
    {
        $type = $request->reportType();
        $format = (string) $request->route('ledger_export_format');
        $context = $this->operatingContext->snapshot($request);
        [$result, $selected, $validated] = $this->ledgerResult($request, $type, $context);
        $filename = str_replace('_', '-', $type);

        $this->activityLogger->log($request, 'accounting', "reports.{$type}.export", 'success', [
            'company_id' => $context['company_id'],
            'properties_only' => true,
            'properties' => [
                'format' => $format,
                'subject_doc_num' => $selected['doc_num'],
                'from_date' => $validated['from_date'],
                'to_date' => $validated['to_date'],
            ],
        ]);

        if ($format === 'pdf') {
            return $pdf->stream('reports.ledger', [
                'title' => __('ledger_reports.types.'.$type),
                'type' => $type,
                'result' => $result,
                'selected' => $selected,
            ], "{$filename}.pdf");
        }

        $writer = $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
        $extension = $format === 'csv' ? 'csv' : 'xlsx';

        return Excel::download(new LedgerReportExport($result), "{$filename}.{$extension}", $writer);
    }

    private function report(LedgerReportRequest $request, string $type): View
    {
        $context = $this->operatingContext->snapshot($request);
        $period = $context['financial_period_id'] ? FinancialPeriod::query()->find($context['financial_period_id']) : null;
        $result = null;
        $selected = null;

        if ($request->boolean('run')) {
            [$result, $selected, $validated] = $this->ledgerResult($request, $type, $context);
            $this->activityLogger->log($request, 'accounting', "reports.{$type}.view", 'success', [
                'company_id' => $context['company_id'],
                'properties_only' => true,
                'properties' => [
                    'subject_doc_num' => $selected['doc_num'],
                    'account_doc_num' => $result['account']['doc_num'],
                    'from_date' => $validated['from_date'],
                    'to_date' => $validated['to_date'],
                ],
            ]);
        }

        return view('modules.accounting.reports.ledger', [
            'type' => $type,
            'result' => $result,
            'selected' => $selected,
            'period' => $period,
            'operatingContext' => $this->operatingContext->current($request),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($this->routeName($type)),
            'branches' => $this->branches((int) ($context['company_id'] ?? 0)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: array{doc_num: string, name: string, account: string}, 2: array<string, mixed>}
     */
    private function ledgerResult(LedgerReportRequest $request, string $type, array $context): array
    {
        $validated = $request->validated();
        [$account, $selected] = $this->resolveSubject($type, $validated, (int) $context['company_id']);
        $filters = [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'all_periods' => $type === 'customer_statement' && $request->boolean('all_periods'),
            'account_id' => (int) $account->getKey(),
            'from_date' => $validated['from_date'],
            'to_date' => $validated['to_date'],
            'branch_id' => $this->branchId((int) $context['company_id'], $validated['branch_doc_num'] ?? null),
            'cost_center_id' => $this->costCenterId((int) $context['company_id'], $validated['cost_center_doc_num'] ?? null),
        ];

        $result = $this->ledger->accountLedger($filters);
        if ($type === 'customer_statement') {
            $result['subledger_events'] = $this->customerSubledgerEvents(
                (int) $selected['id'],
                $filters['all_periods'] ? null : (int) $context['financial_period_id'],
                $validated['from_date'],
                $validated['to_date'],
            );
        }

        return [$result, $selected, $validated];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: Account, 1: array{doc_num: string, name: string, account: string}}
     */
    private function resolveSubject(string $type, array $validated, int $companyId): array
    {
        if ($type === 'account_ledger') {
            $account = Account::query()->forCompany($companyId)->where('doc_num', $validated['account_doc_num'])->firstOrFail();

            return [$account, ['id' => $account->getKey(), 'doc_num' => $account->doc_num, 'name' => $account->codeNameLabel(), 'account' => $account->doc_num]];
        }

        $model = $type === 'customer_statement' ? Customer::class : Supplier::class;
        $field = $type === 'customer_statement' ? 'customer_doc_num' : 'supplier_doc_num';
        $party = $model::query()->forCompany($companyId)->active()->where('doc_num', $validated[$field])->firstOrFail();
        $account = Account::query()->forCompany($companyId)->whereKey($party->account_id)->first();

        if (! $account) {
            throw ValidationException::withMessages([$field => __('ledger_reports.messages.partner_account_missing')]);
        }

        return [$account, [
            'id' => $party->getKey(),
            'doc_num' => $party->doc_num,
            'name' => $party->name,
            'account' => $account->codeNameLabel(),
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function customerSubledgerEvents(int $customerId, ?int $periodId, string $fromDate, string $toDate): array
    {
        $invoices = CustomerInvoice::query()
            ->where('customer_id', $customerId)
            ->when($periodId, fn ($query) => $query->where('financial_period_id', $periodId))
            ->where('posting_status', 'posted')
            ->whereBetween('invoice_date', [$fromDate, $toDate])
            ->get()
            ->map(fn (CustomerInvoice $invoice): array => [
                'date' => $invoice->invoice_date?->toDateString(),
                'event' => $invoice->document_type === CustomerInvoice::TypeCreditNote ? 'credit_note' : 'invoice',
                'document' => $invoice->doc_num,
                'related_document' => $invoice->originalInvoice?->doc_num,
                'amount' => (string) $invoice->total_amount,
                'remaining_credit' => $invoice->document_type === CustomerInvoice::TypeCreditNote ? (string) $invoice->credit_available_amount : null,
                'status' => $invoice->status,
                'sort' => 10,
            ]);
        $receipts = CustomerReceipt::query()
            ->where('customer_id', $customerId)
            ->when($periodId, fn ($query) => $query->where('financial_period_id', $periodId))
            ->where('status', CustomerReceipt::StatusApproved)
            ->whereBetween('receipt_date', [$fromDate, $toDate])
            ->get()
            ->map(fn (CustomerReceipt $receipt): array => [
                'date' => $receipt->receipt_date?->toDateString(), 'event' => 'payment',
                'document' => $receipt->doc_num, 'related_document' => $receipt->order?->doc_num,
                'amount' => (string) $receipt->amount, 'remaining_credit' => null,
                'status' => $receipt->status, 'sort' => 20,
            ]);
        $allocations = CustomerCreditAllocation::query()
            ->with(['creditNote', 'targetInvoice'])
            ->where('customer_id', $customerId)
            ->when($periodId, fn ($query) => $query->where('financial_period_id', $periodId))
            ->whereBetween('allocation_date', [$fromDate, $toDate])
            ->get()
            ->map(fn (CustomerCreditAllocation $allocation): array => [
                'date' => $allocation->allocation_date?->toDateString(), 'event' => 'credit_allocation',
                'document' => $allocation->creditNote?->doc_num, 'related_document' => $allocation->targetInvoice?->doc_num,
                'amount' => (string) $allocation->amount, 'remaining_credit' => (string) $allocation->creditNote?->credit_available_amount,
                'status' => $allocation->status, 'sort' => 30,
            ]);
        $refunds = CustomerCreditRefund::query()
            ->with('creditNote')
            ->where('customer_id', $customerId)
            ->when($periodId, fn ($query) => $query->where('financial_period_id', $periodId))
            ->whereBetween('refund_date', [$fromDate, $toDate])
            ->get()
            ->map(fn (CustomerCreditRefund $refund): array => [
                'date' => $refund->refund_date?->toDateString(), 'event' => 'credit_refund',
                'document' => $refund->doc_num, 'related_document' => $refund->creditNote?->doc_num,
                'amount' => (string) $refund->amount, 'remaining_credit' => (string) $refund->creditNote?->credit_available_amount,
                'status' => $refund->status, 'sort' => 40,
            ]);

        return $invoices->concat($receipts)->concat($allocations)->concat($refunds)
            ->sortBy([['date', 'asc'], ['sort', 'asc']])
            ->values()
            ->all();
    }

    private function branchId(int $companyId, mixed $docNum): ?int
    {
        return is_string($docNum) && $docNum !== ''
            ? (int) Branch::query()->where('company_id', $companyId)->where('doc_num', $docNum)->value('id')
            : null;
    }

    private function costCenterId(int $companyId, mixed $docNum): ?int
    {
        return is_string($docNum) && $docNum !== ''
            ? (int) CostCenter::query()->forCompany($companyId)->where('doc_num', $docNum)->value('id')
            : null;
    }

    /**
     * @return list<Branch>
     */
    private function branches(int $companyId): array
    {
        return Branch::query()->where('company_id', $companyId)->active()->orderBy('name')->get()->all();
    }

    private function routeName(string $type): string
    {
        return match ($type) {
            'customer_statement' => 'admin.accounting.reports.customer-statement',
            'supplier_statement' => 'admin.accounting.reports.supplier-statement',
            default => 'admin.accounting.reports.account-ledger',
        };
    }
}

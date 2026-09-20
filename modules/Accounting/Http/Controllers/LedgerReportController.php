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

    public function generalJournal(LedgerReportRequest $request): View
    {
        $context = $this->operatingContext->snapshot($request);
        $period = $context['financial_period_id'] ? FinancialPeriod::query()->find($context['financial_period_id']) : null;
        $result = null;
        $selectedAccount = null;

        if ($request->boolean('run')) {
            [$result, $selectedAccount, $validated] = $this->generalJournalResult($request, $context);
            $this->activityLogger->log($request, 'accounting', 'reports.general_journal.view', 'success', [
                'company_id' => $context['company_id'],
                'properties_only' => true,
                'properties' => [
                    'account_doc_num' => $selectedAccount['doc_num'] ?? null,
                    'from_date' => $validated['from_date'],
                    'to_date' => $validated['to_date'],
                ],
            ]);
        }

        return view('modules.accounting.reports.general-journal', [
            'result' => $result,
            'selectedAccount' => $selectedAccount,
            'period' => $period,
            'operatingContext' => $this->operatingContext->current($request),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.accounting.reports.general-journal'),
            'branches' => $this->branches((int) ($context['company_id'] ?? 0)),
        ]);
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

        if ($type === 'general_journal') {
            [$result, $selectedAccount, $validated] = $this->generalJournalResult($request, $context);
            $this->activityLogger->log($request, 'accounting', 'reports.general_journal.export', 'success', [
                'company_id' => $context['company_id'],
                'properties_only' => true,
                'properties' => [
                    'format' => $format,
                    'account_doc_num' => $selectedAccount['doc_num'] ?? null,
                    'from_date' => $validated['from_date'],
                    'to_date' => $validated['to_date'],
                ],
            ]);

            if ($format === 'pdf') {
                return $pdf->stream('reports.general-journal', [
                    'title' => __('ledger_reports.types.general_journal'),
                    'result' => $result,
                    'selectedAccount' => $selectedAccount,
                ], 'general-journal.pdf', 'L');
            }

            $writer = $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
            $extension = $format === 'csv' ? 'csv' : 'xlsx';

            return Excel::download(new LedgerReportExport($result, $type), "general-journal.{$extension}", $writer);
        }

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

        return Excel::download(new LedgerReportExport($result, $type), "{$filename}.{$extension}", $writer);
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
        $isPartnerStatement = in_array($type, ['customer_statement', 'supplier_statement'], true);
        [$account, $selected] = $this->resolveSubject($type, $validated, (int) $context['company_id']);
        $filters = [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'all_periods' => $isPartnerStatement || $request->boolean('all_periods'),
            'account_id' => (int) $account->getKey(),
            'from_date' => $validated['from_date'],
            'to_date' => $validated['to_date'],
            'branch_id' => $isPartnerStatement
                ? null
                : $this->branchId((int) $context['company_id'], $validated['branch_doc_num'] ?? null),
            'cost_center_id' => $isPartnerStatement
                ? null
                : $this->costCenterId((int) $context['company_id'], $validated['cost_center_doc_num'] ?? null),
        ];

        $result = $this->ledger->accountLedger($filters);
        if ($isPartnerStatement) {
            $result['opening_movements'] = $this->localizePartnerMovements($result['opening_movements'], $type);
            $result['movements'] = $this->localizePartnerMovements($result['movements'], $type);
        }

        return [$result, $selected, $validated];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: array{doc_num: string, name: string}|null, 2: array<string, mixed>}
     */
    private function generalJournalResult(LedgerReportRequest $request, array $context): array
    {
        $validated = $request->validated();
        $account = filled($validated['account_doc_num'] ?? null)
            ? Account::query()->withTrashed()->forCompany((int) $context['company_id'])->where('doc_num', $validated['account_doc_num'])->firstOrFail()
            : null;
        $result = $this->ledger->generalJournal([
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'all_periods' => $request->boolean('all_periods'),
            'account_id' => $account?->getKey(),
            'from_date' => $validated['from_date'],
            'to_date' => $validated['to_date'],
            'branch_id' => $this->branchId((int) $context['company_id'], $validated['branch_doc_num'] ?? null),
            'cost_center_id' => $this->costCenterId((int) $context['company_id'], $validated['cost_center_doc_num'] ?? null),
        ]);

        return [
            $result,
            $account ? ['doc_num' => $account->doc_num, 'name' => $account->codeNameLabel()] : null,
            $validated,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $movements
     * @return list<array<string, mixed>>
     */
    private function localizePartnerMovements(array $movements, string $type): array
    {
        return array_map(function (array $movement) use ($type): array {
            $translationKey = match (trim((string) $movement['description'])) {
                'Customer receivable', 'مديونية فاتورة مبيعات' => 'customer_receivable',
                'Customer receivable settlement', 'تحصيل مديونية العميل' => 'customer_receivable_settlement',
                'Customer credit', 'إشعار خصم للعميل' => 'customer_credit',
                'Sales return', 'مرتجع مبيعات' => 'sales_return',
                'Supplier payable', 'Supplier payable for purchase invoice', 'مستحقات المورد عن فاتورة مشتريات', 'مديونية فاتورة مشتريات' => 'supplier_payable',
                'Supplier payable settlement', 'تسوية مستحقات المورد', 'سداد مديونية المورد' => 'supplier_payable_settlement',
                'Supplier debit', 'Supplier debit for purchase return', 'تخفيض رصيد المورد بقيمة مرتجع المشتريات', 'إشعار خصم من المورد' => 'supplier_debit',
                'Purchase return', 'مرتجع مشتريات' => 'purchase_return',
                default => null,
            };

            if ($translationKey !== null) {
                $movement['description'] = __('ledger_reports.movement_descriptions.'.$translationKey);
            }

            $movement['collection_source'] = '';
            if ($type === 'customer_statement' && filled($movement['collection_method'] ?? null)) {
                $methodKey = 'ledger_reports.collection_methods.'.$movement['collection_method'];
                $method = __($methodKey);
                $movement['collection_source'] = collect([
                    $method === $methodKey ? $movement['collection_method'] : $method,
                    $movement['collection_account'] ?? null,
                    $movement['collection_reference'] ?? null,
                ])->filter(fn (mixed $value): bool => filled($value))->implode(' / ');
            }

            return $movement;
        }, $movements);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: Account, 1: array{doc_num: string, name: string, account: string}}
     */
    private function resolveSubject(string $type, array $validated, int $companyId): array
    {
        if ($type === 'account_ledger') {
            $account = Account::query()->withTrashed()->forCompany($companyId)->where('doc_num', $validated['account_doc_num'])->firstOrFail();

            return [$account, ['id' => $account->getKey(), 'doc_num' => $account->doc_num, 'name' => $account->codeNameLabel(), 'account' => $account->doc_num]];
        }

        $model = $type === 'customer_statement' ? Customer::class : Supplier::class;
        $field = $type === 'customer_statement' ? 'customer_doc_num' : 'supplier_doc_num';
        $party = $model::query()->withTrashed()->forCompany($companyId)->where('doc_num', $validated[$field])->firstOrFail();
        $account = Account::query()->withTrashed()->forCompany($companyId)->whereKey($party->account_id)->first();

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
            'general_journal' => 'admin.accounting.reports.general-journal',
            'customer_statement' => 'admin.accounting.reports.customer-statement',
            'supplier_statement' => 'admin.accounting.reports.supplier-statement',
            default => 'admin.accounting.reports.account-ledger',
        };
    }
}

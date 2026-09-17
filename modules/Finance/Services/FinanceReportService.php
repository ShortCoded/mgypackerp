<?php

namespace Modules\Finance\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;
use Modules\Finance\Models\ChequeClearingEvent;
use Modules\Finance\Models\FundTransfer;
use Modules\Finance\Models\OpeningBalance;
use Modules\Finance\Models\OpeningBalanceLine;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;

class FinanceReportService
{
    public const CashboxStatement = 'cashbox_statement';

    public const CashboxBalances = 'cashbox_balances';

    public const CashVouchers = 'cash_vouchers';

    public const BankAccountStatement = 'bank_account_statement';

    public const BankAccountBalances = 'bank_account_balances';

    public const FundTransfers = 'fund_transfers';

    public const BankReconciliation = 'bank_reconciliation';

    public const ReceivedCheques = 'received_cheques';

    public const IssuedCheques = 'issued_cheques';

    public const ClearedCheques = 'cleared_cheques';

    public const ReturnedCheques = 'returned_cheques';

    public const DueCheques = 'due_cheques';

    public const CancelledCheques = 'cancelled_cheques';

    public const AdvancesAllocations = 'advances_allocations';

    public const UnapprovedDocuments = 'unapproved_documents';

    public const CustomerAging = 'customer_aging';

    public const SupplierAging = 'supplier_aging';

    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly DateFormatService $dates,
    ) {}

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::CashboxStatement,
            self::CashboxBalances,
            self::CashVouchers,
            self::BankAccountStatement,
            self::BankAccountBalances,
            self::FundTransfers,
            self::BankReconciliation,
            self::ReceivedCheques,
            self::IssuedCheques,
            self::ClearedCheques,
            self::ReturnedCheques,
            self::DueCheques,
            self::CancelledCheques,
            self::AdvancesAllocations,
            self::UnapprovedDocuments,
            self::CustomerAging,
            self::SupplierAging,
        ];
    }

    /** @return list<string> */
    public static function applicableFilters(string $type): array
    {
        return match ($type) {
            self::CashboxStatement => ['from_date', 'to_date', 'cashbox_doc_num', 'currency_doc_num', 'branch_id'],
            self::CashboxBalances => ['as_of_date', 'cashbox_doc_num', 'currency_doc_num', 'branch_id'],
            self::CashVouchers => ['from_date', 'to_date', 'cashbox_doc_num', 'currency_doc_num', 'status'],
            self::BankAccountStatement => ['from_date', 'to_date', 'bank_account_doc_num', 'currency_doc_num'],
            self::BankAccountBalances => ['as_of_date', 'bank_account_doc_num', 'currency_doc_num'],
            self::FundTransfers => ['from_date', 'to_date', 'status'],
            self::BankReconciliation => ['from_date', 'to_date', 'bank_account_doc_num'],
            self::DueCheques => ['to_date', 'as_of_date', 'bank_account_doc_num', 'currency_doc_num', 'due_state'],
            self::ReceivedCheques, self::IssuedCheques, self::ClearedCheques,
            self::ReturnedCheques, self::CancelledCheques => [
                'from_date', 'to_date', 'as_of_date', 'bank_account_doc_num', 'currency_doc_num',
            ],
            self::AdvancesAllocations, self::UnapprovedDocuments => ['from_date', 'to_date', 'currency_doc_num'],
            self::CustomerAging, self::SupplierAging => [
                'as_of_date', 'currency_doc_num', 'due_state', 'branch_id', 'financial_period_id',
            ],
            default => ['as_of_date', 'cashbox_doc_num', 'currency_doc_num'],
        };
    }

    /** @return array<string, mixed> */
    public function filters(Request $request, ?string $defaultType = null): array
    {
        $filters = $request->only([
            'type', 'from_date', 'to_date', 'as_of_date', 'cashbox_doc_num',
            'bank_account_doc_num', 'currency_doc_num', 'status', 'due_state',
            'branch_id', 'financial_period_id',
        ]);
        $requestedType = filled($defaultType)
            ? (string) $defaultType
            : (string) ($filters['type'] ?? self::CashboxBalances);
        $filters['type'] = in_array($requestedType, self::types(), true) ? $requestedType : self::CashboxBalances;

        $applicableFilters = self::applicableFilters($filters['type']);
        $filters = collect($filters)
            ->only(array_merge(['type'], $applicableFilters))
            ->all();

        foreach (array_intersect(['from_date', 'to_date', 'as_of_date'], $applicableFilters) as $field) {
            $filters[$field] = $this->dates->normalizeForStorage(trim((string) ($filters[$field] ?? '')));
        }

        if (in_array('as_of_date', $applicableFilters, true)) {
            $filters['as_of_date'] ??= $filters['to_date'] ?? now()->toDateString();
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== null && trim((string) $value) !== '');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $type = (string) ($filters['type'] ?? self::CashboxBalances);
        [$columns, $rows, $notices] = match ($type) {
            self::CashboxStatement => $this->cashboxStatement($filters),
            self::CashboxBalances => $this->cashboxBalances($filters),
            self::CashVouchers => $this->cashVouchers($filters),
            self::BankAccountStatement => $this->bankAccountStatement($filters),
            self::BankAccountBalances => $this->bankAccountBalances($filters),
            self::FundTransfers => $this->fundTransfers($filters),
            self::BankReconciliation => $this->bankReconciliation($filters),
            self::ReceivedCheques, self::IssuedCheques, self::ClearedCheques, self::ReturnedCheques,
            self::DueCheques, self::CancelledCheques => $this->cheques($filters),
            self::AdvancesAllocations => $this->advancesAllocations($filters),
            self::UnapprovedDocuments => $this->unapprovedDocuments($filters),
            self::CustomerAging => $this->aging($filters, true),
            self::SupplierAging => $this->aging($filters, false),
            default => $this->cashboxBalances($filters),
        };

        return [
            'type' => $type,
            'title' => __("finance_reports.types.{$type}.title"),
            'description' => __("finance_reports.types.{$type}.description"),
            'columns' => $columns,
            'rows' => $rows,
            'filters' => $this->filterSummary($filters),
            'currency_totals' => $this->currencyTotals($rows),
            'notices' => $notices,
        ];
    }

    /** @return array{cashboxes: Collection<int, Cashbox>, bank_accounts: Collection<int, BankAccount>, currencies: Collection<int, mixed>} */
    public function filterOptions(?int $branchId = null): array
    {
        return [
            'cashboxes' => Cashbox::query()->where('company_id', $this->companyId())->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->orderBy('name')->get(['id', 'doc_num', 'name']),
            'bank_accounts' => BankAccount::query()->where('company_id', $this->companyId())->orderBy('account_name')->get(['id', 'doc_num', 'account_name', 'account_number']),
            'currencies' => Currency::query()->where('company_id', $this->companyId())->orderBy('code')->get(['id', 'doc_num', 'code', 'name']),
        ];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>, 2: list<string>} */
    private function cashboxStatement(array $filters): array
    {
        $rows = $this->statementRows($this->cashboxMovements($filters), $filters);

        return [$this->labels(['date', 'cashbox', 'branch', 'currency', 'document', 'movement', 'party_reference', 'status', 'receipt', 'payment', 'balance']), $rows, []];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>, 2: list<string>} */
    private function cashboxBalances(array $filters): array
    {
        $asOf = (string) $filters['as_of_date'];
        $rows = $this->cashboxMovements($filters)
            ->filter(fn (array $row): bool => $row['_date'] <= $asOf)
            ->groupBy('_balance_key')
            ->map(function (Collection $movements) use ($asOf): array {
                $first = $movements->first();

                return [
                    '_balance_key' => $first['_balance_key'],
                    '_cashbox_id' => $first['_cashbox_id'],
                    '_currency_id' => $first['_currency_id'],
                    'cashbox' => $first['cashbox'], 'branch' => $first['branch'], 'currency' => $first['currency'],
                    'receipts' => $this->sum($movements, 'receipt'), 'payments' => $this->sum($movements, 'payment'),
                    'balance' => $this->net($movements), 'as_of_date' => $asOf,
                ];
            });

        $companyCurrencies = Currency::query()
            ->where('company_id', $this->companyId())
            ->active()
            ->when($filters['currency_doc_num'] ?? null, fn ($query, string $docNum) => $query->where('doc_num', $docNum))
            ->get(['id', 'doc_num', 'code']);
        $cashboxes = Cashbox::query()
            ->where('company_id', $this->companyId())
            ->active()
            ->when($filters['branch_id'] ?? null, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['cashbox_doc_num'] ?? null, fn ($query, string $docNum) => $query->where('doc_num', $docNum))
            ->with([
                'branch',
                'currencies' => fn ($query) => $query->where('status', 'active')->with('currency'),
            ])
            ->get();

        foreach ($cashboxes as $cashbox) {
            $restrictedCurrencies = $cashbox->currencies->pluck('currency')->filter();
            $currencies = $restrictedCurrencies->isEmpty()
                ? $companyCurrencies
                : $restrictedCurrencies->when(
                    $filters['currency_doc_num'] ?? null,
                    fn (Collection $items, string $docNum): Collection => $items->where('doc_num', $docNum),
                );

            foreach ($currencies as $currency) {
                $key = $cashbox->getKey().':'.$currency->getKey();
                $rows->put($key, $rows->get($key, [
                    '_balance_key' => $key,
                    '_cashbox_id' => $cashbox->getKey(),
                    '_currency_id' => $currency->getKey(),
                    'cashbox' => trim(implode(' / ', array_filter([$cashbox->doc_num, $cashbox->name]))),
                    'branch' => $cashbox->branch?->name,
                    'currency' => $currency->code,
                    'receipts' => '0.0000',
                    'payments' => '0.0000',
                    'balance' => '0.0000',
                    'as_of_date' => $asOf,
                ]));
            }
        }

        $rows = $rows->sortBy(fn (array $row): string => $row['cashbox'].'|'.$row['currency'])->values();

        return [$this->labels(['cashbox', 'branch', 'currency', 'receipts', 'payments', 'balance', 'as_of_date']), $rows, []];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>, 2: list<string>} */
    private function bankAccountStatement(array $filters): array
    {
        $rows = $this->statementRows($this->bankMovements($filters), $filters);

        return [$this->labels(['date', 'bank_account', 'currency', 'document', 'movement', 'party_reference', 'status', 'receipt', 'payment', 'balance']), $rows, []];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>, 2: list<string>} */
    private function bankAccountBalances(array $filters): array
    {
        $asOf = (string) $filters['as_of_date'];
        $rows = $this->bankMovements($filters)
            ->filter(fn (array $row): bool => $row['_date'] <= $asOf)
            ->groupBy('_balance_key')
            ->map(function (Collection $movements) use ($asOf): array {
                $first = $movements->first();

                return [
                    'bank_account' => $first['bank_account'], 'currency' => $first['currency'],
                    'receipts' => $this->sum($movements, 'receipt'), 'payments' => $this->sum($movements, 'payment'),
                    'balance' => $this->net($movements), 'as_of_date' => $asOf,
                ];
            })->sortBy('bank_account')->values();

        return [$this->labels(['bank_account', 'currency', 'receipts', 'payments', 'balance', 'as_of_date']), $rows, []];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>, 2: list<string>} */
    private function cashVouchers(array $filters): array
    {
        $query = CashVoucher::query()->where('company_id', $this->companyId())->with(['cashbox.branch', 'currency'])
            ->when($filters['cashbox_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('cashbox', fn ($query) => $query->where('doc_num', $value)))
            ->when($filters['currency_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('currency', fn ($query) => $query->where('doc_num', $value)))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value));
        $this->dateFilters($query, 'voucher_date', $filters);

        $rows = $query->orderBy('voucher_date')->orderBy('doc_number')->get()->map(fn (CashVoucher $voucher): array => [
            '_url' => route($voucher->isReceipt() ? 'admin.finance.cash-receipt-vouchers.show' : 'admin.finance.cash-payment-vouchers.show', $voucher),
            'date' => $this->date($voucher->voucher_date), 'document' => $voucher->doc_num,
            'voucher_type' => $this->value($voucher->voucher_type), 'cashbox' => $voucher->cashbox?->name,
            'branch' => $voucher->cashbox?->branch?->name, 'currency' => $voucher->currency?->code,
            'party_reference' => trim(implode(' / ', array_filter([$voucher->person_name, $voucher->person_phone]))),
            'amount' => $voucher->amount, 'amount_base' => $voucher->amount_base,
            'status' => $this->value($voucher->status), 'reason' => $voucher->reason,
        ]);

        return [$this->labels(['date', 'document', 'voucher_type', 'cashbox', 'branch', 'currency', 'party_reference', 'amount', 'amount_base', 'status', 'reason']), $rows, []];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>, 2: list<string>} */
    private function fundTransfers(array $filters): array
    {
        $query = FundTransfer::query()->where('company_id', $this->companyId())
            ->with(['sourceCashbox', 'sourceBankAccount', 'targetCashbox', 'targetBankAccount', 'sourceCurrency', 'targetCurrency'])
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value));
        $this->dateFilters($query, 'transfer_date', $filters);

        $rows = $query->orderBy('transfer_date')->orderBy('doc_number')->get()->map(fn (FundTransfer $transfer): array => [
            '_url' => route('admin.finance.fund-transfers.show', $transfer),
            'date' => $this->date($transfer->transfer_date), 'document' => $transfer->doc_num,
            'source' => $this->transferHolder($transfer, true), 'source_currency' => $transfer->sourceCurrency?->code,
            'source_amount' => $transfer->source_amount, 'target' => $this->transferHolder($transfer, false),
            'target_currency' => $transfer->targetCurrency?->code, 'target_amount' => $transfer->target_amount,
            'exchange_rate' => $transfer->exchange_rate, 'status' => $this->value($transfer->status), 'reason' => $transfer->reason,
        ]);

        return [$this->labels(['date', 'document', 'source', 'source_currency', 'source_amount', 'target', 'target_currency', 'target_amount', 'exchange_rate', 'status', 'reason']), $rows, [__('finance_reports.notices.transfer_not_income_expense')]];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>, 2: list<string>} */
    private function bankReconciliation(array $filters): array
    {
        $query = ChequeClearingEvent::query()
            ->whereHas('cheque', fn ($query) => $query->where('company_id', $this->companyId()))
            ->with(['cheque.bankAccount.currency', 'cheque.currency', 'clearingJournalEntry', 'reversalJournalEntry'])
            ->when($filters['bank_account_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('cheque.bankAccount', fn ($query) => $query->where('doc_num', $value)));
        $this->dateFilters($query, 'clearing_date', $filters);

        $rows = $query->orderBy('clearing_date')->orderBy('sequence')->get()->map(fn (ChequeClearingEvent $event): array => [
            '_url' => route('admin.finance.cheques.show', $event->cheque),
            'date' => $this->date($event->clearing_date), 'bank_account' => $this->bankLabel($event->cheque?->bankAccount),
            'currency' => $event->cheque?->currency?->code, 'document' => $event->cheque?->doc_num,
            'cheque_number' => $event->cheque?->cheque_number, 'event' => $this->value($event->status),
            'amount' => $event->cheque?->amount,
            'journal_entry' => $event->status === ChequeClearingEvent::StatusReversed ? $event->reversalJournalEntry?->doc_num : $event->clearingJournalEntry?->doc_num,
            'reason' => $event->reversal_reason,
        ]);

        return [$this->labels(['date', 'bank_account', 'currency', 'document', 'cheque_number', 'event', 'amount', 'journal_entry', 'reason']), $rows, [__('finance_reports.notices.reconciliation_source_limit')]];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>, 2: list<string>} */
    private function cheques(array $filters): array
    {
        $type = $filters['type'];

        $query = Cheque::query()->where('company_id', $this->companyId())->with(['bankAccount', 'currency'])
            ->when($filters['bank_account_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('bankAccount', fn ($query) => $query->where('doc_num', $value)))
            ->when($filters['currency_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('currency', fn ($query) => $query->where('doc_num', $value)))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value));

        match ($type) {
            self::ReceivedCheques => $query->where('cheque_type', Cheque::TypeReceived),
            self::IssuedCheques => $query->where('cheque_type', Cheque::TypeIssued),
            self::ClearedCheques => $query->whereIn('status', [Cheque::StatusCollected, Cheque::StatusCleared]),
            self::ReturnedCheques => $query->where('status', Cheque::StatusReturned),
            self::CancelledCheques => $query->whereIn('status', [Cheque::StatusCancelled, Cheque::StatusClearingReversed]),
            self::DueCheques => $query->whereNotIn('status', [
                Cheque::StatusCollected,
                Cheque::StatusCleared,
                Cheque::StatusReturned,
                Cheque::StatusCancelled,
                Cheque::StatusClearingReversed,
            ])->whereNotNull('due_date'),
            default => null,
        };

        if ($type === self::DueCheques) {
            $query->whereDate('due_date', '<=', $filters['to_date'] ?? Carbon::parse($filters['as_of_date'])->addDays(30)->toDateString());
        } else {
            $this->dateFilters($query, 'cheque_date', $filters);
        }

        $asOf = Carbon::parse($filters['as_of_date']);
        $rows = $query->orderBy('due_date')->orderBy('doc_number')->get()->map(fn (Cheque $cheque): array => [
            '_url' => route('admin.finance.cheques.show', $cheque),
            'date' => $this->date($cheque->cheque_date), 'due_date' => $this->date($cheque->due_date),
            'due_state' => $this->dueState($cheque, $asOf), 'document' => $cheque->doc_num,
            'cheque_number' => $cheque->cheque_number, 'cheque_type' => $this->value($cheque->cheque_type),
            'party_reference' => $cheque->party_name, 'bank_account' => $this->bankLabel($cheque->bankAccount),
            'currency' => $cheque->currency?->code, 'amount' => $cheque->amount, 'status' => $this->value($cheque->status),
            'deposited_at' => $this->date($cheque->deposited_at),
            'collected_cleared_at' => $this->date($cheque->isReceived() ? $cheque->collected_at : $cheque->cleared_at),
            'returned_cancelled_at' => $this->date($cheque->returned_at ?: $cheque->cancelled_at),
            'reason' => $cheque->cancel_reason ?: $cheque->clearing_reversal_reason ?: $cheque->reason,
        ])->when($filters['due_state'] ?? null, fn (Collection $rows, string $state) => $rows->where('due_state', $state)->values());

        return [$this->labels(['date', 'due_date', 'due_state', 'document', 'cheque_number', 'cheque_type', 'party_reference', 'bank_account', 'currency', 'amount', 'status', 'deposited_at', 'collected_cleared_at', 'returned_cancelled_at', 'reason']), $rows, [__('finance_reports.notices.cheque_history_scope')]];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>, 2: list<string>} */
    private function advancesAllocations(array $filters): array
    {
        $customerQuery = CustomerReceipt::query()->where('company_id', $this->companyId())->where('status', CustomerReceipt::StatusApproved)
            ->where(fn ($query) => $query->where('receipt_type', CustomerReceipt::TypeAdvance)->orWhere('unallocated_amount', '>', 0))
            ->when($filters['currency_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('currency', fn ($query) => $query->where('doc_num', $value)));
        $this->dateFilters($customerQuery, 'receipt_date', $filters);
        $customerRows = $customerQuery->with(['customer', 'currency'])->get()->map(fn (CustomerReceipt $receipt): array => [
            '_url' => route('admin.sales.customer-receipts.show', $receipt), 'side' => $this->value('customer'),
            'date' => $this->date($receipt->receipt_date), 'document' => $receipt->doc_num,
            'party_reference' => $receipt->customer?->name, 'currency' => $receipt->currency?->code,
            'amount' => $receipt->amount, 'allocated' => bcsub((string) $receipt->amount, (string) $receipt->unallocated_amount, 4),
            'unallocated' => $receipt->unallocated_amount, 'status' => $this->value($receipt->status),
        ]);
        $supplierQuery = SupplierPaymentContext::query()->where('company_id', $this->companyId())->where('status', SupplierPaymentContext::StatusApproved)
            ->where(fn ($query) => $query->where('is_advance', true)->orWhereColumn('allocated_amount', '<', 'amount'))
            ->when($filters['currency_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('currency', fn ($query) => $query->where('doc_num', $value)));
        $this->dateFilters($supplierQuery, 'payment_date', $filters);
        $supplierRows = $supplierQuery->with(['supplier', 'currency'])->get()->map(fn (SupplierPaymentContext $payment): array => [
            '_url' => route('admin.purchases.supplier-payments.show', $payment), 'side' => $this->value('supplier'),
            'date' => $this->date($payment->payment_date), 'document' => $payment->doc_num,
            'party_reference' => $payment->supplier?->name, 'currency' => $payment->currency?->code,
            'amount' => $payment->amount, 'allocated' => $payment->allocated_amount,
            'unallocated' => bcsub((string) $payment->amount, (string) $payment->allocated_amount, 4), 'status' => $this->value($payment->status),
        ]);

        return [$this->labels(['side', 'date', 'document', 'party_reference', 'currency', 'amount', 'allocated', 'unallocated', 'status']), $customerRows->concat($supplierRows)->sortBy('date')->values(), []];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>, 2: list<string>} */
    private function unapprovedDocuments(array $filters): array
    {
        $rows = collect();
        $cashVouchers = CashVoucher::query()->where('company_id', $this->companyId())->where('status', CashVoucher::StatusDraft)
            ->when($filters['currency_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('currency', fn ($query) => $query->where('doc_num', $value)));
        $this->dateFilters($cashVouchers, 'voucher_date', $filters);
        $rows->push(...$cashVouchers->with('currency')->get()->map(fn (CashVoucher $row): array => [
            '_url' => route($row->isReceipt() ? 'admin.finance.cash-receipt-vouchers.show' : 'admin.finance.cash-payment-vouchers.show', $row),
            'date' => $this->date($row->voucher_date), 'document_type' => $this->value($row->voucher_type), 'document' => $row->doc_num,
            'party_reference' => $row->person_name, 'currency' => $row->currency?->code, 'amount' => $row->amount, 'status' => $this->value($row->status),
        ]));
        $transfers = FundTransfer::query()->where('company_id', $this->companyId())->where('status', FundTransfer::StatusDraft)
            ->when($filters['currency_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('sourceCurrency', fn ($query) => $query->where('doc_num', $value)));
        $this->dateFilters($transfers, 'transfer_date', $filters);
        $rows->push(...$transfers->with('sourceCurrency')->get()->map(fn (FundTransfer $row): array => [
            '_url' => route('admin.finance.fund-transfers.show', $row), 'date' => $this->date($row->transfer_date), 'document_type' => $this->value('transfer'),
            'document' => $row->doc_num, 'party_reference' => '', 'currency' => $row->sourceCurrency?->code, 'amount' => $row->source_amount, 'status' => $this->value($row->status),
        ]));
        $customerReceipts = CustomerReceipt::query()->where('company_id', $this->companyId())->where('status', CustomerReceipt::StatusDraft)
            ->when($filters['currency_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('currency', fn ($query) => $query->where('doc_num', $value)));
        $this->dateFilters($customerReceipts, 'receipt_date', $filters);
        $rows->push(...$customerReceipts->with(['customer', 'currency'])->get()->map(fn (CustomerReceipt $row): array => [
            '_url' => route('admin.sales.customer-receipts.show', $row), 'date' => $this->date($row->receipt_date), 'document_type' => $this->value('customer_receipt'),
            'document' => $row->doc_num, 'party_reference' => $row->customer?->name, 'currency' => $row->currency?->code, 'amount' => $row->amount, 'status' => $this->value($row->status),
        ]));
        $supplierPayments = SupplierPaymentContext::query()->where('company_id', $this->companyId())->where('status', SupplierPaymentContext::StatusDraft)
            ->when($filters['currency_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('currency', fn ($query) => $query->where('doc_num', $value)));
        $this->dateFilters($supplierPayments, 'payment_date', $filters);
        $rows->push(...$supplierPayments->with(['supplier', 'currency'])->get()->map(fn (SupplierPaymentContext $row): array => [
            '_url' => route('admin.purchases.supplier-payments.show', $row), 'date' => $this->date($row->payment_date), 'document_type' => $this->value('supplier_payment'),
            'document' => $row->doc_num, 'party_reference' => $row->supplier?->name, 'currency' => $row->currency?->code, 'amount' => $row->amount, 'status' => $this->value($row->status),
        ]));
        $cheques = Cheque::query()->where('company_id', $this->companyId())->where('status', Cheque::StatusDraft)
            ->when($filters['currency_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('currency', fn ($query) => $query->where('doc_num', $value)));
        $this->dateFilters($cheques, 'cheque_date', $filters);
        $rows->push(...$cheques->with('currency')->get()->map(fn (Cheque $row): array => [
            '_url' => route('admin.finance.cheques.show', $row), 'date' => $this->date($row->cheque_date), 'document_type' => $this->value('cheque'),
            'document' => $row->doc_num, 'party_reference' => $row->party_name, 'currency' => $row->currency?->code, 'amount' => $row->amount, 'status' => $this->value($row->status),
        ]));
        $openingBalances = OpeningBalance::query()->where('company_id', $this->companyId())->where('status', OpeningBalance::StatusDraft)
            ->when($filters['currency_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('currency', fn ($query) => $query->where('doc_num', $value)));
        $this->dateFilters($openingBalances, 'document_date', $filters);
        $rows->push(...$openingBalances->with(['currency', 'lines'])->get()->map(function (OpeningBalance $row): array {
            $debit = $row->lines->reduce(fn (string $sum, OpeningBalanceLine $line): string => bcadd($sum, (string) $line->debit_amount, 4), '0.0000');
            $credit = $row->lines->reduce(fn (string $sum, OpeningBalanceLine $line): string => bcadd($sum, (string) $line->credit_amount, 4), '0.0000');

            return [
                '_url' => route('admin.finance.opening-balances.show', $row), 'date' => $this->date($row->document_date), 'document_type' => $this->value('opening_balance'),
                'document' => $row->doc_num, 'party_reference' => $row->description, 'currency' => $row->currency?->code,
                'amount' => bccomp($debit, $credit, 4) >= 0 ? $debit : $credit, 'status' => $this->value($row->status),
            ];
        }));

        return [$this->labels(['date', 'document_type', 'document', 'party_reference', 'currency', 'amount', 'status']), $rows->sortBy('date')->values(), [__('finance_reports.notices.unapproved_excluded_from_balances')]];
    }

    /** @return array{0: array<string, string>, 1: Collection<int, array<string, mixed>>, 2: list<string>} */
    private function aging(array $filters, bool $customers): array
    {
        $asOf = Carbon::parse($filters['as_of_date'])->startOfDay();
        $model = $customers ? CustomerInvoice::class : PurchaseInvoice::class;
        $party = $customers ? 'customer' : 'supplier';
        $dateColumn = 'invoice_date';
        $status = $customers ? CustomerInvoice::StatusPosted : PurchaseInvoice::StatusApproved;
        $query = $model::query()->where('company_id', $this->companyId())->where('status', $status)->where('remaining_amount', '>', 0)
            ->whereDate($dateColumn, '<=', $asOf)
            ->when($filters['currency_doc_num'] ?? null, fn ($query, $value) => $query->whereHas('currency', fn ($query) => $query->where('doc_num', $value)))
            ->when($filters['financial_period_id'] ?? null, fn ($invoiceQuery, $periodId) => $invoiceQuery->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($invoiceQuery, $branchId) => $invoiceQuery->where('branch_id', $branchId))
            ->with([$party, 'currency', 'paymentSchedules']);
        if ($customers) {
            $query->where('document_type', CustomerInvoice::TypeInvoice);
        }

        $rows = $query->get()->flatMap(function ($invoice) use ($asOf, $party): Collection {
            $schedules = $invoice->paymentSchedules
                ->filter(fn ($schedule): bool => bccomp((string) $schedule->outstanding_amount, '0', 4) > 0);

            if ($schedules->isEmpty()) {
                $schedules = collect([(object) [
                    'due_date' => $party === 'customer' ? ($invoice->due_date ?: $invoice->invoice_date) : $invoice->invoice_date,
                    'amount' => $invoice->total_amount,
                    'outstanding_amount' => $invoice->remaining_amount,
                ]]);
            }

            return $schedules->map(function ($schedule) use ($asOf, $invoice, $party): array {
                $dueDate = $schedule->due_date;
                $days = $dueDate?->diffInDays($asOf, false) ?? 0;
                $dueState = match (true) {
                    $days > 0 => 'overdue',
                    $days < 0 => 'upcoming',
                    default => 'due',
                };

                return [
                    '_url' => route($party === 'customer' ? 'admin.sales.sales-invoices.show' : 'admin.purchases.purchase-invoices.show', $invoice),
                    'party_reference' => $invoice->{$party}?->name, 'document' => $invoice->doc_num,
                    'due_date' => $this->date($dueDate), 'currency' => $invoice->currency?->code,
                    'original_amount' => $schedule->amount,
                    'settled_amount' => bcsub((string) $schedule->amount, (string) $schedule->outstanding_amount, 4),
                    'outstanding' => $schedule->outstanding_amount,
                    'aging_bucket' => $this->agingBucket($days), 'days_overdue' => max(0, $days), 'due_state' => $dueState,
                ];
            });
        })->when($filters['due_state'] ?? null, function (Collection $rows, string $state): Collection {
            return match ($state) {
                'overdue' => $rows->where('due_state', 'overdue')->values(),
                'due' => $rows->where('due_state', 'due')->values(),
                'due_or_overdue' => $rows->whereIn('due_state', ['due', 'overdue'])->values(),
                default => $rows,
            };
        });

        return [$this->labels(['party_reference', 'document', 'due_date', 'currency', 'original_amount', 'settled_amount', 'outstanding', 'aging_bucket', 'days_overdue']), $rows, [__('finance_reports.notices.aging_current_balance_limit')]];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function cashboxMovements(array $filters): Collection
    {
        $companyId = $this->companyId();
        $through = $filters['to_date'] ?? $filters['as_of_date'];
        $cashboxDocNum = $filters['cashbox_doc_num'] ?? null;
        $currencyDocNum = $filters['currency_doc_num'] ?? null;
        $branchId = $filters['branch_id'] ?? null;
        $rows = collect();

        CashVoucher::query()->where('company_id', $companyId)->where('status', CashVoucher::StatusApproved)->whereDate('voucher_date', '<=', $through)
            ->when($branchId, fn ($query, int $value) => $query->whereHas('cashbox', fn ($query) => $query->where('branch_id', $value)))
            ->when($cashboxDocNum, fn ($query, $value) => $query->whereHas('cashbox', fn ($query) => $query->where('doc_num', $value)))
            ->when($currencyDocNum, fn ($query, $value) => $query->whereHas('currency', fn ($query) => $query->where('doc_num', $value)))
            ->with(['cashbox.branch', 'currency'])->get()->each(function (CashVoucher $voucher) use ($rows): void {
                $rows->push($this->cashboxMovementRow(
                    $voucher->cashbox,
                    $voucher->currency_id,
                    $voucher->currency?->code,
                    $voucher->voucher_date,
                    $voucher->doc_num,
                    $this->value($voucher->voucher_type),
                    trim(implode(' / ', array_filter([$voucher->person_name, $voucher->reason]))),
                    $voucher->status,
                    $voucher->isReceipt() ? (string) $voucher->amount : '0.0000',
                    $voucher->isPayment() ? (string) $voucher->amount : '0.0000',
                    route($voucher->isReceipt() ? 'admin.finance.cash-receipt-vouchers.show' : 'admin.finance.cash-payment-vouchers.show', $voucher),
                ));
            });

        FundTransfer::query()->where('company_id', $companyId)->where('status', FundTransfer::StatusApproved)->whereDate('transfer_date', '<=', $through)
            ->with(['sourceCashbox.branch', 'targetCashbox.branch', 'sourceCurrency', 'targetCurrency', 'sourceBankAccount', 'targetBankAccount'])->get()
            ->each(function (FundTransfer $transfer) use ($branchId, $cashboxDocNum, $currencyDocNum, $rows): void {
                if ($transfer->source_type === FundTransfer::HolderCashbox && $transfer->sourceCashbox
                    && (! $branchId || (int) $transfer->sourceCashbox->branch_id === (int) $branchId)
                    && (! $cashboxDocNum || $transfer->sourceCashbox->doc_num === $cashboxDocNum)
                    && (! $currencyDocNum || $transfer->sourceCurrency?->doc_num === $currencyDocNum)) {
                    $rows->push($this->cashboxMovementRow($transfer->sourceCashbox, $transfer->source_currency_id, $transfer->sourceCurrency?->code, $transfer->transfer_date, $transfer->doc_num, $this->value('transfer_out'), $this->transferHolder($transfer, false), $transfer->status, '0.0000', (string) $transfer->source_amount, route('admin.finance.fund-transfers.show', $transfer)));
                }
                if ($transfer->target_type === FundTransfer::HolderCashbox && $transfer->targetCashbox
                    && (! $branchId || (int) $transfer->targetCashbox->branch_id === (int) $branchId)
                    && (! $cashboxDocNum || $transfer->targetCashbox->doc_num === $cashboxDocNum)
                    && (! $currencyDocNum || $transfer->targetCurrency?->doc_num === $currencyDocNum)) {
                    $rows->push($this->cashboxMovementRow($transfer->targetCashbox, $transfer->target_currency_id, $transfer->targetCurrency?->code, $transfer->transfer_date, $transfer->doc_num, $this->value('transfer_in'), $this->transferHolder($transfer, true), $transfer->status, (string) $transfer->target_amount, '0.0000', route('admin.finance.fund-transfers.show', $transfer)));
                }
            });

        $cashboxes = Cashbox::query()->where('company_id', $companyId)
            ->when($branchId, fn ($query, int $value) => $query->where('branch_id', $value))
            ->with('branch')->get()->keyBy('account_id');
        OpeningBalanceLine::query()->whereIn('account_id', $cashboxes->keys())
            ->whereHas('openingBalance', fn ($query) => $query->where('company_id', $companyId)->where('status', 'approved')->whereDate('document_date', '<=', $through))
            ->with('openingBalance.currency')->get()->each(function (OpeningBalanceLine $line) use ($cashboxes, $cashboxDocNum, $currencyDocNum, $rows): void {
                $cashbox = $cashboxes[$line->account_id] ?? null;
                $currency = $line->openingBalance?->currency;
                if (! $cashbox || ($cashboxDocNum && $cashbox->doc_num !== $cashboxDocNum) || ($currencyDocNum && $currency?->doc_num !== $currencyDocNum)) {
                    return;
                }
                $net = bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4);
                $rows->push($this->cashboxMovementRow(
                    $cashbox, $currency?->getKey(), $currency?->code, $line->openingBalance?->document_date,
                    $line->openingBalance?->doc_num, __('finance_reports.opening_balance'), '', 'approved',
                    bccomp($net, '0', 4) >= 0 ? $net : '0.0000', bccomp($net, '0', 4) < 0 ? bcmul($net, '-1', 4) : '0.0000',
                    route('admin.finance.opening-balances.show', $line->openingBalance),
                ));
            });

        return $rows;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function bankMovements(array $filters): Collection
    {
        $companyId = $this->companyId();
        $through = $filters['to_date'] ?? $filters['as_of_date'];
        $bankDocNum = $filters['bank_account_doc_num'] ?? null;
        $currencyDocNum = $filters['currency_doc_num'] ?? null;
        $rows = collect();
        $bankAccounts = BankAccount::query()->where('company_id', $companyId)->get()->keyBy('id');

        OpeningBalanceLine::query()->whereIn('bank_account_id', $bankAccounts->keys())
            ->whereHas('openingBalance', fn ($query) => $query->where('company_id', $companyId)->where('status', 'approved')->whereDate('document_date', '<=', $through))
            ->with('openingBalance.currency')->get()->each(function (OpeningBalanceLine $line) use ($bankAccounts, $bankDocNum, $currencyDocNum, $rows): void {
                $bank = $bankAccounts[$line->bank_account_id] ?? null;
                $currency = $line->openingBalance?->currency;
                if (! $bank || ($bankDocNum && $bank->doc_num !== $bankDocNum) || ($currencyDocNum && $currency?->doc_num !== $currencyDocNum)) {
                    return;
                }
                $net = bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4);
                $rows->push($this->bankMovementRow($bank, $currency?->getKey(), $currency?->code, $line->openingBalance?->document_date, $line->openingBalance?->doc_num, __('finance_reports.opening_balance'), '', 'approved', bccomp($net, '0', 4) >= 0 ? $net : '0.0000', bccomp($net, '0', 4) < 0 ? bcmul($net, '-1', 4) : '0.0000', route('admin.finance.opening-balances.show', $line->openingBalance)));
            });

        FundTransfer::query()->where('company_id', $companyId)->where('status', FundTransfer::StatusApproved)->whereDate('transfer_date', '<=', $through)
            ->with(['sourceBankAccount', 'targetBankAccount', 'sourceCashbox', 'targetCashbox', 'sourceCurrency', 'targetCurrency'])->get()
            ->each(function (FundTransfer $transfer) use ($bankDocNum, $currencyDocNum, $rows): void {
                if ($transfer->source_type === FundTransfer::HolderBankAccount && $transfer->sourceBankAccount
                    && (! $bankDocNum || $transfer->sourceBankAccount->doc_num === $bankDocNum)
                    && (! $currencyDocNum || $transfer->sourceCurrency?->doc_num === $currencyDocNum)) {
                    $rows->push($this->bankMovementRow($transfer->sourceBankAccount, $transfer->source_currency_id, $transfer->sourceCurrency?->code, $transfer->transfer_date, $transfer->doc_num, $this->value('transfer_out'), $this->transferHolder($transfer, false), $transfer->status, '0.0000', (string) $transfer->source_amount, route('admin.finance.fund-transfers.show', $transfer)));
                }
                if ($transfer->target_type === FundTransfer::HolderBankAccount && $transfer->targetBankAccount
                    && (! $bankDocNum || $transfer->targetBankAccount->doc_num === $bankDocNum)
                    && (! $currencyDocNum || $transfer->targetCurrency?->doc_num === $currencyDocNum)) {
                    $rows->push($this->bankMovementRow($transfer->targetBankAccount, $transfer->target_currency_id, $transfer->targetCurrency?->code, $transfer->transfer_date, $transfer->doc_num, $this->value('transfer_in'), $this->transferHolder($transfer, true), $transfer->status, (string) $transfer->target_amount, '0.0000', route('admin.finance.fund-transfers.show', $transfer)));
                }
            });

        CustomerReceipt::query()->where('company_id', $companyId)->where('status', CustomerReceipt::StatusApproved)
            ->whereNotNull('bank_account_id')->whereNull('cheque_id')->whereDate('receipt_date', '<=', $through)
            ->with(['bankAccount', 'currency', 'customer'])->get()->each(function (CustomerReceipt $receipt) use ($bankDocNum, $currencyDocNum, $rows): void {
                if (($bankDocNum && $receipt->bankAccount?->doc_num !== $bankDocNum) || ($currencyDocNum && $receipt->currency?->doc_num !== $currencyDocNum)) {
                    return;
                }
                $rows->push($this->bankMovementRow($receipt->bankAccount, $receipt->currency_id, $receipt->currency?->code, $receipt->receipt_date, $receipt->doc_num, $this->value('customer_receipt'), $receipt->customer?->name ?? '', $receipt->status, (string) $receipt->amount, '0.0000', route('admin.sales.customer-receipts.show', $receipt)));
            });

        SupplierPaymentContext::query()->where('company_id', $companyId)->where('status', SupplierPaymentContext::StatusApproved)
            ->whereNotNull('bank_account_id')->whereNull('cheque_id')->whereDate('payment_date', '<=', $through)
            ->with(['bankAccount', 'currency', 'supplier'])->get()->each(function (SupplierPaymentContext $payment) use ($bankDocNum, $currencyDocNum, $rows): void {
                if (($bankDocNum && $payment->bankAccount?->doc_num !== $bankDocNum) || ($currencyDocNum && $payment->currency?->doc_num !== $currencyDocNum)) {
                    return;
                }
                $rows->push($this->bankMovementRow($payment->bankAccount, $payment->currency_id, $payment->currency?->code, $payment->payment_date, $payment->doc_num, $this->value('supplier_payment'), $payment->supplier?->name ?? '', $payment->status, '0.0000', (string) $payment->amount, route('admin.purchases.supplier-payments.show', $payment)));
            });

        Cheque::query()->where('company_id', $companyId)->whereNotNull('bank_account_id')->with(['bankAccount', 'currency'])->get()
            ->each(function (Cheque $cheque) use ($bankDocNum, $currencyDocNum, $through, $rows): void {
                if (($bankDocNum && $cheque->bankAccount?->doc_num !== $bankDocNum) || ($currencyDocNum && $cheque->currency?->doc_num !== $currencyDocNum)) {
                    return;
                }
                $eventDate = $cheque->isReceived() ? $cheque->collected_at : $cheque->cleared_at;
                if (! $eventDate || $this->storageDate($eventDate) > $through || in_array($cheque->status, [Cheque::StatusReturned, Cheque::StatusCancelled, Cheque::StatusClearingReversed], true)) {
                    return;
                }
                $rows->push($this->bankMovementRow($cheque->bankAccount, $cheque->currency_id, $cheque->currency?->code, $eventDate, $cheque->doc_num, $this->value('cheque'), trim(implode(' / ', array_filter([$cheque->party_name, $cheque->cheque_number]))), $cheque->status, $cheque->isReceived() ? (string) $cheque->amount : '0.0000', $cheque->isIssued() ? (string) $cheque->amount : '0.0000', route('admin.finance.cheques.show', $cheque)));
            });

        return $rows;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function statementRows(Collection $allRows, array $filters): Collection
    {
        $from = $filters['from_date'] ?? null;
        $to = $filters['to_date'] ?? $filters['as_of_date'];
        $opening = $allRows->filter(fn (array $row): bool => $from !== null && $row['_date'] < $from)->groupBy('_balance_key')->map(fn (Collection $rows): string => $this->net($rows));
        $rows = $allRows->filter(fn (array $row): bool => ($from === null || $row['_date'] >= $from) && $row['_date'] <= $to)
            ->sortBy(fn (array $row): string => $row['_date'].'|'.$row['document'])->values();
        $running = $opening->all();

        return $rows->map(function (array $row) use (&$running): array {
            $key = $row['_balance_key'];
            $row['opening_balance'] = (string) ($running[$key] ?? '0.0000');
            $running[$key] = bcadd($row['opening_balance'], bcsub((string) $row['receipt'], (string) $row['payment'], 4), 4);
            $row['balance'] = $running[$key];

            return $row;
        });
    }

    /** @return array<string, mixed> */
    private function cashboxMovementRow(mixed $cashbox, mixed $currencyId, mixed $currencyCode, mixed $date, mixed $document, string $movement, string $reference, string $status, string $receipt, string $payment, string $url): array
    {
        return [
            '_url' => $url, '_date' => $this->storageDate($date), '_balance_key' => $cashbox?->getKey().':'.$currencyId,
            '_cashbox_id' => $cashbox?->getKey(), '_currency_id' => $currencyId,
            'date' => $this->date($date), 'cashbox' => trim(implode(' / ', array_filter([$cashbox?->doc_num, $cashbox?->name]))),
            'branch' => $cashbox?->branch?->name, 'currency' => $currencyCode, 'document' => $document,
            'movement' => $movement, 'party_reference' => $reference, 'status' => $this->value($status),
            'receipt' => $receipt, 'payment' => $payment,
        ];
    }

    /** @return array<string, mixed> */
    private function bankMovementRow(mixed $bank, mixed $currencyId, mixed $currencyCode, mixed $date, mixed $document, string $movement, string $reference, string $status, string $receipt, string $payment, string $url): array
    {
        return [
            '_url' => $url, '_date' => $this->storageDate($date), '_balance_key' => $bank?->getKey().':'.$currencyId,
            'date' => $this->date($date), 'bank_account' => $this->bankLabel($bank), 'currency' => $currencyCode,
            'document' => $document, 'movement' => $movement, 'party_reference' => $reference,
            'status' => $this->value($status), 'receipt' => $receipt, 'payment' => $payment,
        ];
    }

    /** @return array<string, string> */
    private function labels(array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => __("finance_reports.columns.{$key}")])->all();
    }

    /** @return array<string, string> */
    private function filterSummary(array $filters): array
    {
        return collect($filters)->except('type')->mapWithKeys(function (mixed $value, string $key): array {
            $display = in_array($key, ['from_date', 'to_date', 'as_of_date'], true)
                ? $this->dates->formatDate($value, '')
                : (string) $value;

            return [__("finance_reports.filters.{$key}") => $display];
        })->all();
    }

    /** @return array<string, array<string, string>> */
    private function currencyTotals(Collection $rows): array
    {
        $amountKeys = ['receipt', 'payment', 'balance', 'amount', 'allocated', 'unallocated', 'outstanding'];

        return $rows->filter(fn (array $row): bool => filled($row['currency'] ?? null))->groupBy('currency')
            ->map(function (Collection $currencyRows) use ($amountKeys): array {
                $totals = [];
                foreach ($amountKeys as $key) {
                    if ($currencyRows->contains(fn (array $row): bool => array_key_exists($key, $row))) {
                        $totals[__("finance_reports.columns.{$key}")] = $key === 'balance' && $currencyRows->contains(fn (array $row): bool => isset($row['_balance_key']))
                            ? $currencyRows->groupBy('_balance_key')->reduce(fn (string $sum, Collection $holderRows): string => bcadd($sum, (string) $holderRows->last()['balance'], 4), '0.0000')
                            : $this->sum($currencyRows, $key);
                    }
                }

                return $totals;
            })->all();
    }

    private function dateFilters(mixed $query, string $column, array $filters): void
    {
        $query
            ->when($filters['from_date'] ?? null, fn ($query, $date) => $query->whereDate($column, '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($query, $date) => $query->whereDate($column, '<=', $date));
    }

    private function sum(Collection $rows, string $key): string
    {
        return $rows->reduce(fn (string $sum, array $row): string => bcadd($sum, (string) ($row[$key] ?? '0'), 4), '0.0000');
    }

    private function net(Collection $rows): string
    {
        return bcsub($this->sum($rows, 'receipt'), $this->sum($rows, 'payment'), 4);
    }

    private function transferHolder(FundTransfer $transfer, bool $source): string
    {
        $type = $source ? $transfer->source_type : $transfer->target_type;
        $holder = $source
            ? ($type === FundTransfer::HolderCashbox ? $transfer->sourceCashbox : $transfer->sourceBankAccount)
            : ($type === FundTransfer::HolderCashbox ? $transfer->targetCashbox : $transfer->targetBankAccount);

        return $type === FundTransfer::HolderCashbox
            ? trim(implode(' / ', array_filter([$holder?->doc_num, $holder?->name])))
            : $this->bankLabel($holder);
    }

    private function bankLabel(mixed $bank): string
    {
        return trim(implode(' / ', array_filter([$bank?->doc_num, $bank?->account_name, $bank?->account_number])));
    }

    private function dueState(Cheque $cheque, Carbon $asOf): string
    {
        if (! $cheque->due_date) {
            return '';
        }

        $days = $asOf->copy()->startOfDay()->diffInDays($cheque->due_date, false);

        return $this->value($days < 0 ? 'overdue' : ($days === 0 ? 'due_today' : 'upcoming'));
    }

    private function agingBucket(int $days): string
    {
        return $this->value(match (true) {
            $days <= 0 => 'current',
            $days <= 30 => 'days_1_30',
            $days <= 60 => 'days_31_60',
            $days <= 90 => 'days_61_90',
            default => 'days_over_90',
        });
    }

    private function value(string $value): string
    {
        $key = "finance_reports.values.{$value}";

        return trans()->has($key) ? __($key) : $value;
    }

    private function date(mixed $value): string
    {
        return $this->dates->formatDate($value, '');
    }

    private function storageDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return filled($value) ? Carbon::parse($value)->toDateString() : '';
    }

    private function companyId(): int
    {
        return $this->companies->requireCompanyId();
    }
}

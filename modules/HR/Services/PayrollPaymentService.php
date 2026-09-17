<?php

namespace Modules\HR\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Currency;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;

final class PayrollPaymentService
{
    public function __construct(
        private readonly CashVoucherService $cashVouchers,
        private readonly JournalEntryService $journalEntries,
        private readonly FinancialPeriodService $financialPeriods,
    ) {}

    /**
     * @param  array{cashbox_doc_num: string, amount: mixed, payment_date: string, idempotency_key: string, reference?: string|null}  $data
     * @return array{payment: object, voucher: CashVoucher}
     */
    public function createCashPayment(int $payrollRunId, int $companyId, array $data): array
    {
        return DB::transaction(function () use ($payrollRunId, $companyId, $data): array {
            $existing = DB::table('hr_payroll_payments as payment')
                ->join('cash_vouchers as voucher', 'voucher.id', '=', 'payment.cash_voucher_id')
                ->where('payment.company_id', $companyId)
                ->where('payment.idempotency_key', $data['idempotency_key'])
                ->first(['payment.*', 'voucher.doc_num as voucher_doc_num']);
            if ($existing !== null) {
                return [
                    'payment' => $existing,
                    'voucher' => CashVoucher::query()->findOrFail($existing->cash_voucher_id),
                ];
            }

            $run = $this->run($payrollRunId, $companyId, lock: true);
            if ($run->status !== 'posted') {
                throw new DomainException(__('hr_payroll.messages.payment_requires_posted_run'));
            }

            $cashbox = Cashbox::query()
                ->with('account')
                ->forCompany($companyId)
                ->active()
                ->where('doc_num', $data['cashbox_doc_num'])
                ->whereNull('deleted_at')
                ->firstOrFail();
            $branchId = $cashbox->branch_id === null ? $run->branch_id : (int) $cashbox->branch_id;
            if ($run->branch_id !== null && (int) $run->branch_id !== (int) $branchId) {
                throw new DomainException(__('hr_payroll.messages.payment_branch_mismatch'));
            }

            $amount = number_format((float) $data['amount'], 4, '.', '');
            $remaining = $this->remainingForBranch($payrollRunId, $branchId);
            if (bccomp($amount, '0.0000', 4) <= 0 || bccomp($amount, $remaining, 4) > 0) {
                throw new DomainException(__('hr_payroll.messages.payment_exceeds_remaining', ['remaining' => $remaining]));
            }

            $currency = Currency::query()->forCompany($companyId)->active()->where('is_main', true)->firstOrFail();
            $payable = $this->payrollPayableAccount($companyId);
            $financialPeriod = $this->financialPeriods->resolveOpenForPostingDate(
                $companyId,
                $data['payment_date'],
                lockForUpdate: true,
            );
            $created = $this->cashVouchers->create(CashVoucher::TypePayment, [
                'voucher_date' => $data['payment_date'],
                'cashbox_doc_num' => $cashbox->doc_num,
                'currency_doc_num' => $currency->doc_num,
                'exchange_rate' => 1,
                'amount' => $amount,
                'person_name' => __('hr_payroll.payment.payee'),
                'reason' => __('hr_payroll.payment.reason', ['run' => $payrollRunId]),
                'description' => $data['reference'] ?? __('hr_payroll.payment.reference', ['run' => $payrollRunId]),
                'lines' => [[
                    'account_doc_num' => $payable->doc_num,
                    'amount' => $amount,
                    'description' => __('hr_payroll.payment.line_description', ['run' => $payrollRunId]),
                ]],
            ], $companyId);
            $voucher = $created['record'];
            $paymentId = DB::table('hr_payroll_payments')->insertGetId([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'financial_period_id' => $financialPeriod->getKey(),
                'payroll_run_id' => $payrollRunId,
                'cash_voucher_id' => $voucher->getKey(),
                'amount' => $amount,
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'payment' => DB::table('hr_payroll_payments')->where('id', $paymentId)->first(),
                'voucher' => $voucher,
            ];
        }, attempts: 3);
    }

    public function postApprovedVoucher(CashVoucher $voucher): ?JournalEntry
    {
        return DB::transaction(function () use ($voucher): ?JournalEntry {
            $payment = DB::table('hr_payroll_payments')
                ->where('cash_voucher_id', $voucher->getKey())
                ->lockForUpdate()
                ->first();
            if ($payment === null) {
                return null;
            }

            if ($payment->journal_entry_id !== null) {
                return JournalEntry::query()->findOrFail($payment->journal_entry_id);
            }

            $run = $this->run((int) $payment->payroll_run_id, (int) $payment->company_id, lock: true);
            if ($run->status !== 'posted' || ! $voucher->isApproved() || ! $voucher->isPayment()) {
                throw new DomainException(__('hr_payroll.messages.payment_approval_invalid'));
            }

            if ((int) $voucher->company_id !== (int) $payment->company_id
                || (int) $voucher->cashbox?->branch_id !== (int) $payment->branch_id) {
                throw new DomainException(__('hr_payroll.messages.payment_branch_mismatch'));
            }

            $mainCurrency = Currency::query()->forCompany((int) $payment->company_id)->active()->where('is_main', true)->firstOrFail();
            if ((int) $voucher->currency_id !== (int) $mainCurrency->getKey() || bccomp((string) $voucher->exchange_rate, '1.000000', 6) !== 0) {
                throw new DomainException(__('hr_payroll.messages.functional_currency_required', ['employee' => __('hr_payroll.payment.payee')]));
            }

            $remaining = $this->remainingForBranch((int) $payment->payroll_run_id, $payment->branch_id === null ? null : (int) $payment->branch_id, (int) $payment->id);
            if (bccomp((string) $payment->amount, $remaining, 4) > 0) {
                throw new DomainException(__('hr_payroll.messages.payment_exceeds_remaining', ['remaining' => $remaining]));
            }

            $cashAccount = $voucher->cashbox?->account;
            if (! $cashAccount instanceof Account) {
                throw new DomainException(__('hr_payroll.messages.cashbox_account_missing'));
            }

            $journal = $this->journalEntries->createPostedFromSource([
                'entry_date' => $voucher->voucher_date,
                'company_id' => (int) $payment->company_id,
                'financial_period_id' => (int) $payment->financial_period_id,
                'branch_id' => $payment->branch_id,
                'currency_id' => $voucher->currency_id,
                'exchange_rate' => 1,
                'description' => __('hr_payroll.journal.payment_description', ['run' => $payment->payroll_run_id]),
                'source_type' => 'hr_payroll_payment',
                'source_id' => (int) $payment->id,
                'source_doc_num' => (string) $voucher->doc_num,
            ], [
                [
                    'account_id' => $this->payrollPayableAccount((int) $payment->company_id)->getKey(),
                    'debit_amount' => $payment->amount,
                    'credit_amount' => '0.0000',
                    'description' => __('hr_payroll.journal.payment_payable'),
                    'branch_id' => $payment->branch_id,
                ],
                [
                    'account_id' => $cashAccount->getKey(),
                    'debit_amount' => '0.0000',
                    'credit_amount' => $payment->amount,
                    'description' => __('hr_payroll.journal.payment_cash'),
                    'branch_id' => $payment->branch_id,
                ],
            ]);
            DB::table('hr_payroll_payments')->where('id', $payment->id)->update([
                'status' => 'approved',
                'journal_entry_id' => $journal->getKey(),
                'approved_at' => now(),
                'approved_by' => auth()->id(),
                'updated_at' => now(),
            ]);

            return $journal;
        }, attempts: 3);
    }

    public function reverseCancelledVoucher(CashVoucher $voucher): ?JournalEntry
    {
        return DB::transaction(function () use ($voucher): ?JournalEntry {
            $payment = DB::table('hr_payroll_payments')
                ->where('cash_voucher_id', $voucher->getKey())
                ->lockForUpdate()
                ->first();
            if ($payment === null) {
                return null;
            }

            if ($payment->reversal_journal_entry_id !== null) {
                return JournalEntry::query()->findOrFail($payment->reversal_journal_entry_id);
            }

            if ($payment->journal_entry_id === null) {
                DB::table('hr_payroll_payments')->where('id', $payment->id)->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => auth()->id(),
                    'updated_at' => now(),
                ]);

                return null;
            }

            $original = JournalEntry::query()->findOrFail($payment->journal_entry_id);
            $period = $this->financialPeriods->resolveOpenForPostingDate(
                (int) $payment->company_id,
                $voucher->cancelled_at?->toDateString() ?? now()->toDateString(),
                lockForUpdate: true,
            );
            $reversal = $this->journalEntries->createPostedReversalFromSource($original, [
                'entry_date' => $voucher->cancelled_at?->toDateString() ?? now()->toDateString(),
                'company_id' => (int) $payment->company_id,
                'financial_period_id' => $period->getKey(),
                'branch_id' => $payment->branch_id,
                'currency_id' => $original->currency_id,
                'exchange_rate' => $original->exchange_rate,
                'description' => __('hr_payroll.journal.payment_reversal_description', ['run' => $payment->payroll_run_id]),
                'source_type' => 'hr_payroll_payment_reversal',
                'source_id' => (int) $payment->id,
                'source_doc_num' => (string) $voucher->doc_num,
            ]);
            DB::table('hr_payroll_payments')->where('id', $payment->id)->update([
                'status' => 'cancelled',
                'reversal_journal_entry_id' => $reversal->getKey(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'updated_at' => now(),
            ]);

            return $reversal;
        }, attempts: 3);
    }

    private function remainingForBranch(int $payrollRunId, ?int $branchId, ?int $excludingPaymentId = null): string
    {
        $payable = DB::table('hr_payslips')
            ->where('payroll_run_id', $payrollRunId)
            ->when($branchId === null, fn ($query) => $query->whereNull('branch_id'), fn ($query) => $query->where('branch_id', $branchId))
            ->sum('net_amount');
        $paid = DB::table('hr_payroll_payments as payment')
            ->join('cash_vouchers as voucher', 'voucher.id', '=', 'payment.cash_voucher_id')
            ->where('payment.payroll_run_id', $payrollRunId)
            ->when($branchId === null, fn ($query) => $query->whereNull('payment.branch_id'), fn ($query) => $query->where('payment.branch_id', $branchId))
            ->when($excludingPaymentId !== null, fn ($query) => $query->where('payment.id', '<>', $excludingPaymentId))
            ->where('payment.status', 'approved')
            ->whereNotNull('payment.journal_entry_id')
            ->where('voucher.status', CashVoucher::StatusApproved)
            ->whereNull('voucher.deleted_at')
            ->sum('payment.amount');

        return bcsub(number_format((float) $payable, 4, '.', ''), number_format((float) $paid, 4, '.', ''), 4);
    }

    private function payrollPayableAccount(int $companyId): Account
    {
        $classification = AccountClassification::query()->where('code', 'payroll_payable')->where('status', 'active')->firstOrFail();

        return Account::query()
            ->forCompany($companyId)
            ->eligibleForDirectPosting()
            ->where('account_classification_id', $classification->getKey())
            ->orderBy('account_code')
            ->first()
            ?? throw new DomainException(__('hr_payroll.messages.payroll_payable_mapping_missing'));
    }

    private function run(int $payrollRunId, int $companyId, bool $lock = false): object
    {
        return DB::table('hr_payroll_runs as run')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('run.id', $payrollRunId)
            ->where('period.company_id', $companyId)
            ->whereNull('run.deleted_at')
            ->whereNull('period.deleted_at')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first(['run.*', 'period.company_id', 'period.period_start', 'period.period_end'])
            ?? throw new DomainException(__('hr_payroll.messages.run_not_found'));
    }
}

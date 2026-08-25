<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Finance\Models\ChequeClearingEvent;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\SupplierPaymentContext;

class SupplierPaymentPostingService
{
    public function __construct(
        private readonly JournalEntryService $journalEntries,
        private readonly FinancialPeriodService $financialPeriods,
    ) {}

    public function post(SupplierPaymentContext $payment, Account $creditAccount, ?int $bankAccountId = null): JournalEntry
    {
        $payment->loadMissing(['supplier.account', 'currency', 'journalEntry']);
        $supplierAccount = $payment->supplier?->account;

        if ($payment->journalEntry instanceof JournalEntry) {
            return $payment->journalEntry;
        }

        if (! $supplierAccount instanceof Account
            || $supplierAccount->trashed()
            || ! $supplierAccount->is_postable
            || $supplierAccount->is_group
            || $supplierAccount->status !== 'active') {
            throw new DomainException(__('The Supplier requires an active postable Accounts Payable account.'));
        }

        if ($creditAccount->trashed()
            || ! $creditAccount->is_postable
            || $creditAccount->is_group
            || $creditAccount->status !== 'active') {
            throw new DomainException(__('The selected payment source requires an active postable GL account.'));
        }

        $journalEntry = $this->journalEntries->createPostedFromSource([
            'entry_date' => $payment->payment_date,
            'company_id' => (int) $payment->company_id,
            'financial_period_id' => (int) $payment->financial_period_id,
            'branch_id' => $payment->branch_id,
            'currency_id' => $payment->currency_id,
            'exchange_rate' => $payment->exchange_rate,
            'description' => __('Supplier payment :document', ['document' => $payment->doc_num]),
            'notes' => $payment->notes,
            'source_type' => 'supplier_payment',
            'source_id' => $payment->getKey(),
            'source_doc_num' => $payment->doc_num,
        ], [[
            'account_id' => (int) $supplierAccount->getKey(),
            'debit_amount' => $payment->amount,
            'credit_amount' => '0.0000',
            'description' => __('Supplier payable settlement'),
            'supplier_id' => $payment->supplier_id,
            'branch_id' => $payment->branch_id,
        ], [
            'account_id' => (int) $creditAccount->getKey(),
            'debit_amount' => '0.0000',
            'credit_amount' => $payment->amount,
            'description' => __('Supplier :method payment', ['method' => $payment->payment_method]),
            'supplier_id' => $payment->supplier_id,
            'bank_account_id' => $bankAccountId,
            'branch_id' => $payment->branch_id,
        ]]);

        $payment->forceFill([
            'journal_entry_id' => $journalEntry->getKey(),
            'status' => SupplierPaymentContext::StatusApproved,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ])->save();
        $this->refreshInvoices($payment);

        return $journalEntry;
    }

    public function paymentPaperAccount(int $companyId): Account
    {
        $accountCode = (string) config('purchases.accounts.supplier_payment_papers');
        $account = Account::query()
            ->forCompany($companyId)
            ->eligibleForDirectPosting()
            ->where('account_code', $accountCode)
            ->where('account_type', Account::TypeLiability)
            ->first();

        if (! $account instanceof Account) {
            throw new DomainException(__('The Supplier Payment Papers account is not configured as an active postable liability account.'));
        }

        return $account;
    }

    public function clearIssuedCheque(
        SupplierPaymentContext $payment,
        Account $bankAccount,
        int $bankAccountId,
        Carbon|string|null $clearingDate = null,
        int $clearingSequence = 1,
    ): JournalEntry {
        $sourceType = $clearingSequence === 1 ? 'supplier_cheque_clearing' : 'supplier_cheque_clearing_'.$clearingSequence;
        $existing = JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $payment->getKey())
            ->lockForUpdate()
            ->first();

        if ($existing instanceof JournalEntry) {
            return $existing;
        }

        if ($bankAccount->trashed()
            || ! $bankAccount->is_postable
            || $bankAccount->is_group
            || $bankAccount->status !== 'active') {
            throw new DomainException(__('The issuing Bank Account requires an active postable GL account.'));
        }

        $paymentPaperAccount = $this->paymentPaperAccount((int) $payment->company_id);
        $entryDate = Carbon::parse($clearingDate ?? now())->toDateString();
        $period = $this->financialPeriods->resolveOpenForPostingDate(
            (int) $payment->company_id,
            $entryDate,
            lockForUpdate: true,
        );

        return $this->journalEntries->createPostedFromSource([
            'entry_date' => $entryDate,
            'company_id' => (int) $payment->company_id,
            'financial_period_id' => (int) $period->getKey(),
            'branch_id' => $payment->branch_id,
            'currency_id' => $payment->currency_id,
            'exchange_rate' => $payment->exchange_rate,
            'description' => __('Supplier outgoing cheque clearing :document', ['document' => $payment->doc_num]),
            'notes' => $payment->notes,
            'source_type' => $sourceType,
            'source_id' => $payment->getKey(),
            'source_doc_num' => $payment->doc_num,
        ], [[
            'account_id' => (int) $paymentPaperAccount->getKey(),
            'debit_amount' => $payment->amount,
            'credit_amount' => '0.0000',
            'description' => __('Supplier Payment Papers cleared'),
            'supplier_id' => $payment->supplier_id,
            'branch_id' => $payment->branch_id,
        ], [
            'account_id' => (int) $bankAccount->getKey(),
            'debit_amount' => '0.0000',
            'credit_amount' => $payment->amount,
            'description' => __('Outgoing Supplier cheque cleared through Bank Account'),
            'supplier_id' => $payment->supplier_id,
            'bank_account_id' => $bankAccountId,
            'branch_id' => $payment->branch_id,
        ]]);
    }

    public function reverseChequeClearing(
        SupplierPaymentContext $payment,
        ChequeClearingEvent $event,
        string $reason,
        Carbon|string|null $reversalDate = null,
    ): JournalEntry {
        $event->loadMissing('clearingJournalEntry');
        if (! $event->clearingJournalEntry instanceof JournalEntry || $event->status !== ChequeClearingEvent::StatusCleared) {
            throw new DomainException(__('Only an active cleared-cheque event can be reversed.'));
        }
        $entryDate = Carbon::parse($reversalDate ?? now())->toDateString();
        $period = $this->financialPeriods->resolveOpenForPostingDate((int) $payment->company_id, $entryDate, lockForUpdate: true);

        return $this->journalEntries->createPostedReversalFromSource($event->clearingJournalEntry, [
            'entry_date' => $entryDate, 'company_id' => (int) $payment->company_id,
            'financial_period_id' => (int) $period->getKey(), 'branch_id' => $payment->branch_id,
            'currency_id' => $payment->currency_id, 'exchange_rate' => $payment->exchange_rate,
            'description' => __('Supplier outgoing cheque clearing reversal :document', ['document' => $payment->doc_num]),
            'notes' => $reason, 'source_type' => 'supplier_cheque_clearing_reversal_'.$event->sequence,
            'source_id' => $payment->getKey(), 'source_doc_num' => $payment->doc_num,
        ]);
    }

    public function reverse(SupplierPaymentContext $payment, string $reason, Carbon|string|null $reversalDate = null): ?JournalEntry
    {
        $payment->loadMissing('journalEntry');
        $journalEntry = $payment->journalEntry;

        if (! $journalEntry instanceof JournalEntry) {
            $payment->forceFill([
                'status' => SupplierPaymentContext::StatusCancelled,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();
            $this->refreshInvoices($payment);

            return null;
        }

        if ($journalEntry->reversed_entry_id !== null) {
            $payment->forceFill(['status' => SupplierPaymentContext::StatusCancelled])->save();
            $this->refreshInvoices($payment);

            return $journalEntry->reversedEntry;
        }

        $entryDate = Carbon::parse($reversalDate ?? now())->toDateString();
        $reversalPeriod = $this->financialPeriods->resolveOpenForPostingDate(
            (int) $payment->company_id,
            $entryDate,
            lockForUpdate: true,
        );
        $reversal = $this->journalEntries->createPostedReversalFromSource($journalEntry, [
            'entry_date' => $entryDate,
            'company_id' => (int) $payment->company_id,
            'financial_period_id' => (int) $reversalPeriod->getKey(),
            'branch_id' => $payment->branch_id,
            'currency_id' => $payment->currency_id,
            'exchange_rate' => $payment->exchange_rate,
            'description' => __('Supplier payment reversal :document', ['document' => $payment->doc_num]),
            'notes' => $reason,
            'source_type' => 'supplier_payment_reversal',
            'source_id' => $payment->getKey(),
            'source_doc_num' => $payment->doc_num,
        ]);

        $payment->forceFill([
            'status' => SupplierPaymentContext::StatusCancelled,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();
        $this->refreshInvoices($payment);

        return $reversal;
    }

    private function refreshInvoices(SupplierPaymentContext $payment): void
    {
        PurchaseInvoice::query()
            ->whereKey($payment->allocations()->pluck('purchase_invoice_id')->unique()->all())
            ->get()
            ->each(fn (PurchaseInvoice $invoice) => $invoice->refreshPaymentTotals());
    }
}

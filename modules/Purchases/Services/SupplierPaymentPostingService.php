<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Services\FinancialPeriodService;
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

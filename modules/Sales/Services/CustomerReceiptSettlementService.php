<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Finance\Models\Cheque;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoicePaymentSchedule;
use Modules\Sales\Models\CustomerReceipt;

class CustomerReceiptSettlementService
{
    public function __construct(
        private readonly SalesAccountingService $accounting,
        private readonly JournalEntryService $journals,
        private readonly FinancialPeriodService $periods,
        private readonly SalesCycleAuditService $audit,
    ) {}

    public function synchronizeCheque(Cheque $cheque): void
    {
        DB::transaction(function () use ($cheque): void {
            $receipt = CustomerReceipt::query()->where('cheque_id', $cheque->id)->lockForUpdate()->first();
            if (! $receipt || $receipt->status !== CustomerReceipt::StatusApproved) {
                return;
            }
            if (in_array($cheque->status, [Cheque::StatusReturned, Cheque::StatusCancelled], true)) {
                $this->reverse($receipt, __('Received cheque returned or cancelled.'));
            } elseif ($cheque->status === Cheque::StatusCollected && ! $receipt->journal_entry_id) {
                $posting = $this->postingCopy($receipt);
                $journal = $this->accounting->postReceipt($posting);
                $applied = '0.0000';
                foreach ($receipt->allocations()->orderBy('customer_invoice_id')->orderBy('id')->lockForUpdate()->get() as $allocation) {
                    $invoice = CustomerInvoice::query()->lockForUpdate()->findOrFail($allocation->customer_invoice_id);
                    $schedule = CustomerInvoicePaymentSchedule::query()->lockForUpdate()->findOrFail($allocation->customer_invoice_payment_schedule_id);
                    $amount = bccomp($allocation->allocated_amount, $schedule->outstanding_amount, 4) > 0 ? $schedule->outstanding_amount : $allocation->allocated_amount;
                    $allocation->update(['allocated_amount' => $amount, 'applied_at' => now(), 'applied_by' => auth()->id()]);
                    $schedule->increment('collected_amount', $amount);
                    $this->refreshInvoice($invoice);
                    $applied = bcadd($applied, $amount, 4);
                }
                $receipt->update(['journal_entry_id' => $journal->id, 'unallocated_amount' => bcsub($receipt->amount, $applied, 4), 'updated_by' => auth()->id()]);
                $this->audit->record($receipt, 'customer_receipt.cheque_collected', ['cheque_doc_num' => $cheque->doc_num, 'applied_amount' => $applied]);
            }
        });
    }

    public function deferLegacyCheque(CustomerReceipt $receipt): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt): CustomerReceipt {
            $locked = CustomerReceipt::query()->with('cheque')->lockForUpdate()->findOrFail($receipt->id);
            if (! $locked->journal_entry_id || $locked->status !== CustomerReceipt::StatusApproved || ! $locked->cheque || ! in_array($locked->cheque->status, [Cheque::StatusReceived, Cheque::StatusDeposited], true)) {
                throw new DomainException(__('Only a prematurely posted pending cheque can be deferred.'));
            }
            $originalJournalId = $locked->journal_entry_id;
            $locked = $this->reverse($locked, __('Defer historical cheque settlement until actual collection.'));
            $locked->allocations()->update(['applied_at' => null, 'applied_by' => null]);
            $locked->update(['status' => CustomerReceipt::StatusApproved, 'journal_entry_id' => null,
                'unallocated_amount' => bcsub($locked->amount, (string) $locked->allocations()->sum('allocated_amount'), 4),
                'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null]);
            $this->audit->record($locked, 'customer_receipt.legacy_cheque_deferred', ['original_journal_entry_id' => $originalJournalId, 'reversal_journal_entry_id' => $locked->reversal_journal_entry_id]);

            return $locked->refresh();
        });
    }

    public function reverse(CustomerReceipt $receipt, string $reason): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $reason): CustomerReceipt {
            if (trim($reason) === '') {
                throw new DomainException(__('A reversal reason is required.'));
            }
            $locked = CustomerReceipt::query()->with('journalEntry')->lockForUpdate()->findOrFail($receipt->id);
            if ($locked->status === CustomerReceipt::StatusCancelled) {
                return $locked;
            }
            if ($locked->status !== CustomerReceipt::StatusApproved) {
                throw new DomainException(__('Only an approved collection can be reversed.'));
            }
            $reversal = null;
            if ($locked->journalEntry) {
                $posting = $this->postingCopy($locked);
                $reversal = $this->journals->createPostedReversalFromSource($locked->journalEntry, [
                    'company_id' => $locked->company_id, 'branch_id' => $locked->branch_id, 'financial_period_id' => $posting->financial_period_id,
                    'entry_date' => $posting->receipt_date, 'currency_id' => $locked->currency_id, 'exchange_rate' => $locked->exchange_rate,
                    'description' => __('Collection reversal').' '.$locked->doc_num, 'notes' => trim($reason),
                    'source_type' => 'customer_receipt_reversal', 'source_id' => $locked->id, 'source_doc_num' => $locked->doc_num,
                ]);
                foreach ($locked->allocations()->whereNotNull('applied_at')->orderBy('customer_invoice_id')->orderBy('id')->lockForUpdate()->get() as $allocation) {
                    $invoice = CustomerInvoice::query()->lockForUpdate()->findOrFail($allocation->customer_invoice_id);
                    $schedule = CustomerInvoicePaymentSchedule::query()->lockForUpdate()->findOrFail($allocation->customer_invoice_payment_schedule_id);
                    if (bccomp($schedule->collected_amount, $allocation->allocated_amount, 4) < 0) {
                        throw new DomainException(__('Collection allocation does not reconcile with the invoice.'));
                    }
                    $schedule->decrement('collected_amount', $allocation->allocated_amount);
                    $this->refreshInvoice($invoice);
                }
            }
            $locked->update(['status' => CustomerReceipt::StatusCancelled, 'reversal_journal_entry_id' => $reversal?->id,
                'cancelled_by' => auth()->id(), 'cancelled_at' => now(), 'cancel_reason' => trim($reason), 'unallocated_amount' => '0', 'updated_by' => auth()->id()]);
            $this->audit->record($locked, 'customer_receipt.reversed', ['reason' => trim($reason), 'reversal_journal_entry_id' => $reversal?->id]);

            return $locked->refresh();
        });
    }

    private function postingCopy(CustomerReceipt $receipt): CustomerReceipt
    {
        $posting = clone $receipt;
        $posting->receipt_date = now()->toDateString();
        $posting->financial_period_id = $this->periods->resolveOpenForPostingDate((int) $receipt->company_id, now()->toDateString(), lockForUpdate: true)->id;

        return $posting;
    }

    private function refreshInvoice(CustomerInvoice $invoice): void
    {
        $paid = (string) $invoice->paymentSchedules()->sum('collected_amount');
        $invoice->update(['paid_amount' => $paid, 'remaining_amount' => bcsub(bcsub($invoice->total_amount, $paid, 4), $invoice->credited_amount, 4)]);
    }
}

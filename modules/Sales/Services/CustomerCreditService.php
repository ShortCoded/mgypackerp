<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Sales\Models\CustomerCreditAllocation;
use Modules\Sales\Models\CustomerCreditRefund;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoicePaymentSchedule;

class CustomerCreditService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly SalesAmountService $amounts,
        private readonly JournalEntryService $journals,
        private readonly FinancialPeriodService $periods,
        private readonly SalesCycleAuditService $audit,
    ) {}

    public function allocate(
        CustomerInvoice $creditNote,
        CustomerInvoice $targetInvoice,
        string $amount,
        string $allocationDate,
        ?CustomerInvoicePaymentSchedule $schedule = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): CustomerCreditAllocation {
        return DB::transaction(function () use ($creditNote, $targetInvoice, $amount, $allocationDate, $schedule, $notes, $idempotencyKey): CustomerCreditAllocation {
            Company::query()->whereKey($creditNote->company_id)->lockForUpdate()->firstOrFail();
            $period = $this->periods->resolveOpenForPostingDate(
                (int) $creditNote->company_id, $allocationDate, lockForUpdate: true,
            );
            $credit = CustomerInvoice::query()->with('customer')->lockForUpdate()->findOrFail($creditNote->getKey());
            $invoice = CustomerInvoice::query()->lockForUpdate()->findOrFail($targetInvoice->getKey());
            $key = $idempotencyKey ?? (string) Str::uuid();
            $existing = CustomerCreditAllocation::query()
                ->where('company_id', $credit->company_id)
                ->where('idempotency_key', $key)
                ->first();
            if ($existing instanceof CustomerCreditAllocation) {
                if ((int) $existing->credit_note_id !== (int) $credit->getKey()
                    || (int) $existing->target_invoice_id !== (int) $invoice->getKey()
                    || ($schedule !== null && (int) $existing->target_payment_schedule_id !== (int) $schedule->getKey())
                    || $existing->allocation_date?->toDateString() !== $allocationDate
                    || $this->amounts->compare($existing->amount, $amount) !== 0) {
                    throw new DomainException(__('sales_return_correction.idempotency_conflict'));
                }
                if ($existing->status !== CustomerCreditAllocation::StatusApplied) {
                    throw new DomainException(__('sales_return_correction.allocation_already_reversed'));
                }

                return $existing->load(['creditNote', 'targetInvoice', 'targetPaymentSchedule']);
            }
            $this->assertCreditUsable($credit);
            if ((int) $invoice->currency_id !== (int) $credit->currency_id) {
                throw new DomainException(__('sales_balance_report.credit_currency_mismatch'));
            }
            if ($invoice->document_type !== CustomerInvoice::TypeInvoice || $invoice->posting_status !== 'posted'
                || (int) $invoice->company_id !== (int) $credit->company_id
                || (int) $invoice->customer_id !== (int) $credit->customer_id) {
                throw new DomainException(__('Customer Credit and target Invoice must be posted documents for the same Customer and company.'));
            }
            $this->amounts->assertPositive($amount, __('Credit allocation amount must be greater than zero.'), 4);
            $this->amounts->assertNotGreaterThan($amount, (string) $credit->credit_available_amount, __('Credit allocation exceeds the available Customer Credit.'), 4);
            $this->amounts->assertNotGreaterThan($amount, (string) $invoice->remaining_amount, __('Credit allocation exceeds the target Invoice outstanding amount.'), 4);

            $lockedSchedule = $schedule === null
                ? $invoice->paymentSchedules()->whereRaw('(amount - collected_amount - credited_amount - actual_withholding_amount) > 0')->orderBy('due_date')->lockForUpdate()->first()
                : CustomerInvoicePaymentSchedule::query()->lockForUpdate()->findOrFail($schedule->getKey());
            if (! $lockedSchedule instanceof CustomerInvoicePaymentSchedule
                || (int) $lockedSchedule->customer_invoice_id !== (int) $invoice->getKey()) {
                throw new DomainException(__('An open target Invoice installment is required.'));
            }
            $this->amounts->assertNotGreaterThan($amount, $lockedSchedule->outstanding_amount, __('Credit allocation exceeds the target installment outstanding amount.'), 4);

            $allocation = CustomerCreditAllocation::query()->create([
                'company_id' => $credit->company_id, 'financial_period_id' => $period->getKey(),
                'customer_id' => $credit->customer_id, 'credit_note_id' => $credit->getKey(),
                'idempotency_key' => $key,
                'target_invoice_id' => $invoice->getKey(), 'target_payment_schedule_id' => $lockedSchedule->getKey(),
                'allocation_date' => $allocationDate, 'amount' => $amount,
                'status' => CustomerCreditAllocation::StatusApplied, 'notes' => $notes,
                'applied_by' => auth()->id(), 'applied_at' => now(),
            ]);

            $credit->forceFill([
                'credit_available_amount' => $this->amounts->subtract($credit->credit_available_amount, $amount),
                'credit_allocated_amount' => $this->amounts->add($credit->credit_allocated_amount, $amount),
            ])->save();
            $lockedSchedule->increment('credited_amount', $amount);
            $invoice->forceFill([
                'credited_amount' => $this->amounts->add($invoice->credited_amount, $amount),
                'remaining_amount' => $this->amounts->subtract($invoice->remaining_amount, $amount),
            ])->save();

            return $allocation->load(['creditNote', 'targetInvoice', 'targetPaymentSchedule']);
        }, 3);
    }

    public function reverseAllocation(CustomerCreditAllocation $allocation, string $reason, ?int $correctionId = null): CustomerCreditAllocation
    {
        return DB::transaction(function () use ($allocation, $reason, $correctionId): CustomerCreditAllocation {
            Company::query()->whereKey($allocation->company_id)->lockForUpdate()->firstOrFail();
            $reason = trim($reason);
            if ($reason === '') {
                throw new DomainException(__('sales_return_correction.allocation_reversal_reason_required'));
            }
            $reversedAt = now();
            $allocationDate = $allocation->allocation_date?->toDateString();
            if ($allocationDate === null) {
                throw new DomainException(__('sales_return_correction.allocation_not_reversible'));
            }
            $proposal = $correctionId === null ? null : app(SalesReturnCorrectionService::class)->execution($correctionId, 'allocation', (int) $allocation->id);
            $postingDate = $proposal?->posting_date->toDateString() ?? $reversedAt->toDateString();
            if ($proposal === null) {
                $this->periods->resolveOpenForPostingDate((int) $allocation->company_id, $allocationDate,
                    expectedPeriodId: (int) $allocation->financial_period_id, lockForUpdate: true);
            }
            $period = $this->periods->resolveOpenForPostingDate((int) $allocation->company_id, $postingDate,
                expectedPeriodId: (int) ($proposal?->posting_financial_period_id ?? $allocation->financial_period_id), lockForUpdate: true);
            $credit = CustomerInvoice::query()->lockForUpdate()->findOrFail($allocation->credit_note_id);
            $invoice = CustomerInvoice::query()->lockForUpdate()->findOrFail($allocation->target_invoice_id);
            $schedule = CustomerInvoicePaymentSchedule::query()->lockForUpdate()->findOrFail($allocation->target_payment_schedule_id);
            $locked = CustomerCreditAllocation::query()->lockForUpdate()->findOrFail($allocation->getKey());
            $amount = (string) $locked->amount;
            if ((int) $locked->credit_note_id !== (int) $credit->getKey()
                || (int) $locked->target_invoice_id !== (int) $invoice->getKey()
                || (int) $locked->target_payment_schedule_id !== (int) $schedule->getKey()
                || $locked->status !== CustomerCreditAllocation::StatusApplied
                || $locked->reversed_at !== null
                || $credit->document_type !== CustomerInvoice::TypeCreditNote
                || $credit->status !== CustomerInvoice::StatusPosted
                || $credit->posting_status !== 'posted'
                || $invoice->document_type !== CustomerInvoice::TypeInvoice
                || $invoice->status !== CustomerInvoice::StatusPosted
                || $invoice->posting_status !== 'posted'
                || (int) $credit->company_id !== (int) $locked->company_id
                || (int) $invoice->company_id !== (int) $credit->company_id
                || (int) $invoice->customer_id !== (int) $credit->customer_id
                || ($correctionId === null && (int) $locked->financial_period_id !== (int) $period->getKey())
                || $locked->allocation_date?->toDateString() !== $allocationDate
                || (int) $schedule->customer_invoice_id !== (int) $invoice->getKey()
                || $this->amounts->compare($amount, '0') <= 0
                || $this->amounts->compare($schedule->credited_amount, $amount) < 0
                || $this->amounts->compare($invoice->credited_amount, $amount) < 0
                || $this->amounts->compare($credit->credit_allocated_amount, $amount) < 0
                || $this->amounts->compare(
                    $invoice->remaining_amount,
                    app(CustomerInvoiceBalanceService::class)->remaining($invoice),
                ) !== 0) {
                throw new DomainException(__('sales_return_correction.allocation_not_reversible'));
            }

            $this->assertCreditBalanceReconcilesForAllocationReversal($credit);
            $scheduleCreditedAfter = $this->amounts->subtract($schedule->credited_amount, $amount);
            $invoiceCreditedAfter = $this->amounts->subtract($invoice->credited_amount, $amount);
            $invoiceRemainingAfter = $this->amounts->add($invoice->remaining_amount, $amount);
            $creditAvailableAfter = $this->amounts->add($credit->credit_available_amount, $amount);
            $creditAllocatedAfter = $this->amounts->subtract($credit->credit_allocated_amount, $amount);
            $reversalEffect = [
                'credit_note_id' => $credit->getKey(),
                'target_invoice_id' => $invoice->getKey(),
                'target_payment_schedule_id' => $schedule->getKey(),
                'amount' => $amount,
                'allocation_date' => $allocationDate,
                'allocation_financial_period_id' => $locked->financial_period_id,
                'reversal_date' => $postingDate,
                'sales_return_correction_id' => $correctionId,
                'reversal_financial_period_id' => $period->getKey(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => $reason,
                'credit_available_before' => (string) $credit->credit_available_amount,
                'credit_available_after' => $creditAvailableAfter,
                'credit_allocated_before' => (string) $credit->credit_allocated_amount,
                'credit_allocated_after' => $creditAllocatedAfter,
                'target_invoice_credited_before' => (string) $invoice->credited_amount,
                'target_invoice_credited_after' => $invoiceCreditedAfter,
                'target_invoice_remaining_before' => (string) $invoice->remaining_amount,
                'target_invoice_remaining_after' => $invoiceRemainingAfter,
                'target_schedule_credited_before' => (string) $schedule->credited_amount,
                'target_schedule_credited_after' => $scheduleCreditedAfter,
            ];
            $schedule->forceFill(['credited_amount' => $scheduleCreditedAfter])->save();
            $invoice->forceFill([
                'credited_amount' => $invoiceCreditedAfter,
                'remaining_amount' => $invoiceRemainingAfter,
            ])->save();
            $credit->forceFill([
                'credit_available_amount' => $creditAvailableAfter,
                'credit_allocated_amount' => $creditAllocatedAfter,
            ])->save();
            $locked->forceFill([
                'status' => CustomerCreditAllocation::StatusReversed,
                'reversed_by' => auth()->id(),
                'reversed_at' => $reversedAt,
                'reversal_reason' => $reason,
                'reversal_effect_snapshot' => $reversalEffect,
            ])->save();
            $this->audit->record($locked, 'customer_credit_allocation.reversed', [
                'credit_note' => $credit->doc_num,
                'target_invoice' => $invoice->doc_num,
                'amount' => $amount,
                'reason' => $reason,
            ]);

            return $locked->refresh()->load(['creditNote', 'targetInvoice', 'targetPaymentSchedule']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function refund(CustomerInvoice $creditNote, array $data): CustomerCreditRefund
    {
        return DB::transaction(function () use ($creditNote, $data): CustomerCreditRefund {
            Company::query()->whereKey($creditNote->company_id)->lockForUpdate()->firstOrFail();
            $this->periods->resolveOpenForPostingDate(
                (int) $creditNote->company_id, (string) $data['refund_date'],
                expectedPeriodId: (int) $data['financial_period_id'], lockForUpdate: true,
            );
            $credit = CustomerInvoice::query()->with('customer.account')->lockForUpdate()->findOrFail($creditNote->getKey());
            $key = (string) ($data['idempotency_key'] ?? Str::uuid());
            $existing = CustomerCreditRefund::query()
                ->where('company_id', $credit->company_id)
                ->where('idempotency_key', $key)
                ->first();
            if ($existing instanceof CustomerCreditRefund) {
                if ((int) $existing->credit_note_id !== (int) $credit->getKey()
                    || (int) $existing->financial_period_id !== (int) $data['financial_period_id']
                    || (int) $existing->branch_id !== (int) $data['branch_id']
                    || $existing->refund_date?->toDateString() !== (string) $data['refund_date']
                    || $existing->payment_method !== (string) $data['payment_method']
                    || (int) $existing->cashbox_id !== (int) ($data['cashbox_id'] ?? 0)
                    || (int) $existing->bank_account_id !== (int) ($data['bank_account_id'] ?? 0)
                    || (int) $existing->currency_id !== (int) $data['currency_id']
                    || $this->amounts->compare($existing->exchange_rate, (string) ($data['exchange_rate'] ?? 1), 6) !== 0
                    || $this->amounts->compare($existing->amount, (string) $data['amount']) !== 0) {
                    throw new DomainException(__('sales_return_correction.idempotency_conflict'));
                }
                if ($existing->status !== CustomerCreditRefund::StatusPosted) {
                    throw new DomainException(__('sales_return_correction.refund_already_reversed'));
                }

                return $existing->load(['creditNote', 'cashbox', 'bankAccount', 'journalEntry']);
            }
            $this->assertCreditUsable($credit);
            if ((int) $credit->branch_id !== (int) $data['branch_id']
                || (int) $credit->currency_id !== (int) $data['currency_id']
                || $this->amounts->compare($credit->exchange_rate, (string) ($data['exchange_rate'] ?? 1), 6) !== 0) {
                throw new DomainException(__('sales_return_correction.refund_source_mismatch'));
            }
            $amount = (string) $data['amount'];
            $this->amounts->assertPositive($amount, __('Refund amount must be greater than zero.'), 4);
            $this->amounts->assertNotGreaterThan($amount, (string) $credit->credit_available_amount, __('Refund exceeds the available Customer Credit.'), 4);

            $method = (string) $data['payment_method'];
            $cashbox = $method === CustomerCreditRefund::MethodCash
                ? Cashbox::query()->with('account')->forCompany((int) $credit->company_id)->active()->findOrFail($data['cashbox_id'])
                : null;
            $bank = $method === CustomerCreditRefund::MethodBank
                ? BankAccount::query()->with('account')->forCompany((int) $credit->company_id)->active()->findOrFail($data['bank_account_id'])
                : null;
            $cashAccount = $cashbox?->account ?? $bank?->account;
            if (! in_array($method, [CustomerCreditRefund::MethodCash, CustomerCreditRefund::MethodBank], true)
                || $cashAccount === null || ! $credit->customer?->account) {
                throw new DomainException(__('Customer refund requires an active mapped Cashbox or Bank Account and Customer AR account.'));
            }

            $numbers = $this->documents->nextForCompany(
                'customer_credit_refunds', CustomerCreditRefund::class, (int) $credit->company_id,
            );
            $refund = CustomerCreditRefund::query()->create([
                ...$numbers, 'company_id' => $credit->company_id, 'financial_period_id' => $data['financial_period_id'],
                'branch_id' => $data['branch_id'], 'customer_id' => $credit->customer_id,
                'credit_note_id' => $credit->getKey(), 'refund_date' => $data['refund_date'],
                'idempotency_key' => $key,
                'payment_method' => $method, 'cashbox_id' => $cashbox?->getKey(),
                'bank_account_id' => $bank?->getKey(), 'currency_id' => $data['currency_id'],
                'exchange_rate' => $data['exchange_rate'] ?? 1, 'amount' => $amount,
                'reference_no' => $data['reference_no'] ?? null, 'status' => CustomerCreditRefund::StatusPosted,
                'notes' => $data['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
            $journal = $this->journals->createPostedFromSource([
                'entry_date' => $refund->refund_date, 'company_id' => (int) $refund->company_id,
                'financial_period_id' => (int) $refund->financial_period_id, 'branch_id' => $refund->branch_id,
                'currency_id' => $refund->currency_id, 'exchange_rate' => $refund->exchange_rate,
                'description' => __('Customer Credit refund :document', ['document' => $refund->doc_num]),
                'notes' => $refund->notes, 'source_type' => 'customer_credit_refund',
                'source_id' => $refund->getKey(), 'source_doc_num' => $refund->doc_num,
            ], [
                ['account_id' => $credit->customer->account->getKey(), 'debit_amount' => $amount, 'credit_amount' => 0, 'description' => __('Customer AR credit refund'), 'customer_id' => $credit->customer_id],
                ['account_id' => $cashAccount->getKey(), 'debit_amount' => 0, 'credit_amount' => $amount, 'description' => __('Cash / bank refund'), 'bank_account_id' => $bank?->getKey()],
            ]);
            $refund->forceFill(['journal_entry_id' => $journal->getKey(), 'posted_by' => auth()->id(), 'posted_at' => now()])->save();
            $credit->forceFill([
                'credit_available_amount' => $this->amounts->subtract($credit->credit_available_amount, $amount),
                'credit_refunded_amount' => $this->amounts->add($credit->credit_refunded_amount, $amount),
            ])->save();

            return $refund->refresh()->load(['creditNote', 'cashbox', 'bankAccount', 'journalEntry']);
        }, 3);
    }

    public function reverseRefund(CustomerCreditRefund $refund, string $reason, string $recoveryReference, ?int $correctionId = null): CustomerCreditRefund
    {
        return DB::transaction(function () use ($refund, $reason, $recoveryReference, $correctionId): CustomerCreditRefund {
            Company::query()->whereKey($refund->company_id)->lockForUpdate()->firstOrFail();
            $reason = trim($reason);
            $recoveryReference = trim($recoveryReference);
            if ($reason === '' || $recoveryReference === '' || mb_strlen($recoveryReference) > 255) {
                throw new DomainException(__('sales_return_correction.refund_recovery_required'));
            }
            $reversedAt = now();
            $refundDate = $refund->refund_date?->toDateString();
            if ($refundDate === null) {
                throw new DomainException(__('sales_return_correction.refund_not_reversible'));
            }
            $proposal = $correctionId === null ? null : app(SalesReturnCorrectionService::class)->execution($correctionId, 'refund', (int) $refund->id);
            $postingDate = $proposal?->posting_date->toDateString() ?? $reversedAt->toDateString();
            if ($proposal === null) {
                $this->periods->resolveOpenForPostingDate((int) $refund->company_id, $refundDate,
                    expectedPeriodId: (int) $refund->financial_period_id, lockForUpdate: true);
            }
            $period = $this->periods->resolveOpenForPostingDate((int) $refund->company_id, $postingDate,
                expectedPeriodId: (int) ($proposal?->posting_financial_period_id ?? $refund->financial_period_id), lockForUpdate: true);
            $credit = CustomerInvoice::query()->lockForUpdate()->findOrFail($refund->credit_note_id);
            $sourceJournal = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($refund->journal_entry_id);
            $locked = CustomerCreditRefund::query()->with(['cashbox', 'bankAccount'])->lockForUpdate()->findOrFail($refund->getKey());
            $amount = (string) $locked->amount;
            $fundAccountId = $locked->payment_method === CustomerCreditRefund::MethodCash
                ? $locked->cashbox?->account_id
                : $locked->bankAccount?->account_id;
            $customerAccountId = $credit->customer?->account_id;
            $arLine = $sourceJournal->lines->firstWhere('account_id', $customerAccountId);
            $fundLine = $sourceJournal->lines->firstWhere('account_id', $fundAccountId);

            if ($locked->status !== CustomerCreditRefund::StatusPosted
                || $locked->reversed_at !== null
                || $locked->reversal_journal_entry_id !== null
                || (int) $locked->credit_note_id !== (int) $credit->getKey()
                || (int) $locked->journal_entry_id !== (int) $sourceJournal->getKey()
                || ($correctionId === null && (int) $locked->financial_period_id !== (int) $period->getKey())
                || $locked->refund_date?->toDateString() !== $refundDate
                || (int) $locked->company_id !== (int) $credit->company_id
                || (int) $locked->branch_id !== (int) $credit->branch_id
                || (int) $locked->customer_id !== (int) $credit->customer_id
                || (int) $locked->currency_id !== (int) $credit->currency_id
                || $this->amounts->compare($locked->exchange_rate, $credit->exchange_rate, 6) !== 0
                || $credit->document_type !== CustomerInvoice::TypeCreditNote
                || $credit->status !== CustomerInvoice::StatusPosted
                || $credit->posting_status !== 'posted'
                || $credit->reversal_journal_entry_id !== null
                || $this->amounts->compare($amount, '0') <= 0
                || $this->amounts->compare($credit->credit_refunded_amount, $amount) < 0
                || $fundAccountId === null
                || $customerAccountId === null
                || $sourceJournal->trashed()
                || $sourceJournal->status !== JournalEntry::StatusPosted
                || ! $sourceJournal->is_posted
                || $sourceJournal->reversed_entry_id !== null
                || $sourceJournal->source_type !== 'customer_credit_refund'
                || (int) $sourceJournal->source_id !== (int) $locked->getKey()
                || $sourceJournal->source_doc_num !== $locked->doc_num
                || (int) $sourceJournal->company_id !== (int) $locked->company_id
                || (int) $sourceJournal->branch_id !== (int) $locked->branch_id
                || (int) $sourceJournal->financial_period_id !== (int) $locked->financial_period_id
                || (int) $sourceJournal->currency_id !== (int) $locked->currency_id
                || $sourceJournal->entry_date?->toDateString() !== $refundDate
                || $this->amounts->compare($sourceJournal->exchange_rate, $locked->exchange_rate, 6) !== 0
                || $sourceJournal->lines->count() !== 2
                || $arLine === null || $fundLine === null || $arLine->is($fundLine)
                || (int) $arLine->customer_id !== (int) $locked->customer_id
                || $this->amounts->compare($arLine->debit_amount, $amount) !== 0
                || $this->amounts->compare($arLine->credit_amount, '0') !== 0
                || $this->amounts->compare($fundLine->debit_amount, '0') !== 0
                || $this->amounts->compare($fundLine->credit_amount, $amount) !== 0
                || ($locked->payment_method === CustomerCreditRefund::MethodBank
                    && (int) $fundLine->bank_account_id !== (int) $locked->bank_account_id)
                || JournalEntry::query()->withTrashed()
                    ->where('company_id', $locked->company_id)
                    ->where('source_type', 'customer_credit_refund_reversal')
                    ->where('source_id', $locked->getKey())->exists()) {
                throw new DomainException(__('sales_return_correction.refund_not_reversible'));
            }

            $this->assertCreditBalanceReconcilesForAllocationReversal($credit);
            $availableBefore = (string) $credit->credit_available_amount;
            $refundedBefore = (string) $credit->credit_refunded_amount;
            $availableAfter = $this->amounts->add($availableBefore, $amount);
            $refundedAfter = $this->amounts->subtract($refundedBefore, $amount);
            $reversal = $this->journals->createPostedReversalFromSource($sourceJournal, [
                'entry_date' => $postingDate,
                'company_id' => (int) $locked->company_id,
                'financial_period_id' => (int) $period->getKey(),
                'branch_id' => (int) $locked->branch_id,
                'currency_id' => (int) $locked->currency_id,
                'exchange_rate' => (string) $locked->exchange_rate,
                'description' => __('sales_return_correction.refund_reversal_journal', ['refund' => $locked->doc_num]),
                'notes' => $reason.' / '.$recoveryReference,
                'source_type' => 'customer_credit_refund_reversal',
                'source_id' => (int) $locked->getKey(),
                'source_doc_num' => $locked->doc_num,
            ]);
            app(SalesReturnCorrectionService::class)->assertInverse($sourceJournal, $reversal, (int) $period->id, $postingDate);
            $credit->forceFill([
                'credit_available_amount' => $availableAfter,
                'credit_refunded_amount' => $refundedAfter,
            ])->save();
            $locked->forceFill([
                'status' => CustomerCreditRefund::StatusReversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_by' => auth()->id(),
                'reversed_at' => $reversedAt,
                'reversal_reason' => $reason,
                'recovery_reference' => $recoveryReference,
                'reversal_effect_snapshot' => [
                    'credit_note_id' => $credit->getKey(),
                    'refund_journal_entry_id' => $sourceJournal->getKey(),
                    'reversal_journal_entry_id' => $reversal->getKey(),
                    'amount' => $amount,
                    'refund_date' => $refundDate,
                    'refund_financial_period_id' => $locked->financial_period_id,
                    'reversal_date' => $postingDate,
                    'sales_return_correction_id' => $correctionId,
                    'reversal_financial_period_id' => $period->getKey(),
                    'reversed_by' => auth()->id(),
                    'reversal_reason' => $reason,
                    'recovery_reference' => $recoveryReference,
                    'credit_available_before' => $availableBefore,
                    'credit_available_after' => $availableAfter,
                    'credit_refunded_before' => $refundedBefore,
                    'credit_refunded_after' => $refundedAfter,
                ],
            ])->save();
            $this->audit->record($locked, 'customer_credit_refund.reversed', [
                'credit_note' => $credit->doc_num,
                'amount' => $amount,
                'reason' => $reason,
                'recovery_reference' => $recoveryReference,
                'reversal_journal_entry_id' => $reversal->getKey(),
            ]);

            return $locked->refresh()->load(['creditNote', 'cashbox', 'bankAccount', 'journalEntry', 'reversalJournalEntry']);
        }, 3);
    }

    private function assertCreditUsable(CustomerInvoice $credit): void
    {
        if ($credit->document_type !== CustomerInvoice::TypeCreditNote || $credit->posting_status !== 'posted'
            || $credit->status !== CustomerInvoice::StatusPosted || bccomp((string) $credit->credit_available_amount, '0', 4) <= 0) {
            throw new DomainException(__('Only an available posted Customer Credit may be applied or refunded.'));
        }
    }

    public function refundReversalEvidenceValid(CustomerCreditRefund $refund, CustomerInvoice $credit): bool
    {
        $effect = $refund->reversal_effect_snapshot;
        $later = is_array($effect) && isset($effect['sales_return_correction_id']);
        if ($later && ! app(SalesReturnCorrectionService::class)->approvedRecovery($effect, 'refund', (int) $refund->id)) {
            return false;
        }
        if ($refund->status !== CustomerCreditRefund::StatusReversed
            || $refund->reversed_at === null
            || $refund->reversed_by === null
            || $refund->reversal_journal_entry_id === null
            || blank($refund->reversal_reason)
            || blank($refund->recovery_reference)
            || ! is_array($effect)
            || (int) $refund->credit_note_id !== (int) $credit->getKey()
            || (int) ($effect['credit_note_id'] ?? 0) !== (int) $credit->getKey()
            || (int) ($effect['refund_journal_entry_id'] ?? 0) !== (int) $refund->journal_entry_id
            || (int) ($effect['reversal_journal_entry_id'] ?? 0) !== (int) $refund->reversal_journal_entry_id
            || (int) ($effect['refund_financial_period_id'] ?? 0) !== (int) $refund->financial_period_id
            || (! $later && (int) ($effect['reversal_financial_period_id'] ?? 0) !== (int) $refund->financial_period_id)
            || (int) ($effect['reversed_by'] ?? 0) !== (int) $refund->reversed_by
            || ($effect['refund_date'] ?? null) !== $refund->refund_date?->toDateString()
            || (! $later && ($effect['reversal_date'] ?? null) !== $refund->reversed_at->toDateString())
            || ($effect['reversal_reason'] ?? null) !== $refund->reversal_reason
            || ($effect['recovery_reference'] ?? null) !== $refund->recovery_reference) {
            return false;
        }
        foreach (['amount', 'credit_available_before', 'credit_available_after', 'credit_refunded_before', 'credit_refunded_after'] as $field) {
            if (! is_string($effect[$field] ?? null)
                || preg_match('/^\d+(?:\.\d{1,4})?$/D', $effect[$field]) !== 1) {
                return false;
            }
        }
        if ($this->amounts->compare($effect['amount'], $refund->amount) !== 0
            || $this->amounts->compare($effect['amount'], '0') <= 0
            || $this->amounts->compare($effect['credit_available_after'], $this->amounts->add($effect['credit_available_before'], $effect['amount'])) !== 0
            || $this->amounts->compare($effect['credit_refunded_after'], $this->amounts->subtract($effect['credit_refunded_before'], $effect['amount'])) !== 0) {
            return false;
        }

        $source = JournalEntry::query()->with('lines')->withTrashed()->find($refund->journal_entry_id);
        $reversal = JournalEntry::query()->with('lines')->withTrashed()->find($refund->reversal_journal_entry_id);
        $fundAccountId = $refund->payment_method === CustomerCreditRefund::MethodCash
            ? $refund->cashbox?->account_id
            : $refund->bankAccount?->account_id;
        $customerAccountId = $credit->customer?->account_id;
        if (! $source instanceof JournalEntry || ! $reversal instanceof JournalEntry
            || $source->trashed() || $reversal->trashed()
            || $source->status !== JournalEntry::StatusPosted || ! $source->is_posted
            || $reversal->status !== JournalEntry::StatusPosted || ! $reversal->is_posted
            || (int) $source->reversed_entry_id !== (int) $reversal->getKey()
            || (int) $refund->company_id !== (int) $credit->company_id
            || (int) $refund->branch_id !== (int) $credit->branch_id
            || (int) $refund->customer_id !== (int) $credit->customer_id
            || (int) $refund->currency_id !== (int) $credit->currency_id
            || $this->amounts->compare($refund->exchange_rate, $credit->exchange_rate, 6) !== 0
            || $fundAccountId === null || $customerAccountId === null
            || $source->source_type !== 'customer_credit_refund'
            || $reversal->source_type !== 'customer_credit_refund_reversal'
            || (int) $source->source_id !== (int) $refund->getKey()
            || (int) $reversal->source_id !== (int) $refund->getKey()
            || $source->source_doc_num !== $refund->doc_num
            || $reversal->source_doc_num !== $refund->doc_num
            || (int) $source->company_id !== (int) $refund->company_id
            || (int) $reversal->company_id !== (int) $refund->company_id
            || (int) $source->branch_id !== (int) $refund->branch_id
            || (int) $reversal->branch_id !== (int) $refund->branch_id
            || (int) $source->financial_period_id !== (int) $refund->financial_period_id
            || (int) $reversal->financial_period_id !== (int) ($effect['reversal_financial_period_id'] ?? 0)
            || (int) $source->currency_id !== (int) $refund->currency_id
            || (int) $reversal->currency_id !== (int) $refund->currency_id
            || $this->amounts->compare($source->exchange_rate, $refund->exchange_rate, 6) !== 0
            || $this->amounts->compare($reversal->exchange_rate, $refund->exchange_rate, 6) !== 0
            || $source->entry_date?->toDateString() !== $refund->refund_date?->toDateString()
            || $reversal->entry_date?->toDateString() !== ($effect['reversal_date'] ?? null)
            || $source->lines->count() !== 2
            || $reversal->lines->count() !== 2) {
            return false;
        }

        $arLine = $source->lines->firstWhere('account_id', $customerAccountId);
        $fundLine = $source->lines->firstWhere('account_id', $fundAccountId);
        if ($arLine === null || $fundLine === null || $arLine->is($fundLine)
            || (int) $arLine->customer_id !== (int) $refund->customer_id
            || $this->amounts->compare($arLine->debit_amount, $refund->amount) !== 0
            || $this->amounts->compare($arLine->credit_amount, '0') !== 0
            || $this->amounts->compare($fundLine->debit_amount, '0') !== 0
            || $this->amounts->compare($fundLine->credit_amount, $refund->amount) !== 0
            || ($refund->payment_method === CustomerCreditRefund::MethodBank
                && (int) $fundLine->bank_account_id !== (int) $refund->bank_account_id)) {
            return false;
        }

        foreach ($source->lines as $line) {
            $inverse = $reversal->lines->firstWhere('line_no', $line->line_no);
            if ($inverse === null
                || (int) $inverse->account_id !== (int) $line->account_id
                || (int) $inverse->customer_id !== (int) $line->customer_id
                || (int) $inverse->bank_account_id !== (int) $line->bank_account_id
                || $this->amounts->compare($inverse->debit_amount, $line->credit_amount) !== 0
                || $this->amounts->compare($inverse->credit_amount, $line->debit_amount) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function assertCreditBalanceReconcilesForAllocationReversal(CustomerInvoice $credit): void
    {
        app(CustomerCreditApplicationEvidenceService::class)->assertApprovedApplication($credit);
        $application = $credit->credit_application_snapshot;
        $applied = is_array($application) ? ($application['applied_to_original'] ?? null) : null;
        $rows = is_array($application) ? ($application['schedules'] ?? null) : null;
        if (! is_string($applied) || preg_match('/^\d+(?:\.\d{1,4})?$/D', $applied) !== 1 || ! is_array($rows)
            || (int) ($application['original_invoice_id'] ?? 0) !== (int) $credit->original_invoice_id) {
            throw new DomainException(__('sales_return_correction.allocation_not_reversible'));
        }

        $scheduleAmounts = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['schedule_id'] ?? null)
                || ! is_string($row['amount'] ?? null)
                || preg_match('/^\d+(?:\.\d{1,4})?$/D', $row['amount']) !== 1) {
                throw new DomainException(__('sales_return_correction.allocation_not_reversible'));
            }
            $scheduleAmounts[] = (string) $row['amount'];
        }

        $activeAmounts = CustomerCreditAllocation::query()
            ->where('credit_note_id', $credit->getKey())
            ->where('status', CustomerCreditAllocation::StatusApplied)
            ->pluck('amount');
        $refunds = CustomerCreditRefund::query()->where('credit_note_id', $credit->getKey())->get();
        $refundedAmounts = $refunds->where('status', CustomerCreditRefund::StatusPosted)->pluck('amount');
        $dispositionTotal = $this->amounts->sum([
            $applied, $credit->credit_available_amount,
            $credit->credit_allocated_amount, $credit->credit_refunded_amount,
        ]);
        if (CustomerCreditAllocation::query()->where('credit_note_id', $credit->getKey())
            ->whereNotIn('status', [CustomerCreditAllocation::StatusApplied, CustomerCreditAllocation::StatusReversed])->exists()
            || $refunds->contains(fn (CustomerCreditRefund $refund): bool => $refund->status !== CustomerCreditRefund::StatusPosted
                && ! $this->refundReversalEvidenceValid($refund, $credit))
            || $this->amounts->compare($applied, '0') < 0
            || $this->amounts->compare($this->amounts->sum($scheduleAmounts), $applied) !== 0
            || $this->amounts->compare($this->amounts->sum($activeAmounts), $credit->credit_allocated_amount) !== 0
            || $this->amounts->compare($this->amounts->sum($refundedAmounts), $credit->credit_refunded_amount) !== 0
            || $this->amounts->compare($dispositionTotal, $credit->total_amount) !== 0) {
            throw new DomainException(__('sales_return_correction.allocation_not_reversible'));
        }
    }
}

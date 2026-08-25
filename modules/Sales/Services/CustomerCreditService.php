<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Services\DocumentNumberService;
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
            $credit = CustomerInvoice::query()->with('customer')->lockForUpdate()->findOrFail($creditNote->getKey());
            $invoice = CustomerInvoice::query()->lockForUpdate()->findOrFail($targetInvoice->getKey());
            $key = $idempotencyKey ?? (string) Str::uuid();
            $existing = CustomerCreditAllocation::query()
                ->where('company_id', $credit->company_id)
                ->where('idempotency_key', $key)
                ->first();
            if ($existing instanceof CustomerCreditAllocation) {
                return $existing->load(['creditNote', 'targetInvoice', 'targetPaymentSchedule']);
            }
            $this->assertCreditUsable($credit);
            if ($invoice->document_type !== CustomerInvoice::TypeInvoice || $invoice->posting_status !== 'posted'
                || (int) $invoice->company_id !== (int) $credit->company_id
                || (int) $invoice->customer_id !== (int) $credit->customer_id) {
                throw new DomainException(__('Customer Credit and target Invoice must be posted documents for the same Customer and company.'));
            }
            $this->amounts->assertPositive($amount, __('Credit allocation amount must be greater than zero.'), 4);
            $this->amounts->assertNotGreaterThan($amount, (string) $credit->credit_available_amount, __('Credit allocation exceeds the available Customer Credit.'), 4);
            $this->amounts->assertNotGreaterThan($amount, (string) $invoice->remaining_amount, __('Credit allocation exceeds the target Invoice outstanding amount.'), 4);

            $lockedSchedule = $schedule === null
                ? $invoice->paymentSchedules()->whereRaw('(amount - collected_amount - credited_amount) > 0')->orderBy('due_date')->lockForUpdate()->first()
                : CustomerInvoicePaymentSchedule::query()->lockForUpdate()->findOrFail($schedule->getKey());
            if (! $lockedSchedule instanceof CustomerInvoicePaymentSchedule
                || (int) $lockedSchedule->customer_invoice_id !== (int) $invoice->getKey()) {
                throw new DomainException(__('An open target Invoice installment is required.'));
            }
            $this->amounts->assertNotGreaterThan($amount, $lockedSchedule->outstanding_amount, __('Credit allocation exceeds the target installment outstanding amount.'), 4);

            $allocation = CustomerCreditAllocation::query()->create([
                'company_id' => $credit->company_id, 'financial_period_id' => $credit->financial_period_id,
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

    /** @param array<string, mixed> $data */
    public function refund(CustomerInvoice $creditNote, array $data): CustomerCreditRefund
    {
        return DB::transaction(function () use ($creditNote, $data): CustomerCreditRefund {
            $credit = CustomerInvoice::query()->with('customer.account')->lockForUpdate()->findOrFail($creditNote->getKey());
            $key = (string) ($data['idempotency_key'] ?? Str::uuid());
            $existing = CustomerCreditRefund::query()
                ->where('company_id', $credit->company_id)
                ->where('idempotency_key', $key)
                ->first();
            if ($existing instanceof CustomerCreditRefund) {
                return $existing->load(['creditNote', 'cashbox', 'bankAccount', 'journalEntry']);
            }
            $this->assertCreditUsable($credit);
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
                fn ($query) => $query->where('financial_period_id', $data['financial_period_id']),
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

    private function assertCreditUsable(CustomerInvoice $credit): void
    {
        if ($credit->document_type !== CustomerInvoice::TypeCreditNote || $credit->posting_status !== 'posted'
            || $credit->status !== CustomerInvoice::StatusPosted || bccomp((string) $credit->credit_available_amount, '0', 4) <= 0) {
            throw new DomainException(__('Only an available posted Customer Credit may be applied or refunded.'));
        }
    }
}

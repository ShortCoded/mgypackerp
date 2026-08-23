<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DocumentNumberService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;
use Modules\Finance\Services\CashVoucherService;
use Modules\Finance\Services\ChequeService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoicePaymentSchedule;
use Modules\Sales\Models\CustomerReceipt;

class CustomerReceiptService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly SalesAmountService $amounts,
        private readonly SalesAccountingService $accounting,
        private readonly SalesCycleAuditService $audit,
        private readonly CashVoucherService $cashVouchers,
        private readonly ChequeService $cheques,
    ) {}

    /** @param array<string, mixed> $data @param list<array{customer_invoice_payment_schedule_id: int, amount: string|int|float}> $allocations */
    public function createAndApprove(array $data, array $allocations = []): CustomerReceipt
    {
        return DB::transaction(function () use ($data, $allocations): CustomerReceipt {
            $this->amounts->assertPositive($data['amount'], 'Receipt amount must be greater than zero.', 4);
            if (empty($data['cashbox_id']) === empty($data['bank_account_id'])) {
                throw new DomainException('Select either one cashbox or one bank account.');
            }
            $this->assertPaymentSource($data);
            $numbers = $this->documents->nextForCompany('customer_receipts', CustomerReceipt::class, (int) $data['company_id'], fn ($query) => $query->where('financial_period_id', $data['financial_period_id']));
            $receipt = CustomerReceipt::query()->create([
                ...$data, ...$numbers, 'status' => CustomerReceipt::StatusDraft,
                'unallocated_amount' => $data['amount'], 'created_by' => auth()->id(),
            ]);
            $this->attachCanonicalFinanceDocument($receipt);
            $allocated = '0.0000';
            $allocatedScheduleIds = [];
            foreach ($allocations as $input) {
                $schedule = CustomerInvoicePaymentSchedule::query()->with('invoice')->lockForUpdate()->findOrFail($input['customer_invoice_payment_schedule_id']);
                if (in_array($schedule->getKey(), $allocatedScheduleIds, true)) {
                    throw new DomainException('An installment may only be allocated once per receipt.');
                }
                $allocatedScheduleIds[] = $schedule->getKey();
                if ($schedule->invoice->customer_id !== $receipt->customer_id || $schedule->invoice->posting_status !== 'posted') {
                    throw new DomainException('The selected installment is not an open posted invoice for this customer.');
                }
                $amount = (string) $input['amount'];
                $this->amounts->assertPositive($amount, 'Allocation amount must be greater than zero.', 4);
                $this->amounts->assertNotGreaterThan($amount, $schedule->outstanding_amount, 'Allocation exceeds the installment outstanding amount.', 4);
                $allocated = $this->amounts->add($allocated, $amount);
                $this->amounts->assertNotGreaterThan($allocated, $receipt->amount, 'Allocations exceed the receipt amount.', 4);
                $receipt->allocations()->create([
                    'customer_invoice_id' => $schedule->customer_invoice_id,
                    'customer_invoice_payment_schedule_id' => $schedule->getKey(), 'allocated_amount' => $amount,
                    'applied_by' => auth()->id(), 'applied_at' => now(),
                ]);
                $schedule->increment('collected_amount', $amount);
                $this->refreshInvoice($schedule->invoice);
            }
            $journal = $this->accounting->postReceipt($receipt);
            $receipt->update([
                'status' => CustomerReceipt::StatusApproved, 'unallocated_amount' => $this->amounts->subtract($receipt->amount, $allocated),
                'journal_entry_id' => $journal->getKey(), 'approved_by' => auth()->id(), 'approved_at' => now(),
                'is_closed' => true, 'updated_by' => auth()->id(),
            ]);
            $this->audit->record($receipt, 'customer_receipt.approved', ['allocated_amount' => $allocated, 'unallocated_amount' => $receipt->fresh()->unallocated_amount]);

            return $receipt->refresh()->load(['allocations.invoiceSchedule', 'cashVoucher', 'cheque']);
        });
    }

    private function attachCanonicalFinanceDocument(CustomerReceipt $receipt): void
    {
        $receipt->loadMissing(['customer.account', 'currency']);
        $customer = $receipt->customer;
        $account = $customer?->account;

        if (! $customer || ! $account) {
            throw new DomainException('The customer must have a posting account before collection.');
        }

        if ($receipt->payment_method === 'cash') {
            $cashbox = Cashbox::query()->findOrFail($receipt->cashbox_id);
            $result = $this->cashVouchers->create(CashVoucher::TypeReceipt, [
                'voucher_date' => $receipt->receipt_date->toDateString(),
                'cashbox_doc_num' => $cashbox->doc_num,
                'currency_doc_num' => $receipt->currency->doc_num,
                'exchange_rate' => $receipt->exchange_rate,
                'amount' => $receipt->amount,
                'person_name' => $customer->name,
                'person_phone' => $customer->phone ?: $customer->mobile,
                'reason' => 'Customer collection '.$receipt->doc_num,
                'description' => $receipt->notes,
                'lines' => [[
                    'account_doc_num' => $account->doc_num,
                    'amount' => $receipt->amount,
                    'description' => 'Customer receivable settlement',
                ]],
            ], (int) $receipt->company_id);
            $voucher = $this->cashVouchers->approve(CashVoucher::TypeReceipt, $result['record'], (int) $receipt->company_id);
            $receipt->update(['cash_voucher_id' => $voucher->getKey()]);

            return;
        }

        if ($receipt->payment_method !== 'cheque') {
            return;
        }

        $bank = BankAccount::query()->findOrFail($receipt->bank_account_id);
        $result = $this->cheques->create([
            'cheque_type' => Cheque::TypeReceived,
            'cheque_number' => $receipt->reference_no,
            'cheque_date' => $receipt->receipt_date->toDateString(),
            'due_date' => $receipt->cheque_due_date ?? $receipt->receipt_date->toDateString(),
            'bank_account_doc_num' => $bank->doc_num,
            'external_bank_name' => $receipt->external_bank_name,
            'external_bank_branch' => null,
            'party_type' => 'customer',
            'party_id' => $customer->getKey(),
            'party_name' => $customer->name,
            'currency_doc_num' => $receipt->currency->doc_num,
            'exchange_rate' => $receipt->exchange_rate,
            'amount' => $receipt->amount,
            'reason' => 'Customer collection '.$receipt->doc_num,
            'description' => $receipt->notes,
            'lines' => [[
                'account_doc_num' => $account->doc_num,
                'amount' => $receipt->amount,
                'description' => 'Customer receivable settlement',
            ]],
        ], (int) $receipt->company_id);
        $receipt->update(['cheque_id' => $result['record']->getKey()]);
    }

    private function refreshInvoice(CustomerInvoice $invoice): void
    {
        $paid = (string) DB::table('customer_invoice_payment_schedules')->where('customer_invoice_id', $invoice->getKey())->sum('collected_amount');
        $remaining = $this->amounts->subtract($this->amounts->subtract($invoice->total_amount, $paid), $invoice->credited_amount);
        $invoice->update(['paid_amount' => $paid, 'remaining_amount' => $remaining]);
    }

    /** @param array<string, mixed> $data */
    private function assertPaymentSource(array $data): void
    {
        $currency = Currency::query()->forCompany((int) $data['company_id'])->active()->find($data['currency_id']);
        if (! $currency instanceof Currency) {
            throw new DomainException('The receipt currency is not active for this company.');
        }

        $method = (string) $data['payment_method'];
        if ($method === 'cash' && empty($data['cashbox_id'])) {
            throw new DomainException('Cash collections require a cashbox.');
        }
        if (in_array($method, ['bank', 'cheque', 'transfer'], true) && empty($data['bank_account_id'])) {
            throw new DomainException('Bank, cheque, and transfer collections require a bank account.');
        }

        if (! empty($data['cashbox_id'])) {
            $cashbox = Cashbox::query()->forCompany((int) $data['company_id'])->active()->find($data['cashbox_id']);
            if (! $cashbox instanceof Cashbox) {
                throw new DomainException('The selected cashbox is not active for this company.');
            }
            $allowedCurrencies = $cashbox->currencies()->where('status', 'active')->whereNull('deleted_at');
            if ($allowedCurrencies->exists() && ! (clone $allowedCurrencies)->where('currency_id', $currency->getKey())->exists()) {
                throw new DomainException('The selected currency is not enabled for this cashbox.');
            }
        }

        if (! empty($data['bank_account_id'])) {
            $bank = BankAccount::query()->forCompany((int) $data['company_id'])->active()->find($data['bank_account_id']);
            if (! $bank instanceof BankAccount || (int) $bank->currency_id !== (int) $currency->getKey()) {
                throw new DomainException('The selected bank account is not active in the receipt currency.');
            }
        }
    }
}

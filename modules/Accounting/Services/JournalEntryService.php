<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\DocumentNumberService;
use Modules\Finance\Models\OpeningBalance;
use Modules\Purchases\Models\PurchaseInvoice;

class JournalEntryService
{
    public function __construct(private readonly DocumentNumberService $documents) {}

    public function createPostedFromOpeningBalance(OpeningBalance $openingBalance): JournalEntry
    {
        if ($openingBalance->journal_entry_id !== null) {
            throw new DomainException(__('opening_balances.messages.already_has_journal_entry'));
        }

        return DB::transaction(function () use ($openingBalance): JournalEntry {
            $now = now();
            $userId = auth()->id();

            $journalEntry = JournalEntry::query()->create([
                ...$this->documents->next('journal_entries', JournalEntry::class),
                'entry_date' => $openingBalance->document_date,
                'company_id' => $openingBalance->company_id,
                'financial_period_id' => $openingBalance->financial_period_id,
                'currency_id' => $openingBalance->currency_id,
                'exchange_rate' => $openingBalance->exchange_rate,
                'description' => $openingBalance->description,
                'notes' => $openingBalance->notes,
                'source_type' => 'opening_balance',
                'source_id' => $openingBalance->getKey(),
                'source_doc_num' => $openingBalance->doc_num,
                'status' => JournalEntry::StatusPosted,
                'is_system_generated' => true,
                'is_posted' => true,
                'posted_at' => $now,
                'posted_by' => $userId,
                'approved' => true,
                'approved_at' => $now,
                'approved_by' => $userId,
                'created_by' => $userId,
            ]);

            foreach ($openingBalance->lines as $line) {
                $journalEntry->lines()->create([
                    'line_no' => $line->line_no,
                    'account_id' => $line->account_id,
                    'debit_amount' => $line->debit_amount,
                    'credit_amount' => $line->credit_amount,
                    'description' => $line->description,
                    'customer_id' => $line->customer_id,
                    'supplier_id' => $line->supplier_id,
                    'employee_id' => $line->employee_id,
                    'bank_account_id' => $line->bank_account_id,
                    'cost_center_id' => $line->cost_center_id,
                    'branch_id' => $line->branch_id,
                ]);
            }

            return $journalEntry->refresh();
        });
    }

    /**
     * @param  list<array{account_id: int, debit_amount: string, description: string}>  $debitLines
     */
    public function createPostedFromPurchaseInvoice(PurchaseInvoice $purchaseInvoice, array $debitLines, Account $supplierAccount): JournalEntry
    {
        if ($purchaseInvoice->journal_entry_id !== null) {
            throw new DomainException(__('purchase_invoices.messages.already_has_journal_entry'));
        }

        return DB::transaction(function () use ($purchaseInvoice, $debitLines, $supplierAccount): JournalEntry {
            $now = now();
            $userId = auth()->id();

            $journalEntry = JournalEntry::query()->create([
                ...$this->documents->next('journal_entries', JournalEntry::class),
                'entry_date' => $purchaseInvoice->invoice_date,
                'company_id' => $purchaseInvoice->company_id,
                'financial_period_id' => $purchaseInvoice->financial_period_id,
                'currency_id' => $purchaseInvoice->currency_id,
                'exchange_rate' => $purchaseInvoice->exchange_rate,
                'description' => __('purchase_invoices.journal.description', ['invoice' => $purchaseInvoice->doc_num]),
                'notes' => $purchaseInvoice->notes,
                'source_type' => 'purchase_invoice',
                'source_id' => $purchaseInvoice->getKey(),
                'source_doc_num' => $purchaseInvoice->doc_num,
                'status' => JournalEntry::StatusPosted,
                'is_system_generated' => true,
                'is_posted' => true,
                'posted_at' => $now,
                'posted_by' => $userId,
                'approved' => true,
                'approved_at' => $now,
                'approved_by' => $userId,
                'created_by' => $userId,
            ]);

            foreach ($debitLines as $index => $line) {
                $journalEntry->lines()->create([
                    'line_no' => $index + 1,
                    'account_id' => $line['account_id'],
                    'debit_amount' => $line['debit_amount'],
                    'credit_amount' => 0,
                    'description' => $line['description'],
                    'supplier_id' => $purchaseInvoice->supplier_id,
                    'branch_id' => $purchaseInvoice->branch_id,
                ]);
            }

            $journalEntry->lines()->create([
                'line_no' => count($debitLines) + 1,
                'account_id' => $supplierAccount->getKey(),
                'debit_amount' => 0,
                'credit_amount' => $purchaseInvoice->total_amount,
                'description' => __('purchase_invoices.journal.supplier_payable'),
                'supplier_id' => $purchaseInvoice->supplier_id,
                'branch_id' => $purchaseInvoice->branch_id,
            ]);

            return $journalEntry->refresh();
        });
    }

    public function assertManuallyEditable(JournalEntry $journalEntry): void
    {
        $journalEntry->assertManuallyEditable();
    }
}

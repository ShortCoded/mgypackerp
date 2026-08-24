<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Finance\Models\OpeningBalance;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseReturn;

class JournalEntryService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly FinancialPeriodService $financialPeriods,
    ) {}

    public function createPostedFromOpeningBalance(OpeningBalance $openingBalance): JournalEntry
    {
        if ($openingBalance->journal_entry_id !== null) {
            throw new DomainException(__('opening_balances.messages.already_has_journal_entry'));
        }

        return DB::transaction(function () use ($openingBalance): JournalEntry {
            $now = now();
            $userId = auth()->id();
            $branchIds = $openingBalance->lines->pluck('branch_id')->filter()->unique()->values();

            $journalEntry = JournalEntry::query()->create([
                ...$this->documents->next('journal_entries', JournalEntry::class),
                'entry_date' => $openingBalance->document_date,
                'company_id' => $openingBalance->company_id,
                'financial_period_id' => $openingBalance->financial_period_id,
                'branch_id' => $branchIds->count() === 1 ? $branchIds->first() : null,
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
            $this->financialPeriods->resolveOpenForPostingDate(
                (int) $purchaseInvoice->company_id,
                $purchaseInvoice->invoice_date,
                expectedPeriodId: (int) $purchaseInvoice->financial_period_id,
                lockForUpdate: true,
            );
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

    public function createPostedFromPurchaseReturn(PurchaseReturn $purchaseReturn): JournalEntry
    {
        if ($purchaseReturn->journal_entry_id !== null) {
            throw new DomainException(__('The purchase return already has an accounting entry.'));
        }

        $purchaseReturn->loadMissing(['purchaseInvoice.journalEntry.lines', 'lines.product']);
        $invoice = $purchaseReturn->purchaseInvoice;
        $originalEntry = $invoice?->journalEntry;
        if (! $invoice instanceof PurchaseInvoice || ! $originalEntry instanceof JournalEntry || (float) $invoice->total_amount <= 0) {
            throw new DomainException(__('A posted source purchase invoice is required for the financial return adjustment.'));
        }

        $supplierLine = $originalEntry->lines->first(fn ($line): bool => (float) $line->credit_amount > 0 && (int) $line->supplier_id === (int) $invoice->supplier_id);
        if ($supplierLine === null) {
            throw new DomainException(__('The source invoice accounting entry cannot be reversed safely.'));
        }

        $credits = [];
        foreach ($purchaseReturn->lines as $returnLine) {
            $code = match ($returnLine->product?->item_classification) {
                Product::ClassificationRawMaterial => '1131',
                Product::ClassificationSemiFinished => '1132',
                Product::ClassificationFinishedProduct => '1133',
                Product::ClassificationPackaging, Product::ClassificationOther => '1134',
                Product::ClassificationService => '512',
                default => '1134',
            };
            $account = Account::query()->forCompany((int) $purchaseReturn->company_id)
                ->where('account_code', $code)->where('status', 'active')->where('is_postable', true)->where('is_group', false)->first();
            if (! $account instanceof Account) {
                throw new DomainException(__('A return posting account is not configured for account code :code.', ['code' => $code]));
            }
            $credits[$code] ??= ['account_id' => $account->getKey(), 'amount' => 0.0];
            $credits[$code]['amount'] += (float) $returnLine->unit_price * (float) $returnLine->quantity;
        }

        $tax = (float) $purchaseReturn->lines->sum('tax_amount');
        if ($tax > 0) {
            $taxCode = (string) config('purchases.accounts.recoverable_input_vat', '2131');
            $taxAccount = Account::query()->forCompany((int) $purchaseReturn->company_id)
                ->where('account_code', $taxCode)->where('status', 'active')->where('is_postable', true)->where('is_group', false)->first();
            if (! $taxAccount instanceof Account) {
                throw new DomainException(__('The recoverable Input VAT account is not configured.'));
            }
            $credits['tax'] = ['account_id' => $taxAccount->getKey(), 'amount' => $tax];
        }

        $total = collect($credits)->sum('amount');
        $lines = [[
            'account_id' => (int) $supplierLine->account_id,
            'debit_amount' => number_format($total, 4, '.', ''),
            'credit_amount' => '0.0000',
            'description' => __('Supplier debit for purchase return'),
            'supplier_id' => $invoice->supplier_id,
            'branch_id' => $purchaseReturn->branch_id,
        ], ...collect($credits)->map(fn (array $credit): array => [
            'account_id' => (int) $credit['account_id'],
            'debit_amount' => '0.0000',
            'credit_amount' => number_format((float) $credit['amount'], 4, '.', ''),
            'description' => __('Purchase return reversal'),
            'supplier_id' => $invoice->supplier_id,
            'branch_id' => $purchaseReturn->branch_id,
        ])->values()->all()];

        return $this->createPostedFromSource([
            'entry_date' => $purchaseReturn->return_date,
            'company_id' => (int) $purchaseReturn->company_id,
            'financial_period_id' => (int) $purchaseReturn->financial_period_id,
            'branch_id' => $purchaseReturn->branch_id,
            'currency_id' => $invoice->currency_id,
            'exchange_rate' => $invoice->exchange_rate,
            'description' => __('Purchase return :document', ['document' => $purchaseReturn->doc_num]),
            'notes' => $purchaseReturn->notes,
            'source_type' => 'purchase_return',
            'source_id' => $purchaseReturn->getKey(),
            'source_doc_num' => $purchaseReturn->doc_num,
        ], $lines);
    }

    public function assertManuallyEditable(JournalEntry $journalEntry): void
    {
        $journalEntry->assertManuallyEditable();
    }

    /**
     * @param  array{entry_date: mixed, company_id: int, financial_period_id: int, branch_id?: int|null, currency_id?: int|null, exchange_rate?: string|int|float, description: string, notes?: string|null, source_type: string, source_id: int, source_doc_num: string}  $header
     * @param  list<array{account_id: int, debit_amount: string|int|float, credit_amount: string|int|float, description: string, customer_id?: int|null, supplier_id?: int|null, employee_id?: int|null, bank_account_id?: int|null, cost_center_id?: int|null, branch_id?: int|null}>  $lines
     */
    public function createPostedFromSource(array $header, array $lines): JournalEntry
    {
        if ($lines === []) {
            throw new DomainException('A system journal entry requires at least one line.');
        }

        try {
            return DB::transaction(function () use ($header, $lines): JournalEntry {
                $this->financialPeriods->resolveOpenForPostingDate(
                    (int) $header['company_id'],
                    $header['entry_date'],
                    expectedPeriodId: (int) $header['financial_period_id'],
                    lockForUpdate: true,
                );

                $existing = JournalEntry::query()
                    ->where('company_id', $header['company_id'])
                    ->where('source_type', $header['source_type'])
                    ->where('source_id', $header['source_id'])
                    ->where('status', JournalEntry::StatusPosted)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof JournalEntry) {
                    return $existing;
                }

                $debit = '0';
                $credit = '0';
                foreach ($lines as $line) {
                    $debit = bcadd($debit, (string) $line['debit_amount'], 4);
                    $credit = bcadd($credit, (string) $line['credit_amount'], 4);
                }
                if (bccomp($debit, $credit, 4) !== 0) {
                    throw new DomainException('The system journal entry is not balanced.');
                }

                $now = now();
                $userId = auth()->id();
                $journalEntry = JournalEntry::query()->create([
                    ...$this->documents->next('journal_entries', JournalEntry::class),
                    ...$header,
                    'exchange_rate' => $header['exchange_rate'] ?? 1,
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

                foreach ($lines as $index => $line) {
                    $journalEntry->lines()->create([
                        ...$line,
                        'line_no' => $index + 1,
                        'branch_id' => $line['branch_id'] ?? ($header['branch_id'] ?? null),
                    ]);
                }

                return $journalEntry->refresh();
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23505') {
                throw $exception;
            }

            $existing = JournalEntry::query()
                ->where('company_id', $header['company_id'])
                ->where('source_type', $header['source_type'])
                ->where('source_id', $header['source_id'])
                ->where('status', JournalEntry::StatusPosted)
                ->first();

            if (! $existing instanceof JournalEntry) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * @param  array{entry_date: mixed, company_id: int, financial_period_id: int, branch_id?: int|null, currency_id?: int|null, exchange_rate?: string|int|float, description: string, notes?: string|null, source_type: string, source_id: int, source_doc_num: string}  $header
     */
    public function createPostedReversalFromSource(JournalEntry $original, array $header): JournalEntry
    {
        return DB::transaction(function () use ($original, $header): JournalEntry {
            $locked = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($original->getKey());

            if ($locked->reversed_entry_id !== null) {
                return JournalEntry::query()->findOrFail($locked->reversed_entry_id);
            }

            if (! $locked->is_posted || $locked->status !== JournalEntry::StatusPosted) {
                throw new DomainException('Only a posted journal entry can be reversed.');
            }

            $lines = $locked->lines->map(fn ($line): array => [
                'account_id' => (int) $line->account_id,
                'debit_amount' => $line->credit_amount,
                'credit_amount' => $line->debit_amount,
                'description' => $header['description'],
                'customer_id' => $line->customer_id,
                'supplier_id' => $line->supplier_id,
                'employee_id' => $line->employee_id,
                'bank_account_id' => $line->bank_account_id,
                'cost_center_id' => $line->cost_center_id,
                'branch_id' => $line->branch_id,
            ])->all();

            $reversal = $this->createPostedFromSource($header, $lines);
            $locked->forceFill(['reversed_entry_id' => $reversal->getKey()])->save();

            return $reversal;
        });
    }
}

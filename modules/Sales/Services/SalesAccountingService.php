<?php

namespace Modules\Sales\Services;

use DomainException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Currency;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesReturn;

class SalesAccountingService
{
    public function __construct(private readonly JournalEntryService $journals, private readonly SalesAmountService $amounts) {}

    public function postInvoice(CustomerInvoice $invoice): JournalEntry
    {
        $invoice->loadMissing(['customer', 'lines']);
        if (! $invoice->customer?->account_id) {
            throw new DomainException('The customer must have a posting account before invoicing.');
        }
        $goods = $this->amounts->sum($invoice->lines->where('is_service', false)->map(fn ($line): string => $this->amounts->subtract($line->line_total, $line->tax_amount)));
        $services = $this->amounts->sum($invoice->lines->where('is_service', true)->map(fn ($line): string => $this->amounts->subtract($line->line_total, $line->tax_amount)));
        $lines = [[
            'account_id' => (int) $invoice->customer->account_id, 'debit_amount' => $invoice->total_amount,
            'credit_amount' => 0, 'description' => 'Customer receivable', 'customer_id' => $invoice->customer_id,
        ]];
        if ($this->amounts->compare($goods, '0') > 0) {
            $lines[] = $this->creditLine($this->account($invoice->company_id, 'sales_revenue'), $goods, 'Finished goods revenue');
        }
        if ($this->amounts->compare($services, '0') > 0) {
            $lines[] = $this->creditLine($this->account($invoice->company_id, 'service_revenue'), $services, 'Service revenue');
        }
        if ($this->amounts->compare($invoice->tax_amount, '0') > 0) {
            $lines[] = $this->creditLine($this->account($invoice->company_id, 'tax_payable'), (string) $invoice->tax_amount, 'Output tax');
        }

        $sourceType = (int) $invoice->posting_revision === 0
            ? 'customer_invoice'
            : 'customer_invoice_post_'.$invoice->posting_revision;

        return $this->journals->createPostedFromSource($this->header($invoice, $sourceType, 'Sales invoice '.$invoice->doc_num), $lines);
    }

    public function reverseInvoice(CustomerInvoice $invoice, string $reason, int $revision): JournalEntry
    {
        $invoice->loadMissing('journalEntry');
        $journal = $invoice->journalEntry;

        if (! $journal instanceof JournalEntry || $journal->reversed_entry_id !== null) {
            throw new DomainException('The posted invoice journal cannot be reversed safely.');
        }

        return $this->journals->createPostedReversalFromSource($journal, $this->header(
            $invoice,
            'customer_invoice_reversal_'.$revision,
            'Sales invoice reversal '.$invoice->doc_num,
        ) + ['notes' => trim($reason)]);
    }

    public function postReceipt(CustomerReceipt $receipt): JournalEntry
    {
        $receipt->loadMissing('customer');
        if (! $receipt->customer?->account_id) {
            throw new DomainException('The customer must have a posting account before collection.');
        }
        $cashAccountId = $receipt->cashbox_id ? Cashbox::query()->findOrFail($receipt->cashbox_id)->account_id : BankAccount::query()->findOrFail($receipt->bank_account_id)->account_id;
        if (! $cashAccountId) {
            throw new DomainException('The selected cash or bank account is not mapped to the chart of accounts.');
        }

        return $this->journals->createPostedFromSource($this->header($receipt, 'customer_receipt', 'Customer receipt '.$receipt->doc_num), [
            ['account_id' => (int) $cashAccountId, 'debit_amount' => $receipt->amount, 'credit_amount' => 0, 'description' => 'Cash / bank receipt', 'bank_account_id' => $receipt->bank_account_id],
            ['account_id' => (int) $receipt->customer->account_id, 'debit_amount' => 0, 'credit_amount' => $receipt->amount, 'description' => 'Customer receivable settlement', 'customer_id' => $receipt->customer_id],
        ]);
    }

    public function postDeliveryCost(InventoryDocument $delivery): JournalEntry
    {
        $delivery->loadMissing('lines');
        $cost = $this->amounts->sum($delivery->lines->pluck('total_cost'), 4);
        if ($this->amounts->compare($cost, '0') <= 0) {
            throw new DomainException('A delivery cannot post COGS without an inventory cost.');
        }

        return $this->journals->createPostedFromSource($this->header($delivery, 'sales_delivery_cogs', 'Cost of sales '.$delivery->doc_num), [
            ['account_id' => $this->account($delivery->company_id, 'cost_of_goods_sold')->getKey(), 'debit_amount' => $cost, 'credit_amount' => 0, 'description' => 'Cost of goods sold'],
            ['account_id' => $this->account($delivery->company_id, 'inventory')->getKey(), 'debit_amount' => 0, 'credit_amount' => $cost, 'description' => 'Finished goods inventory issue'],
        ]);
    }

    public function postCreditNote(CustomerInvoice $creditNote): JournalEntry
    {
        $creditNote->loadMissing(['customer', 'lines']);
        if (! $creditNote->customer?->account_id) {
            throw new DomainException('The customer must have a posting account before crediting.');
        }
        $net = $this->amounts->subtract($creditNote->total_amount, $creditNote->tax_amount);
        $lines = [
            ['account_id' => $this->account($creditNote->company_id, 'sales_returns')->getKey(), 'debit_amount' => $net, 'credit_amount' => 0, 'description' => 'Sales return'],
        ];
        if ($this->amounts->compare($creditNote->tax_amount, '0') > 0) {
            $lines[] = ['account_id' => $this->account($creditNote->company_id, 'tax_payable')->getKey(), 'debit_amount' => $creditNote->tax_amount, 'credit_amount' => 0, 'description' => 'Output tax reversal'];
        }
        $lines[] = ['account_id' => (int) $creditNote->customer->account_id, 'debit_amount' => 0, 'credit_amount' => $creditNote->total_amount, 'description' => 'Customer credit', 'customer_id' => $creditNote->customer_id];

        return $this->journals->createPostedFromSource($this->header($creditNote, 'customer_credit_note', 'Sales credit note '.$creditNote->doc_num), $lines);
    }

    public function postSaleableReturnCost(SalesReturn $return): ?JournalEntry
    {
        $return->loadMissing('lines');
        $cost = $this->amounts->sum($return->lines->map(fn ($line): string => $this->amounts->multiply($line->saleable_base_quantity, $line->original_unit_cost, 4)));
        if ($this->amounts->compare($cost, '0') <= 0) {
            return null;
        }

        return $this->journals->createPostedFromSource($this->header($return, 'sales_return_cogs', 'Returned inventory cost '.$return->doc_num), [
            ['account_id' => $this->account($return->company_id, 'inventory')->getKey(), 'debit_amount' => $cost, 'credit_amount' => 0, 'description' => 'Saleable inventory returned'],
            ['account_id' => $this->account($return->company_id, 'cost_of_goods_sold')->getKey(), 'debit_amount' => 0, 'credit_amount' => $cost, 'description' => 'Cost of sales reversal'],
        ]);
    }

    private function account(int $companyId, string $classification): Account
    {
        $account = Account::query()->join('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->where('accounts.company_id', $companyId)->where('accounts.status', 'active')->where('accounts.is_postable', true)
            ->where('account_classifications.code', $classification)->orderBy('accounts.account_code')->select('accounts.*')->first();
        if (! $account instanceof Account) {
            throw new DomainException("No active postable account is mapped for {$classification}.");
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function header(object $document, string $sourceType, string $description): array
    {
        $currencyId = $document->currency_id ?? Currency::query()
            ->where('company_id', $document->company_id)
            ->where('status', 'active')
            ->orderByDesc('is_main')
            ->value('id');
        if (! $currencyId) {
            throw new DomainException('The company requires an active accounting currency.');
        }

        return [
            'entry_date' => $document->invoice_date ?? $document->receipt_date ?? $document->document_date ?? $document->return_date,
            'company_id' => (int) $document->company_id, 'financial_period_id' => (int) $document->financial_period_id,
            'branch_id' => $document->branch_id, 'currency_id' => $currencyId,
            'exchange_rate' => $document->exchange_rate ?? 1, 'description' => $description,
            'notes' => $document->notes, 'source_type' => $sourceType,
            'source_id' => (int) $document->getKey(), 'source_doc_num' => (string) $document->doc_num,
        ];
    }

    /** @return array<string, mixed> */
    private function creditLine(Account $account, string $amount, string $description): array
    {
        return ['account_id' => (int) $account->getKey(), 'debit_amount' => 0, 'credit_amount' => $amount, 'description' => $description];
    }
}

<?php

namespace Modules\Sales\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Currency;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesReturn;

class SalesAccountingService
{
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly SalesAmountService $amounts,
        private readonly PostingAccountResolver $accounts,
    ) {}

    public function postInvoice(CustomerInvoice $invoice): JournalEntry
    {
        $invoice->loadMissing(['customer', 'lines']);
        if (! $invoice->customer?->account_id) {
            throw new DomainException(__('The customer must have a posting account before invoicing.'));
        }
        $goods = $this->amounts->sum($invoice->lines->where('is_service', false)->map(fn ($line): string => $this->amounts->subtract($line->line_total, $line->tax_amount)));
        $services = $this->amounts->sum($invoice->lines->where('is_service', true)->map(fn ($line): string => $this->amounts->subtract($line->line_total, $line->tax_amount)));
        $lines = [[
            'account_id' => (int) $invoice->customer->account_id, 'debit_amount' => $invoice->total_amount,
            'credit_amount' => 0, 'description' => 'Customer receivable', 'customer_id' => $invoice->customer_id,
        ]];
        if ($this->amounts->compare($goods, '0') > 0) {
            $lines[] = $this->creditLine($this->account($invoice->company_id, PostingAccountResolver::SalesRevenue), $goods, 'Finished goods revenue');
        }
        if ($this->amounts->compare($services, '0') > 0) {
            $serviceCreditAccount = $invoice->source_type === 'fixed_asset_disposal'
                ? $this->fixedAssetDisposalClearingAccount($invoice)
                : $this->account($invoice->company_id, PostingAccountResolver::ServiceRevenue);
            $lines[] = $this->creditLine($serviceCreditAccount, $services, $invoice->source_type === 'fixed_asset_disposal' ? 'Fixed Asset disposal clearing' : 'Service revenue');
        }
        if ($this->amounts->compare($invoice->tax_amount, '0') > 0) {
            $lines[] = $this->creditLine($this->account($invoice->company_id, PostingAccountResolver::OutputVatPayable), (string) $invoice->tax_amount, 'Output tax');
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
            throw new DomainException(__('The posted invoice journal cannot be reversed safely.'));
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
            throw new DomainException(__('The customer must have a posting account before collection.'));
        }
        $cashAccountId = $receipt->cashbox_id ? Cashbox::query()->findOrFail($receipt->cashbox_id)->account_id : BankAccount::query()->findOrFail($receipt->bank_account_id)->account_id;
        if (! $cashAccountId) {
            throw new DomainException(__('The selected cash or bank account is not mapped to the chart of accounts.'));
        }

        return $this->journals->createPostedFromSource($this->header($receipt, $receipt->reversal_journal_entry_id ? 'customer_receipt_clearing' : 'customer_receipt', 'Customer receipt '.$receipt->doc_num), [
            ['account_id' => (int) $cashAccountId, 'debit_amount' => $receipt->amount, 'credit_amount' => 0, 'description' => 'Cash / bank receipt', 'bank_account_id' => $receipt->bank_account_id],
            ['account_id' => (int) $receipt->customer->account_id, 'debit_amount' => 0, 'credit_amount' => $receipt->amount, 'description' => 'Customer receivable settlement', 'customer_id' => $receipt->customer_id],
        ]);
    }

    public function postDeliveryCost(InventoryDocument $delivery): JournalEntry
    {
        $delivery->loadMissing('lines.product');
        $cost = $this->amounts->sum($delivery->lines->pluck('total_cost'), 4);
        if ($this->amounts->compare($cost, '0') <= 0) {
            throw new DomainException(__('A delivery cannot post COGS without an inventory cost.'));
        }

        $credits = [];
        foreach ($delivery->lines as $line) {
            $account = $this->accounts->inventoryForProduct((int) $delivery->company_id, $line->product, __('Sales Delivery'));
            $credits[$account->id] = $this->amounts->add($credits[$account->id] ?? '0', $line->total_cost);
        }
        $lines = [['account_id' => $this->account($delivery->company_id, PostingAccountResolver::CostOfGoodsSold)->getKey(), 'debit_amount' => $cost, 'credit_amount' => 0, 'description' => 'Cost of goods sold']];
        foreach ($credits as $accountId => $amount) {
            if ($this->amounts->compare($amount, '0') > 0) {
                $lines[] = ['account_id' => $accountId, 'debit_amount' => 0, 'credit_amount' => $amount, 'description' => 'Inventory issue by product classification'];
            }
        }

        return $this->journals->createPostedFromSource($this->header($delivery, 'sales_delivery_cogs', 'Cost of sales '.$delivery->doc_num), $lines);
    }

    public function postCreditNote(CustomerInvoice $creditNote): JournalEntry
    {
        $creditNote->loadMissing(['customer', 'lines']);
        if (! $creditNote->customer?->account_id) {
            throw new DomainException(__('The customer must have a posting account before crediting.'));
        }
        $net = $this->amounts->subtract($creditNote->total_amount, $creditNote->tax_amount);
        $lines = [
            ['account_id' => $this->account($creditNote->company_id, PostingAccountResolver::SalesReturns)->getKey(), 'debit_amount' => $net, 'credit_amount' => 0, 'description' => 'Sales return'],
        ];
        if ($this->amounts->compare($creditNote->tax_amount, '0') > 0) {
            $lines[] = ['account_id' => $this->account($creditNote->company_id, PostingAccountResolver::OutputVatPayable)->getKey(), 'debit_amount' => $creditNote->tax_amount, 'credit_amount' => 0, 'description' => 'Output tax reversal'];
        }
        $lines[] = ['account_id' => (int) $creditNote->customer->account_id, 'debit_amount' => 0, 'credit_amount' => $creditNote->total_amount, 'description' => 'Customer credit', 'customer_id' => $creditNote->customer_id];

        return $this->journals->createPostedFromSource($this->header($creditNote, 'customer_credit_note', 'Sales credit note '.$creditNote->doc_num), $lines);
    }

    public function postSaleableReturnCost(SalesReturn $return): ?JournalEntry
    {
        $return->loadMissing('lines.product');
        $cost = $this->amounts->sum($return->lines->map(fn ($line): string => $this->amounts->multiply($line->saleable_base_quantity, $line->original_unit_cost, 4)));
        if ($this->amounts->compare($cost, '0') <= 0) {
            return null;
        }

        $inventoryDebits = [];
        foreach ($return->lines as $line) {
            $lineCost = $this->amounts->multiply($line->saleable_base_quantity, $line->original_unit_cost, 4);
            if ($this->amounts->compare($lineCost, '0') <= 0) {
                continue;
            }

            $inventoryAccount = $this->accounts->inventoryForProduct(
                (int) $return->company_id,
                $line->product,
                __('Sales Return'),
            );
            $inventoryDebits[$inventoryAccount->getKey()] = $this->amounts->add(
                $inventoryDebits[$inventoryAccount->getKey()] ?? '0.0000',
                $lineCost,
            );
        }

        $lines = collect($inventoryDebits)->map(fn (string $amount, int $accountId): array => [
            'account_id' => $accountId,
            'debit_amount' => $amount,
            'credit_amount' => 0,
            'description' => 'Saleable inventory returned',
        ])->values()->all();
        $lines[] = [
            'account_id' => $this->account($return->company_id, PostingAccountResolver::CostOfGoodsSold)->getKey(),
            'debit_amount' => 0,
            'credit_amount' => $cost,
            'description' => 'Cost of sales reversal',
        ];

        return $this->journals->createPostedFromSource($this->header($return, 'sales_return_cogs', 'Returned inventory cost '.$return->doc_num), $lines);
    }

    public function postReturnedGoodsToQuarantine(SalesReturn $return): ?JournalEntry
    {
        $return->loadMissing('lines.product');
        $cost = $this->amounts->sum($return->lines->where('is_service', false)
            ->map(fn ($line): string => $this->amounts->multiply($line->base_quantity, $line->original_unit_cost, 4)));
        if ($this->amounts->compare($cost, '0') <= 0) {
            return null;
        }

        $quarantine = $this->accounts->resolve(
            (int) $return->company_id,
            PostingAccountResolver::QuarantineInventory,
            __('Sales Return Receipt'),
        );

        return $this->journals->createPostedFromSource($this->header($return, 'sales_return_quarantine_receipt', 'Returned goods to quarantine '.$return->doc_num), [
            ['account_id' => $quarantine->getKey(), 'debit_amount' => $cost, 'credit_amount' => 0, 'description' => 'Returned goods quarantine'],
            ['account_id' => $this->account($return->company_id, PostingAccountResolver::CostOfGoodsSold)->getKey(), 'debit_amount' => 0, 'credit_amount' => $cost, 'description' => 'Cost of sales reversal'],
        ]);
    }

    public function postReturnDisposition(SalesReturn $return): ?JournalEntry
    {
        $return->loadMissing('lines.product');
        $quarantine = $this->accounts->resolve(
            (int) $return->company_id,
            PostingAccountResolver::QuarantineInventory,
            __('Sales Return Disposition'),
        );
        $debits = [];
        $total = '0.0000';

        foreach ($return->lines->where('is_service', false) as $line) {
            $profiles = [
                'saleable_base_quantity' => $this->accounts->inventoryForProduct((int) $return->company_id, $line->product, __('Sales Return Disposition')),
                'rework_base_quantity' => $this->accounts->resolve((int) $return->company_id, PostingAccountResolver::ReworkInventory, __('Sales Return Disposition')),
                'scrap_base_quantity' => $this->accounts->resolve((int) $return->company_id, PostingAccountResolver::WarehouseDamageLoss, __('Sales Return Disposition')),
            ];
            foreach ($profiles as $quantityField => $account) {
                $amount = $this->amounts->multiply($line->{$quantityField}, $line->original_unit_cost, 4);
                if ($this->amounts->compare($amount, '0') <= 0) {
                    continue;
                }
                if ((int) $account->getKey() === (int) $quarantine->getKey()) {
                    continue;
                }
                $debits[$account->getKey()] = $this->amounts->add($debits[$account->getKey()] ?? '0.0000', $amount);
                $total = $this->amounts->add($total, $amount);
            }
        }

        if ($this->amounts->compare($total, '0') <= 0) {
            return null;
        }

        $lines = collect($debits)->map(fn (string $amount, int $accountId): array => [
            'account_id' => $accountId, 'debit_amount' => $amount, 'credit_amount' => 0,
            'description' => 'Returned goods quality disposition',
        ])->values()->all();
        $lines[] = [
            'account_id' => $quarantine->getKey(), 'debit_amount' => 0, 'credit_amount' => $total,
            'description' => 'Released from returned goods quarantine',
        ];

        return $this->journals->createPostedFromSource($this->header($return, 'sales_return_financial_disposition', 'Returned goods disposition '.$return->doc_num), $lines);
    }

    private function account(int $companyId, string $classification): Account
    {
        return $this->accounts->resolveFirst($companyId, $classification, __('Sales accounting'));
    }

    private function fixedAssetDisposalClearingAccount(CustomerInvoice $invoice): Account
    {
        $disposal = FixedAssetDisposal::query()->with('asset')->find($invoice->source_id);
        $categoryId = $disposal?->asset?->asset_group_account_id ?: $disposal?->asset?->account?->parent_id;
        $account = FixedAssetCategoryMapping::query()
            ->with('disposalClearingAccount')
            ->where('company_id', $invoice->company_id)
            ->where('asset_group_account_id', $categoryId)
            ->first()?->disposalClearingAccount;
        if (! $account instanceof Account || $account->trashed() || $account->status !== 'active' || $account->is_group || ! $account->is_postable) {
            throw new DomainException(__('The Fixed Asset disposal clearing account is not configured.'));
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
            throw new DomainException(__('The company requires an active accounting currency.'));
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

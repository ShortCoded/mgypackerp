<?php

namespace Modules\Finance\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Finance\Models\OpeningBalance;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\OpeningStockPricingLine;

class OpeningInventoryValuationService
{
    public function __construct(
        private readonly PostingAccountResolver $accounts,
    ) {}

    public function isInventoryAccount(Account $account): bool
    {
        return in_array($account->classification?->code, [PostingAccountResolver::RawMaterialInventory,
            PostingAccountResolver::PackagingMaterialInventory, PostingAccountResolver::SemiFinishedGoodsInventory,
            PostingAccountResolver::FinishedGoodsInventory, PostingAccountResolver::QuarantineInventory,
            PostingAccountResolver::ReworkInventory], true);
    }

    /** @return array<string, mixed> */
    public function preview(int $companyId, int $periodId, ?int $branchId, string $accountDocNum, bool $lock = false): array
    {
        $account = Account::query()->with('classification')->forCompany($companyId)->eligibleForDirectPosting()
            ->where('doc_num', $accountDocNum)->firstOrFail();
        if (! $this->isInventoryAccount($account)) {
            return ['applicable' => false];
        }
        $period = FinancialPeriod::query()->where('company_id', $companyId)->findOrFail($periodId);
        $branch = Branch::query()->where('company_id', $companyId)->where('status', 'active')->findOrFail($branchId);
        if ($period->is_closed || ! $period->allows_opening_entries) {
            throw new DomainException(__('opening_balances.messages.inventory_period_unavailable'));
        }
        $currency = Currency::query()->where('company_id', $companyId)->where('is_main', true)->where('status', 'active')->sole();
        $query = DB::table('inventory_transactions as movement')
            ->join('inventory_opening_stocks as source', 'source.id', '=', 'movement.source_id')
            ->join('inventory_opening_stock_lines as source_line', 'source_line.id', '=', 'movement.source_line_id')
            ->join('products as product', 'product.id', '=', 'movement.product_id')
            ->join('branch_stores as store', 'store.id', '=', 'movement.branch_store_id')
            ->where('movement.company_id', $companyId)->where('source.company_id', $companyId)->where('product.company_id', $companyId)
            ->where('movement.financial_period_id', $periodId)->where('source.financial_period_id', $periodId)
            ->where('movement.branch_id', $branch->id)->where('source.branch_id', $branch->id)->where('store.branch_id', $branch->id)
            ->where('movement.source_type', OpeningStock::class)->where('movement.source_line_type', OpeningStockLine::class)
            ->whereColumn('source_line.opening_stock_id', 'source.id')->whereColumn('source_line.product_id', 'movement.product_id')
            ->where('movement.transaction_type', 'opening_stock')->where('movement.is_reversal', false)
            ->where('movement.quantity_in', '>', 0)->where('movement.quantity_out', 0)
            ->select(['movement.id', 'movement.product_id', 'movement.source_id', 'movement.source_line_id',
                'movement.branch_store_id', 'movement.quantity_in', 'movement.unit_cost', 'movement.total_cost',
                'movement.transaction_date', 'movement.stock_status', 'product.item_classification',
                'source.branch_store_id as source_store_id', 'source_line.quantity as source_quantity',
                'source_line.stock_status as source_stock_status', 'source.doc_num', 'source.status', 'source.approved', 'source.deleted_at'])
            ->orderBy('movement.id');
        $rows = $query->get();
        $classificationAccounts = [];
        $rows = $rows->filter(function ($row) use ($companyId, $account, &$classificationAccounts): bool {
            $key = $row->stock_status.':'.$row->item_classification;
            $classificationAccounts[$key] ??= match ($row->stock_status) {
                InventoryTransaction::StatusQuarantine => $this->accounts->resolve($companyId, PostingAccountResolver::QuarantineInventory, __('opening_balances.title'))->id,
                InventoryTransaction::StatusRework => $this->accounts->resolve($companyId, PostingAccountResolver::ReworkInventory, __('opening_balances.title'))->id,
                default => $this->accounts->inventoryForProduct($companyId, new Product(['item_classification' => $row->item_classification]), __('opening_balances.title'))->id,
            };

            return (int) $classificationAccounts[$key] === (int) $account->id;
        })->values();
        if ($rows->isEmpty()) {
            throw new DomainException(__('opening_balances.messages.inventory_source_missing'));
        }
        if ($lock) {
            Product::query()->whereIn('id', $rows->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
            InventoryTransaction::query()->whereIn('id', $rows->pluck('id'))->orderBy('id')->lockForUpdate()->get();

            return $this->preview($companyId, $periodId, $branchId, $accountDocNum);
        }
        $pricingBySource = OpeningStockPricingLine::query()
            ->with('pricing')->whereIn('opening_stock_line_id', $rows->pluck('source_line_id'))
            ->whereHas('pricing', fn ($query) => $query->where('company_id', $companyId)->where('financial_period_id', $periodId)
                ->where('branch_id', $branchId)->where('is_closed', true)->where('status', OpeningStockPricing::StatusClosed))
            ->get()->groupBy('opening_stock_line_id');
        $total = '0.00000000';
        foreach ($rows as $row) {
            if ((int) $row->branch_store_id !== (int) $row->source_store_id
                || bccomp((string) $row->quantity_in, (string) $row->source_quantity, 4) !== 0
                || $row->stock_status !== ($row->source_stock_status ?: InventoryTransaction::StatusAvailable)) {
                throw new DomainException(__('opening_balances.messages.inventory_source_changed'));
            }
            if (! $row->approved || $row->status !== OpeningStock::StatusApproved || $row->deleted_at !== null
                || $row->unit_cost === null || $row->total_cost === null || bccomp((string) $row->total_cost, '0', 8) < 0) {
                throw new DomainException(__('opening_balances.messages.inventory_source_unpriced'));
            }
            $pricingLines = $pricingBySource->get($row->source_line_id);
            if (! $pricingLines || $pricingLines->count() !== 1) {
                throw new DomainException(__('opening_balances.messages.inventory_source_unpriced'));
            }
            $pricingLine = $pricingLines->first();
            $pricing = $pricingLine->pricing;
            if ((int) $pricing->opening_stock_id !== (int) $row->source_id
                || bccomp((string) $pricingLine->quantity, (string) $row->quantity_in, 4) !== 0
                || bccomp((string) $row->unit_cost, bcmul((string) $pricingLine->unit_price, (string) $pricing->exchange_rate, 8), 8) !== 0
                || bccomp((string) $row->total_cost, bcmul((string) $pricingLine->line_total, (string) $pricing->exchange_rate, 4), 8) !== 0
                || ($pricing->pricing_basis === OpeningStockPricing::BasisEstimate
                    && (! $pricing->approved_by || ! $pricing->approved_at || ! $pricing->approval_reference || ! $pricing->source_reference))) {
                throw new DomainException(__('opening_balances.messages.inventory_source_changed'));
            }
            $row->pricing = ['pricing_id' => $pricing->id, 'line_id' => $pricingLine->id, 'doc_num' => $pricing->doc_num,
                'document_date' => $pricing->document_date->toDateString(), 'pricing_basis' => $pricing->pricing_basis,
                'source_reference' => $pricing->source_reference, 'approval_reference' => $pricing->approval_reference,
                'approved_by' => $pricing->approved_by, 'approved_at' => $pricing->approved_at?->toISOString(),
                'source_file_sha256' => $pricing->source_file_sha256, 'currency_id' => $pricing->currency_id,
                'exchange_rate' => $pricing->exchange_rate, 'unit_price' => $pricingLine->unit_price, 'line_total' => $pricingLine->line_total];
            $total = bcadd($total, (string) $row->total_cost, 8);
        }
        $amount = bcround($total, 4);
        if (bccomp($amount, '0', 4) <= 0 || bccomp($amount, '99999999999999.9999', 4) > 0) {
            throw new DomainException(__('opening_balances.messages.inventory_amount_invalid'));
        }
        $snapshot = ['company_id' => $companyId, 'financial_period_id' => $periodId, 'branch_id' => (int) $branch->id,
            'account_id' => (int) $account->id, 'account_doc_num' => $account->doc_num, 'currency_id' => (int) $currency->id,
            'currency_doc_num' => $currency->doc_num, 'amount' => $amount, 'source_rows' => $rows->map(fn ($row): array => (array) $row)->all()];
        $snapshot['fingerprint'] = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

        return ['applicable' => true, 'amount' => $amount, 'transaction_type' => 'debit',
            'currency_doc_num' => $currency->doc_num, 'branch_doc_num' => $branch->doc_num,
            'financial_period_doc_num' => $period->doc_num, 'source_count' => $rows->count(),
            'can_post' => ! $this->alreadyPosted($companyId, $periodId, (int) $branch->id, (int) $account->id),
            'snapshot' => $snapshot];
    }

    /** @return array{lines: array, inventory_valuation_snapshot: array|null} */
    public function prepare(array $data, array $context, bool $lock = false): array
    {
        $lines = $data['lines'] ?? [];
        $selected = null;
        $index = null;
        foreach ($lines as $i => $line) {
            $account = Account::query()->with('classification')->forCompany($context['company_id'])
                ->where('doc_num', $line['account_doc_num'] ?? '')->first();
            if (! $account instanceof Account || ! $this->isInventoryAccount($account)) {
                continue;
            }
            $preview = $this->preview($context['company_id'], $context['financial_period_id'], $context['branch_id'], $account->doc_num, $lock);
            if (! $preview['applicable']) {
                continue;
            }
            if ($selected !== null || count($lines) !== 2 || ! $preview['can_post']) {
                throw new DomainException(__('opening_balances.messages.inventory_duplicate_or_counterpart'));
            }
            $selected = $preview;
            $index = $i;
        }
        if ($selected === null) {
            return ['lines' => $lines, 'inventory_valuation_snapshot' => null];
        }
        if (($data['currency_doc_num'] ?? '') !== $selected['currency_doc_num']) {
            throw new DomainException(__('opening_balances.messages.inventory_main_currency_only'));
        }
        if (($data['inventory_source_fingerprint'] ?? '') !== $selected['snapshot']['fingerprint']) {
            throw new DomainException(__('opening_balances.messages.inventory_source_changed'));
        }
        $other = $index === 0 ? 1 : 0;
        $counterpart = Account::query()->with('classification')->forCompany($context['company_id'])->eligibleForDirectPosting()
            ->where('doc_num', $lines[$other]['account_doc_num'] ?? '')->first();
        if (! $counterpart instanceof Account || $this->isInventoryAccount($counterpart)) {
            throw new DomainException(__('opening_balances.messages.inventory_duplicate_or_counterpart'));
        }
        foreach ([$index => 'debit', $other => 'credit'] as $i => $type) {
            $lines[$i]['amount'] = $selected['amount'];
            $lines[$i]['transaction_type'] = $type;
        }

        return ['lines' => $lines, 'inventory_valuation_snapshot' => $selected['snapshot']];
    }

    public function assertApproval(OpeningBalance $record): void
    {
        $snapshot = $record->inventory_valuation_snapshot;
        if (! is_array($snapshot)) {
            foreach ($record->lines as $line) {
                if ($line->account instanceof Account && $this->isInventoryAccount($line->account)) {
                    throw new DomainException(__('opening_balances.messages.inventory_source_changed'));
                }
            }

            return;
        }
        $context = ['company_id' => (int) $record->company_id, 'financial_period_id' => (int) $record->financial_period_id,
            'branch_id' => (int) $snapshot['branch_id']];
        $prepared = $this->prepare(['currency_doc_num' => $record->currency?->doc_num,
            'inventory_source_fingerprint' => $snapshot['fingerprint'] ?? '',
            'lines' => $record->lines->map(fn ($line): array => ['account_doc_num' => $line->account?->doc_num])->all()], $context, true);
        if ($prepared['inventory_valuation_snapshot'] !== $snapshot) {
            throw new DomainException(__('opening_balances.messages.inventory_source_changed'));
        }
        foreach ($record->lines as $i => $line) {
            $expected = $prepared['lines'][$i];
            if ((int) $line->branch_id !== $context['branch_id']
                || bccomp((string) $line->debit_amount, $expected['transaction_type'] === 'debit' ? $expected['amount'] : '0', 4) !== 0
                || bccomp((string) $line->credit_amount, $expected['transaction_type'] === 'credit' ? $expected['amount'] : '0', 4) !== 0) {
                throw new DomainException(__('opening_balances.messages.inventory_source_changed'));
            }
        }
    }

    public function assertSourceMayBePosted(int $companyId, int $periodId, int $branchId, Product $product, string $status): void
    {
        $classification = match ($status) {
            InventoryTransaction::StatusQuarantine => PostingAccountResolver::QuarantineInventory,
            InventoryTransaction::StatusRework => PostingAccountResolver::ReworkInventory,
            default => $this->accounts->inventoryClassificationForProduct($product, __('opening_balances.title')),
        };
        $finalized = DB::table('journal_entry_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->join('account_classifications as classification', 'classification.id', '=', 'account.account_classification_id')
            ->where('entry.company_id', $companyId)->where('entry.financial_period_id', $periodId)
            ->where('entry.source_type', 'opening_balance')->where('entry.status', JournalEntry::StatusPosted)->where('entry.is_posted', true)
            ->where('account.company_id', $companyId)->where('classification.code', $classification)
            ->where(fn ($query) => $query->where('line.branch_id', $branchId)->orWhereNull('line.branch_id'))->exists();
        if ($finalized) {
            throw new DomainException(__('opening_balances.messages.inventory_finalized'));
        }
    }

    private function alreadyPosted(int $companyId, int $periodId, int $branchId, int $accountId): bool
    {
        return DB::table('journal_entry_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->where('entry.company_id', $companyId)->where('entry.financial_period_id', $periodId)
            ->where('entry.source_type', 'opening_balance')->where('entry.status', JournalEntry::StatusPosted)->where('entry.is_posted', true)
            ->where('line.account_id', $accountId)->where(fn ($query) => $query->where('line.branch_id', $branchId)->orWhereNull('line.branch_id'))->exists();
    }
}

<?php

namespace Modules\Inventory\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryCostStandard;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryStandardCostSettlement;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;

class InventoryStandardCostService
{
    public const Components = ['materials', 'labor', 'overhead'];

    public function __construct(
        private readonly InventoryCostPolicyService $policies,
        private readonly OperatingContextService $context,
        private readonly InventoryReceiptCostCompletionService $completion,
        private readonly InventoryValueAdjustmentService $adjustments,
        private readonly InventoryReceiptCostProposalService $proposals,
        private readonly ProductionCostService $productionCosts,
        private readonly ActivityLogger $activity,
        private readonly PostingAccountResolver $postingAccounts,
    ) {}

    /** @param array<string, mixed> $data */
    public function prepareVersion(int $companyId, array $data, int $actorId): InventoryCostStandard
    {
        return DB::transaction(function () use ($companyId, $data, $actorId): InventoryCostStandard {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->policies->authorizeOperation($companyId, (int) $data['branch_id'], null, $data['effective_from'], $actorId, 'inventory.cost_policies.standard.prepare');
            $rules = ['effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
                'source_reference' => ['required', 'string', 'min:5', 'max:2000']];
            foreach (self::Components as $component) {
                $rules[$component.'_unit_cost'] = ['required', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/D'];
            }
            if (Validator::make($data, $rules)->fails()) {
                throw new DomainException(__('inventory_standard_cost.errors.inputs'));
            }
            $total = collect(self::Components)->reduce(fn (string $sum, string $part): string => bcadd($sum, $data[$part.'_unit_cost'], 8), '0');
            if (bccomp($total, '0', 8) <= 0 || bccomp($total, '999999999999.99999999', 8) > 0) {
                throw new DomainException(__('inventory_standard_cost.errors.inputs'));
            }
            $basis = $this->versionBasis($companyId, $data);
            $overlap = InventoryCostStandard::query()->where('company_id', $companyId)->where('branch_id', $data['branch_id'])
                ->where('product_id', $data['product_id'])->whereIn('status', [InventoryCostStandard::StatusPrepared, InventoryCostStandard::StatusApproved])
                ->whereDate('effective_from', '<=', $data['effective_to'])->whereDate('effective_to', '>=', $data['effective_from'])->exists();
            if ($overlap) {
                throw new DomainException(__('inventory_standard_cost.errors.overlap'));
            }
            $standard = InventoryCostStandard::query()->create([
                ...collect($data)->only(['branch_id', 'product_id', 'effective_from', 'effective_to', 'source_reference', 'counterpart_account_id',
                    ...array_map(fn ($part): string => $part.'_unit_cost', self::Components),
                    ...array_map(fn ($part): string => $part.'_variance_account_id', self::Components)])->all(),
                'company_id' => $companyId, 'public_uuid' => (string) Str::uuid(), 'status' => InventoryCostStandard::StatusPrepared,
                'doc_num' => 'STD-'.str_pad((string) (InventoryCostStandard::query()->where('company_id', $companyId)->count() + 1), 5, '0', STR_PAD_LEFT),
                'basis_snapshot' => $basis, 'basis_sha256' => $this->hash($basis), 'prepared_by' => $actorId,
            ]);
            $this->log($standard, 'standard_cost_prepared');

            return $standard;
        });
    }

    public function approveVersion(InventoryCostStandard $standard, int $actorId, string $reference): InventoryCostStandard
    {
        return DB::transaction(function () use ($standard, $actorId, $reference): InventoryCostStandard {
            Company::query()->whereKey($standard->company_id)->lockForUpdate()->firstOrFail();
            $locked = InventoryCostStandard::query()->lockForUpdate()->findOrFail($standard->id);
            $this->policies->authorizeOperation((int) $locked->company_id, (int) $locked->branch_id, null,
                $locked->effective_from->toDateString(), $actorId, 'inventory.cost_policies.standard.approve');
            $this->assertDecision($locked, $actorId, $reference);
            if ($locked->basis_sha256 !== $this->hash($this->versionBasis((int) $locked->company_id, $locked->getAttributes()))) {
                throw new DomainException(__('inventory_standard_cost.errors.stale'));
            }
            $locked->forceFill(['status' => InventoryCostStandard::StatusApproved, 'approved_by' => $actorId,
                'approved_at' => now(), 'approval_reference' => trim($reference)])->save();
            $this->log($locked, 'standard_cost_approved');

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    private function versionBasis(int $companyId, array $data): array
    {
        $product = Product::query()->where('company_id', $companyId)->where('status', 'active')
            ->where('item_classification', Product::ClassificationFinishedProduct)->lockForUpdate()->findOrFail($data['product_id']);
        $accountIds = array_map(fn ($part): int => (int) $data[$part.'_variance_account_id'], self::Components);
        if (count(array_unique($accountIds)) !== 3) {
            throw new DomainException(__('inventory_standard_cost.errors.accounts'));
        }
        $accounts = [];
        foreach (self::Components as $component) {
            $account = Account::query()->forCompany($companyId)->eligibleForDirectPosting()->where('account_type', Account::TypeExpense)
                ->lockForUpdate()->findOrFail($data[$component.'_variance_account_id']);
            $accounts[$component] = $account->only(['id', 'account_code', 'name', 'name_en', 'status', 'is_group', 'is_postable', 'account_type', 'account_classification_id']);
        }
        $clearing = Account::query()->forCompany($companyId)->eligibleForDirectPosting()->where('account_type', Account::TypeLiability)
            ->whereHas('classification', fn ($query) => $query->where('code', PostingAccountResolver::InventoryCostCompletionClearing))
            ->lockForUpdate()->findOrFail($data['counterpart_account_id']);

        return ['product' => $product->only(['id', 'doc_num', 'name', 'item_unit_id', 'item_classification']),
            'accounts' => $accounts, 'clearing' => $clearing->only(['id', 'account_code', 'name', 'status', 'is_group', 'is_postable', 'account_classification_id'])];
    }

    public function prepareSettlement(ProductionRun $run, string $postingDate, string $reason, int $actorId): InventoryStandardCostSettlement
    {
        return DB::transaction(function () use ($run, $postingDate, $reason, $actorId): InventoryStandardCostSettlement {
            Company::query()->whereKey($run->company_id)->lockForUpdate()->firstOrFail();
            $run = ProductionRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->policies->authorizeOperation((int) $run->company_id, (int) $run->branch_id, null, $postingDate, $actorId, 'inventory.cost_policies.standard.settle');
            if (trim($reason) === '' || InventoryStandardCostSettlement::query()->where('production_run_id', $run->id)->where('status', 'prepared')->exists()) {
                throw new DomainException(__('inventory_standard_cost.errors.duplicate'));
            }
            $receipts = $this->runReceipts($run);
            $standard = InventoryCostStandard::query()->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)
                ->where('product_id', $run->product_id)->where('status', InventoryCostStandard::StatusApproved)
                ->whereDate('effective_from', '<=', $receipts->min('transaction_date')->toDateString())
                ->whereDate('effective_to', '>=', $receipts->max('transaction_date')->toDateString())->lockForUpdate()->first();
            if ($standard === null) {
                throw new DomainException(__('inventory_standard_cost.errors.missing'));
            }
            $settlement = InventoryStandardCostSettlement::query()->create([
                'company_id' => $run->company_id, 'branch_id' => $run->branch_id, 'financial_period_id' => $run->financial_period_id,
                'posting_period_id' => $this->context->snapshot(request())['financial_period_id'], 'production_run_id' => $run->id,
                'inventory_cost_standard_id' => $standard->id, 'counterpart_account_id' => $standard->counterpart_account_id,
                'public_uuid' => (string) Str::uuid(), 'status' => InventoryStandardCostSettlement::StatusPrepared,
                'doc_num' => 'SCV-'.str_pad((string) (InventoryStandardCostSettlement::query()->where('company_id', $run->company_id)->count() + 1), 5, '0', STR_PAD_LEFT),
                'revision' => (int) InventoryStandardCostSettlement::query()->where('production_run_id', $run->id)->max('revision') + 1,
                'posting_date' => $postingDate, 'reason' => trim($reason), 'prepared_by' => $actorId,
            ]);
            $plan = $this->plan($settlement, $actorId, 'inventory.cost_policies.standard.settle');
            if ($plan['effects'] === [] && InventoryStandardCostSettlement::query()->where('production_run_id', $run->id)->where('status', 'finalized')->exists()) {
                throw new DomainException(__('inventory_standard_cost.errors.duplicate'));
            }
            $settlement->forceFill(['impact_snapshot' => $plan, 'impact_sha256' => $this->hash($plan)])->save();
            $this->log($settlement, 'standard_variance_prepared');

            return $settlement->refresh();
        });
    }

    /** @return Collection<int, InventoryTransaction> */
    private function runReceipts(ProductionRun $run): Collection
    {
        if ($run->status !== ProductionRun::StatusCompleted || bccomp((string) $run->good_base_quantity, '0', 8) <= 0
            || bccomp((string) $run->received_base_quantity, (string) $run->good_base_quantity, 8) !== 0) {
            throw new DomainException(__('inventory_standard_cost.errors.run'));
        }
        $receipts = InventoryTransaction::query()->where('company_id', $run->company_id)->where('production_run_id', $run->id)
            ->where('transaction_type', InventoryDocument::TypeProductionReceipt)->where('is_reversal', false)->where('quantity_in', '>', 0)
            ->where('source_type', InventoryDocument::class)
            ->whereIn('source_id', InventoryDocument::query()->where('company_id', $run->company_id)
                ->where('production_run_id', $run->id)->where('status', InventoryDocument::StatusPosted)->select('id'))
            ->orderBy('transaction_date')->orderBy('id')->lockForUpdate()->get();
        $quantity = $receipts->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->quantity_in, 8), '0');
        if ($receipts->isEmpty() || bccomp($quantity, (string) $run->good_base_quantity, 8) !== 0
            || $receipts->contains(fn ($row): bool => $row->product_id !== $run->product_id || $row->completedTotalCost() === null
                || $row->source_type !== InventoryDocument::class || ! InventoryDocument::query()->where('company_id', $run->company_id)
                    ->whereKey($row->source_id)->where('status', InventoryDocument::StatusPosted)->exists())) {
            throw new DomainException(__('inventory_standard_cost.errors.run'));
        }

        return $receipts;
    }

    /** @return array<string, mixed> */
    public function plan(InventoryStandardCostSettlement $settlement, int $actorId, string $permission): array
    {
        $this->policies->authorizeOperation((int) $settlement->company_id, (int) $settlement->branch_id, null,
            $settlement->posting_date->toDateString(), $actorId, $permission);
        if ((int) $this->context->snapshot(request())['financial_period_id'] !== (int) $settlement->posting_period_id) {
            throw new DomainException(__('inventory_standard_cost.errors.scope'));
        }
        $run = ProductionRun::query()->where('company_id', $settlement->company_id)->lockForUpdate()->findOrFail($settlement->production_run_id);
        $standard = InventoryCostStandard::query()->lockForUpdate()->findOrFail($settlement->inventory_cost_standard_id);
        $receipts = $this->runReceipts($run);
        if ($standard->status !== InventoryCostStandard::StatusApproved || (int) $standard->company_id !== (int) $run->company_id
            || (int) $standard->branch_id !== (int) $run->branch_id || (int) $standard->product_id !== (int) $run->product_id
            || $receipts->min('transaction_date')->lt($standard->effective_from) || $receipts->max('transaction_date')->gt($standard->effective_to)
            || $standard->basis_sha256 !== $this->hash($this->versionBasis((int) $run->company_id, $standard->getAttributes()))) {
            throw new DomainException(__('inventory_standard_cost.errors.stale'));
        }
        $position = $this->productionCosts->runPosition($run);
        if (! $position['material_valuation_complete'] || ! $position['expense_valuation_complete'] || ! $position['labor_valuation_complete']
        ) {
            throw new DomainException(__('inventory_standard_cost.errors.costs'));
        }
        $actual = ['materials' => $position['direct_material_cost'], 'labor' => $position['direct_labor_cost'],
            'overhead' => bcadd($position['other_direct_cost'], $position['allocated_overhead'], 8)];
        $targets = $components = [];
        $unitCost = '0.00000000';
        $previousVariances = InventoryValueAdjustmentLine::query()->where('effect', InventoryValueAdjustmentLine::EffectStandardVariance)
            ->where('production_run_id', $run->id)->whereIn('source_transaction_id', $receipts->modelKeys())
            ->whereHas('adjustment', fn ($query) => $query->where('company_id', $run->company_id)
                ->where('source_type', InventoryStandardCostSettlement::class)->where('status', 'posted'))
            ->orderBy('id')->lockForUpdate()->get()->groupBy(fn ($line): string => $line->source_snapshot['component']);
        foreach (self::Components as $component) {
            $unitCost = bcadd($unitCost, (string) $standard->{$component.'_unit_cost'}, 8);
            $total = bcmul((string) $standard->{$component.'_unit_cost'}, (string) $run->good_base_quantity, 8);
            $variance = bcsub($actual[$component], $total, 8);
            $components[$component] = ['unit_cost' => (string) $standard->{$component.'_unit_cost'}, 'standard_total' => $total,
                'actual_total' => $actual[$component], 'variance' => $variance,
                'previous_variance' => $previousVariances->get($component, collect())->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->production_cost_delta, 8), '0.00000000'),
                'account_id' => (int) $standard->{$component.'_variance_account_id'}];
        }
        foreach ($receipts as $receipt) {
            $targets[$receipt->id] = bcmul((string) $receipt->quantity_in, $unitCost, 8);
        }
        $plan = $this->completion->planStandard($settlement, $targets, [(int) $run->product_id], $receipts->min('transaction_date')->toDateString());
        $plan['effects'] = array_values(array_filter($plan['effects'], fn ($effect): bool => ! ($effect['effect'] === InventoryValueAdjustmentLine::EffectWip
            && in_array($effect['source_transaction_id'], $receipts->modelKeys(), true))));
        $expenseCapitalization = $this->expenseCapitalization($run, $receipts);
        $plan['effects'] = [...$plan['effects'], ...$expenseCapitalization['effects']];
        foreach ($components as $component => $values) {
            $delta = bcsub($values['variance'], $values['previous_variance'], 8);
            $residue = $delta;
            foreach ($receipts as $index => $receipt) {
                $amount = $index === $receipts->count() - 1 ? $residue
                    : bcdiv(bcmul($delta, (string) $receipt->quantity_in, 16), (string) $run->good_base_quantity, 8);
                $residue = bcsub($residue, $amount, 8);
                if (bccomp($amount, '0', 8) === 0) {
                    continue;
                }
                $plan['effects'][] = ['effect' => InventoryValueAdjustmentLine::EffectStandardVariance, 'component' => $component,
                    'account_id' => $values['account_id'], 'branch_id' => (int) $run->branch_id, 'cost_center_id' => $run->cost_center_id,
                    'source_transaction_id' => (int) $receipt->id, 'source_doc_num' => $receipt->source_doc_num,
                    'production_run_id' => (int) $run->id, 'document_line_id' => $this->receiptLineId($receipt), 'production_cost_role' => 'standard_variance',
                    'production_cost_delta' => $amount, 'amount' => $amount, 'unvalued_quantity_delta' => '0.00000000',
                    'account_labels' => ['ar' => Account::findOrFail($values['account_id'])->codeNameLabel('ar'), 'en' => Account::findOrFail($values['account_id'])->codeNameLabel('en')]];
            }
        }
        $wipSources = $this->wipSources($run, $expenseCapitalization['effects']);
        if (bccomp(array_reduce($wipSources['balances'], fn (string $sum, string $amount): string => bcadd($sum, $amount, 8), '0'), $position['wip'], 8) !== 0) {
            throw new DomainException(__('inventory_standard_cost.errors.costs'));
        }
        foreach ($wipSources['balances'] as $accountId => $amount) {
            if (bccomp($amount, '0', 8) === 0) {
                continue;
            }
            $wip = Account::withTrashed()->forCompany((int) $run->company_id)->findOrFail($accountId);
            $receipt = $receipts->last();
            $plan['effects'][] = ['effect' => InventoryValueAdjustmentLine::EffectWip, 'account_id' => (int) $wip->id,
                'historical_account' => true,
                'branch_id' => (int) $run->branch_id, 'cost_center_id' => $run->cost_center_id,
                'source_transaction_id' => (int) $receipt->id, 'source_doc_num' => $receipt->source_doc_num,
                'production_run_id' => (int) $run->id, 'document_line_id' => $this->receiptLineId($receipt),
                'production_cost_role' => null, 'production_cost_delta' => null,
                'amount' => bcmul($amount, '-1', 8), 'unvalued_quantity_delta' => '0.00000000',
                'account_labels' => ['ar' => $wip->codeNameLabel('ar'), 'en' => $wip->codeNameLabel('en')]];
        }
        if (bccomp(collect($plan['effects'])->reduce(fn (string $sum, array $effect): string => bcadd($sum, $effect['amount'], 8), '0'), '0', 8) !== 0) {
            throw new DomainException(__('inventory_standard_cost.errors.unbalanced'));
        }
        $plan['source_total'] = '0.00000000';
        $plan['components'] = $components;
        $plan['good_quantity'] = (string) $run->good_base_quantity;
        $plan['standard_id'] = (int) $standard->id;
        $plan['standard_doc_num'] = $standard->doc_num;
        $plan['standard_basis_sha256'] = $standard->basis_sha256;
        $plan['actual_position'] = $position;
        $plan['expense_sources'] = $expenseCapitalization['sources'];
        $plan['wip_sources'] = $wipSources;
        $plan['allocation_sources'] = OverheadAllocationRun::query()->where('company_id', $run->company_id)
            ->whereHas('lines', fn ($query) => $query->where('production_run_id', $run->id))
            ->with(['lines' => fn ($query) => $query->where('production_run_id', $run->id)->orderBy('id'), 'journalEntry.lines'])
            ->orderBy('id')->lockForUpdate()->get()->toArray();
        $plan['run_number'] = $run->run_number;
        $this->proposals->assertImpactBranchAccess(request(), $plan);

        return $plan;
    }

    /** @param Collection<int, InventoryTransaction> $receipts @return array{effects: list<array<string, mixed>>, sources: list<array<string, mixed>>} */
    private function expenseCapitalization(ProductionRun $run, Collection $receipts): array
    {
        $expenses = ProductionExpenseRequest::withTrashed()->where('company_id', $run->company_id)->where('production_run_id', $run->id)
            ->with(['currency', 'journalEntry.lines'])->orderBy('id')->lockForUpdate()->get();
        $previous = InventoryValueAdjustmentLine::where('production_run_id', $run->id)->where('production_cost_role', 'expense_capitalized')
            ->whereIn('source_transaction_id', $receipts->modelKeys())
            ->whereHas('adjustment', fn ($query) => $query->where('company_id', $run->company_id)->where('status', 'posted'))
            ->orderBy('id')->lockForUpdate()->get()->groupBy(fn ($line): int => (int) $line->source_snapshot['expense_id']);
        $effects = $sources = [];
        foreach ($expenses as $expense) {
            $journal = $expense->journalEntry;
            $sources[] = ['expense' => $expense->only(['id', 'doc_num', 'status', 'amount', 'currency_id', 'exchange_rate', 'expense_account_id',
                'journal_entry_id', 'reversal_journal_entry_id', 'deleted_at', 'cost_accounting_snapshot']), 'journal' => $journal?->toArray()];
            $sources[array_key_last($sources)]['currency_code'] = $expense->currency?->code ?? '';
            $expenseRate = $expense->exchange_rate === null && $expense->currency?->is_main ? '1.000000' : $expense->exchange_rate;
            $sources[array_key_last($sources)]['effective_exchange_rate'] = $expenseRate;
            $retained = $previous->get($expense->id, collect())->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->amount, 8), '0.00000000');
            $desired = '0.00000000';
            if (! $expense->trashed() && $expense->status === ProductionExpenseRequest::StatusPaid) {
                if ($journal === null || $journal->status !== JournalEntry::StatusPosted || ! $journal->is_posted
                    || (int) $journal->company_id !== (int) $run->company_id || (int) $journal->branch_id !== (int) $run->branch_id
                    || $journal->source_type !== 'production_expense_payment' || (int) $journal->source_id !== (int) $expense->id
                    || $journal->reversed_entry_id !== null || $expense->reversal_journal_entry_id !== null
                    || (int) $journal->currency_id !== (int) $expense->currency_id
                    || $expenseRate === null || bccomp((string) $journal->exchange_rate, (string) $expenseRate, 6) !== 0) {
                    throw new DomainException(__('inventory_standard_cost.errors.costs'));
                }
                $debits = $journal->lines->filter(fn ($line): bool => bccomp((string) $line->debit_amount, '0', 4) > 0);
                $snapshot = $expense->cost_accounting_snapshot;
                $capitalized = $snapshot !== null && ($snapshot['capitalized'] ?? false)
                    && (int) $snapshot['journal_id'] === (int) $journal->id && $debits->count() === 1
                    && (int) $snapshot['debit_account_id'] === (int) $debits->sole()->account_id
                    && bccomp((string) $snapshot['amount'], (string) $expense->amount, 4) === 0
                    && bccomp((string) $snapshot['exchange_rate'], (string) $journal->exchange_rate, 6) === 0;
                if ($debits->count() !== 1 || bccomp((string) $debits->sole()->debit_amount, (string) $expense->amount, 4) !== 0
                    || (! $capitalized && (int) $debits->sole()->account_id !== (int) $expense->expense_account_id)) {
                    throw new DomainException(__('inventory_standard_cost.errors.costs'));
                }
                if (! $capitalized) {
                    $desired = bcmul((string) $expense->amount, (string) $journal->exchange_rate, 8);
                }
            }
            $delta = bcsub($desired, $retained, 8);
            if (bccomp($delta, '0', 8) === 0) {
                continue;
            }
            $expenseAccount = Account::withTrashed()->forCompany((int) $run->company_id)->findOrFail($expense->expense_account_id);
            $residue = $delta;
            foreach ($receipts as $index => $receipt) {
                $amount = $index === $receipts->count() - 1 ? $residue
                    : bcdiv(bcmul($delta, (string) $receipt->quantity_in, 16), (string) $run->good_base_quantity, 8);
                $residue = bcsub($residue, $amount, 8);
                if (bccomp($amount, '0', 8) === 0) {
                    continue;
                }
                $receiptAccountId = InventoryDocumentLine::findOrFail($this->receiptLineId($receipt))->product_snapshot['inventory_accounting']['credit_account_id'];
                $wip = Account::withTrashed()->forCompany((int) $run->company_id)->findOrFail($receiptAccountId);
                $base = ['expense_id' => (int) $expense->id, 'expense_journal_id' => (int) $journal->id,
                    'historical_account' => true,
                    'branch_id' => (int) $run->branch_id, 'cost_center_id' => $run->cost_center_id,
                    'source_transaction_id' => (int) $receipt->id, 'source_doc_num' => $expense->doc_num,
                    'production_run_id' => (int) $run->id, 'document_line_id' => $this->receiptLineId($receipt),
                    'unvalued_quantity_delta' => '0.00000000'];
                $effects[] = [...$base, 'effect' => InventoryValueAdjustmentLine::EffectWip, 'account_id' => (int) $wip->id,
                    'production_cost_role' => 'expense_capitalized', 'production_cost_delta' => $amount, 'amount' => $amount,
                    'account_labels' => ['ar' => $wip->codeNameLabel('ar'), 'en' => $wip->codeNameLabel('en')]];
                $effects[] = [...$base, 'effect' => InventoryValueAdjustmentLine::EffectExpense, 'account_id' => (int) $expenseAccount->id,
                    'production_cost_role' => null, 'production_cost_delta' => null, 'amount' => bcmul($amount, '-1', 8),
                    'account_labels' => ['ar' => $expenseAccount->codeNameLabel('ar'), 'en' => $expenseAccount->codeNameLabel('en')]];
            }
        }

        return ['effects' => $effects, 'sources' => $sources];
    }

    /** @param list<array<string, mixed>> $pending @return array{balances: array<int, string>, accounts: array<int, array{ar: string, en: string}>, documents: list<array<string, mixed>>, journals: list<array<string, mixed>>} */
    private function wipSources(ProductionRun $run, array $pending): array
    {
        $accountIds = app(InventoryAccountingPostingService::class)->historicalWipAccountIds((int) $run->company_id);
        $balances = $documents = $journals = [];
        $add = function (int $accountId, string $amount) use (&$balances, $accountIds): void {
            if (in_array($accountId, $accountIds, true)) {
                $balances[$accountId] = bcadd($balances[$accountId] ?? '0', $amount, 8);
            }
        };
        $activeDocuments = InventoryDocument::where('company_id', $run->company_id)->where('production_run_id', $run->id)
            ->where('status', InventoryDocument::StatusPosted)->with(['lines', 'journalEntry.lines'])->orderBy('id')->lockForUpdate()->get();
        foreach ($activeDocuments as $document) {
            if ($document->journalEntry === null) {
                continue;
            }
            $documents[] = ['document' => $document->only(['id', 'doc_num', 'status']), 'journal' => $document->journalEntry->toArray()];
            foreach ($document->lines as $line) {
                $accounting = $line->product_snapshot['inventory_accounting'] ?? null;
                if ($accounting === null) {
                    throw new DomainException(__('inventory_standard_cost.errors.costs'));
                }
                $add((int) $accounting['debit_account_id'], (string) $line->total_cost);
                $add((int) $accounting['credit_account_id'], bcmul((string) $line->total_cost, '-1', 8));
            }
        }
        $corrections = InventoryValueAdjustmentLine::where('production_run_id', $run->id)->whereIn('account_id', $accountIds)
            ->whereHas('adjustment', fn ($query) => $query->where('company_id', $run->company_id)->where('status', 'posted'))
            ->whereHas('sourceTransaction', fn ($query) => $query->where('source_type', InventoryDocument::class)
                ->whereIn('source_id', $activeDocuments->modelKeys()))->orderBy('id')->lockForUpdate()->get();
        foreach ($corrections as $line) {
            $add((int) $line->account_id, (string) $line->amount);
        }
        foreach (ProductionExpenseRequest::where('company_id', $run->company_id)->where('production_run_id', $run->id)
            ->where('status', ProductionExpenseRequest::StatusPaid)->with('journalEntry.lines')->orderBy('id')->get() as $expense) {
            if ($expense->journalEntry !== null) {
                $journals[] = $expense->journalEntry->toArray();
                foreach ($expense->journalEntry->lines as $line) {
                    $add((int) $line->account_id, bcmul(bcsub((string) $line->debit_amount, (string) $line->credit_amount, 8), (string) $expense->journalEntry->exchange_rate, 8));
                }
            }
        }
        foreach (OverheadAllocationRun::where('company_id', $run->company_id)->where('status', 'posted')
            ->whereHas('lines', fn ($query) => $query->where('production_run_id', $run->id))
            ->with(['lines' => fn ($query) => $query->where('production_run_id', $run->id), 'sources', 'journalEntry.lines'])->orderBy('id')->get() as $allocation) {
            $wipLines = $allocation->journalEntry?->lines->whereIn('account_id', $accountIds)
                ->whereNotIn('account_id', $allocation->sources->pluck('account_id')->all())
                ->filter(fn ($line): bool => bccomp((string) $line->debit_amount, '0', 4) > 0);
            if ($wipLines === null || $wipLines->pluck('account_id')->unique()->count() !== 1) {
                throw new DomainException(__('inventory_standard_cost.errors.costs'));
            }
            $journals[] = $allocation->journalEntry->toArray();
            foreach ($allocation->lines as $share) {
                $add((int) $wipLines->first()->account_id, (string) $share->allocated_amount);
            }
        }
        foreach ($pending as $effect) {
            $add((int) $effect['account_id'], $effect['amount']);
        }
        ksort($balances);
        $accounts = Account::withTrashed()->forCompany((int) $run->company_id)->whereIn('id', array_keys($balances))
            ->get()->mapWithKeys(fn (Account $account): array => [$account->id => ['ar' => $account->codeNameLabel('ar'), 'en' => $account->codeNameLabel('en')]])->all();

        return compact('balances', 'accounts', 'documents', 'journals');
    }

    public function approveSettlement(InventoryStandardCostSettlement $settlement, int $actorId, string $reference): InventoryStandardCostSettlement
    {
        return DB::transaction(function () use ($settlement, $actorId, $reference): InventoryStandardCostSettlement {
            Company::query()->whereKey($settlement->company_id)->lockForUpdate()->firstOrFail();
            $locked = InventoryStandardCostSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            $this->assertDecision($locked, $actorId, $reference);
            $plan = $this->plan($locked, $actorId, 'inventory.cost_policies.standard.approve');
            if ($locked->impact_sha256 !== $this->hash($plan)) {
                throw new DomainException(__('inventory_standard_cost.errors.stale'));
            }
            $locked->forceFill(['approval_reference' => trim($reference)])->save();
            $adjustment = $this->adjustments->postStandardSettlement($locked, $plan, request());
            $this->completion->persistBases($adjustment, $plan);
            $locked->forceFill(['status' => InventoryStandardCostSettlement::StatusFinalized, 'approved_by' => $actorId, 'approved_at' => now()])->save();
            $this->log($locked, 'standard_variance_finalized');

            return $locked->refresh();
        });
    }

    public function reject(InventoryCostStandard|InventoryStandardCostSettlement $record, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($record, $actorId, $reason): void {
            Company::query()->whereKey($record->company_id)->lockForUpdate()->firstOrFail();
            $record = $record::query()->lockForUpdate()->findOrFail($record->id);
            $this->policies->authorizeScopeOperation((int) $record->company_id, (int) $record->branch_id, null, $actorId, 'inventory.cost_policies.standard.approve');
            $this->assertDecision($record, $actorId, $reason);
            $record->forceFill(['status' => 'rejected', 'rejection_reason' => trim($reason), 'approved_by' => $actorId, 'approved_at' => now()])->save();
            $this->log($record, 'standard_cost_rejected');
        });
    }

    private function assertDecision(InventoryCostStandard|InventoryStandardCostSettlement $record, int $actorId, string $reference): void
    {
        if ($record->status !== 'prepared' || (int) $record->prepared_by === $actorId || strlen(trim($reference)) < 5 || strlen($reference) > 500) {
            throw new DomainException(__('inventory_standard_cost.errors.approval'));
        }
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function receiptLineId(InventoryTransaction $receipt): int
    {
        if (! preg_match('/^inventory-document:\d+:line:(\d+):in$/D', $receipt->posting_key, $matches)) {
            throw new DomainException(__('inventory_standard_cost.errors.run'));
        }

        return (int) InventoryDocumentLine::query()->where('inventory_document_id', $receipt->source_id)
            ->where('product_id', $receipt->product_id)->findOrFail($matches[1])->id;
    }

    private function log(InventoryCostStandard|InventoryStandardCostSettlement $record, string $action): void
    {
        $this->activity->log(request(), 'inventory', $action, 'success', ['subject' => $record, 'company_id' => $record->company_id,
            'properties_only' => true, 'properties' => $record->only(['doc_num', 'status', 'basis_sha256', 'impact_sha256', 'approval_reference'])]);
    }
}

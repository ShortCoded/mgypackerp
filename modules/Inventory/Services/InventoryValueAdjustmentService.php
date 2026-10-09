<?php

namespace Modules\Inventory\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryPeriodicCostClose;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Models\InventoryStandardCostSettlement;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Inventory\Models\OpeningStockCostCorrection;
use Modules\Production\Services\ProductionInvoiceCostCorrectionService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Services\PurchaseInvoiceCostAdjustmentService;
use Modules\Purchases\Services\PurchaseReturnCostAdjustmentService;

class InventoryValueAdjustmentService
{
    public function __construct(private readonly JournalEntryService $journals, private readonly FinancialPeriodService $periods) {}

    /** @param array<string, mixed> $plan */
    public function postReceiptCompletion(InventoryReceiptCostProposal $proposal, array $plan, Request $request): InventoryValueAdjustment
    {
        return $this->postPlan($proposal, $plan, $request, false);
    }

    /** @param array<string, mixed> $plan */
    public function postPeriodicClose(InventoryPeriodicCostClose $close, array $plan, Request $request): InventoryValueAdjustment
    {
        return $this->postPlan($close, $plan, $request, true);
    }

    /** @param array<string, mixed> $plan */
    public function postStandardSettlement(InventoryStandardCostSettlement $settlement, array $plan, Request $request): InventoryValueAdjustment
    {
        return $this->postPlan($settlement, $plan, $request, false, true);
    }

    /** @param array<string, mixed> $plan */
    public function postOpeningCorrection(OpeningStockCostCorrection $correction, array $plan, Request $request): InventoryValueAdjustment
    {
        return $this->postPlan($correction, $plan, $request, false, false, true);
    }

    /** @param array<string, mixed> $plan */
    public function postPurchaseVariance(PurchaseInvoice $invoice, array $plan, Request $request, bool $reverse = false): InventoryValueAdjustment
    {
        return $this->postPlan($invoice, $plan, $request, false, purchaseReverse: $reverse);
    }

    /** @param array<string, mixed> $plan */
    public function postPurchaseReturn(PurchaseReturn $return, array $plan, Request $request, bool $reverse = false): InventoryValueAdjustment
    {
        return $this->postPlan($return, $plan, $request, false, purchaseReverse: $reverse);
    }

    private function postPlan(InventoryReceiptCostProposal|InventoryPeriodicCostClose|InventoryStandardCostSettlement|OpeningStockCostCorrection|PurchaseInvoice|PurchaseReturn $proposal,
        array $plan, Request $request, bool $periodic, bool $standard = false, bool $opening = false, bool $purchaseReverse = false): InventoryValueAdjustment
    {
        return DB::transaction(function () use ($proposal, $plan, $request, $periodic, $standard, $opening, $purchaseReverse): InventoryValueAdjustment {
            Company::query()->whereKey($proposal->company_id)->lockForUpdate()->firstOrFail();
            $locked = $proposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $return = $locked instanceof PurchaseReturn;
            $purchase = $locked instanceof PurchaseInvoice || $return;
            $sourceType = $purchaseReverse ? ($return ? PurchaseReturnCostAdjustmentService::ReversalSource : PurchaseInvoiceCostAdjustmentService::ReversalSource) : $locked::class;
            $postingDate = $purchase ? $plan['posting_date'] : $locked->posting_date;
            $postingPeriodId = (int) ($purchase ? $plan['posting_period_id'] : $locked->posting_period_id);
            $counterpartId = $purchase ? $plan['counterpart_account_id'] : $locked->counterpart_account_id;
            $permission = $purchase ? ($return ? ($purchaseReverse ? 'purchases.purchase_returns.reverse' : 'purchases.purchase_returns.post')
                : ($purchaseReverse ? 'purchase_invoices.reverse' : 'purchase_invoices.approve')) : ($opening ? 'inventory.opening_stock_cost_corrections.approve'
                : ($standard ? 'inventory.cost_policies.standard.approve' : ($periodic ? 'inventory.cost_policies.periodic.approve' : 'inventory.documents.approve_receipt_cost')));
            $validSource = $purchase
                ? ($return ? ($locked->status === ($purchaseReverse ? PurchaseReturn::StatusReversed : PurchaseReturn::StatusPosted)
                    && PurchaseReturnCostAdjustmentService::snapshot($locked) === $plan['return_snapshot'])
                    : (in_array($locked->status, $purchaseReverse ? [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed] : [PurchaseInvoice::StatusDraft], true)
                        && PurchaseInvoiceCostAdjustmentService::snapshot($locked) === $plan['invoice_snapshot']))
                : ($locked->status === ($periodic || $standard ? InventoryPeriodicCostClose::StatusPrepared : InventoryReceiptCostProposal::StatusPending)
                    && (int) $locked->prepared_by !== (int) $request->user()?->id
                    && ($opening ? $locked->plan === $plan : $locked->impact_sha256 === hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR))));
            if (! $request->user()?->can($permission)
                || ! $validSource) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
            }
            if ($locked instanceof PurchaseInvoice) {
                if (($plan['stage_cost_graph']['runs'] ?? []) !== []) {
                    app(ProductionInvoiceCostCorrectionService::class)->assertPostingAuthorization($locked, $plan, $purchaseReverse);
                } else {
                    $costs = app(PurchaseInvoiceCostAdjustmentService::class);
                    $fresh = $purchaseReverse ? (app(ProductionInvoiceCostCorrectionService::class)->authorizedPlan($locked, reverse: true)
                        ?? $costs->planStageReversal($locked, $plan['posting_date'], (int) $plan['posting_period_id'], stageCorrection: false)) : $costs->plan($locked);
                    if ($fresh !== $plan) {
                        throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
                    }
                }
            }
            $existing = InventoryValueAdjustment::query()->where('company_id', $locked->company_id)->where('source_type', $sourceType)
                ->where('source_id', $locked->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->source_snapshot !== $plan) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
                }

                return $existing;
            }
            $this->periods->resolveOpenForPostingDate((int) $locked->company_id, $postingDate,
                expectedPeriodId: $postingPeriodId, lockForUpdate: true);
            $counterpart = Account::query()->forCompany((int) $locked->company_id)->eligibleForDirectPosting()
                ->whereKey($counterpartId)->lockForUpdate()->firstOrFail();
            if (! in_array($counterpart->account_type, [Account::TypeLiability, Account::TypeEquity, Account::TypeRevenue], true)
                || ! in_array($counterpart->classification?->code, $purchase ? [PostingAccountResolver::GoodsReceivedNotInvoiced] : [PostingAccountResolver::InventoryAdjustmentGain, PostingAccountResolver::InventoryCostCompletionClearing], true)
                || $counterpart->classification?->status !== 'active') {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_counterpart_invalid'));
            }
            if ($periodic || $opening) {
                if ($periodic) {
                    app(InventoryPeriodicCostCloseService::class)->capture($locked, (int) $request->user()->id, 'inventory.cost_policies.periodic.approve');
                }
                if ($counterpart->classification?->code !== PostingAccountResolver::InventoryCostCompletionClearing
                    || $counterpart->account_type !== Account::TypeLiability) {
                    throw new DomainException(__('inventory.movements.messages.receipt_completion_counterpart_invalid'));
                }
            }
            if ($standard && hash('sha256', json_encode(app(InventoryStandardCostService::class)->plan($locked, (int) $request->user()->id,
                'inventory.cost_policies.standard.approve'), JSON_THROW_ON_ERROR)) !== $locked->impact_sha256) {
                throw new DomainException(__('inventory_standard_cost.errors.stale'));
            }
            $adjustment = InventoryValueAdjustment::query()->create([
                'company_id' => $locked->company_id, 'branch_id' => $locked->branch_id,
                'financial_period_id' => $postingPeriodId, 'posting_date' => $postingDate,
                'source_type' => $sourceType, 'source_id' => $locked->id,
                'source_doc_num' => $purchase ? $locked->doc_num : ($opening ? $locked->openingStock->doc_num.' / '.substr($locked->public_uuid, 0, 8)
                    : ($periodic || $standard ? $locked->doc_num : $locked->document->doc_num.' / '.$locked->revision)),
                'status' => InventoryValueAdjustment::StatusPosted, 'source_snapshot' => $plan,
                'approved_by' => $request->user()->id, 'approved_at' => now(),
            ]);
            $grouped = [];
            $branchSums = [];
            $sum = '0.00000000';
            foreach ($plan['effects'] as $index => $effect) {
                $accountQuery = Account::query()->forCompany((int) $locked->company_id);
                if ($standard && ($effect['historical_account'] ?? false)) {
                    $accountQuery->withTrashed();
                } else {
                    $accountQuery->eligibleForDirectPosting();
                }
                $account = $accountQuery->whereKey($effect['account_id'])->firstOrFail();
                $amount = (string) $effect['amount'];
                if (! preg_match('/^-?\d{1,12}(?:\.\d{1,8})?$/D', $amount)) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_precision'));
                }
                $transaction = null;
                if ($effect['effect'] === InventoryValueAdjustmentLine::EffectStock) {
                    $source = InventoryTransaction::query()->where('company_id', $locked->company_id)
                        ->lockForUpdate()->findOrFail($effect['source_transaction_id']);
                    $transaction = InventoryTransaction::query()->create([
                        ...$source->only(['company_id', 'branch_id', 'branch_store_id', 'branch_hall_id', 'warehouse_location_id',
                            'production_run_id', 'production_run_batch_id', 'product_id', 'unit_id', 'stock_status', 'batch_lot',
                            'manufacture_date', 'expiry_date']),
                        'financial_period_id' => $postingPeriodId, 'transaction_date' => $postingDate,
                        'posting_key' => 'inventory-value-adjustment:'.$adjustment->id.':'.$index,
                        'transaction_type' => InventoryTransaction::TypeValueAdjustment,
                        'quantity_in' => '0', 'quantity_out' => '0', 'unit_cost' => '0', 'total_cost' => '0',
                        'value_delta' => $amount, 'unvalued_quantity_delta' => $effect['unvalued_quantity_delta'],
                        'source_type' => InventoryValueAdjustment::class, 'source_id' => $adjustment->id,
                        'source_doc_num' => $adjustment->source_doc_num, 'created_by' => $request->user()->id,
                        'cost_method' => $source->cost_method, 'cost_policy_id' => $source->cost_policy_id,
                        'cost_basis' => $purchase ? ($return ? ($purchaseReverse ? 'purchase_return_cost_reversal' : 'purchase_return_cost_adjustment')
                            : ($purchaseReverse ? 'purchase_invoice_cost_reversal' : 'purchase_invoice_cost_adjustment')) : ($opening ? 'approved_opening_stock_cost_correction'
                            : ($standard ? 'approved_standard_cost_settlement' : ($periodic ? 'approved_periodic_cost_finalization' : 'approved_receipt_cost_completion'))),
                        'is_reversal' => false,
                    ]);
                }
                $adjustment->lines()->create([
                    'account_id' => $account->id, 'branch_id' => $effect['branch_id'], 'cost_center_id' => $effect['cost_center_id'],
                    'source_transaction_id' => $effect['source_transaction_id'], 'inventory_transaction_id' => $transaction?->id,
                    'production_run_id' => $effect['production_run_id'], 'inventory_document_line_id' => $effect['document_line_id'],
                    'production_cost_role' => $effect['production_cost_role'], 'production_cost_delta' => $effect['production_cost_delta'],
                    'effect' => $effect['effect'], 'precision_key' => $effect['precision_key'] ?? null, 'amount' => $amount, 'unvalued_quantity_delta' => $effect['unvalued_quantity_delta'],
                    'source_snapshot' => $effect,
                ]);
                $key = implode(':', [$account->id, $effect['branch_id'], $effect['cost_center_id'] ?? 'none']);
                $grouped[$key] ??= ['account_id' => $account->id, 'branch_id' => $effect['branch_id'],
                    'cost_center_id' => $effect['cost_center_id'], 'amount' => '0.00000000'];
                $grouped[$key]['amount'] = bcadd($grouped[$key]['amount'], $amount, 8);
                $sum = bcadd($sum, $amount, 8);
                $branchSums[$effect['branch_id']] = bcadd($branchSums[$effect['branch_id']] ?? '0', $amount, 8);
            }
            if (bccomp($sum, (string) $plan['source_total'], 8) !== 0) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_unbalanced'));
            }
            $journalLines = [];
            $roundedTotals = [];
            $description = __($purchase ? ($return ? 'purchase_invoices.sections.return_cost_adjustment' : 'purchase_invoices.sections.cost_adjustment') : ($opening ? 'opening_stock_cost_correction.title' : ($standard ? 'inventory_standard_cost.settlements'
                : ($periodic ? 'inventory_periodic_cost.title' : 'inventory.movements.receipt_completion_title'))));
            $purchaseRounds = $purchase ? $this->purchaseRoundedGroups($grouped, $plan, $adjustment) : [];
            foreach ($grouped as $key => $group) {
                $rounded = $purchase ? $purchaseRounds[$key] : $this->roundGl($group['amount']);
                if (bccomp($rounded, '0', 4) === 0) {
                    continue;
                }
                $roundedTotals[$group['branch_id']] = bcadd($roundedTotals[$group['branch_id']] ?? '0', $rounded, 4);
                $journalLines[] = [...collect($group)->except('amount')->all(),
                    'description' => $description.' '.$adjustment->source_doc_num,
                    'debit_amount' => bccomp($rounded, '0', 4) > 0 ? $rounded : '0.0000',
                    'credit_amount' => bccomp($rounded, '0', 4) < 0 ? bcmul($rounded, '-1', 4) : '0.0000'];
            }
            if ($purchase) {
                $branchSums = [];
                foreach ($plan['root_deltas'] as $rootId => $delta) {
                    $branchId = $plan['root_branches'][$rootId];
                    $branchSums[$branchId] = bcadd($branchSums[$branchId] ?? '0', $delta, 8);
                }
                $roundedTotals = $plan['invoice_variance_by_branch'];
                foreach ($grouped as $key => $group) {
                    if ((int) $group['account_id'] === (int) $counterpart->id) {
                        $roundedTotals[$group['branch_id']] = bcadd($roundedTotals[$group['branch_id']] ?? '0', $purchaseRounds[$key], 4);
                    }
                }
            }
            foreach ($branchSums as $branchId => $branchSum) {
                $roundedTotal = $roundedTotals[$branchId] ?? '0.0000';
                $adjustment->lines()->create(['account_id' => $counterpart->id, 'branch_id' => $branchId,
                    'effect' => InventoryValueAdjustmentLine::EffectCounterpart, 'amount' => bcmul($branchSum, '-1', 8),
                    'source_snapshot' => ['source_total' => $branchSum, 'rounded_gl_total' => $roundedTotal,
                        'gl_rounding_difference' => bcsub($roundedTotal, $branchSum, 8)]]);
                $counterpartPrecision = bcsub($branchSum, $roundedTotal, 8);
                if ($purchase && bccomp($counterpartPrecision, '0', 8) !== 0) {
                    $adjustment->lines()->create([
                        'account_id' => $counterpart->id, 'branch_id' => $branchId,
                        'effect' => InventoryValueAdjustmentLine::EffectGlPrecision,
                        'precision_key' => 'purchase-invoice:'.$adjustment->id.':counterpart:'.$branchId,
                        'amount' => $counterpartPrecision,
                        'source_snapshot' => ['source_total' => $branchSum, 'rounded_gl_total' => $roundedTotal],
                    ]);
                }
                if (bccomp($roundedTotal, '0', 4) === 0) {
                    continue;
                }
                $journalLines[] = ['account_id' => $counterpart->id, 'branch_id' => $branchId,
                    'description' => $locked->approval_reference ?? $description,
                    'debit_amount' => bccomp($roundedTotal, '0', 4) < 0 ? bcmul($roundedTotal, '-1', 4) : '0.0000',
                    'credit_amount' => bccomp($roundedTotal, '0', 4) > 0 ? $roundedTotal : '0.0000'];
            }
            if ($journalLines === []) {
                return $adjustment->refresh();
            }
            $journal = $this->journals->createPostedFromSource([
                'company_id' => $locked->company_id, 'branch_id' => count(array_unique([...array_keys($branchSums), ...array_column($grouped, 'branch_id')])) === 1 ? array_key_first($branchSums) : null,
                'financial_period_id' => $postingPeriodId, 'entry_date' => $postingDate,
                'currency_id' => Currency::query()->forCompany((int) $locked->company_id)->where('is_main', true)->sole()->id,
                'exchange_rate' => '1', 'source_type' => InventoryValueAdjustment::class, 'source_id' => $adjustment->id,
                'source_doc_num' => $adjustment->source_doc_num, 'description' => $description,
            ], $journalLines);
            $adjustment->forceFill(['journal_entry_id' => $journal->id])->save();

            return $adjustment->refresh();
        });
    }

    /** @param array<string, array<string, mixed>> $groups @param array<string, mixed> $plan @return array<string, string> */
    private function purchaseRoundedGroups(array $groups, array $plan, InventoryValueAdjustment $adjustment): array
    {
        $rounded = [];
        $total = '0.0000';
        $remainders = [];
        foreach ($groups as $key => $group) {
            $rounded[$key] = $this->roundGl($group['amount']);
            if ((int) $group['account_id'] !== (int) $plan['counterpart_account_id']) {
                $total = bcadd($total, $rounded[$key], 4);
                $remainders[$key] = bcsub($group['amount'], $rounded[$key], 8);
            }
        }
        $expected = array_reduce($plan['invoice_variance_by_branch'], fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
        $difference = bcsub($expected, $total, 4);
        $iterations = 0;
        while (bccomp($difference, '0', 4) !== 0) {
            if ($remainders === [] || ++$iterations > count($groups) + 1) {
                throw new DomainException(__('purchase_invoices.messages.cost_lineage_unbalanced'));
            }
            $positive = bccomp($difference, '0', 4) > 0;
            uksort($remainders, fn (string $left, string $right): int => ($positive
                ? bccomp($remainders[$right], $remainders[$left], 8)
                : bccomp($remainders[$left], $remainders[$right], 8)) ?: strcmp($left, $right));
            $key = array_key_first($remainders);
            $step = $positive ? '0.0001' : '-0.0001';
            $rounded[$key] = bcadd($rounded[$key], $step, 4);
            $remainders[$key] = bcsub($remainders[$key], $step, 8);
            $difference = bcsub($difference, $step, 4);
        }
        foreach ($groups as $key => $group) {
            $amount = bcsub($rounded[$key], $group['amount'], 8);
            if (bccomp($amount, '0', 8) === 0) {
                continue;
            }
            $adjustment->lines()->create([
                'account_id' => $groups[$key]['account_id'], 'branch_id' => $groups[$key]['branch_id'],
                'cost_center_id' => $groups[$key]['cost_center_id'], 'effect' => InventoryValueAdjustmentLine::EffectGlPrecision,
                'precision_key' => 'purchase-invoice:'.$adjustment->id.':'.$key, 'amount' => $amount,
                'source_snapshot' => ['group_amount' => $groups[$key]['amount'], 'rounded_gl_total' => $rounded[$key]],
            ]);
        }

        return $rounded;
    }

    private function roundGl(string $amount): string
    {
        return bccomp($amount, '0', 8) < 0
            ? bcmul(bcadd(bcmul($amount, '-1', 8), '0.00005', 4), '-1', 4)
            : bcadd($amount, '0.00005', 4);
    }
}

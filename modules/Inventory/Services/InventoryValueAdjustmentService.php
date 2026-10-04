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

    private function postPlan(InventoryReceiptCostProposal|InventoryPeriodicCostClose|InventoryStandardCostSettlement|OpeningStockCostCorrection $proposal,
        array $plan, Request $request, bool $periodic, bool $standard = false, bool $opening = false): InventoryValueAdjustment
    {
        return DB::transaction(function () use ($proposal, $plan, $request, $periodic, $standard, $opening): InventoryValueAdjustment {
            Company::query()->whereKey($proposal->company_id)->lockForUpdate()->firstOrFail();
            $locked = $proposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $permission = $opening ? 'inventory.opening_stock_cost_corrections.approve'
                : ($standard ? 'inventory.cost_policies.standard.approve' : ($periodic ? 'inventory.cost_policies.periodic.approve' : 'inventory.documents.approve_receipt_cost'));
            if (! $request->user()?->can($permission)
                || $locked->status !== ($periodic || $standard ? InventoryPeriodicCostClose::StatusPrepared : InventoryReceiptCostProposal::StatusPending)
                || (int) $locked->prepared_by === (int) $request->user()->id
                || ($opening ? $locked->plan !== $plan : $locked->impact_sha256 !== hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)))) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
            }
            $existing = InventoryValueAdjustment::query()->where('source_type', $locked::class)
                ->where('source_id', $locked->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->source_snapshot !== $plan) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
                }

                return $existing;
            }
            $this->periods->resolveOpenForPostingDate((int) $locked->company_id, $locked->posting_date,
                expectedPeriodId: (int) $locked->posting_period_id, lockForUpdate: true);
            $counterpart = Account::query()->forCompany((int) $locked->company_id)->eligibleForDirectPosting()
                ->whereKey($locked->counterpart_account_id)->lockForUpdate()->firstOrFail();
            if (! in_array($counterpart->account_type, [Account::TypeLiability, Account::TypeEquity, Account::TypeRevenue], true)
                || ! in_array($counterpart->classification?->code, [PostingAccountResolver::InventoryAdjustmentGain, PostingAccountResolver::InventoryCostCompletionClearing], true)
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
                'financial_period_id' => $locked->posting_period_id, 'posting_date' => $locked->posting_date,
                'source_type' => $locked::class, 'source_id' => $locked->id,
                'source_doc_num' => $opening ? $locked->openingStock->doc_num.' / '.substr($locked->public_uuid, 0, 8)
                    : ($periodic || $standard ? $locked->doc_num : $locked->document->doc_num.' / '.$locked->revision),
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
                        'financial_period_id' => $locked->posting_period_id, 'transaction_date' => $locked->posting_date,
                        'posting_key' => 'inventory-value-adjustment:'.$adjustment->id.':'.$index,
                        'transaction_type' => InventoryTransaction::TypeValueAdjustment,
                        'quantity_in' => '0', 'quantity_out' => '0', 'unit_cost' => '0', 'total_cost' => '0',
                        'value_delta' => $amount, 'unvalued_quantity_delta' => $effect['unvalued_quantity_delta'],
                        'source_type' => InventoryValueAdjustment::class, 'source_id' => $adjustment->id,
                        'source_doc_num' => $adjustment->source_doc_num, 'created_by' => $request->user()->id,
                        'cost_method' => $source->cost_method, 'cost_policy_id' => $source->cost_policy_id,
                        'cost_basis' => $opening ? 'approved_opening_stock_cost_correction'
                            : ($standard ? 'approved_standard_cost_settlement' : ($periodic ? 'approved_periodic_cost_finalization' : 'approved_receipt_cost_completion')),
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
            $description = __($opening ? 'opening_stock_cost_correction.title' : ($standard ? 'inventory_standard_cost.settlements'
                : ($periodic ? 'inventory_periodic_cost.title' : 'inventory.movements.receipt_completion_title')));
            foreach ($grouped as $group) {
                $rounded = $this->roundGl($group['amount']);
                if (bccomp($rounded, '0', 4) === 0) {
                    continue;
                }
                $roundedTotals[$group['branch_id']] = bcadd($roundedTotals[$group['branch_id']] ?? '0', $rounded, 4);
                $journalLines[] = [...collect($group)->except('amount')->all(),
                    'description' => $description.' '.$adjustment->source_doc_num,
                    'debit_amount' => bccomp($rounded, '0', 4) > 0 ? $rounded : '0.0000',
                    'credit_amount' => bccomp($rounded, '0', 4) < 0 ? bcmul($rounded, '-1', 4) : '0.0000'];
            }
            foreach ($branchSums as $branchId => $branchSum) {
                $roundedTotal = $roundedTotals[$branchId] ?? '0.0000';
                $adjustment->lines()->create(['account_id' => $counterpart->id, 'branch_id' => $branchId,
                    'effect' => InventoryValueAdjustmentLine::EffectCounterpart, 'amount' => bcmul($branchSum, '-1', 8),
                    'source_snapshot' => ['source_total' => $branchSum, 'rounded_gl_total' => $roundedTotal,
                        'gl_rounding_difference' => bcsub($roundedTotal, $branchSum, 8)]]);
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
                'company_id' => $locked->company_id, 'branch_id' => count($branchSums) === 1 ? array_key_first($branchSums) : null,
                'financial_period_id' => $locked->posting_period_id, 'entry_date' => $locked->posting_date,
                'currency_id' => Currency::query()->forCompany((int) $locked->company_id)->where('is_main', true)->sole()->id,
                'exchange_rate' => '1', 'source_type' => InventoryValueAdjustment::class, 'source_id' => $adjustment->id,
                'source_doc_num' => $adjustment->source_doc_num, 'description' => $description,
            ], $journalLines);
            $adjustment->forceFill(['journal_entry_id' => $journal->id])->save();

            return $adjustment->refresh();
        });
    }

    private function roundGl(string $amount): string
    {
        return bccomp($amount, '0', 8) < 0
            ? bcmul(bcadd(bcmul($amount, '-1', 8), '0.00005', 4), '-1', 4)
            : bcadd($amount, '0.00005', 4);
    }
}

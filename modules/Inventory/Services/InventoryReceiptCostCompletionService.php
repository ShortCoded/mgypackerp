<?php

namespace Modules\Inventory\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Support\Collection;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\Branch;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryAllocationCostCompletion;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryCostPolicyTransition;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryPeriodicCostClose;
use Modules\Inventory\Models\InventoryReceiptCostBasis;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryStandardCostSettlement;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Inventory\Models\OpeningStock;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionStageTransferService;

class InventoryReceiptCostCompletionService
{
    public function __construct(private readonly PostingAccountResolver $accounts, private readonly FinancialPeriodService $periods) {}

    /** @param array<int, string> $unitCosts @return array<string, mixed> */
    public function plan(InventoryDocument $document, array $unitCosts, string $postingDate, int $postingPeriodId, int $counterpartAccountId): array
    {
        $this->periods->resolveOpenForPostingDate((int) $document->company_id, $postingDate,
            expectedPeriodId: $postingPeriodId, lockForUpdate: true);
        $counterpart = Account::query()->forCompany((int) $document->company_id)->eligibleForDirectPosting()
            ->whereKey($counterpartAccountId)->lockForUpdate()->firstOrFail();
        if (! in_array($counterpart->account_type, [Account::TypeLiability, Account::TypeEquity, Account::TypeRevenue], true)
            || ! in_array($counterpart->classification?->code, [PostingAccountResolver::InventoryAdjustmentGain, PostingAccountResolver::InventoryCostCompletionClearing], true)) {
            throw new DomainException(__('inventory.movements.messages.receipt_completion_counterpart_invalid'));
        }
        $sourcePeriod = FinancialPeriod::query()->findOrFail($document->financial_period_id);
        $sourceLines = $document->lines()->with('product')->orderBy('id')->lockForUpdate()->get();
        $roots = [];
        $sourceTotal = '0.00000000';
        foreach ($sourceLines as $line) {
            $root = InventoryTransaction::query()->where('company_id', $document->company_id)
                ->where('source_type', InventoryDocument::class)->where('source_id', $document->id)
                ->where('posting_key', "inventory-document:{$document->id}:line:{$line->id}:in")->lockForUpdate()->sole();
            if ($root->unit_cost !== null || $root->total_cost !== null || $root->is_reversal
                || bccomp((string) $root->quantity_in, (string) $line->quantity, 8) !== 0
                || ! isset($unitCosts[$line->id]) || bccomp((string) $unitCosts[$line->id], '0', 8) <= 0) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
            }
            $roots[$root->id] = bcmul((string) $root->quantity_in, (string) $unitCosts[$line->id], 8);
            $sourceTotal = bcadd($sourceTotal, $roots[$root->id], 8);
        }

        return $this->planTargets($document, $roots, $sourceLines->pluck('product_id')->unique()->all(),
            $document->document_date->toDateString(), $postingDate, $postingPeriodId, $counterpart);
    }

    /** @param array<string, mixed> $periodic @return array<string, mixed> */
    public function planPeriodic(InventoryPeriodicCostClose $close, array $periodic): array
    {
        $this->periods->resolveOpenForPostingDate((int) $close->company_id, $close->posting_date,
            expectedPeriodId: (int) $close->posting_period_id, lockForUpdate: true);
        $counterpart = Account::query()->forCompany((int) $close->company_id)->eligibleForDirectPosting()
            ->whereKey($close->counterpart_account_id)->lockForUpdate()->firstOrFail();
        if ($counterpart->classification?->code !== PostingAccountResolver::InventoryCostCompletionClearing
            || $counterpart->account_type !== Account::TypeLiability) {
            throw new DomainException(__('inventory.movements.messages.receipt_completion_counterpart_invalid'));
        }
        $productIds = InventoryTransaction::query()->where('company_id', $close->company_id)
            ->whereIn('branch_store_id', $periodic['store_ids'])->whereDate('transaction_date', '<=', $close->to_date)
            ->distinct()->pluck('product_id')->all();

        return $this->planTargets($close, [], $productIds, $close->from_date->toDateString(),
            $close->posting_date->toDateString(), (int) $close->posting_period_id, $counterpart, $periodic);
    }

    /** @param array<int, string> $roots @param array<int, int> $productIds @param array<string, mixed>|null $periodic @return array<string, mixed> */
    public function planStandard(InventoryStandardCostSettlement $settlement, array $roots, array $productIds, string $sourceDate): array
    {
        $this->periods->resolveOpenForPostingDate((int) $settlement->company_id, $settlement->posting_date,
            expectedPeriodId: (int) $settlement->posting_period_id, lockForUpdate: true);
        $counterpart = Account::query()->forCompany((int) $settlement->company_id)->eligibleForDirectPosting()
            ->whereKey($settlement->counterpart_account_id)->lockForUpdate()->firstOrFail();
        if ($counterpart->classification?->code !== PostingAccountResolver::InventoryCostCompletionClearing
            || $counterpart->account_type !== Account::TypeLiability) {
            throw new DomainException(__('inventory.movements.messages.receipt_completion_counterpart_invalid'));
        }

        return $this->planTargets($settlement, $roots, $productIds, $sourceDate,
            $settlement->posting_date->toDateString(), (int) $settlement->posting_period_id, $counterpart);
    }

    /** @param array<int, string> $unitCosts @return array<string, mixed> */
    public function planOpening(OpeningStock $openingStock, array $unitCosts, string $postingDate, int $postingPeriodId, int $counterpartAccountId): array
    {
        $this->periods->resolveOpenForPostingDate((int) $openingStock->company_id, $postingDate,
            expectedPeriodId: $postingPeriodId, lockForUpdate: true);
        $counterpart = Account::query()->forCompany((int) $openingStock->company_id)->eligibleForDirectPosting()
            ->whereKey($counterpartAccountId)->lockForUpdate()->firstOrFail();
        if ($counterpart->account_type !== Account::TypeLiability
            || $counterpart->classification?->code !== PostingAccountResolver::InventoryCostCompletionClearing
            || $counterpart->classification?->status !== 'active') {
            throw new DomainException(__('inventory.movements.messages.receipt_completion_counterpart_invalid'));
        }
        $lines = $openingStock->lines()->with('product')->orderBy('id')->lockForUpdate()->get();
        if ($lines->isEmpty() || count($unitCosts) !== $lines->count()
            || array_diff($lines->modelKeys(), array_map('intval', array_keys($unitCosts))) !== []) {
            throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
        }
        $roots = [];
        foreach ($lines as $line) {
            $unitCost = (string) ($unitCosts[$line->id] ?? '');
            $root = InventoryTransaction::query()->where('company_id', $openingStock->company_id)
                ->where('source_type', OpeningStock::class)->where('source_id', $openingStock->id)
                ->where('posting_key', "opening-stock:{$openingStock->id}:line:{$line->id}")
                ->lockForUpdate()->sole();
            if ($root->is_reversal
                || (int) $root->financial_period_id !== (int) $openingStock->financial_period_id
                || (int) $root->branch_id !== (int) $openingStock->branch_id
                || (int) $root->branch_store_id !== (int) $openingStock->branch_store_id
                || (int) $root->product_id !== (int) $line->product_id
                || (int) $root->source_line_id !== (int) $line->id
                || $root->source_line_type !== $line::class
                || $root->transaction_date->toDateString() !== $openingStock->document_date->toDateString()
                || bccomp((string) $root->quantity_in, (string) $line->quantity, 8) !== 0
                || bccomp((string) $root->quantity_out, '0', 8) !== 0
                || ! preg_match('/^\d{1,11}(?:\.\d{1,8})?$/D', $unitCost)
                || bccomp($unitCost, '0', 8) <= 0) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
            }
            $roots[$root->id] = bcmul((string) $root->quantity_in, $unitCost, 8);
        }

        return $this->planTargets($openingStock, $roots, $lines->pluck('product_id')->unique()->all(),
            $openingStock->document_date->toDateString(), $postingDate, $postingPeriodId, $counterpart);
    }

    private function planTargets(InventoryDocument|InventoryPeriodicCostClose|InventoryStandardCostSettlement|OpeningStock $document, array $roots, array $productIds,
        string $sourceDate, string $postingDate, int $postingPeriodId, Account $counterpart, ?array $periodic = null): array
    {
        $sourcePeriod = FinancialPeriod::query()->findOrFail($document->financial_period_id);

        do {
            $previous = count($productIds);
            $runIds = InventoryTransaction::query()->where('company_id', $document->company_id)->whereIn('product_id', $productIds)
                ->whereDate('transaction_date', '>=', $sourceDate)->whereNotNull('production_run_id')
                ->distinct()->pluck('production_run_id');
            $outputs = InventoryTransaction::query()->where('company_id', $document->company_id)->whereIn('production_run_id', $runIds)
                ->where('transaction_type', InventoryDocument::TypeProductionReceipt)->distinct()->pluck('product_id');
            $productIds = array_values(array_unique([...$productIds, ...$outputs->all()]));
        } while (count($productIds) !== $previous);
        sort($productIds);
        $transactions = InventoryTransaction::query()->where('company_id', $document->company_id)->whereIn('product_id', $productIds)
            ->where('transaction_type', '!=', InventoryTransaction::TypeValueAdjustment)
            ->with('product')->orderBy('transaction_date')->orderBy('id')->lockForUpdate()->get();
        $scopeStoreIds = $periodic['store_ids'] ?? ($document instanceof InventoryStandardCostSettlement
            ? $transactions->whereIn('id', array_keys($roots))->pluck('branch_store_id')->unique()->all() : null);
        if ($scopeStoreIds !== null) {
            $transactions = $this->causalPeriodicTransactions($transactions, $scopeStoreIds);
        }
        if ($transactions->contains(fn (InventoryTransaction $transaction): bool => $transaction->transaction_date->toDateString() > $postingDate)) {
            throw new DomainException(__('inventory.movements.messages.receipt_completion_date_before_movements'));
        }
        $layers = InventoryReceiptLayer::query()->where('company_id', $document->company_id)->whereIn('product_id', $productIds)
            ->when($scopeStoreIds !== null, fn ($query) => $query->whereIn('receipt_transaction_id', $transactions->modelKeys()))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $allocations = InventoryLayerAllocation::query()->whereIn('issue_transaction_id', $transactions->modelKeys())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $transitions = InventoryCostPolicyTransition::query()->where('company_id', $document->company_id)
            ->where('status', InventoryCostPolicyTransition::StatusActivated)->whereDate('effective_from', '<=', $postingDate)
            ->whereHas('bases', fn ($query) => $query->whereIn('product_id', $productIds)
                ->when($scopeStoreIds !== null, fn ($query) => $query->whereIn('inventory_receipt_layer_id', $layers->modelKeys())))
            ->with(['bases' => fn ($query) => $query->whereIn('product_id', $productIds)
                ->when($scopeStoreIds !== null, fn ($query) => $query->whereIn('inventory_receipt_layer_id', $layers->modelKeys()))
                ->orderBy('id')->lockForUpdate()])
            ->orderBy('effective_from')->orderBy('id')->lockForUpdate()->get();
        $documentLines = InventoryDocumentLine::query()->whereIn('inventory_document_id', $transactions
            ->where('source_type', InventoryDocument::class)->pluck('source_id')->unique())
            ->with('document')->orderBy('id')->lockForUpdate()->get()->keyBy(fn (InventoryDocumentLine $line): string => $line->inventory_document_id.':'.$line->id);
        $runs = ProductionRun::query()->where('company_id', $document->company_id)->whereIn('id', $transactions->pluck('production_run_id')->filter()->unique())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($runs as $run) {
            app(ProductionStageTransferService::class)->assertCostMutationAllowed($run);
        }
        if (InventoryValueAdjustment::query()->where('company_id', $document->company_id)
            ->where('status', InventoryValueAdjustment::StatusPosted)->whereDate('posting_date', '>', $postingDate)
            ->whereHas('lines', fn ($query) => $query->whereIn('source_transaction_id', $transactions->modelKeys()))->exists()) {
            throw new DomainException(__('inventory.movements.messages.receipt_completion_date_before_movements'));
        }
        $currentCorrections = InventoryValueAdjustmentLine::query()->where('effect', InventoryValueAdjustmentLine::EffectStock)
            ->whereIn('source_transaction_id', $transactions->modelKeys())
            ->whereHas('adjustment', fn ($query) => $query->where('status', InventoryValueAdjustment::StatusPosted))
            ->get()->groupBy('source_transaction_id');
        $originalCosts = $transactions->mapWithKeys(function (InventoryTransaction $transaction) use ($currentCorrections): array {
            $corrections = $currentCorrections->get($transaction->id, collect());
            $delta = $corrections->reduce(fn (string $sum, InventoryValueAdjustmentLine $line): string => bcadd($sum, (string) $line->amount, 8), '0');
            $sign = bccomp((string) $transaction->quantity_in, '0', 8) > 0 ? '1' : '-1';
            $covered = $corrections->contains(fn (InventoryValueAdjustmentLine $line): bool => (bool) ($line->source_snapshot['cost_completed'] ?? false));

            return [$transaction->id => $transaction->total_cost === null && ! $covered ? null : bcadd((string) ($transaction->total_cost ?? '0'), bcmul($delta, $sign, 8), 8)];
        });
        $sourceTotal = $periodic === null && ! $document instanceof InventoryStandardCostSettlement ? collect($roots)->reduce(fn (string $sum, string $target, int $id): string => bcadd($sum,
            bcsub($target, (string) ($originalCosts[$id] ?? '0'), 8), 8), '0.00000000') : '0.00000000';
        $productionDeltas = [];
        $fingerprint = null;
        $maximumPasses = $periodic === null ? $runs->count() + 1 : max(128, $transactions->count() * 8);
        $periodInputs = [];
        for ($pass = 0; $pass <= $maximumPasses; $pass++) {
            if ($periodic !== null) {
                $calculation = app(InventoryPeriodicCostCalculator::class)->calculate($transactions,
                    $pass === 0 ? $originalCosts : collect($replay['costs']), $periodic, $originalCosts);
                $roots = $calculation['targets'];
                $periodInputs = $calculation['inputs'];
            }
            $replay = $this->replay($transactions, $layers, $allocations, $originalCosts, $roots, $productionDeltas, $transitions);
            $nextProductionDeltas = [];
            foreach ($transactions as $transaction) {
                if (! $transaction->production_run_id || $transaction->is_reversal) {
                    continue;
                }
                $role = $this->productionRole($transaction);
                if (! in_array($role, ['issued', 'returned', 'waste'], true) || ! isset($replay['costs'][$transaction->id])) {
                    continue;
                }
                $delta = bcsub($replay['costs'][$transaction->id], (string) ($originalCosts[$transaction->id] ?? '0'), 8);
                $nextProductionDeltas[$transaction->production_run_id] = bcadd($nextProductionDeltas[$transaction->production_run_id] ?? '0',
                    $role === 'issued' ? $delta : bcmul($delta, '-1', 8), 8);
            }
            ksort($nextProductionDeltas);
            $hash = hash('sha256', json_encode([$nextProductionDeltas, $roots], JSON_THROW_ON_ERROR));
            if ($hash === $fingerprint) {
                break;
            }
            $fingerprint = $hash;
            $productionDeltas = $nextProductionDeltas;
        }
        if ($pass > $maximumPasses) {
            throw new DomainException(__('inventory.movements.messages.receipt_completion_lineage_changed'));
        }
        $effects = [];
        foreach ($transactions as $transaction) {
            if (! isset($replay['affected'][$transaction->id])) {
                continue;
            }
            $cost = $replay['costs'][$transaction->id];
            $original = $originalCosts[$transaction->id];
            $delta = bcsub($cost, (string) ($original ?? '0'), 8);
            $sign = bccomp((string) $transaction->quantity_in, '0', 8) > 0 ? '1' : '-1';
            $run = $runs->get($transaction->production_run_id);
            $line = $this->documentLine($transaction, $documentLines);
            $role = $transaction->is_reversal ? null : $this->productionRole($transaction);
            $inventoryClassification = match ($transaction->stock_status) {
                InventoryTransaction::StatusProductionStaging, InventoryTransaction::StatusWip => PostingAccountResolver::WorkInProcessInventory,
                InventoryTransaction::StatusQuarantine => PostingAccountResolver::QuarantineInventory,
                InventoryTransaction::StatusRework => PostingAccountResolver::ReworkInventory,
                InventoryTransaction::StatusScrap => PostingAccountResolver::WarehouseDamageLoss,
                default => null,
            };
            $inventoryAccount = $inventoryClassification !== null
                ? $this->accounts->resolve((int) $document->company_id, $inventoryClassification, __('inventory.movements.receipt_completion_title'))
                : $this->accounts->inventoryForProduct((int) $document->company_id, $transaction->product, __('inventory.movements.receipt_completion_title'));
            $base = ['source_transaction_id' => (int) $transaction->id, 'source_doc_num' => $transaction->source_doc_num,
                'branch_id' => (int) $transaction->branch_id, 'cost_center_id' => $run?->cost_center_id,
                'production_run_id' => $run?->id, 'document_line_id' => $line?->id,
                'production_cost_role' => $role, 'production_cost_delta' => $role === null ? null : $delta,
                'original_total_cost' => $original, 'completed_total_cost' => $cost, 'cost_completed' => true,
                'effect' => InventoryValueAdjustmentLine::EffectStock, 'account_id' => (int) $inventoryAccount->id,
                'amount' => bcmul($delta, $sign, 8),
                'unvalued_quantity_delta' => $original === null ? bcmul(bcsub((string) $transaction->quantity_in, (string) $transaction->quantity_out, 8), '-1', 8) : '0.00000000'];
            if (bccomp($delta, '0', 8) !== 0 || $original === null) {
                $effects[] = $base;
                $terminal = $this->terminalAccount($transaction, $transactions, (int) $document->company_id);
                if ($terminal !== null) {
                    $effects[] = [...$base, 'effect' => $terminal['effect'], 'account_id' => $terminal['account_id'],
                        'amount' => bcmul($base['amount'], '-1', 8), 'unvalued_quantity_delta' => '0.00000000',
                        'production_cost_role' => null, 'production_cost_delta' => null];
                }
            }
        }
        if ($periodic !== null) {
            $effects = [...$effects, ...app(InventoryGlPrecisionCorrectionService::class)->plan($transactions)];
        }
        if (collect($effects)->contains(fn (array $effect): bool => (int) $effect['account_id'] === (int) $counterpart->id)) {
            throw new DomainException(__('inventory.movements.messages.receipt_completion_counterpart_invalid'));
        }
        $sum = collect($effects)->reduce(fn (string $sum, array $effect): string => bcadd($sum, $effect['amount'], 8), '0');
        if (bccomp($sum, $sourceTotal, 8) !== 0) {
            throw new DomainException(__('inventory.movements.messages.receipt_completion_unbalanced'));
        }
        $effectAccounts = Account::query()->forCompany((int) $document->company_id)
            ->whereIn('id', array_column($effects, 'account_id'))->get()->keyBy('id');
        $effectBranches = Branch::query()->where('company_id', $document->company_id)
            ->whereIn('id', array_column($effects, 'branch_id'))->get()->keyBy('id');
        $effectCenters = CostCenter::query()->where('company_id', $document->company_id)
            ->whereIn('id', array_filter(array_column($effects, 'cost_center_id')))->get()->keyBy('id');
        $effects = array_map(function (array $effect) use ($effectAccounts, $effectBranches, $effectCenters): array {
            $account = $effectAccounts->get($effect['account_id']);
            $branch = $effectBranches->get($effect['branch_id']);
            $center = $effectCenters->get($effect['cost_center_id']);

            return [...$effect, 'account_labels' => $account === null ? null : ['ar' => $account->codeNameLabel('ar'), 'en' => $account->codeNameLabel('en')],
                'branch_label' => $branch?->name, 'cost_center_labels' => $center === null ? null : [
                    'ar' => CostCenter::codeNameLabelFor($center->cost_center_code, $center->displayName('ar')),
                    'en' => CostCenter::codeNameLabelFor($center->cost_center_code, $center->displayName('en'))]];
        }, $effects);
        $inputSnapshot = ['transactions' => $transactions->map(fn ($row) => $row->only(['id', 'posting_key', 'transaction_date', 'quantity_in', 'quantity_out',
            'unit_cost', 'total_cost', 'cost_method', 'cost_policy_id', 'reversal_of_id', 'stock_status', 'branch_store_id', 'production_run_id']))->all(),
            'layers' => $layers->map(fn ($row) => $row->only(['id', 'receipt_transaction_id', 'source_allocation_id', 'original_quantity', 'remaining_quantity', 'unit_cost', 'source_allocation_cost_snapshot']))->values()->all(),
            'allocations' => $allocations->map(fn ($row) => $row->only(['id', 'inventory_receipt_layer_id', 'issue_transaction_id', 'quantity', 'cost_unit_snapshot', 'cost_total_snapshot']))->values()->all(),
            'transitions' => $transitions->map(fn ($row) => [
                ...$row->only(['id', 'effective_from', 'inventory_cost_policy_id', 'status', 'input_fingerprint']),
                'bases' => $row->bases->map(fn ($basis) => $basis->only(['id', 'inventory_receipt_layer_id', 'original_quantity',
                    'original_book_value', 'remaining_quantity', 'remaining_book_value']))->values()->all(),
            ])->all(),
            'runs' => $runs->map(fn ($row) => $row->only(['id', 'status', 'good_base_quantity', 'received_base_quantity', 'cost_center_id', 'correction_sequence']))->values()->all()];

        return ['source_document_id' => (int) $document->id, 'source_type' => $document::class, 'source_doc_num' => $document->doc_num,
            'source_period_id' => (int) $document->financial_period_id, 'source_period_closed' => $sourcePeriod->is_closed,
            'period_inputs' => $periodInputs, 'period_window' => $periodic, 'target_costs' => $roots,
            'posting_period_id' => $postingPeriodId, 'posting_date' => $postingDate, 'counterpart_account_id' => (int) $counterpart->id,
            'counterpart_account_labels' => ['ar' => $counterpart->codeNameLabel('ar'), 'en' => $counterpart->codeNameLabel('en')],
            'source_total' => $sourceTotal, 'input_sha256' => hash('sha256', json_encode($inputSnapshot, JSON_THROW_ON_ERROR)),
            'allocation_high_watermark' => (int) ($allocations->keys()->max() ?? 0),
            'effects' => $effects, 'layers' => $replay['layer_bases'], 'allocations' => $replay['allocation_bases']];
    }

    /** @param array<int, int> $storeIds */
    private function causalPeriodicTransactions(Collection $transactions, array $storeIds): Collection
    {
        $positions = $transactions->filter(fn ($row): bool => in_array((int) $row->branch_store_id, $storeIds, true))
            ->mapWithKeys(fn ($row): array => [$this->positionKey($row) => true])->all();
        $restorations = InventoryReceiptLayer::query()->whereIn('receipt_transaction_id', $transactions->modelKeys())
            ->whereNotNull('source_allocation_id')->with('sourceAllocation:id,issue_transaction_id')->get();
        do {
            $previous = count($positions);
            $reachable = $transactions->filter(fn ($row): bool => isset($positions[$this->positionKey($row)]));
            $ids = $reachable->modelKeys();
            $runIds = $reachable->pluck('production_run_id')->filter()->unique()->all();
            $restoredIds = $restorations->filter(fn ($layer): bool => in_array((int) $layer->sourceAllocation?->issue_transaction_id, $ids, true))
                ->pluck('receipt_transaction_id')->all();
            foreach ($transactions as $transaction) {
                if (in_array($transaction->id, $restoredIds, true)
                    || in_array($transaction->reversal_of_id, $ids, true)
                    || ($transaction->transaction_type === InventoryDocument::TypeProductionReceipt
                        && in_array($transaction->production_run_id, $runIds, true))) {
                    $positions[$this->positionKey($transaction)] = true;
                }
            }
        } while (count($positions) !== $previous);

        return $transactions->filter(fn ($row): bool => isset($positions[$this->positionKey($row)]))->values();
    }

    /** @return array<string, mixed> */
    private function replay(Collection $transactions, Collection $layers, Collection $allocations, Collection $originalCosts, array $roots, array $productionDeltas, Collection $transitions): array
    {
        $positions = [];
        $costs = [];
        $affected = [];
        $layerValues = [];
        $allocationValues = [];
        $allocationBases = [];
        $restoredSlices = [];
        $restorationContributions = [];
        $layerBases = [];
        $pendingTransitions = $transitions->values();
        $layerGroups = $layers->groupBy('receipt_transaction_id');
        $allocationGroups = $allocations->groupBy('issue_transaction_id');
        $productionReceipts = $transactions->where('transaction_type', InventoryDocument::TypeProductionReceipt)->where('is_reversal', false)->groupBy('production_run_id');
        foreach ($transactions as $transaction) {
            foreach ($restorationContributions[$transaction->reversal_of_id] ?? [] as $contribution) {
                $restoredSlices[$contribution['key']]['quantity'] = bcsub($restoredSlices[$contribution['key']]['quantity'], $contribution['quantity'], 8);
                $restoredSlices[$contribution['key']]['value'] = bcsub($restoredSlices[$contribution['key']]['value'], $contribution['value'], 8);
            }
            while ($pendingTransitions->isNotEmpty() && $pendingTransitions->first()->effective_from->lte($transaction->transaction_date)) {
                $this->replayTransition($pendingTransitions->shift(), $positions, $layerValues, $layerBases, $layers);
            }
            $key = $this->positionKey($transaction);
            $positions[$key] ??= ['quantity' => '0', 'value' => '0', 'unknown' => '0', 'affected' => false];
            $position = &$positions[$key];
            $inbound = bccomp((string) $transaction->quantity_in, '0', 8) > 0;
            $quantity = $inbound ? (string) $transaction->quantity_in : (string) $transaction->quantity_out;
            if (bccomp($quantity, '0', 8) === 0) {
                unset($position);

                continue;
            }
            $cost = $originalCosts[$transaction->id];
            $isAffected = isset($roots[$transaction->id]);
            if ($isAffected) {
                $cost = $roots[$transaction->id];
            } elseif ($transaction->reversal_of_id !== null && isset($affected[$transaction->reversal_of_id])) {
                $cost = $costs[$transaction->reversal_of_id];
                $isAffected = true;
            } elseif ($inbound) {
                $restored = $layerGroups->get($transaction->id, collect())->whereNotNull('source_allocation_id');
                if ($restored->contains(fn ($layer): bool => isset($allocationBases[$layer->source_allocation_id]))) {
                    $cost = '0';
                    foreach ($restored as $layer) {
                        $source = $allocations->get($layer->source_allocation_id);
                        $sourceValue = $allocationValues[$source->id] ?? $source->completedTotalCost();
                        if ($sourceValue === null) {
                            throw new DomainException(__('inventory.movements.messages.receipt_completion_unvalued_dependency'));
                        }
                        $returnKey = $source->id.':'.($transaction->is_reversal ? 'reversal' : ($transaction->transaction_type === InventoryDocument::TypeTransfer || in_array($transaction->transaction_type, [InventoryDocument::TypeMaterialIssue, InventoryDocument::TypeAdditionalMaterialIssue, InventoryDocument::TypeMaterialReturn, InventoryDocument::TypeDamage], true) ? 'transfer' : 'return'));
                        $restoredSlices[$returnKey] ??= ['quantity' => '0', 'value' => '0'];
                        $returned = &$restoredSlices[$returnKey];
                        $nextQuantity = bcadd($returned['quantity'], (string) $layer->original_quantity, 8);
                        if (bccomp($nextQuantity, (string) $source->quantity, 8) > 0) {
                            throw new DomainException(__('inventory.movements.messages.receipt_completion_lineage_changed'));
                        }
                        $part = bcdiv(bcmul($sourceValue, (string) $layer->original_quantity, 16), (string) $source->quantity, 8);
                        if (bccomp($nextQuantity, (string) $source->quantity, 8) === 0) {
                            $part = bcsub($sourceValue, $returned['value'], 8);
                        }
                        $returned['quantity'] = $nextQuantity;
                        $returned['value'] = bcadd($returned['value'], $part, 8);
                        $restorationContributions[$transaction->id][] = ['key' => $returnKey, 'quantity' => (string) $layer->original_quantity, 'value' => $part];
                        unset($returned);
                        $cost = bcadd($cost, $part, 8);
                    }
                    $isAffected = true;
                } elseif ($transaction->transaction_type === InventoryDocument::TypeProductionReceipt
                    && isset($productionDeltas[$transaction->production_run_id])
                    && bccomp($productionDeltas[$transaction->production_run_id], '0', 8) !== 0) {
                    $run = ProductionRun::query()->findOrFail($transaction->production_run_id);
                    if (bccomp((string) $run->good_base_quantity, '0', 8) <= 0) {
                        throw new DomainException(__('inventory.movements.messages.receipt_completion_unvalued_dependency'));
                    }
                    $receipts = $productionReceipts->get($run->id);
                    $priorQuantity = $receipts->takeWhile(fn ($row): bool => $row->id !== $transaction->id)
                        ->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->quantity_in, 8), '0');
                    $delta = bcdiv(bcmul($productionDeltas[$run->id], $quantity, 16), (string) $run->good_base_quantity, 8);
                    if (bccomp(bcadd($priorQuantity, $quantity, 8), (string) $run->good_base_quantity, 8) === 0) {
                        $priorDelta = $receipts->takeWhile(fn ($row): bool => $row->id !== $transaction->id)->reduce(
                            fn (string $sum, $row): string => bcadd($sum, bcsub($costs[$row->id] ?? '0', (string) ($originalCosts[$row->id] ?? '0'), 8), 8), '0');
                        $delta = bcsub($productionDeltas[$run->id], $priorDelta, 8);
                    }
                    $cost = bcadd((string) ($cost ?? '0'), $delta, 8);
                    $isAffected = true;
                }
            } elseif ($position['affected']) {
                if (bccomp($position['quantity'], $quantity, 8) < 0 || bccomp($position['unknown'], '0', 8) !== 0) {
                    throw new DomainException(__('inventory.movements.messages.receipt_completion_unvalued_dependency'));
                }
                if (InventoryCostPolicy::usesReceiptLayers($transaction->cost_method)) {
                    $cost = '0';
                    foreach ($allocationGroups->get($transaction->id, collect()) as $allocation) {
                        $layer = $layers->get($allocation->inventory_receipt_layer_id);
                        $basis = $layerValues[$layer->id] ?? null;
                        if ($basis === null || $basis['value'] === null || bccomp($basis['quantity'], (string) $allocation->quantity, 8) < 0) {
                            throw new DomainException(__('inventory.movements.messages.receipt_completion_unvalued_dependency'));
                        }
                        $slice = bccomp($basis['quantity'], (string) $allocation->quantity, 8) === 0 ? $basis['value']
                            : bcdiv(bcmul($basis['value'], (string) $allocation->quantity, 16), $basis['quantity'], 8);
                        $cost = bcadd($cost, $slice, 8);
                    }
                } else {
                    $cost = bccomp($position['quantity'], $quantity, 8) === 0 ? $position['value']
                        : bcmul(bcdiv($position['value'], $position['quantity'], 8), $quantity, 8);
                }
                $isAffected = true;
            }
            if ($isAffected && ($cost === null || bccomp($cost, '0', 8) < 0)) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_unvalued_dependency'));
            }
            if ($cost !== null) {
                $costs[$transaction->id] = $cost;
            }
            if ($isAffected) {
                $affected[$transaction->id] = true;
                $position['affected'] = true;
            }
            $position['quantity'] = bcadd($position['quantity'], $inbound ? $quantity : bcmul($quantity, '-1', 8), 8);
            $position['value'] = bcadd($position['value'], $inbound ? (string) ($cost ?? '0') : bcmul((string) ($cost ?? '0'), '-1', 8), 8);
            if ($cost === null) {
                $position['unknown'] = bcadd($position['unknown'], $inbound ? $quantity : bcmul($quantity, '-1', 8), 8);
            }
            if ($inbound) {
                $bornLayers = $layerGroups->get($transaction->id, collect());
                $residue = $cost;
                foreach ($bornLayers as $index => $layer) {
                    $part = $cost === null ? null : ($index === $bornLayers->count() - 1 ? $residue
                        : bcdiv(bcmul($cost, (string) $layer->original_quantity, 16), $quantity, 8));
                    $layerValues[$layer->id] = ['quantity' => (string) $layer->original_quantity, 'value' => $part];
                    if ($cost !== null) {
                        $residue = bcsub($residue, $part, 8);
                    }
                    if ($isAffected) {
                        $layerBases[$layer->id] = ['layer_id' => (int) $layer->id, 'original_total_cost' => $layer->source_allocation_cost_snapshot
                            ?? ($layer->unit_cost === null ? null : bcmul((string) $layer->original_quantity, (string) $layer->unit_cost, 8)),
                            'completed_total_cost' => $part];
                    }
                }
            } else {
                $issueAllocations = $allocationGroups->get($transaction->id, collect());
                $allocationQuantity = $issueAllocations->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->quantity, 8), '0');
                if ($isAffected && bccomp($allocationQuantity, $quantity, 8) !== 0) {
                    throw new DomainException(__('inventory.movements.messages.receipt_completion_lineage_changed'));
                }
                $residue = $cost;
                foreach ($issueAllocations as $index => $allocation) {
                    $basis = $layerValues[$allocation->inventory_receipt_layer_id];
                    $physicalValue = $basis['value'] === null ? null : (bccomp($basis['quantity'], (string) $allocation->quantity, 8) === 0
                        ? $basis['value'] : bcdiv(bcmul($basis['value'], (string) $allocation->quantity, 16), $basis['quantity'], 8));
                    $part = $cost === null ? null : (InventoryCostPolicy::usesReceiptLayers($transaction->cost_method) ? $physicalValue
                        : ($index === $issueAllocations->count() - 1 ? $residue : bcdiv(bcmul($cost, (string) $allocation->quantity, 16), $quantity, 8)));
                    $allocationValues[$allocation->id] = $part;
                    $layerValues[$allocation->inventory_receipt_layer_id] = [
                        'quantity' => bcsub($basis['quantity'], (string) $allocation->quantity, 8),
                        'value' => $basis['value'] === null ? null : bcsub($basis['value'], (string) $physicalValue, 8)];
                    if ($part !== null) {
                        $residue = bcsub($residue, $part, 8);
                    }
                    if ($isAffected) {
                        $allocationBases[$allocation->id] = ['allocation_id' => (int) $allocation->id,
                            'original_total_cost' => $allocation->completedTotalCost(), 'completed_total_cost' => $part];
                    }
                }
            }
            unset($position);
        }
        foreach ($pendingTransitions as $transition) {
            $this->replayTransition($transition, $positions, $layerValues, $layerBases, $layers);
        }
        foreach ($layerBases as $id => &$basis) {
            $basis['remaining_quantity'] = $layerValues[$id]['quantity'];
            $basis['remaining_value'] = $layerValues[$id]['value'];
            if (bccomp($basis['remaining_quantity'], (string) $layers[$id]->remaining_quantity, 8) !== 0) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_lineage_changed'));
            }
        }
        unset($basis);

        return ['costs' => $costs, 'affected' => $affected, 'layer_bases' => array_values($layerBases), 'allocation_bases' => array_values($allocationBases)];
    }

    private function positionKey(object $row): string
    {
        return implode(':', [$row->branch_store_id, $row->product_id, $row->stock_status,
            $row->warehouse_location_id ?? 'none', $row->batch_lot ?? 'none',
            $row->stock_status === InventoryTransaction::StatusProductionStaging ? ($row->production_run_id ?? 'none') : 'none']);
    }

    /** @param array<string, array<string, mixed>> $positions @param array<int, array<string, mixed>> $layerValues @param array<int, array<string, mixed>> $layerBases */
    private function replayTransition(InventoryCostPolicyTransition $transition, array $positions, array &$layerValues, array &$layerBases, Collection $layers): void
    {
        foreach ($transition->bases->groupBy(fn ($basis): string => $this->positionKey($basis)) as $key => $bases) {
            $position = $positions[$key] ?? null;
            $quantity = $bases->reduce(fn (string $sum, $basis): string => bcadd($sum, (string) $basis->original_quantity, 8), '0');
            if ($position === null || bccomp($position['quantity'], $quantity, 8) !== 0 || bccomp($position['unknown'], '0', 8) !== 0) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_lineage_changed'));
            }
            $average = bcdiv($position['value'], $quantity, 8);
            $residue = $position['value'];
            foreach ($bases->values() as $index => $basis) {
                $layer = $layers->get($basis->inventory_receipt_layer_id);
                $current = $layerValues[$basis->inventory_receipt_layer_id] ?? null;
                if ($layer === null || $current === null || bccomp($current['quantity'], (string) $basis->original_quantity, 8) !== 0) {
                    throw new DomainException(__('inventory.movements.messages.receipt_completion_lineage_changed'));
                }
                $value = ! $position['affected'] ? (string) $basis->original_book_value
                    : ($index === $bases->count() - 1 ? $residue : bcmul($average, (string) $basis->original_quantity, 8));
                $layerValues[$layer->id]['value'] = $value;
                $residue = bcsub($residue, $value, 8);
                if ($position['affected'] && ! isset($layerBases[$layer->id])) {
                    $original = $layer->source_allocation_cost_snapshot
                        ?? ($layer->unit_cost === null ? null : bcmul((string) $layer->original_quantity, (string) $layer->unit_cost, 8));
                    $layerBases[$layer->id] = ['layer_id' => (int) $layer->id, 'original_total_cost' => $original, 'completed_total_cost' => $original];
                }
            }
        }
    }

    private function productionRole(InventoryTransaction $transaction): ?string
    {
        $outbound = bccomp((string) $transaction->quantity_out, '0', 8) > 0;

        return match (true) {
            $outbound && in_array($transaction->transaction_type, [InventoryDocument::TypeMaterialIssue, InventoryDocument::TypeAdditionalMaterialIssue], true) => 'issued',
            $outbound && $transaction->transaction_type === InventoryDocument::TypeMaterialReturn => 'returned',
            $outbound && $transaction->transaction_type === InventoryDocument::TypeProductionWaste => 'waste',
            ! $outbound && $transaction->transaction_type === InventoryDocument::TypeProductionReceipt => 'finished_goods',
            default => null,
        };
    }

    private function documentLine(InventoryTransaction $transaction, Collection $lines): ?InventoryDocumentLine
    {
        if ($transaction->source_type !== InventoryDocument::class || ! preg_match('/^inventory-document:\d+:line:(\d+):(in|out)$/D', $transaction->posting_key, $match)) {
            return null;
        }

        return $lines->get($transaction->source_id.':'.$match[1]);
    }

    /** @return array{effect: string, account_id: int}|null */
    private function terminalAccount(InventoryTransaction $transaction, Collection $transactions, int $companyId): ?array
    {
        $inbound = bccomp((string) $transaction->quantity_in, '0', 8) > 0;
        $type = $transaction->transaction_type;
        if (in_array($type, [InventoryDocument::TypeTransfer, InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue, InventoryDocument::TypeMaterialReturn, InventoryDocument::TypeDamage], true)) {
            return null;
        }
        $classification = match ($type) {
            InventoryDocument::TypeSalesDelivery, InventoryDocument::TypeSalesReturnReceipt => PostingAccountResolver::CostOfGoodsSold,
            InventoryDocument::TypeIssue, InventoryDocument::TypeAdjustmentOut => PostingAccountResolver::InventoryAdjustmentLoss,
            InventoryDocument::TypeMaintenanceMaterialIssue, InventoryDocument::TypeMaintenanceMaterialReturn => PostingAccountResolver::FactoryMaintenanceExpense,
            InventoryDocument::TypeProductionWaste => PostingAccountResolver::AbnormalWasteLoss,
            InventoryDocument::TypeScrap => PostingAccountResolver::WarehouseDamageLoss,
            InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionReceipt => PostingAccountResolver::WorkInProcessInventory,
            default => null,
        };
        if ($classification === null) {
            if (! $inbound) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_lineage_changed'));
            }

            return null;
        }

        return ['effect' => $classification === PostingAccountResolver::WorkInProcessInventory ? InventoryValueAdjustmentLine::EffectWip : InventoryValueAdjustmentLine::EffectExpense,
            'account_id' => (int) $this->accounts->resolve($companyId, $classification, __('inventory.movements.receipt_completion_title'))->id];
    }

    /** @param array<string, mixed> $plan */
    public function persistBases(InventoryValueAdjustment $adjustment, array $plan): void
    {
        foreach ($plan['layers'] as $basis) {
            InventoryReceiptCostBasis::query()->create(['inventory_value_adjustment_id' => $adjustment->id,
                'inventory_receipt_layer_id' => $basis['layer_id'], ...collect($basis)->except('layer_id')->all()]);
        }
        foreach ($plan['allocations'] as $basis) {
            InventoryAllocationCostCompletion::query()->create(['inventory_value_adjustment_id' => $adjustment->id,
                'inventory_layer_allocation_id' => $basis['allocation_id'], ...collect($basis)->except('allocation_id')->all()]);
        }
    }
}

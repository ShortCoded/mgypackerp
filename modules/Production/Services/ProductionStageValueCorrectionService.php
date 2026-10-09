<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionStageOutputCostOwner;
use Modules\Production\Models\ProductionStageTransfer;

final class ProductionStageValueCorrectionService
{
    /** @param list<int> $seedRunIds @return list<int> */
    public function runIds(int $companyId, array $seedRunIds): array
    {
        $ids = array_values(array_unique($seedRunIds));
        do {
            $before = $ids;
            $targets = ProductionStageTransfer::query()->where('company_id', $companyId)->where('status', 'posted')
                ->whereIn('source_run_id', $ids)->orderBy('id')->lockForUpdate()->pluck('target_run_id')->all();
            $ids = array_values(array_unique([...$ids, ...$targets]));
        } while ($ids !== $before);
        sort($ids);

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    public function values(int $companyId, string $kind, int $ownerId): array
    {
        $lines = InventoryValueAdjustmentLine::query()->where('source_snapshot->stage_value->kind', $kind)
            ->where('source_snapshot->stage_value->owner_id', $ownerId)
            ->whereHas('adjustment', fn ($query) => $query->where('company_id', $companyId)->where('status', InventoryValueAdjustment::StatusPosted))
            ->with('adjustment')->orderBy('id')->get();
        $result = [];
        foreach ($lines as $line) {
            app(ProductionInvoiceCostCorrectionService::class)->assertConsumed($line->adjustment);
            if (! in_array($line->source_snapshot, $line->adjustment->source_snapshot['effects'], true)
                || (int) $line->account_id !== (int) $line->source_snapshot['account_id']
                || (int) $line->production_run_id !== (int) $line->source_snapshot['production_run_id']
                || (int) $line->branch_id !== (int) $line->source_snapshot['branch_id']
                || bccomp((string) $line->amount, $line->source_snapshot['amount'], 8) !== 0) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $result[] = $line->source_snapshot['stage_value'];
        }

        return $result;
    }

    public function lossValue(int $companyId, ?int $periodId, ?int $branchId, bool $booked = false): string
    {
        $lines = InventoryValueAdjustmentLine::query()->where('source_snapshot->stage_value->kind', 'scrap')
            ->whereHas('adjustment', fn ($query) => $query->where('company_id', $companyId)->where('status', InventoryValueAdjustment::StatusPosted)
                ->when($periodId !== null, fn ($query) => $query->where('financial_period_id', $periodId)))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))->with('adjustment')->orderBy('id')->get();
        $amount = '0.00000000';
        foreach ($lines as $line) {
            $this->values($companyId, 'scrap', (int) $line->source_snapshot['stage_value']['owner_id']);
            $amount = bcadd($amount, $line->source_snapshot['stage_value'][$booked ? 'booked_delta' : 'delta'], 8);
        }

        return $amount;
    }

    public function delta(int $companyId, string $kind, int $ownerId, ?int $entryId = null, bool $booked = false): string
    {
        return collect($this->values($companyId, $kind, $ownerId))->filter(fn (array $value): bool => $entryId === null || ($value['entry_id'] ?? null) === $entryId)
            ->reduce(fn (string $sum, array $value): string => bcadd($sum, $value[$booked ? 'booked_delta' : 'delta'], 8), '0.00000000');
    }

    public function transferCost(ProductionStageTransfer $owner): string
    {
        return bcadd((string) $owner->total_cost, $this->delta((int) $owner->company_id, 'transfer', (int) $owner->id), 8);
    }

    /** @return array<string, string> */
    public function transferComponents(ProductionStageTransfer $owner): array
    {
        $parts = $owner->posting_snapshot['cost_components'] ?? [...app(ProductionStageCostComponentService::class)->zero(), 'material' => (string) $owner->total_cost];
        $parts['material'] = bcadd($parts['material'], $this->delta((int) $owner->company_id, 'transfer', (int) $owner->id), 8);

        return app(ProductionStageCostComponentService::class)->normalize($parts);
    }

    public function materialCost(object $line): string
    {
        $movement = InventoryTransaction::query()->where('company_id', $line->company_id)->where('source_type', InventoryDocument::class)
            ->where('posting_key', 'inventory-document:'.$line->inventory_document_id.':line:'.$line->id.':out')->first();
        if ($movement === null || $movement->total_cost === null || bccomp((string) $movement->total_cost, (string) $line->total_cost, 8) !== 0) {
            throw new DomainException(__('production_invoice_cost.lineage_missing'));
        }

        return $movement->completedTotalCost() ?? throw new DomainException(__('production_invoice_cost.lineage_missing'));
    }

    /** @param Collection<int, ProductionRun> $runs @return array<string, mixed> */
    public function graph(Collection $runs): array
    {
        $managed = $runs->filter(fn ($run): bool => app(ProductionStageTransferService::class)->isManaged($run));
        if ($managed->isEmpty()) {
            return ['runs' => [], 'transfers' => []];
        }
        $companyId = (int) $managed->first()->company_id;
        $transfers = ProductionStageTransfer::query()->where('company_id', $companyId)->where('status', 'posted')
            ->whereIn('source_run_id', $managed->modelKeys())->orderBy('id')->lockForUpdate()->get();
        $snapshots = [];
        foreach ($managed as $run) {
            $stage = app(ProductionStageTransferService::class)->position($run);
            $loss = app(ProductionStageOutputCostService::class)->position($run);
            $owners = ProductionStageOutputCostOwner::query()->where('company_id', $companyId)->where('production_run_id', $run->id)->orderBy('id')->lockForUpdate()->get();
            $snapshots[$run->id] = ['run' => $run->getRawOriginal(), 'stage' => $stage, 'loss' => $loss,
                'pool' => app(ProductionStageCostComponentService::class)->outputPool($run),
                'output_owners' => $owners->map->getRawOriginal()->all(),
                'progress' => $run->progressEntries()->orderBy('id')->lockForUpdate()->get()->map->getRawOriginal()->all(),
                'quality' => $run->inspections()->orderBy('id')->lockForUpdate()->get()->map->getRawOriginal()->all()];
        }
        $links = [];
        foreach ($transfers as $owner) {
            if (! isset($snapshots[$owner->source_run_id], $snapshots[$owner->target_run_id]) || ($owner->posting_snapshot['version'] ?? null) !== 2) {
                throw new DomainException(__('production_invoice_cost.lineage_missing'));
            }
            $uses = DB::table('production_stage_input_consumptions')->where('production_stage_transfer_id', $owner->id)->orderBy('id')->lockForUpdate()->get();
            $adjustments = DB::table('production_stage_input_adjustments')->where('production_stage_transfer_id', $owner->id)->orderBy('id')->lockForUpdate()->get();
            $entryIds = $uses->pluck('production_progress_entry_id')->merge($adjustments->pluck('production_progress_entry_id'))->unique()->sort()->values();
            $entries = [];
            foreach ($entryIds as $entryId) {
                $entries[$entryId] = app(ProductionStageInputAdjustmentService::class)->consumption($owner, (int) $entryId);
            }
            $links[$owner->id] = ['owner' => $owner->getRawOriginal(), 'posting' => $owner->posting_snapshot,
                'effective_cost' => $this->transferCost($owner),
                'effective_booked' => bcadd((string) $owner->booked_amount, $this->delta($companyId, 'transfer', (int) $owner->id, booked: true), 4),
                'uses' => $uses->map(fn ($row): array => (array) $row)->all(),
                'physical_adjustments' => $adjustments->map(fn ($row): array => (array) $row)->all(), 'entries' => $entries];
        }

        return json_decode(json_encode(['runs' => $snapshots, 'transfers' => $links], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param Collection<int, InventoryTransaction> $transactions @param Collection<int, string|null> $originalCosts @return array<string, mixed> */
    public function price(array $graph, Collection $transactions, array $costs, Collection $originalCosts): array
    {
        $deltas = [];
        foreach ($transactions as $transaction) {
            if ($transaction->is_reversal || $transaction->transaction_type !== InventoryDocument::TypeMaterialConsumption || ! isset($costs[$transaction->id])) {
                continue;
            }
            $id = (int) $transaction->production_run_id;
            if (isset($graph['runs'][$id])) {
                $deltas[$id] = bcadd($deltas[$id] ?? '0', bcsub($costs[$transaction->id], (string) $originalCosts[$transaction->id], 8), 8);
            }
        }
        $effects = [];
        $ordered = collect($graph['runs']);
        $processed = [];
        while ($ordered->isNotEmpty()) {
            $ready = $ordered->filter(fn (array $state, int $id): bool => ! collect($graph['transfers'])->contains(fn (array $link): bool => (int) $link['owner']['target_run_id'] === $id && ! in_array((int) $link['owner']['source_run_id'], $processed, true)));
            if ($ready->isEmpty()) {
                throw new DomainException(__('production_invoice_cost.lineage_missing'));
            }
            foreach ($ready as $id => $state) {
                $delta = $deltas[$id] ?? '0.00000000';
                if (bccomp(bcadd($state['pool']['material'], $delta, 8), '0', 8) < 0) {
                    throw new DomainException(__('production_invoice_cost.lineage_missing'));
                }
                $goodDelta = $delta;
                $loss = $state['loss'];
                if ($loss['owner_id'] !== null) {
                    $outputOwner = collect($state['output_owners'])->firstWhere('id', $loss['owner_id']);
                    $posting = json_decode($outputOwner['posting_snapshot'], true, flags: JSON_THROW_ON_ERROR);
                    $whole = array_reduce($posting['quantities'], fn (string $sum, string $quantity): string => bcadd($sum, $quantity, 8), '0');
                    $denominator = $posting['scrap_treatment'] === 'normal' ? bcsub($whole, $posting['quantities']['scrap'], 8) : $whole;
                    foreach (['rejected', 'rework', 'scrap'] as $kind) {
                        $quantity = $kind === 'scrap' && $posting['scrap_treatment'] === 'normal' ? '0' : $posting['quantities'][$kind];
                        $share = $this->share($delta, $quantity, $denominator);
                        $heldShare = $share;
                        $recoveredQuantity = '0';
                        $recoveredShare = '0';
                        if ($kind !== 'scrap') {
                            foreach (collect($state['output_owners'])->filter(fn (array $row): bool => $row['kind'] === 'recovery' && $row['status'] === 'posted' && (int) $row['parent_owner_id'] === (int) $outputOwner['id'] && $row['output_kind'] === $kind) as $recovery) {
                                $recoveredQuantity = bcadd($recoveredQuantity, $recovery['base_quantity'], 8);
                                $part = bccomp($recoveredQuantity, $quantity, 8) === 0 ? bcsub($share, $recoveredShare, 8)
                                    : $this->share($delta, $recovery['base_quantity'], $denominator);
                                $recoveredShare = bcadd($recoveredShare, $part, 8);
                                $heldShare = bcsub($heldShare, $part, 8);
                                if (bccomp($part, '0', 8) !== 0) {
                                    $effects[] = $this->effect($state['run'], $posting['source_account_id'], '0',
                                        ['kind' => 'recovery', 'owner_id' => (int) $recovery['id'], 'owner_seal' => $recovery['proposal_seal'], 'delta' => $part, 'booked_delta' => '0.0000']);
                                }
                            }
                        }
                        $goodDelta = bcsub($goodDelta, $heldShare, 8);
                        if (bccomp($share, '0', 8) === 0) {
                            continue;
                        }
                        $value = ['kind' => $kind, 'owner_id' => (int) $outputOwner['id'], 'owner_seal' => $outputOwner['proposal_seal'], 'delta' => $share, 'booked_delta' => '0.0000'];
                        if ($kind === 'scrap') {
                            $booked = bcsub(bcround(bcadd($loss['expensed_cost'], $share, 8), 4), bcadd($posting['booked_loss_amount'], $this->delta((int) $state['run']['company_id'], 'scrap', (int) $outputOwner['id'], booked: true), 4), 4);
                            $value['booked_delta'] = $booked;
                            $effects[] = $this->effect($state['run'], $posting['loss_account_id'], $booked, $value, InventoryValueAdjustmentLine::EffectExpense);
                            $effects[] = $this->effect($state['run'], $posting['source_account_id'], bcsub('0', $booked, 8), [...$value, 'kind' => 'scrap_counterpart']);
                        } else {
                            $effects[] = $this->effect($state['run'], $posting['source_account_id'], '0', $value);
                        }
                    }
                }
                $deltas[$id] = $goodDelta;
                $previousQuantity = '0';
                $previousDelta = '0';
                $newCumulative = '0';
                $previousTargetBooked = '0';
                foreach (collect($graph['transfers'])->filter(fn (array $link): bool => (int) $link['owner']['source_run_id'] === $id) as $ownerId => $link) {
                    $quantity = $link['owner']['base_quantity'];
                    $previousQuantity = bcadd($previousQuantity, $quantity, 8);
                    $part = bccomp($previousQuantity, (string) $state['run']['good_base_quantity'], 8) === 0
                        ? bcsub($goodDelta, $previousDelta, 8) : $this->share($goodDelta, $quantity, (string) $state['run']['good_base_quantity']);
                    $previousDelta = bcadd($previousDelta, $part, 8);
                    $newCost = bcadd($link['effective_cost'], $part, 8);
                    if (bccomp($newCost, '0', 8) < 0) {
                        throw new DomainException(__('production_invoice_cost.lineage_missing'));
                    }
                    $newCumulative = bcadd($newCumulative, $newCost, 8);
                    $targetBooked = bcround($newCumulative, 4);
                    $bookedDelta = bcsub(bcsub($targetBooked, $previousTargetBooked, 4), $link['effective_booked'], 4);
                    $previousTargetBooked = $targetBooked;
                    if (bccomp($part, '0', 8) !== 0 || bccomp($bookedDelta, '0', 4) !== 0) {
                        $value = ['kind' => 'transfer', 'owner_id' => (int) $ownerId, 'owner_seal' => $link['owner']['proposal_seal'], 'delta' => $part, 'booked_delta' => $bookedDelta];
                        $target = $graph['runs'][$link['owner']['target_run_id']]['run'];
                        $effects[] = $this->effect($target, $link['posting']['target_account_id'], $bookedDelta, $value);
                        $effects[] = $this->effect($state['run'], $link['posting']['source_account_id'], bcsub('0', $bookedDelta, 8), [...$value, 'kind' => 'transfer_counterpart']);
                    }
                    $usedQuantity = '0';
                    $usedDelta = '0';
                    foreach ($link['entries'] as $entryId => $use) {
                        $usedQuantity = bcadd($usedQuantity, $use['quantity'], 8);
                        $useDelta = bccomp($usedQuantity, $quantity, 8) === 0 ? bcsub($part, $usedDelta, 8) : $this->share($part, $use['quantity'], $quantity);
                        $usedDelta = bcadd($usedDelta, $useDelta, 8);
                        $targetId = (int) $link['owner']['target_run_id'];
                        $deltas[$targetId] = bcadd($deltas[$targetId] ?? '0', $useDelta, 8);
                        if (bccomp($useDelta, '0', 8) !== 0) {
                            $effects[] = $this->effect($graph['runs'][$targetId]['run'], $link['posting']['target_account_id'], '0',
                                ['kind' => 'input', 'owner_id' => (int) $ownerId, 'owner_seal' => $link['owner']['proposal_seal'], 'entry_id' => (int) $entryId, 'delta' => $useDelta, 'booked_delta' => '0.0000']);
                        }
                    }
                }
                $processed[] = $id;
                $ordered->forget($id);
            }
        }

        return ['output_deltas' => $deltas, 'effects' => $effects];
    }

    private function share(string $delta, string $quantity, string $whole): string
    {
        if (bccomp($quantity, '0', 8) === 0) {
            return '0.00000000';
        }
        if (bccomp($whole, '0', 8) <= 0 || bccomp($quantity, $whole, 8) > 0) {
            throw new DomainException(__('production_invoice_cost.lineage_missing'));
        }

        return bccomp($quantity, $whole, 8) === 0 ? $delta : bcdiv(bcmul($delta, $quantity, 16), $whole, 8);
    }

    /** @return array<string, mixed> */
    private function effect(array $run, int $accountId, string $amount, array $value, string $effect = InventoryValueAdjustmentLine::EffectWip): array
    {
        return ['source_transaction_id' => null, 'source_doc_num' => $run['run_number'], 'branch_id' => (int) $run['branch_id'],
            'cost_center_id' => $run['cost_center_id'], 'production_run_id' => (int) $run['id'], 'document_line_id' => null,
            'production_cost_role' => null, 'production_cost_delta' => null, 'effect' => $effect,
            'account_id' => $accountId, 'amount' => bcadd($amount, '0', 8), 'unvalued_quantity_delta' => '0.00000000', 'stage_value' => $value];
    }
}

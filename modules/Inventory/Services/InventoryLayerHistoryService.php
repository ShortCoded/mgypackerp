<?php

namespace Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryCostPolicyTransition;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;

class InventoryLayerHistoryService
{
    public function __construct(private readonly InventoryCostPolicyService $policies) {}

    /** @param Collection<int, InventoryReceiptLayer> $layers @return Collection<int, InventoryReceiptLayer> */
    public function atDate(Collection $layers, CarbonImmutable $asOf): Collection
    {
        $date = $asOf->toDateString();
        $layers->load(['receiptTransaction', 'allocations' => fn ($query) => $query
            ->whereHas('issueTransaction', fn ($issue) => $issue->whereDate('transaction_date', '<=', $date))
            ->with(['issueTransaction', 'costCompletions' => fn ($cost) => $cost
                ->whereHas('adjustment', fn ($adjustment) => $adjustment->where('status', InventoryValueAdjustment::StatusPosted)
                    ->whereDate('posting_date', '<=', $date))->orderByDesc('id')]),
            'costCompletionBases' => fn ($query) => $query->with('adjustment')
                ->whereHas('adjustment', fn ($adjustment) => $adjustment->where('status', InventoryValueAdjustment::StatusPosted)
                    ->whereDate('posting_date', '<=', $date))->orderByDesc('id'),
            'transitionBases' => fn ($query) => $query->with('transition')
                ->whereHas('transition', fn ($transition) => $transition->where('status', InventoryCostPolicyTransition::StatusActivated)
                    ->whereDate('effective_from', '<=', $date))->orderByDesc('id'),
        ]);

        $rows = $layers->map(function (InventoryReceiptLayer $layer): InventoryReceiptLayer {
            $quantity = (string) $layer->original_quantity;
            $value = $layer->source_allocation_cost_snapshot
                ?? ($layer->unit_cost === null ? null : bcmul($quantity, (string) $layer->unit_cost, 8));
            $allocations = $layer->allocations;
            $completion = $layer->costCompletionBases->first();
            $transition = $layer->transitionBases->first();
            if ($transition && ($completion === null || $transition->transition->effective_from->gt($completion->adjustment->posting_date))) {
                $quantity = (string) $transition->original_quantity;
                $value = (string) $transition->original_book_value;
                $allocations = $allocations->filter(fn ($allocation): bool => $allocation->issueTransaction->transaction_date
                    ->gte($transition->transition->effective_from));
            } elseif ($completion) {
                $snapshot = $completion->adjustment->source_snapshot;
                $frozen = collect($snapshot['layers'])->firstWhere('layer_id', $layer->id);
                $quantity = (string) $frozen['remaining_quantity'];
                $value = $frozen['remaining_value'] === null ? null : (string) $frozen['remaining_value'];
                $watermark = $snapshot['allocation_high_watermark'] ?? (int) (collect($snapshot['allocations'])->max('allocation_id') ?? 0);
                $allocations = $allocations->filter(fn ($allocation): bool => $allocation->id > $watermark);
            }
            foreach ($allocations as $allocation) {
                $quantity = bcsub($quantity, (string) $allocation->quantity, 8);
                $cost = $allocation->costCompletions->first()?->completed_total_cost ?? $allocation->cost_total_snapshot;
                $value = $value === null || $cost === null ? null : bcsub($value, (string) $cost, 8);
            }
            $copy = clone $layer;
            $copy->setAttribute('remaining_quantity', $quantity);
            $copy->setAttribute('remaining_value', $value);
            $copy->setAttribute('valuation_complete', $value !== null);

            return $copy;
        })->filter(fn (InventoryReceiptLayer $layer): bool => bccomp((string) $layer->remaining_quantity, '0', 8) > 0)->values();

        $runDimension = "case when stock_status = 'production_staging' then production_run_id else null end";
        $positions = InventoryTransaction::query()->whereIn('company_id', $rows->pluck('company_id')->unique())
            ->whereIn('branch_store_id', $rows->pluck('branch_store_id')->unique())
            ->whereIn('product_id', $rows->pluck('product_id')->unique())->whereDate('transaction_date', '<=', $date)
            ->select(['company_id', 'branch_store_id', 'product_id', 'stock_status', 'warehouse_location_id', 'batch_lot'])
            ->selectRaw($runDimension.' as production_run_id')
            ->selectRaw('sum(quantity_in-quantity_out) as quantity, sum('.InventoryTransaction::signedValueSql().') as value, sum('.InventoryTransaction::unvaluedQuantitySql().') as unknown_quantity')
            ->groupBy(['company_id', 'branch_store_id', 'product_id', 'stock_status', 'warehouse_location_id', 'batch_lot', DB::raw($runDimension)])
            ->get()->keyBy(fn ($row): string => $this->positionKey($row));
        $methods = [];
        foreach ($rows->groupBy(fn ($row): string => $this->positionKey($row)) as $key => $group) {
            $first = $group->first();
            $storeKey = $first->company_id.':'.$first->branch_store_id;
            $methods[$storeKey] ??= $this->policies->resolve((int) $first->company_id, (int) $first->branch_store_id, $date)['method'];
            if (InventoryCostPolicy::usesReceiptLayers($methods[$storeKey])) {
                continue;
            }
            $position = $positions->get($key);
            $complete = $position !== null && bccomp((string) $position->quantity, '0', 8) > 0
                && bccomp((string) $position->unknown_quantity, '0', 8) === 0 && bccomp((string) $position->value, '0', 8) >= 0;
            $quantity = $group->reduce(fn (string $sum, $layer): string => bcadd($sum, (string) $layer->remaining_quantity, 8), '0');
            $residue = $complete ? bcdiv(bcmul((string) $position->value, $quantity, 16), (string) $position->quantity, 8) : null;
            foreach ($group->values() as $index => $layer) {
                $value = ! $complete ? null : ($index === $group->count() - 1 ? $residue
                    : bcdiv(bcmul((string) $position->value, (string) $layer->remaining_quantity, 16), (string) $position->quantity, 8));
                $layer->setAttribute('remaining_value', $value);
                $layer->setAttribute('valuation_complete', $complete);
                if ($complete) {
                    $residue = bcsub($residue, $value, 8);
                }
            }
        }

        return $rows;
    }

    private function positionKey(object $row): string
    {
        $run = $row instanceof InventoryReceiptLayer ? $row->receiptTransaction?->production_run_id : $row->production_run_id;

        return implode(':', [$row->company_id, $row->branch_store_id, $row->product_id, $row->stock_status,
            $row->warehouse_location_id ?? 'none', $row->batch_lot ?? 'none',
            $row->stock_status === InventoryTransaction::StatusProductionStaging ? ($run ?? 'none') : 'none']);
    }
}

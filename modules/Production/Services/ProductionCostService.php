<?php

namespace Modules\Production\Services;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionRun;

class ProductionCostService
{
    /** @return array{issued: string, returned: string, waste: string, direct_material_cost: string, material_valuation_complete: bool, other_direct_cost: string, allocated_overhead: string, capitalizable: string, finished_goods: string, wip: string} */
    public function runPosition(ProductionRun $run): array
    {
        return $this->positions(new EloquentCollection([$run]))->get($run->getKey());
    }

    /**
     * Return the canonical posted cost position for each production run, keyed by run id.
     *
     * @param  EloquentCollection<int, ProductionRun>|Collection<int, ProductionRun>  $runs
     * @return Collection<int, array{issued: string, returned: string, waste: string, direct_material_cost: string, material_valuation_complete: bool, other_direct_cost: string, allocated_overhead: string, capitalizable: string, finished_goods: string, wip: string}>
     */
    public function positions(EloquentCollection|Collection $runs): Collection
    {
        if ($runs->isEmpty()) {
            return collect();
        }

        $runIds = $runs->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $inventoryCosts = DB::table('inventory_document_lines')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->whereIn('inventory_documents.production_run_id', $runIds)
            ->where('inventory_documents.status', InventoryDocument::StatusPosted)
            ->whereNull('inventory_document_lines.deleted_at')
            ->groupBy('inventory_documents.production_run_id')
            ->selectRaw(
                'inventory_documents.production_run_id,
                coalesce(sum(case when inventory_documents.document_type in (?, ?) then inventory_document_lines.total_cost else 0 end), 0) as issued,
                coalesce(sum(case when inventory_documents.document_type = ? then inventory_document_lines.total_cost else 0 end), 0) as returned,
                coalesce(sum(case when inventory_documents.document_type = ? then inventory_document_lines.total_cost else 0 end), 0) as waste,
                coalesce(sum(case when inventory_documents.document_type = ? then inventory_document_lines.total_cost else 0 end), 0) as finished_goods,
                sum(case when inventory_documents.document_type in (?, ?, ?) and inventory_document_lines.total_cost is null then 1 else 0 end) as unvalued_material_lines',
                [
                    InventoryDocument::TypeMaterialIssue,
                    InventoryDocument::TypeAdditionalMaterialIssue,
                    InventoryDocument::TypeMaterialReturn,
                    InventoryDocument::TypeProductionWaste,
                    InventoryDocument::TypeProductionReceipt,
                    InventoryDocument::TypeMaterialIssue,
                    InventoryDocument::TypeAdditionalMaterialIssue,
                    InventoryDocument::TypeMaterialReturn,
                ],
            )
            ->get()
            ->keyBy('production_run_id');

        $otherDirectCosts = DB::table('production_expense_requests')
            ->whereIn('production_run_id', $runIds)
            ->where('status', ProductionExpenseRequest::StatusPaid)
            ->whereNotNull('journal_entry_id')
            ->whereNull('reversal_journal_entry_id')
            ->whereNull('deleted_at')
            ->groupBy('production_run_id')
            ->selectRaw('production_run_id, coalesce(sum(amount), 0) as amount')
            ->pluck('amount', 'production_run_id');

        $allocatedOverheads = DB::table('cost_overhead_allocation_lines')
            ->join('cost_overhead_allocation_runs', 'cost_overhead_allocation_runs.id', '=', 'cost_overhead_allocation_lines.allocation_run_id')
            ->whereIn('cost_overhead_allocation_lines.production_run_id', $runIds)
            ->where('cost_overhead_allocation_runs.status', 'posted')
            ->groupBy('cost_overhead_allocation_lines.production_run_id')
            ->selectRaw('cost_overhead_allocation_lines.production_run_id, coalesce(sum(cost_overhead_allocation_lines.allocated_amount), 0) as amount')
            ->pluck('amount', 'cost_overhead_allocation_lines.production_run_id');

        return $runs->mapWithKeys(function (ProductionRun $run) use ($inventoryCosts, $otherDirectCosts, $allocatedOverheads): array {
            $inventory = $inventoryCosts->get($run->getKey());
            $issued = bcadd((string) ($inventory->issued ?? 0), '0', 8);
            $returned = bcadd((string) ($inventory->returned ?? 0), '0', 8);
            $waste = bcadd((string) ($inventory->waste ?? 0), '0', 8);
            $finishedGoods = bcadd((string) ($inventory->finished_goods ?? 0), '0', 8);
            $directMaterialCost = bcsub(bcsub($issued, $returned, 8), $waste, 8);
            $otherDirectCost = bcadd((string) ($otherDirectCosts->get($run->getKey()) ?? 0), '0', 8);
            $allocatedOverhead = bcadd((string) ($allocatedOverheads->get($run->getKey()) ?? 0), '0', 8);
            $capitalizable = bcadd(bcadd($directMaterialCost, $otherDirectCost, 8), $allocatedOverhead, 8);

            return [$run->getKey() => [
                'issued' => $issued,
                'returned' => $returned,
                'waste' => $waste,
                'direct_material_cost' => $directMaterialCost,
                'material_valuation_complete' => (int) ($inventory->unvalued_material_lines ?? 0) === 0,
                'other_direct_cost' => $otherDirectCost,
                'allocated_overhead' => $allocatedOverhead,
                'capitalizable' => $capitalizable,
                'finished_goods' => $finishedGoods,
                'wip' => bcsub($capitalizable, $finishedGoods, 8),
            ]];
        });
    }

    public function directMaterialCost(ProductionRun $run): string
    {
        return $this->runPosition($run)['direct_material_cost'];
    }

    public function receiptCost(ProductionRun $run, string $receiptBaseQuantity): string
    {
        $position = $this->runPosition($run);
        $remainingGood = bcsub((string) $run->good_base_quantity, (string) $run->received_base_quantity, 8);

        if (bccomp($receiptBaseQuantity, $remainingGood, 8) === 0) {
            return $position['wip'];
        }

        return bcdiv(
            bcmul($position['capitalizable'], $receiptBaseQuantity, 8),
            (string) $run->good_base_quantity,
            8,
        );
    }
}

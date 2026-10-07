<?php

namespace Modules\Production\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\OverheadAllocationRule;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionRun;

class ProductionCostService
{
    /** @return array{issued: string, returned: string, waste: string, direct_material_cost: string, material_valuation_complete: bool, expense_valuation_complete: bool, labor_valuation_complete: bool, other_direct_cost: string, direct_labor_cost: string, allocated_overhead: string, capitalizable: string, finished_goods: string, standard_variance: string, wip: string} */
    public function runPosition(ProductionRun $run): array
    {
        return $this->positions(new EloquentCollection([$run]))->get($run->getKey());
    }

    /**
     * Return the canonical posted cost position for each production run, keyed by run id.
     *
     * @param  EloquentCollection<int, ProductionRun>|Collection<int, ProductionRun>  $runs
     * @return Collection<int, array{issued: string, returned: string, waste: string, direct_material_cost: string, material_valuation_complete: bool, expense_valuation_complete: bool, labor_valuation_complete: bool, other_direct_cost: string, direct_labor_cost: string, allocated_overhead: string, capitalizable: string, finished_goods: string, standard_variance: string, wip: string}>
     */
    public function positions(EloquentCollection|Collection $runs): Collection
    {
        if ($runs->isEmpty()) {
            return collect();
        }

        $runIds = $runs->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $inventoryCosts = DB::table('inventory_document_lines')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->where(function ($query) use ($runIds): void {
                $query->whereIn('inventory_document_lines.production_run_id', $runIds)
                    ->orWhereIn('inventory_documents.production_run_id', $runIds);
            })
            ->where('inventory_documents.status', InventoryDocument::StatusPosted)
            ->whereNull('inventory_document_lines.deleted_at')
            ->groupByRaw('coalesce(inventory_document_lines.production_run_id, inventory_documents.production_run_id)')
            ->selectRaw(
                'coalesce(inventory_document_lines.production_run_id, inventory_documents.production_run_id) as production_run_id,
                coalesce(sum(case when inventory_documents.document_type in (?, ?) then inventory_document_lines.total_cost else 0 end), 0) as issued,
                coalesce(sum(case when inventory_documents.document_type = ? then inventory_document_lines.total_cost else 0 end), 0) as returned,
                coalesce(sum(case when inventory_documents.document_type = ? then inventory_document_lines.total_cost else 0 end), 0) as waste,
                coalesce(sum(case when inventory_documents.document_type = ? then inventory_document_lines.total_cost else 0 end), 0) as finished_goods,
                sum(case when inventory_documents.document_type in (?, ?, ?) and inventory_document_lines.total_cost is null and not exists (
                    select 1 from inventory_value_adjustment_lines as completed_line
                    join inventory_value_adjustments as completed on completed.id = completed_line.inventory_value_adjustment_id
                    where completed_line.inventory_document_line_id = inventory_document_lines.id
                    and completed_line.production_cost_role is not null and completed.status = \'posted\'
                ) then 1 else 0 end) as unvalued_material_lines',
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

        $completedInventoryCosts = DB::table('inventory_value_adjustment_lines as correction_line')
            ->join('inventory_value_adjustments as correction', 'correction.id', '=', 'correction_line.inventory_value_adjustment_id')
            ->join('inventory_document_lines as source_line', 'source_line.id', '=', 'correction_line.inventory_document_line_id')
            ->join('inventory_documents as source_document', 'source_document.id', '=', 'source_line.inventory_document_id')
            ->whereIn('correction_line.production_run_id', $runIds)->where('correction.status', InventoryValueAdjustment::StatusPosted)
            ->where('source_document.status', InventoryDocument::StatusPosted)->whereNotNull('correction_line.production_cost_role')
            ->groupBy('correction_line.production_run_id', 'correction_line.production_cost_role')
            ->selectRaw('correction_line.production_run_id, correction_line.production_cost_role, sum(correction_line.production_cost_delta) as amount')
            ->get()->groupBy('production_run_id');

        $otherDirectCosts = DB::table('production_expense_requests')
            ->join('currencies', 'currencies.id', '=', 'production_expense_requests.currency_id')
            ->whereIn('production_run_id', $runIds)
            ->where('production_expense_requests.status', ProductionExpenseRequest::StatusPaid)
            ->whereNotNull('journal_entry_id')
            ->whereNull('reversal_journal_entry_id')
            ->whereNull('production_expense_requests.deleted_at')
            ->groupBy('production_expense_requests.production_run_id')
            ->selectRaw('production_expense_requests.production_run_id,
                coalesce(sum(case when currencies.is_main then production_expense_requests.amount else production_expense_requests.amount * production_expense_requests.exchange_rate end), 0) as amount,
                sum(case when currencies.is_main and (production_expense_requests.exchange_rate is null or production_expense_requests.exchange_rate = 1) then 0
                    when currencies.is_main then 1
                    when production_expense_requests.exchange_rate is null or production_expense_requests.exchange_rate <= 0 then 1 else 0 end) as unvalued_expenses')
            ->get()
            ->keyBy('production_run_id');

        $allocatedOverheads = DB::table('cost_overhead_allocation_lines')
            ->join('cost_overhead_allocation_runs', 'cost_overhead_allocation_runs.id', '=', 'cost_overhead_allocation_lines.allocation_run_id')
            ->whereIn('cost_overhead_allocation_lines.production_run_id', $runIds)
            ->where('cost_overhead_allocation_runs.status', 'posted')
            ->groupBy('cost_overhead_allocation_lines.production_run_id', 'cost_overhead_allocation_runs.basis_used')
            ->selectRaw('cost_overhead_allocation_lines.production_run_id, cost_overhead_allocation_runs.basis_used, coalesce(sum(cost_overhead_allocation_lines.allocated_amount), 0) as amount')
            ->get()->groupBy('production_run_id');
        $laborValuation = $this->laborValuationCompleteness($runs);

        return $runs->mapWithKeys(function (ProductionRun $run) use ($inventoryCosts, $completedInventoryCosts, $otherDirectCosts, $allocatedOverheads, $laborValuation): array {
            $inventory = $inventoryCosts->get($run->getKey());
            $issued = bcadd((string) ($inventory->issued ?? 0), '0', 8);
            $returned = bcadd((string) ($inventory->returned ?? 0), '0', 8);
            $waste = bcadd((string) ($inventory->waste ?? 0), '0', 8);
            $finishedGoods = bcadd((string) ($inventory->finished_goods ?? 0), '0', 8);
            $completion = $completedInventoryCosts->get($run->id, collect())->keyBy('production_cost_role');
            $issued = bcadd($issued, (string) ($completion->get('issued')?->amount ?? '0'), 8);
            $returned = bcadd($returned, (string) ($completion->get('returned')?->amount ?? '0'), 8);
            $waste = bcadd($waste, (string) ($completion->get('waste')?->amount ?? '0'), 8);
            $finishedGoods = bcadd($finishedGoods, (string) ($completion->get('finished_goods')?->amount ?? '0'), 8);
            $standardVariance = bcadd((string) ($completion->get('standard_variance')?->amount ?? '0'), '0', 8);
            $directMaterialCost = bcsub(bcsub($issued, $returned, 8), $waste, 8);
            $expensePosition = $otherDirectCosts->get($run->getKey());
            $otherDirectCost = bcadd((string) ($expensePosition->amount ?? 0), '0', 8);
            $laborAllocations = $allocatedOverheads->get($run->getKey(), collect());
            $directLaborCost = $laborAllocations->where('basis_used', 'direct_payroll_hours')
                ->reduce(fn (string $sum, object $line): string => bcadd($sum, (string) $line->amount, 8), '0.00000000');
            $allocatedOverhead = $laborAllocations->where('basis_used', '!=', 'direct_payroll_hours')
                ->reduce(fn (string $sum, object $line): string => bcadd($sum, (string) $line->amount, 8), '0.00000000');
            $capitalizable = bcadd(bcadd(bcadd($directMaterialCost, $otherDirectCost, 8), $directLaborCost, 8), $allocatedOverhead, 8);
            $stage = app(ProductionStageTransferService::class)->position($run);
            $capitalizable = bcsub(bcadd($capitalizable, $stage['incoming'], 8), $stage['outgoing'], 8);
            $outputCosts = app(ProductionStageOutputCostService::class)->position($run);
            $capitalizable = bcsub($capitalizable, $outputCosts['expensed_cost'], 8);

            return [$run->getKey() => [
                'issued' => $issued,
                'returned' => $returned,
                'waste' => $waste,
                'direct_material_cost' => $directMaterialCost,
                'material_valuation_complete' => (int) ($inventory->unvalued_material_lines ?? 0) === 0,
                'expense_valuation_complete' => (int) ($expensePosition->unvalued_expenses ?? 0) === 0,
                'labor_valuation_complete' => $laborValuation->get($run->id),
                'other_direct_cost' => $otherDirectCost,
                'direct_labor_cost' => $directLaborCost,
                'allocated_overhead' => $allocatedOverhead,
                'capitalizable' => $capitalizable,
                'stage_incoming_cost' => $stage['incoming'],
                'stage_outgoing_cost' => $stage['outgoing'],
                'stage_input_used_cost' => $stage['used_cost'],
                'stage_loss_cost' => $outputCosts['expensed_cost'],
                'stage_held_output_cost' => app(ProductionStageCostComponentService::class)->sum(app(ProductionStageCostComponentService::class)->add($outputCosts['held_components']['rejected'], $outputCosts['held_components']['rework'])),
                'finished_goods' => $finishedGoods,
                'standard_variance' => $standardVariance,
                'wip' => bcsub(bcsub($capitalizable, $finishedGoods, 8), $standardVariance, 8),
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
        if (! $position['material_valuation_complete']) {
            throw new DomainException(__('production_execution.messages.unvalued_material_cost'));
        }
        if (! $position['expense_valuation_complete']) {
            throw new DomainException(__('production_execution.messages.unvalued_expense_cost'));
        }
        if (! $position['labor_valuation_complete']) {
            throw new DomainException(__('production_execution.messages.unvalued_labor_cost'));
        }
        $remainingGood = bcsub((string) $run->good_base_quantity, (string) $run->received_base_quantity, 8);
        $stage = app(ProductionStageTransferService::class)->position($run);
        $remainingGood = bcsub($remainingGood, $stage['outgoing_quantity'], 8);
        if (bccomp($receiptBaseQuantity, '0', 8) <= 0 || bccomp($remainingGood, '0', 8) <= 0 || bccomp($receiptBaseQuantity, $remainingGood, 8) > 0) {
            throw new DomainException(__('production_execution.messages.invalid_receipt_cost'));
        }

        if ($run->material_accounting_mode === ProductionOutputEvidenceService::Mode || app(ProductionShiftEvidenceService::class)->hasDailyReports($run)) {
            $consumption = DB::table('inventory_document_lines as line')->join('inventory_documents as document', 'document.id', '=', 'line.inventory_document_id')
                ->where('document.company_id', $run->company_id)->where('document.branch_id', $run->branch_id)->where('document.production_run_id', $run->id)
                ->where('document.status', InventoryDocument::StatusPosted)->where('document.document_type', InventoryDocument::TypeMaterialConsumption)
                ->whereNull('document.deleted_at')->whereNull('line.deleted_at');
            if ((clone $consumption)->whereNull('line.total_cost')->exists()) {
                throw new DomainException(__('production_execution.messages.unvalued_material_cost'));
            }
            $consumedCost = (string) BigDecimal::of((string) $consumption->sum('line.total_cost'))->toScale(8, RoundingMode::HalfUp);
            $eligible = bcadd(bcadd(bcadd($consumedCost, $position['other_direct_cost'], 8), $position['direct_labor_cost'], 8), $position['allocated_overhead'], 8);
            $eligible = bcadd($eligible, $stage['used_cost'], 8);
            if (app(ProductionStageTransferService::class)->isManaged($run)) {
                app(ProductionStageOutputCostService::class)->assertReadyForOutput($run);
                $excluded = app(ProductionStageOutputCostService::class)->position($run)['excluded_components'];
                $eligible = bcsub($eligible, app(ProductionStageCostComponentService::class)->sum($excluded), 8);
            }
            $unreceivedCost = bcsub(bcsub(bcsub($eligible, $position['finished_goods'], 8), $position['standard_variance'], 8), $stage['outgoing'], 8);
            $receiptCost = bccomp($receiptBaseQuantity, $remainingGood, 8) === 0 ? $unreceivedCost
                : bcdiv(bcmul($unreceivedCost, $receiptBaseQuantity, 16), $remainingGood, 8);
        } elseif (bccomp($receiptBaseQuantity, $remainingGood, 8) === 0) {
            $receiptCost = $position['wip'];
        } else {
            $receiptCost = bcdiv(
                bcmul($position['capitalizable'], $receiptBaseQuantity, 8),
                (string) $run->good_base_quantity,
                8,
            );
        }

        if (bccomp($receiptCost, '0', 8) < 0) {
            throw new DomainException(__('production_execution.messages.invalid_receipt_cost'));
        }

        return $receiptCost;
    }

    /** @param list<array<string, mixed>> $details */
    public function assertLaborAmendmentAllowed(ProductionRun $run, array $details): void
    {
        $hasPostedLabor = OverheadAllocationRun::query()->where('company_id', $run->company_id)
            ->where('basis_used', OverheadAllocationRule::BasisDirectPayrollHours)
            ->where('status', OverheadAllocationRun::StatusPosted)
            ->whereHas('lines', fn ($query) => $query->where('production_run_id', $run->id))->exists();
        if ($hasPostedLabor && $this->laborSignature($run->labor_details ?? []) !== $this->laborSignature($details)) {
            throw new DomainException(__('production_execution.messages.reverse_labor_allocation'));
        }
    }

    /** @param list<array<string, mixed>> $details @return list<array<string, mixed>> */
    private function laborSignature(array $details): array
    {
        return collect($details)->map(function (array $labor): array {
            $segments = collect($labor['work_segments'] ?? [])->sortBy('work_date')->map(fn (array $segment): array => [
                'work_date' => $segment['work_date'], 'actual_hours' => bcadd((string) $segment['actual_hours'], '0', 8),
            ])->values()->all();

            return ['employee_id' => (int) $labor['employee_id'], 'actual_hours' => bcadd((string) ($labor['actual_hours'] ?? 0), '0', 8), 'work_segments' => $segments];
        })->sortBy('employee_id')->values()->all();
    }

    /** @param EloquentCollection<int, ProductionRun>|Collection<int, ProductionRun> $runs @return Collection<int, bool> */
    private function laborValuationCompleteness(EloquentCollection|Collection $runs): Collection
    {
        $rules = OverheadAllocationRule::query()->whereIn('company_id', $runs->pluck('company_id')->unique())
            ->where('basis', OverheadAllocationRule::BasisDirectPayrollHours)->where('status', 'active')->get();
        $posted = OverheadAllocationRun::query()->whereIn('company_id', $runs->pluck('company_id')->unique())
            ->where('basis_used', OverheadAllocationRule::BasisDirectPayrollHours)->where('status', OverheadAllocationRun::StatusPosted)
            ->whereHas('lines', fn ($query) => $query->whereIn('production_run_id', $runs->pluck('id')))->get();
        $shares = $posted->flatMap(fn (OverheadAllocationRun $allocation): array => $allocation->policy_snapshot['direct_payroll_allocations'] ?? [])
            ->groupBy('production_run_id');

        return $runs->mapWithKeys(function (ProductionRun $run) use ($rules, $shares): array {
            $runShares = $shares->get($run->id, collect());
            $workDates = collect($run->labor_details ?? [])->flatMap(fn (array $worker): array => $worker['work_segments'] ?? [])->pluck('work_date');
            $laborFrom = $workDates->min() ?? $run->actual_start_at?->toDateString();
            $laborTo = $workDates->max() ?? $run->actual_end_at?->toDateString() ?? $run->progressEntries->last()?->recorded_at?->toDateString();
            $requiresLaborCost = $runShares->isNotEmpty() || $rules->contains(fn (OverheadAllocationRule $rule): bool => (int) $rule->company_id === (int) $run->company_id
                && ($rule->branch_id === null || (int) $rule->branch_id === (int) $run->branch_id)
                && ($laborTo === null || $rule->effective_from->toDateString() <= $laborTo)
                && ($laborFrom === null || $rule->effective_to === null || $rule->effective_to->toDateString() >= $laborFrom)
                && ($rule->target_cost_center_ids === null || in_array((int) $run->cost_center_id, array_map('intval', $rule->target_cost_center_ids), true)));
            if (! $requiresLaborCost) {
                return [$run->id => true];
            }
            $labor = collect($run->labor_details ?? []);
            if ($labor->isEmpty()) {
                return [$run->id => (int) $run->actual_labor_count === 0];
            }
            $complete = $labor->every(function (array $worker) use ($run, $runShares): bool {
                if (! is_numeric($worker['actual_hours'] ?? null) || bccomp((string) $worker['actual_hours'], '0', 8) <= 0) {
                    return false;
                }
                $workerShares = $runShares->where('employee_id', (int) $worker['employee_id']);
                $segments = collect($worker['work_segments'] ?? []);
                if ($segments->isEmpty()) {
                    return $workerShares->contains(fn (array $share): bool => empty($share['work_segments']) && bccomp((string) $share['hours'], (string) $worker['actual_hours'], 8) === 0
                        && $run->actual_start_at?->toDateString() >= $share['period_start']
                        && ($run->actual_end_at?->toDateString() ?? $run->progressEntries->last()?->recorded_at?->toDateString()) <= $share['period_end']);
                }

                return $segments->every(fn (array $segment): bool => $workerShares->contains(fn (array $share): bool => collect($share['work_segments'] ?? [])->contains(fn (array $covered): bool => $covered['work_date'] === $segment['work_date'] && bccomp((string) $covered['actual_hours'], (string) $segment['actual_hours'], 8) === 0)));
            });

            return [$run->id => $complete];
        });
    }
}

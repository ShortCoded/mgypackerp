<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Production\Models\ProductionProgressEntry;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionStageTransfer;

final class ProductionStageInputAdjustmentService
{
    /** @return array{quantity: string, cost: string, components: array<string, string>} */
    public function consumption(ProductionStageTransfer $owner, ?int $entryId = null): array
    {
        $components = app(ProductionStageCostComponentService::class);
        $result = ['quantity' => '0.00000000', 'cost' => '0.00000000', 'components' => $components->zero()];
        $uses = DB::table('production_stage_input_consumptions')->where('production_stage_transfer_id', $owner->id)
            ->when($entryId !== null, fn ($query) => $query->where('production_progress_entry_id', $entryId))->orderBy('id')->get();
        foreach ($uses as $use) {
            $parts = ($use->cost_components ?? null) === null ? [...$components->zero(), 'material' => (string) $use->total_cost]
                : json_decode($use->cost_components, true, flags: JSON_THROW_ON_ERROR);
            $result['quantity'] = bcadd($result['quantity'], (string) $use->base_quantity, 8);
            $result['cost'] = bcadd($result['cost'], (string) $use->total_cost, 8);
            $result['components'] = $components->add($result['components'], $parts);
        }
        if (Schema::hasTable('production_stage_input_adjustments')) {
            foreach (DB::table('production_stage_input_adjustments')->where('production_stage_transfer_id', $owner->id)
                ->when($entryId !== null, fn ($query) => $query->where('production_progress_entry_id', $entryId))->orderBy('id')->get() as $row) {
                $run = ProductionRun::query()->findOrFail($owner->target_run_id);
                $proposal = app(ProductionDailyReportCorrectionService::class)->assertApprovedCorrection($run, (int) $row->production_run_correction_id);
                if ((int) $proposal->company_id !== (int) $owner->company_id || (int) $proposal->financial_period_id !== (int) $owner->financial_period_id
                    || (int) DB::table('production_progress_entries')->where('id', $row->production_progress_entry_id)->value('production_run_id') !== (int) $run->id) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }
                $parts = json_decode($row->cost_components, true, flags: JSON_THROW_ON_ERROR);
                if (array_keys($parts) !== ProductionStageCostComponentService::Components) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }
                $sum = '0.00000000';
                foreach ($parts as $key => $part) {
                    $sum = bcadd($sum, (string) $part, 8);
                    $result['components'][$key] = bcadd($result['components'][$key], (string) $part, 8);
                }
                if (bccomp($sum, (string) $row->total_cost, 8) !== 0) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }
                $result['quantity'] = bcadd($result['quantity'], (string) $row->base_quantity, 8);
                $result['cost'] = bcadd($result['cost'], (string) $row->total_cost, 8);
            }
        }
        $result['components'] = $components->normalize($result['components']);
        if (bccomp($result['quantity'], '0', 8) < 0 || bccomp($result['cost'], '0', 8) < 0
            || bccomp($result['quantity'], (string) $owner->base_quantity, 8) > 0 || bccomp($result['cost'], (string) $owner->total_cost, 8) > 0
            || bccomp($components->sum($result['components']), $result['cost'], 8) !== 0) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function plan(ProductionRun $run, ProductionProgressEntry $entry, string $delta): array
    {
        $stage = app(ProductionStageTransferService::class);
        if (! $stage->isManaged($run) || ! $stage->requiresInput($run) || bccomp($delta, '0', 8) === 0) {
            return [];
        }
        if (! Schema::hasTable('production_stage_input_adjustments')) {
            throw new DomainException(__('production_stage_transfer.input_adjustment_migration_required'));
        }
        $stage->position($run);
        $components = app(ProductionStageCostComponentService::class);
        $negative = bccomp($delta, '0', 8) < 0;
        $remaining = $negative ? bcsub('0', $delta, 8) : $delta;
        $result = [];
        $owners = ProductionStageTransfer::query()->where('company_id', $run->company_id)->where('target_run_id', $run->id)
            ->where('status', 'posted')->orderBy('id', $negative ? 'desc' : 'asc')->get();
        foreach ($owners as $owner) {
            $used = $this->consumption($owner, $negative ? (int) $entry->id : null);
            $whole = $negative ? $used['quantity'] : bcsub((string) $owner->base_quantity, $used['quantity'], 8);
            if (bccomp($whole, '0', 8) <= 0 || bccomp($remaining, '0', 8) <= 0) {
                continue;
            }
            $pool = $negative ? $used['components'] : $components->subtract($owner->posting_snapshot['cost_components']
                ?? [...$components->zero(), 'material' => (string) $owner->total_cost], $used['components']);
            $quantity = bccomp($remaining, $whole, 8) <= 0 ? $remaining : $whole;
            $share = $components->share($pool, $quantity, $whole);
            $cost = $components->sum($share);
            if ($negative) {
                $share = array_map(fn (string $part): string => bcsub('0', $part, 8), $share);
            }
            $result[] = ['production_stage_transfer_id' => (int) $owner->id, 'production_progress_entry_id' => (int) $entry->id,
                'base_quantity' => $negative ? bcsub('0', $quantity, 8) : $quantity,
                'total_cost' => $negative ? bcsub('0', $cost, 8) : $cost, 'cost_components' => $share,
                'source_owner_seal' => $owner->proposal_seal];
            $remaining = bcsub($remaining, $quantity, 8);
        }
        if (bccomp($remaining, '0', 8) !== 0) {
            throw new DomainException(__('production_stage_transfer.input_required'));
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $plan @return list<array<string, mixed>> */
    public function apply(ProductionRun $run, ProductionProgressEntry $entry, int $correctionId, string $delta, array $plan): array
    {
        if (DB::transactionLevel() < 1 || $this->plan($run, $entry, $delta) !== $plan) {
            throw new DomainException(__('production_run_correction.stale'));
        }
        foreach ($plan as $slice) {
            unset($slice['source_owner_seal']);
            DB::table('production_stage_input_adjustments')->insert([...$slice, 'production_run_correction_id' => $correctionId,
                'cost_components' => json_encode($slice['cost_components'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $plan === [] ? [] : DB::table('production_stage_input_adjustments')->where('production_run_correction_id', $correctionId)
            ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }
}

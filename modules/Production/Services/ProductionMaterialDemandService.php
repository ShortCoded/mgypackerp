<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionMaterialRequestLine;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionRun;

class ProductionMaterialDemandService
{
    /** @return Collection<int, ProductionMaterialRequirement> */
    public function options(ProductionRun $run): Collection
    {
        $run->loadMissing(['requirements.product', 'requirements.unit', 'orderLine']);
        $requirements = collect($run->requirements->all());
        $missing = collect($run->orderLine->bom_snapshot['components'] ?? [])
            ->reject(fn (array $component): bool => $requirements->contains('product_component_id', $component['product_component_id']));
        $products = Product::withTrashed()->where('company_id', $run->company_id)->whereIn('id', $missing->pluck('product_id'))->get()->keyBy('id');
        $units = ItemUnit::withTrashed()->where('company_id', $run->company_id)->whereIn('id', $missing->pluck('base_unit_id'))->get()->keyBy('id');
        foreach ($missing as $component) {
            $requirement = new ProductionMaterialRequirement($this->attributes($run, $component));
            $requirement->setRelation('run', $run);
            $requirement->setRelation('product', $products->get($component['product_id']));
            $requirement->setRelation('unit', $units->get($component['base_unit_id']));
            $requirements->push($requirement);
        }

        return $requirements->values();
    }

    /**
     * Persist only selected component lineage; approval and issue remain separate stock actions.
     *
     * @param  array<int, string|int|float>  $quantities
     * @return array<int, string|int|float>
     */
    public function materializeSelections(ProductionRun $run, array $quantities): array
    {
        $run->loadMissing('orderLine');
        $components = collect($run->orderLine->bom_snapshot['components'] ?? [])->keyBy('product_component_id');
        $resolved = [];
        foreach ($quantities as $componentId => $quantity) {
            $component = $components->get((int) $componentId);
            if (! is_array($component) || bccomp((string) $quantity, '0', 8) <= 0) {
                throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
            }
            $requirement = $run->requirements()->where('product_component_id', $componentId)->first();
            if ($requirement === null) {
                if ($run->material_accounting_mode === ProductionOutputEvidenceService::Mode) {
                    throw new DomainException(__('production_execution.evidence.policy_changed'));
                }
                $requirement = $run->requirements()->create([
                    ...$this->attributes($run, $component),
                    'line_number' => (int) $run->requirements()->max('line_number') + 1,
                ]);
            }
            $resolved[(int) $requirement->id] = $quantity;
        }

        return $resolved;
    }

    public function remaining(ProductionMaterialRequirement $requirement, ?int $excludeRequestId = null): string
    {
        $run = $requirement->run;
        $run->loadMissing('orderLine');
        $credit = $this->unlinkedCredit($requirement, $excludeRequestId);
        $localCommitted = $requirement->exists ? $this->committed($this->requirements($run)->whereKey($requirement->id), $excludeRequestId) : '0';
        $localRemaining = $this->positive(bcadd(bcsub((string) $requirement->planned_quantity, $localCommitted, 8), $credit, 8));
        if ($requirement->product_component_id === null) {
            return $localRemaining;
        }
        $component = collect($run->orderLine->bom_snapshot['components'] ?? [])->firstWhere('product_component_id', $requirement->product_component_id);
        if (! is_array($component) || (int) $component['base_unit_id'] !== (int) $requirement->unit_id) {
            throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
        }
        $originalIds = $this->requirements($run)->where('product_component_id', $requirement->product_component_id)->pluck('id');
        $ledger = $this->requirements($run)->where(fn (Builder $query) => $query
            ->where('product_component_id', $requirement->product_component_id)
            ->orWhereIn('component_snapshot->approved_substitution->original_requirement_id', $originalIds));
        $cap = $this->planned($run, $component, (string) $run->orderLine->base_quantity);
        $remaining = $this->positive(bcadd(bcsub($cap, $this->committed($ledger, $excludeRequestId), 8), $credit, 8));

        return bccomp($localRemaining, $remaining, 8) < 0 ? $localRemaining : $remaining;
    }

    /** @return Builder<ProductionMaterialRequirement> */
    private function requirements(ProductionRun $run): Builder
    {
        return ProductionMaterialRequirement::query()->where('production_order_line_id', $run->production_order_line_id)
            ->whereHas('run', fn (Builder $query) => $query->where('company_id', $run->company_id)
                ->where('branch_id', $run->branch_id)->where('financial_period_id', $run->financial_period_id)
                ->where('status', '<>', ProductionRun::StatusCancelled));
    }

    /** @param Builder<ProductionMaterialRequirement> $requirements */
    private function committed(Builder $requirements, ?int $excludeRequestId): string
    {
        $rows = (clone $requirements)->get(['id', 'issued_quantity', 'returned_quantity']);
        $pending = ProductionMaterialRequestLine::query()->whereIn('production_material_requirement_id', $rows->modelKeys())
            ->whereHas('request', fn (Builder $query) => $query->where('request_type', 'planned')
                ->whereNotIn('status', [ProductionMaterialRequest::StatusRejected, ProductionMaterialRequest::StatusCancelled])
                ->when($excludeRequestId !== null, fn (Builder $query) => $query->whereKeyNot($excludeRequestId)))
            ->groupBy('production_material_requirement_id')
            ->selectRaw('production_material_requirement_id, coalesce(sum(case when requested_quantity > issued_quantity then requested_quantity - issued_quantity else 0 end), 0) as committed_quantity')
            ->pluck('committed_quantity', 'production_material_requirement_id');
        $unlinked = $this->unlinked()->whereIn('production_material_requirement_id', $rows->modelKeys())
            ->groupBy('production_material_requirement_id')->selectRaw('production_material_requirement_id, sum(remaining_quantity) as committed_quantity')
            ->pluck('committed_quantity', 'production_material_requirement_id');

        return $rows->reduce(function (string $total, ProductionMaterialRequirement $row) use ($pending, $unlinked): string {
            $issued = $this->positive(bcsub((string) $row->issued_quantity, (string) $row->returned_quantity, 8));
            $requested = (string) ($pending->get($row->id) ?? '0');
            $reserved = (string) ($unlinked->get($row->id) ?? '0');
            $held = bccomp($requested, $reserved, 8) > 0 ? $requested : $reserved;

            return bcadd($total, bcadd($issued, $held, 8), 8);
        }, '0.00000000');
    }

    private function unlinked(): \Illuminate\Database\Query\Builder
    {
        return DB::query()->fromSub(InventoryReservation::query()
            ->whereNull('production_material_request_line_id')->where('status', InventoryReservation::StatusActive)
            ->selectRaw('production_material_requirement_id, quantity - consumed_quantity - released_quantity as remaining_quantity'), 'held_material');
    }

    private function unlinkedCredit(ProductionMaterialRequirement $requirement, ?int $excludeRequestId): string
    {
        if (! $requirement->exists) {
            return '0.00000000';
        }
        $reserved = (string) $this->unlinked()->where('production_material_requirement_id', $requirement->id)->sum('remaining_quantity');
        $pending = (string) ProductionMaterialRequestLine::query()->where('production_material_requirement_id', $requirement->id)
            ->whereHas('request', fn (Builder $query) => $query->where('request_type', 'planned')
                ->whereNotIn('status', [ProductionMaterialRequest::StatusRejected, ProductionMaterialRequest::StatusCancelled])
                ->when($excludeRequestId !== null, fn (Builder $query) => $query->whereKeyNot($excludeRequestId)))
            ->selectRaw('coalesce(sum(case when requested_quantity > issued_quantity then requested_quantity - issued_quantity else 0 end), 0) as committed_quantity')->first()->committed_quantity;

        return $this->positive(bcsub($reserved, $pending, 8));
    }

    /** @param array<string, mixed> $component @return array<string, mixed> */
    private function attributes(ProductionRun $run, array $component): array
    {
        return ['production_order_id' => $run->production_order_id, 'production_order_line_id' => $run->production_order_line_id,
            'production_run_id' => $run->id, 'line_number' => 0, 'product_component_id' => $component['product_component_id'],
            'product_id' => $component['product_id'], 'unit_id' => $component['base_unit_id'], 'calculation_method' => $component['calculation_method'],
            'component_quantity_snapshot' => $component['base_quantity_per_output'],
            'planned_quantity' => $this->planned($run, $component, (string) $run->planned_base_quantity),
            'issued_quantity' => '0', 'component_snapshot' => $component];
    }

    /** @param array<string, mixed> $component */
    private function planned(ProductionRun $run, array $component, string $output): string
    {
        return bcmul((string) $component['base_quantity_per_output'], bcmul($output, (string) ($run->orderLine->bom_snapshot['basis_base_quantity'] ?? '1'), 8), 8);
    }

    private function positive(string $quantity): string
    {
        return bccomp($quantity, '0', 8) > 0 ? $quantity : '0.00000000';
    }
}

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
        $requirements = collect();
        $persisted = collect($run->requirements->all())->groupBy('product_component_id');

        foreach ($run->orderLine->bom_snapshot['components'] ?? [] as $component) {
            $componentRequirements = $persisted->get((int) $component['product_component_id'], collect());
            $choices = collect([['choice' => 'primary']])->merge(
                collect($component['alternatives'] ?? [])->map(fn (array $alternative): array => [
                    'choice' => (string) $alternative['public_id'],
                ]),
            );

            foreach ($choices as $choice) {
                $existing = $componentRequirements->first(
                    fn (ProductionMaterialRequirement $requirement): bool => $this->materialChoice($requirement) === $choice['choice'],
                );
                if ($existing instanceof ProductionMaterialRequirement) {
                    $requirements->push($existing);

                    continue;
                }

                try {
                    $attributes = $this->attributes($run, $component, $choice['choice']);
                } catch (DomainException) {
                    continue;
                }
                $requirement = new ProductionMaterialRequirement($attributes);
                $requirement->setRelation('run', $run);
                $requirement->setRelation('product', Product::withTrashed()->find($attributes['product_id']));
                $requirement->setRelation('unit', ItemUnit::withTrashed()->find($attributes['unit_id']));
                $requirements->push($requirement);
            }
        }

        return $requirements->values();
    }

    /**
     * Persist only selected component lineage; approval and issue remain separate stock actions.
     *
     * @param  list<array{product_component_id:int, material_choice:string, quantity:string|int|float}>  $allocations
     * @return array<int, string|int|float>
     */
    public function materializeSelections(
        ProductionRun $run,
        array $allocations,
    ): array {
        $resolved = [];
        foreach ($allocations as $allocation) {
            $componentId = (int) ($allocation['product_component_id'] ?? 0);
            $quantity = (string) ($allocation['quantity'] ?? '0');
            $choice = (string) ($allocation['material_choice'] ?? 'primary');
            if ($componentId <= 0 || bccomp($quantity, '0', 8) <= 0) {
                throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
            }
            $requirement = $this->allocationRequirement($run, $componentId, $choice);
            if (array_key_exists((int) $requirement->id, $resolved)) {
                throw new DomainException(__('production_execution.messages.material_allocation_duplicate'));
            }
            $resolved[(int) $requirement->id] = $allocation['quantity'];
        }

        return $resolved;
    }

    public function allocationRequirement(ProductionRun $run, int $componentId, string $materialChoice): ProductionMaterialRequirement
    {
        $run->loadMissing('orderLine');
        $component = collect($run->orderLine->bom_snapshot['components'] ?? [])->firstWhere('product_component_id', $componentId);
        if (! is_array($component)) {
            throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
        }

        $selection = $this->materialSelection($run, $component, $materialChoice);
        $requirements = $run->requirements()->where('product_component_id', $componentId)->orderBy('id')->lockForUpdate()->get();
        $existing = $requirements->first(fn (ProductionMaterialRequirement $requirement): bool => (int) $requirement->product_id === $selection['product_id']
            && (int) $requirement->unit_id === $selection['unit_id']
            && bccomp($requirement->conversionFactor(), $selection['conversion_factor'], 8) === 0
        );
        if ($existing instanceof ProductionMaterialRequirement) {
            return $existing;
        }
        if ($run->material_accounting_mode === ProductionOutputEvidenceService::Mode) {
            throw new DomainException(__('production_execution.messages.material_allocation_policy_frozen'));
        }

        $root = $requirements->first(
            fn (ProductionMaterialRequirement $requirement): bool => $requirement->allocation_root_requirement_id === null,
        ) ?? $requirements->first();

        return $run->requirements()->create([
            ...$this->attributes($run, $component, $materialChoice),
            'allocation_root_requirement_id' => $root?->getKey(),
            'line_number' => (int) $run->requirements()->max('line_number') + 1,
        ]);
    }

    /**
     * @param  array<int, string>  $choicesByRequirementId
     */
    public function selectRequirementMaterials(
        ProductionRun $run,
        array $choicesByRequirementId,
        ?int $excludeRequestId = null,
    ): void {
        if ($choicesByRequirementId === []) {
            return;
        }

        $run->loadMissing('orderLine');
        $components = collect($run->orderLine->bom_snapshot['components'] ?? [])->keyBy('product_component_id');
        $requirements = $run->requirements()->whereIn('id', array_map('intval', array_keys($choicesByRequirementId)))
            ->lockForUpdate()->get()->keyBy('id');
        if ($requirements->count() !== count($choicesByRequirementId)) {
            throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
        }

        foreach ($choicesByRequirementId as $requirementId => $choice) {
            $requirement = $requirements->get((int) $requirementId);
            $component = $requirements->get((int) $requirementId)?->product_component_id === null
                ? null
                : $components->get((int) $requirement->product_component_id);
            if (! $requirement instanceof ProductionMaterialRequirement || ! is_array($component)) {
                throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
            }

            $this->assertMaterialChoice($run, $requirement, $component, $choice);
        }
    }

    public function remaining(ProductionMaterialRequirement $requirement, ?int $excludeRequestId = null): string
    {
        $run = $requirement->run;
        $run->loadMissing('orderLine');
        $credit = $this->unlinkedCredit($requirement, $excludeRequestId);
        $localCommitted = $requirement->exists
            ? $this->committed($this->requirements($run)->whereKey($requirement->id), $excludeRequestId, false)
            : '0';
        $localRemaining = $this->positive(bcadd(bcsub((string) $requirement->planned_quantity, $localCommitted, 8), $credit, 8));
        if ($requirement->product_component_id === null) {
            return $localRemaining;
        }
        $component = collect($run->orderLine->bom_snapshot['components'] ?? [])->firstWhere('product_component_id', $requirement->product_component_id);
        if (! is_array($component)
            || (int) $component['base_unit_id'] !== (int) ($requirement->original_unit_id ?? $requirement->unit_id)
        ) {
            throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
        }
        $originalIds = $this->requirements($run)->where('product_component_id', $requirement->product_component_id)->pluck('id');
        $ledger = $this->requirements($run)->where(fn (Builder $query) => $query
            ->where('product_component_id', $requirement->product_component_id)
            ->orWhereIn('component_snapshot->approved_substitution->original_requirement_id', $originalIds));
        $cap = $this->planned($run, $component, (string) $run->orderLine->base_quantity);
        $remainingOriginal = $this->positive(bcadd(
            bcsub($cap, $this->committed($ledger, $excludeRequestId), 8),
            $requirement->toOriginalQuantity($credit),
            8,
        ));
        $remaining = $requirement->toActualQuantity($remainingOriginal);

        return bccomp($localRemaining, $remaining, 8) < 0 ? $localRemaining : $remaining;
    }

    /**
     * Validate a complete planned-request payload against one locked component budget.
     *
     * @param  array<int, string|int|float>  $quantitiesByRequirementId
     */
    public function assertPlannedRequestBudget(
        ProductionRun $run,
        array $quantitiesByRequirementId,
        ?int $excludeRequestId = null,
    ): void {
        $run->loadMissing('orderLine');
        $quantities = collect($quantitiesByRequirementId)
            ->mapWithKeys(fn (mixed $quantity, int|string $requirementId): array => [
                (int) $requirementId => bcadd((string) $quantity, '0', 8),
            ])
            ->filter(fn (string $quantity): bool => bccomp($quantity, '0', 8) > 0);

        if ($quantities->isEmpty()) {
            return;
        }

        $requirements = $this->requirements($run)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $selected = $requirements->only($quantities->keys()->all());
        if ($selected->count() !== $quantities->count()
            || $selected->contains(fn (ProductionMaterialRequirement $requirement): bool => (int) $requirement->production_run_id !== (int) $run->getKey())
        ) {
            throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
        }

        foreach ($selected as $requirement) {
            $quantity = (string) $quantities->get($requirement->getKey());
            $credit = $this->unlinkedCredit($requirement, $excludeRequestId);
            $localCommitted = $this->committed(
                $this->requirements($run)->whereKey($requirement->getKey()),
                $excludeRequestId,
                false,
            );
            $localRemaining = $this->positive(bcadd(
                bcsub((string) $requirement->planned_quantity, $localCommitted, 8),
                $credit,
                8,
            ));
            if (bccomp($quantity, $localRemaining, 8) > 0) {
                throw new DomainException(__('production_execution.messages.material_request_exceeds_bom'));
            }
        }

        $components = collect($run->orderLine->bom_snapshot['components'] ?? [])->keyBy('product_component_id');
        foreach ($selected->whereNotNull('product_component_id')->groupBy('product_component_id') as $componentId => $componentRequirements) {
            $component = $components->get((int) $componentId);
            if (! is_array($component)
                || $componentRequirements->contains(fn (ProductionMaterialRequirement $requirement): bool => (int) ($requirement->original_unit_id ?? $requirement->unit_id) !== (int) $component['base_unit_id'])
            ) {
                throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
            }

            $originalIds = $requirements->where('product_component_id', (int) $componentId)->keys();
            $ledger = $this->requirements($run)->where(fn (Builder $query) => $query
                ->where('product_component_id', (int) $componentId)
                ->orWhereIn('component_snapshot->approved_substitution->original_requirement_id', $originalIds->all()));
            $cap = $this->planned($run, $component, (string) $run->orderLine->base_quantity);
            $credit = $componentRequirements->reduce(function (string $total, ProductionMaterialRequirement $requirement) use ($excludeRequestId, $quantities): string {
                $requested = (string) $quantities->get($requirement->getKey());
                $availableCredit = $this->unlinkedCredit($requirement, $excludeRequestId);
                $applicableCredit = bccomp($availableCredit, $requested, 8) > 0 ? $requested : $availableCredit;

                return bcadd($total, $requirement->toOriginalQuantity($applicableCredit), 8);
            }, '0.00000000');
            $remaining = $this->positive(bcadd(
                bcsub($cap, $this->committed($ledger, $excludeRequestId), 8),
                $credit,
                8,
            ));
            $requested = $componentRequirements->reduce(
                fn (string $total, ProductionMaterialRequirement $requirement): string => bcadd(
                    $total,
                    $requirement->toOriginalQuantity((string) $quantities->get($requirement->getKey())),
                    8,
                ),
                '0.00000000',
            );
            if (bccomp($requested, $remaining, 8) > 0) {
                throw new DomainException(__('production_execution.messages.material_request_exceeds_bom'));
            }
        }
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
    private function committed(Builder $requirements, ?int $excludeRequestId, bool $convertToOriginal = true): string
    {
        $rows = (clone $requirements)->get(['id', 'issued_quantity', 'additional_issued_quantity', 'returned_quantity', 'material_conversion_factor']);
        $pending = ProductionMaterialRequestLine::query()->whereIn('production_material_requirement_id', $rows->modelKeys())
            ->whereHas('request', fn (Builder $query) => $query->where('request_type', 'planned')
                ->whereNotIn('status', [ProductionMaterialRequest::StatusRejected, ProductionMaterialRequest::StatusCancelled])
                ->when($excludeRequestId !== null, fn (Builder $query) => $query->whereKeyNot($excludeRequestId)))
            ->groupBy('production_material_requirement_id')
            ->selectRaw('production_material_requirement_id, coalesce(sum(case when requested_quantity > issued_quantity + reallocated_quantity then requested_quantity - issued_quantity - reallocated_quantity else 0 end), 0) as committed_quantity')
            ->pluck('committed_quantity', 'production_material_requirement_id');
        $unlinked = $this->unlinked()->whereIn('production_material_requirement_id', $rows->modelKeys())
            ->groupBy('production_material_requirement_id')->selectRaw('production_material_requirement_id, sum(remaining_quantity) as committed_quantity')
            ->pluck('committed_quantity', 'production_material_requirement_id');

        return $rows->reduce(function (string $total, ProductionMaterialRequirement $row) use ($pending, $unlinked, $convertToOriginal): string {
            $plannedReturn = $this->positive(bcsub((string) $row->returned_quantity, (string) $row->additional_issued_quantity, 8));
            $issued = $this->positive(bcsub((string) $row->issued_quantity, $plannedReturn, 8));
            $requested = (string) ($pending->get($row->id) ?? '0');
            $reserved = (string) ($unlinked->get($row->id) ?? '0');
            $held = bccomp($requested, $reserved, 8) > 0 ? $requested : $reserved;

            $committed = bcadd($issued, $held, 8);

            return bcadd($total, $convertToOriginal ? $row->toOriginalQuantity($committed) : $committed, 8);
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
            ->selectRaw('coalesce(sum(case when requested_quantity > issued_quantity + reallocated_quantity then requested_quantity - issued_quantity - reallocated_quantity else 0 end), 0) as committed_quantity')->first()->committed_quantity;

        return $this->positive(bcsub($reserved, $pending, 8));
    }

    /** @param array<string, mixed> $component @return array<string, mixed> */
    private function attributes(ProductionRun $run, array $component, string $materialChoice = 'primary'): array
    {
        $selection = $this->materialSelection($run, $component, $materialChoice);
        $originalPlanned = $this->planned($run, $component, (string) $run->planned_base_quantity);

        return ['production_order_id' => $run->production_order_id, 'production_order_line_id' => $run->production_order_line_id,
            'production_run_id' => $run->id, 'line_number' => 0, 'product_component_id' => $component['product_component_id'],
            'original_product_id' => $component['product_id'], 'product_id' => $selection['product_id'],
            'original_unit_id' => $component['base_unit_id'], 'unit_id' => $selection['unit_id'],
            'calculation_method' => $component['calculation_method'],
            'component_quantity_snapshot' => bcmul((string) $component['base_quantity_per_output'], $selection['conversion_factor'], 8),
            'original_planned_quantity' => $originalPlanned, 'material_conversion_factor' => $selection['conversion_factor'],
            'planned_quantity' => bcmul($originalPlanned, $selection['conversion_factor'], 8),
            'issued_quantity' => '0', 'component_snapshot' => [...$component, 'selected_material' => $selection]];
    }

    /** @param array<string, mixed> $component */
    private function assertMaterialChoice(
        ProductionRun $run,
        ProductionMaterialRequirement $requirement,
        array $component,
        string $materialChoice,
    ): void {
        $selection = $this->materialSelection($run, $component, $materialChoice);
        $factor = $requirement->conversionFactor();
        if ((int) $requirement->product_id === $selection['product_id']
            && (int) $requirement->unit_id === $selection['unit_id']
            && bccomp($factor, $selection['conversion_factor'], 8) === 0
        ) {
            return;
        }

        throw new DomainException(__('production_execution.messages.material_choice_locked'));
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array{kind:string, alternative_public_id:string|null, product_id:int, product_doc_num:string, product_name:string, unit_id:int, unit_name:string, conversion_factor:string}
     */
    private function materialSelection(ProductionRun $run, array $component, string $materialChoice): array
    {
        $choice = trim($materialChoice);
        if ($choice === '' || $choice === 'primary') {
            $candidate = [
                'kind' => 'primary',
                'alternative_public_id' => null,
                'product_id' => (int) $component['product_id'],
                'product_doc_num' => (string) $component['product_doc_num'],
                'product_name' => (string) $component['product_name'],
                'unit_id' => (int) $component['base_unit_id'],
                'unit_name' => (string) ($component['base_unit_name'] ?? ''),
                'conversion_factor' => '1.00000000',
            ];
        } else {
            $alternative = collect($component['alternatives'] ?? [])->firstWhere('public_id', $choice);
            if (! is_array($alternative)) {
                throw new DomainException(__('production_execution.messages.material_choice_not_approved'));
            }
            $candidate = [
                'kind' => 'alternative',
                'alternative_public_id' => (string) $alternative['public_id'],
                'product_id' => (int) $alternative['product_id'],
                'product_doc_num' => (string) $alternative['product_doc_num'],
                'product_name' => (string) $alternative['product_name'],
                'unit_id' => (int) $alternative['base_unit_id'],
                'unit_name' => (string) ($alternative['base_unit_name'] ?? ''),
                'conversion_factor' => bcadd((string) $alternative['conversion_factor'], '0', 8),
            ];
        }

        $product = Product::query()->where('company_id', $run->company_id)->active()->whereKey($candidate['product_id'])->first();
        $unit = ItemUnit::query()->where('company_id', $run->company_id)->whereKey($candidate['unit_id'])->first();
        if (! $product instanceof Product || ! $unit instanceof ItemUnit
            || $unit->status !== 'active'
            || (int) $product->item_unit_id !== (int) $candidate['unit_id']
            || ($candidate['kind'] === 'alternative' && $product->item_classification !== Product::ClassificationRawMaterial)
            || bccomp($candidate['conversion_factor'], '0', 8) <= 0
        ) {
            throw new DomainException(__('production_execution.messages.material_choice_unavailable'));
        }

        return $candidate;
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

    private function materialChoice(ProductionMaterialRequirement $requirement): string
    {
        $selection = $requirement->component_snapshot['selected_material'] ?? [];

        return filled($selection['alternative_public_id'] ?? null)
            ? (string) $selection['alternative_public_id']
            : 'primary';
    }
}

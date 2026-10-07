<?php

namespace Modules\Production\Services;

use DomainException;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionRun;

class ProductionOutputEvidenceService
{
    public const Mode = 'output_evidence';

    public const BasisOutputComponents = 'output_components';

    public const BasisMeasuredMaterial = 'measured_material';

    public const StructureFactoryWorkflow = 'factory_workflow';

    public const StructurePhysicalRoute = 'physical_route';

    /**
     * @param  list<array{requirement_public_id: string, basis: string}>  $definitions
     * @return array<string, mixed>
     */
    public function policy(ProductionRun $run, array $definitions, string $executionStructure = self::StructurePhysicalRoute, array $stageRoles = []): array
    {
        $requirements = $run->requirements->keyBy('public_id');
        $policy = [];
        foreach ($definitions as $definition) {
            $publicId = (string) ($definition['requirement_public_id'] ?? '');
            $requirement = $requirements->get($publicId);
            $basis = $definition['basis'] ?? null;
            if (! $requirement instanceof ProductionMaterialRequirement || isset($policy[$publicId])
                || ! in_array($basis, [self::BasisOutputComponents, self::BasisMeasuredMaterial], true)
                || bccomp((string) $requirement->planned_quantity, '0', 8) <= 0) {
                throw new DomainException(__('production_execution.evidence.policy_invalid'));
            }
            $policy[$publicId] = [
                'requirement_id' => (int) $requirement->id,
                'product_id' => (int) $requirement->product_id,
                'unit_id' => (int) $requirement->unit_id,
                'planned_quantity' => (string) $requirement->planned_quantity,
                'component_quantity_snapshot' => (string) $requirement->component_quantity_snapshot,
                'basis' => $basis,
            ];
        }
        if (count($policy) !== $requirements->count() || bccomp((string) $run->planned_base_quantity, '0', 8) <= 0) {
            throw new DomainException(__('production_execution.evidence.policy_invalid'));
        }

        $stages = $run->order->orderStageSnapshots->where('is_required', true)->sortBy('sequence')->values();
        $lineStages = $run->orderLine->stageSnapshots->where('is_required', true);
        if (! in_array($executionStructure, [self::StructureFactoryWorkflow, self::StructurePhysicalRoute], true)) {
            throw new DomainException(__('production_execution.evidence.structure_invalid'));
        }
        if ($executionStructure === self::StructureFactoryWorkflow) {
            $expected = collect($run->orderLine->bom_snapshot['components'] ?? [])->pluck('product_component_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
            $actual = $requirements->pluck('product_component_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
            if ($lineStages->isNotEmpty() || $stages->isEmpty() || $expected !== $actual
                || $requirements->contains(fn (ProductionMaterialRequirement $requirement): bool => filled($requirement->component_snapshot['production_stage_id'] ?? null))) {
                throw new DomainException(__('production_execution.evidence.factory_structure_invalid'));
            }
            $roles = collect($stageRoles)->keyBy('stage_public_id');
            if ($roles->count() !== count($stageRoles) || $roles->keys()->sort()->values()->all() !== $stages->pluck('public_id')->sort()->values()->all()
                || $roles->contains(fn (array $row): bool => ! in_array($row['role'] ?? null, ['checklist', 'manufacturing', 'quality_notification', 'quality', 'receipt'], true))
                || $roles->where('role', 'manufacturing')->count() !== 1
                || $roles->where('role', 'receipt')->isEmpty()) {
                throw new DomainException(__('production_execution.evidence.factory_structure_invalid'));
            }
            $manufacturing = $stages->firstWhere('public_id', $roles->where('role', 'manufacturing')->keys()->sole());
            if ($stages->contains(fn ($stage): bool => $stage->sequence < $manufacturing->sequence && ! in_array($roles[$stage->public_id]['role'], ['checklist', 'quality_notification'], true))
                || $stages->contains(fn ($stage): bool => in_array($roles[$stage->public_id]['role'], ['quality', 'receipt'], true) && $stage->sequence <= $manufacturing->sequence)) {
                throw new DomainException(__('production_execution.evidence.factory_structure_invalid'));
            }
        }

        $result = ['version' => 1, 'requirements' => $policy, 'execution_structure' => $executionStructure, 'stage_roles' => $stageRoles,
            'workflow_stage_fingerprint' => $executionStructure === self::StructureFactoryWorkflow ? $this->workflowStageFingerprint($run) : null];
        if ($executionStructure === self::StructurePhysicalRoute && app(ProductionStageTransferService::class)->stages($run)->count() > 1) {
            app(ProductionStageTransferService::class)->requireSchema();
            $result['stage_transfer_version'] = 1;
            $result['physical_stage_fingerprint'] = app(ProductionStageTransferService::class)->routeFingerprint($run);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{requirement_id: int, requirement_public_id: string, basis: string, consumed_quantity: string, waste_quantity: string, waste_classification: ?string, notes: ?string, consumed_receipt_layer_ids: list<int>, waste_receipt_layer_ids: list<int>}>
     */
    public function materials(ProductionRun $run, array $data): array
    {
        $definitions = $run->material_evidence_policy['requirements'] ?? [];
        $requirements = $run->requirements->keyBy('public_id');
        $submitted = [];
        foreach ($data['material_evidence'] ?? [] as $row) {
            $publicId = (string) ($row['requirement_public_id'] ?? '');
            if (! $requirements->has($publicId) || isset($submitted[$publicId])) {
                throw new DomainException(__('production_execution.evidence.policy_invalid'));
            }
            $submitted[$publicId] = $row;
        }
        if (count($definitions) !== $requirements->count()) {
            throw new DomainException(__('production_execution.evidence.policy_invalid'));
        }
        $good = $this->quantity($data['good_base_quantity'] ?? '0');
        $nextGood = bcadd((string) $run->good_base_quantity, $good, 8);
        if (app(ProductionStageTransferService::class)->isManaged($run)) {
            foreach (['rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity'] as $field) {
                $good = bcadd($good, $this->quantity($data[$field] ?? '0'), 8);
                $nextGood = bcadd($nextGood, bcadd((string) $run->{$field}, $this->quantity($data[$field] ?? '0'), 8), 8);
            }
        }
        $resolved = [];
        foreach ($requirements as $publicId => $requirement) {
            $definition = $definitions[$publicId] ?? [];
            if ((int) ($definition['product_id'] ?? 0) !== (int) $requirement->product_id
                || (int) ($definition['unit_id'] ?? 0) !== (int) $requirement->unit_id
                || ($definition['planned_quantity'] ?? null) !== (string) $requirement->planned_quantity
                || ($definition['component_quantity_snapshot'] ?? null) !== (string) $requirement->component_quantity_snapshot) {
                throw new DomainException(__('production_execution.evidence.policy_changed'));
            }
            $row = $submitted[$publicId] ?? [];
            $basis = $definition['basis'];
            if ($basis === self::BasisOutputComponents) {
                $target = bcdiv(bcmul((string) $requirement->planned_quantity, $nextGood, 16), (string) $run->planned_base_quantity, 8);
                $used = bcsub($target, (string) $requirement->consumed_quantity, 8);
                if (filled($row['measured_quantity'] ?? null)) {
                    throw new DomainException(__('production_execution.evidence.measured_basis_required'));
                }
            } elseif ($basis === self::BasisMeasuredMaterial) {
                $used = $this->quantity($row['measured_quantity'] ?? '0');
                if (bccomp($good, '0', 8) > 0 && bccomp($used, '0', 8) <= 0) {
                    throw new DomainException(__('production_execution.evidence.measured_quantity_required'));
                }
            } else {
                throw new DomainException(__('production_execution.evidence.policy_invalid'));
            }
            $waste = $this->quantity($row['waste_quantity'] ?? '0');
            if (bccomp($used, '0', 8) > 0 && bccomp($good, '0', 8) <= 0) {
                throw new DomainException(__('production_execution.evidence.material_usage_requires_output'));
            }
            $wasteDetails = $this->wasteDetails($row, $waste);
            $available = bcsub(bcsub(bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8), (string) $requirement->returned_quantity, 8), bcadd((string) $requirement->consumed_quantity, (string) $requirement->waste_quantity, 8), 8);
            if (bccomp($used, '0', 8) < 0 || bccomp(bcadd($used, $waste, 8), $available, 8) > 0) {
                throw new DomainException(__('production_execution.evidence.material_capacity_exceeded'));
            }
            $resolved[] = [
                'requirement_id' => (int) $requirement->id,
                'requirement_public_id' => (string) $publicId,
                'basis' => $basis,
                'material_name' => $requirement->product?->name,
                'unit_name' => $requirement->unit?->name,
                'consumed_quantity' => $used,
                'waste_quantity' => $waste,
                ...$wasteDetails,
                'consumed_receipt_layer_ids' => array_map('intval', $row['consumed_receipt_layer_ids'] ?? []),
                'waste_receipt_layer_ids' => array_map('intval', $row['waste_receipt_layer_ids'] ?? []),
            ];
        }

        return $resolved;
    }

    /** @param array<string, mixed> $row @return array{waste_classification: ?string, notes: ?string} */
    public function wasteDetails(array $row, string $waste): array
    {
        $classification = $row['waste_classification'] ?? null;
        if (bccomp($waste, '0', 8) > 0 && (! in_array($classification, ['process_scrap', 'packaging_loss', 'roll_trim', 'rejected_output'], true)
            || blank($row['notes'] ?? null))) {
            throw new DomainException(__('production_execution.evidence.waste_details_required'));
        }

        return ['waste_classification' => bccomp($waste, '0', 8) > 0 ? $classification : null,
            'notes' => filled($row['notes'] ?? null) ? trim($row['notes']) : null];
    }

    public function quantity(mixed $value): string
    {
        $quantity = (string) $value;
        if (! preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', $quantity)) {
            throw new DomainException(__('production_execution.evidence.quantity_invalid'));
        }

        return bcadd($quantity, '0', 8);
    }

    public function isFactoryWorkflow(ProductionRun $run): bool
    {
        if ($run->material_accounting_mode !== self::Mode || ($run->material_evidence_policy['execution_structure'] ?? null) !== self::StructureFactoryWorkflow) {
            return false;
        }
        if (($run->material_evidence_policy['workflow_stage_fingerprint'] ?? null) !== $this->workflowStageFingerprint($run)
            || $run->orderLine->stageSnapshots->where('is_required', true)->isNotEmpty()) {
            throw new DomainException(__('production_execution.evidence.policy_changed'));
        }

        return true;
    }

    private function workflowStageFingerprint(ProductionRun $run): string
    {
        return hash('sha256', $run->order->orderStageSnapshots->where('is_required', true)->sortBy('sequence')->map(fn ($stage): array => [
            'id' => (int) $stage->id, 'production_stage_id' => (int) $stage->production_stage_id,
            'sequence' => (int) $stage->sequence, 'stage_name' => $stage->stage_name,
        ])->values()->toJson());
    }
}

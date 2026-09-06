<?php

namespace Modules\Production\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryReservationService;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionProgressEntry;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\QualityInspectionType;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\SalesUnitConversionService;

class ProductionCycleService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly SalesUnitConversionService $units,
        private readonly InventoryReservationService $reservations,
        private readonly InventoryMovementService $movements,
        private readonly ProductionCostService $costs,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function createMakeToStockOrder(array $header, array $lines): ProductionOrder
    {
        return DB::transaction(function () use ($header, $lines): ProductionOrder {
            if ($lines === []) {
                throw new DomainException(__('A production order requires at least one finished-product line.'));
            }

            $numbers = $this->documents->nextForCompany(
                'production_orders',
                ProductionOrder::class,
                (int) $header['company_id'],
                fn ($query) => $query->where('financial_period_id', $header['financial_period_id']),
            );
            $order = ProductionOrder::query()->create([
                ...$numbers,
                'company_id' => $header['company_id'],
                'financial_period_id' => $header['financial_period_id'],
                'branch_id' => $header['branch_id'],
                'source_type' => 'make_to_stock',
                'production_order_date' => $header['production_order_date'] ?? now()->toDateString(),
                'expected_start_date' => $header['expected_start_date'] ?? null,
                'expected_finish_date' => $header['expected_finish_date'] ?? null,
                'priority' => $header['priority'] ?? 'normal',
                'overproduction_tolerance_percent' => $header['overproduction_tolerance_percent'] ?? 0,
                'status' => ProductionOrder::StatusDraft,
                'production_notes' => $header['production_notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach (array_values($lines) as $index => $input) {
                $product = Product::query()->lockForUpdate()->findOrFail($input['product_id']);

                if ((int) $product->company_id !== (int) $order->company_id
                    || $product->item_classification !== Product::ClassificationFinishedProduct) {
                    throw new DomainException(__('Make-to-stock production lines must use a finished product from the operating company.'));
                }

                $snapshot = $this->units->snapshot($product, $input['unit_id'] ?? null, $input['quantity']);

                if (bccomp($snapshot['base_quantity'], '0', 8) <= 0) {
                    throw new DomainException(__('Production quantity must be greater than zero.'));
                }

                $order->lines()->create([
                    'line_number' => $index + 1,
                    'product_id' => $product->getKey(),
                    'unit_id' => $snapshot['unit_id'],
                    'description' => $input['description'] ?? $product->name,
                    'quantity' => $input['quantity'],
                    'conversion_factor' => $snapshot['conversion_factor'],
                    'base_quantity' => $snapshot['base_quantity'],
                    'specifications' => $input['specifications'] ?? null,
                    'production_notes' => $input['production_notes'] ?? null,
                    'mandatory_specs_resolved' => true,
                ]);
            }

            return $order->load('lines.product');
        });
    }

    public function releaseOrder(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order): ProductionOrder {
            $locked = ProductionOrder::query()->with('lines.product')->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status === ProductionOrder::StatusReleased) {
                return $locked;
            }

            if (! in_array($locked->status, [ProductionOrder::StatusDraft, ProductionOrder::StatusPlanned], true)) {
                throw new DomainException(__('Only a draft or planned production order can be released.'));
            }

            if ($locked->lines->isEmpty()) {
                throw new DomainException(__('A production order requires at least one line before release.'));
            }

            foreach ($locked->lines as $line) {
                $components = ProductComponent::query()
                    ->forCompany((int) $locked->company_id)
                    ->where('product_id', $line->product_id)
                    ->with(['componentProduct.unit', 'unit'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($components->isEmpty()) {
                    throw new DomainException(__('Product :product does not have a bill of materials.', ['product' => $line->description]));
                }

                $snapshotComponents = $components->map(function (ProductComponent $component): array {
                    $componentProduct = $component->componentProduct;
                    $unitSnapshot = $this->units->snapshot(
                        $componentProduct,
                        $component->unit_id,
                        (string) $component->quantity,
                    );

                    return [
                        'product_component_id' => $component->getKey(),
                        'public_id' => $component->public_id,
                        'product_id' => $component->component_product_id,
                        'product_doc_num' => $componentProduct->doc_num,
                        'product_name' => $componentProduct->name,
                        'source_unit_id' => $component->unit_id,
                        'base_unit_id' => $unitSnapshot['base_unit_id'],
                        'calculation_method' => $component->calculation_method,
                        'quantity' => (string) $component->quantity,
                        'base_quantity_per_output' => $unitSnapshot['base_quantity'],
                        'percentage' => $component->percentage,
                        'reference_component_id' => $component->reference_component_id,
                    ];
                })->all();

                $line->update([
                    'bom_snapshot' => [
                        'captured_at' => now()->toIso8601String(),
                        'finished_product_id' => $line->product_id,
                        'basis_base_quantity' => '1.00000000',
                        'components' => $snapshotComponents,
                    ],
                ]);
            }

            $locked->update([
                'status' => ProductionOrder::StatusReleased,
                'released_by' => auth()->id(),
                'released_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load('lines');
        });
    }

    /** @param array<string, mixed> $data */
    public function createRun(ProductionOrderLine $orderLine, array $data): ProductionRun
    {
        return DB::transaction(function () use ($orderLine, $data): ProductionRun {
            $line = ProductionOrderLine::query()->with('order')->lockForUpdate()->findOrFail($orderLine->getKey());
            $order = $line->order;

            if (! in_array($order->status, [
                ProductionOrder::StatusReleased,
                ProductionOrder::StatusInProgress,
                ProductionOrder::StatusPartiallyCompleted,
            ], true)) {
                throw new DomainException(__('Production runs require a released production order.'));
            }

            if (! is_array($line->bom_snapshot) || empty($line->bom_snapshot['components'])) {
                throw new DomainException(__('The released line does not contain an immutable BOM snapshot.'));
            }

            $plannedQuantity = (string) $data['planned_quantity'];
            $plannedBaseQuantity = bcmul($plannedQuantity, (string) $line->conversion_factor, 8);
            $alreadyPlanned = (string) ProductionRun::query()
                ->where('production_order_line_id', $line->getKey())
                ->where('status', '<>', ProductionRun::StatusCancelled)
                ->sum('planned_base_quantity');
            $remaining = bcsub((string) $line->base_quantity, $alreadyPlanned, 8);

            if (bccomp($plannedBaseQuantity, '0', 8) <= 0 || bccomp($plannedBaseQuantity, $remaining, 8) > 0) {
                throw new DomainException(__('Run quantity must be positive and cannot exceed the unplanned production quantity.'));
            }

            $startsAt = CarbonImmutable::parse($data['planned_start_at']);
            $endsAt = CarbonImmutable::parse($data['planned_end_at']);

            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw new DomainException(__('Run end time must be after its start time.'));
            }

            $machineId = isset($data['production_machine_id']) ? (int) $data['production_machine_id'] : null;
            $moldId = isset($data['production_mold_id']) ? (int) $data['production_mold_id'] : null;
            $this->assertResources($order, (int) $line->product_id, $machineId, $moldId, $startsAt, $endsAt);
            $runSequence = ProductionRun::query()
                ->where('production_order_id', $order->getKey())
                ->lockForUpdate()
                ->get(['id'])
                ->count() + 1;
            $run = ProductionRun::query()->create([
                'run_number' => sprintf('%s-R%03d', $order->doc_num, $runSequence),
                'company_id' => $order->company_id,
                'financial_period_id' => $order->financial_period_id,
                'branch_id' => $order->branch_id,
                'production_order_id' => $order->getKey(),
                'production_order_line_id' => $line->getKey(),
                'product_id' => $line->product_id,
                'unit_id' => $line->unit_id,
                'conversion_factor' => $line->conversion_factor,
                'planned_quantity' => $plannedQuantity,
                'planned_base_quantity' => $plannedBaseQuantity,
                'planned_start_at' => $startsAt,
                'planned_end_at' => $endsAt,
                'production_shift_id' => $data['production_shift_id'] ?? null,
                'production_machine_id' => $machineId,
                'cost_center_id' => $this->runCostCenterId($data, $machineId, (int) $order->company_id),
                'production_mold_id' => $moldId,
                'batch_lot' => $data['batch_lot'] ?? null,
                'status' => ProductionRun::StatusPlanned,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach (array_values($line->bom_snapshot['components']) as $index => $component) {
                $plannedMaterial = bcmul((string) $component['base_quantity_per_output'], $plannedBaseQuantity, 8);
                $run->requirements()->create([
                    'production_order_id' => $order->getKey(),
                    'production_order_line_id' => $line->getKey(),
                    'line_number' => $index + 1,
                    'product_component_id' => $component['product_component_id'],
                    'product_id' => $component['product_id'],
                    'unit_id' => $component['base_unit_id'],
                    'calculation_method' => $component['calculation_method'],
                    'component_quantity_snapshot' => $component['base_quantity_per_output'],
                    'planned_quantity' => $plannedMaterial,
                    'component_snapshot' => $component,
                ]);
            }

            return $run->load(['requirements.product', 'order', 'orderLine']);
        });
    }

    public function reserveRun(ProductionRun $run, int $branchStoreId, ?int $warehouseLocationId = null): ProductionRun
    {
        return DB::transaction(function () use ($run, $branchStoreId, $warehouseLocationId): ProductionRun {
            $locked = ProductionRun::query()->with('requirements')->lockForUpdate()->findOrFail($run->getKey());

            if (! in_array($locked->status, [ProductionRun::StatusPlanned, ProductionRun::StatusSetup, ProductionRun::StatusReady], true)) {
                throw new DomainException(__('Materials can only be reserved before the run starts.'));
            }

            foreach ($locked->requirements as $requirement) {
                $remaining = bcsub((string) $requirement->planned_quantity, (string) $requirement->reserved_quantity, 8);

                if (bccomp($remaining, '0', 8) > 0) {
                    $this->reservations->reserveForProduction($requirement, $branchStoreId, $remaining, $warehouseLocationId);
                }
            }

            return $locked->refresh()->load(['requirements', 'order']);
        });
    }

    /** @param array<int, string|int|float> $quantitiesByRequirementId */
    public function issueMaterials(
        ProductionRun $run,
        int $branchStoreId,
        array $quantitiesByRequirementId = [],
        bool $additional = false,
        ?int $warehouseLocationId = null,
    ): InventoryDocument {
        return DB::transaction(function () use ($run, $branchStoreId, $quantitiesByRequirementId, $additional, $warehouseLocationId): InventoryDocument {
            $locked = ProductionRun::query()->with(['requirements', 'order'])->lockForUpdate()->findOrFail($run->getKey());

            if (! in_array($locked->status, [
                ProductionRun::StatusPlanned,
                ProductionRun::StatusSetup,
                ProductionRun::StatusReady,
                ProductionRun::StatusRunning,
                ProductionRun::StatusHeld,
            ], true)) {
                throw new DomainException(__('This run cannot receive a material issue.'));
            }

            $movementLines = [];
            $issuedRequirements = [];

            foreach ($locked->requirements as $requirement) {
                $defaultQuantity = $additional
                    ? '0'
                    : bcsub((string) $requirement->planned_quantity, (string) $requirement->issued_quantity, 8);
                $quantity = (string) ($quantitiesByRequirementId[$requirement->getKey()] ?? $defaultQuantity);

                if (bccomp($quantity, '0', 8) <= 0) {
                    continue;
                }

                if (! $additional && bccomp($quantity, $defaultQuantity, 8) > 0) {
                    throw new DomainException(__('A planned material issue cannot exceed the remaining planned requirement.'));
                }

                if ($additional) {
                    $this->reservations->reserveForProduction(
                        $requirement,
                        $branchStoreId,
                        $quantity,
                        $warehouseLocationId,
                        true,
                    );
                }

                $consumptions = $this->reservations->consumeForRequirement($requirement, $quantity);

                foreach ($consumptions as $consumption) {
                    $reservation = $consumption['reservation'];
                    $movementLines[] = [
                        'product_id' => $requirement->product_id,
                        'unit_id' => $requirement->unit_id,
                        'quantity' => $consumption['quantity'],
                        'warehouse_location_id' => $reservation->warehouse_location_id,
                        'destination_warehouse_location_id' => $reservation->warehouse_location_id,
                        'batch_lot' => $reservation->batch_lot,
                        'inventory_reservation_id' => $reservation->getKey(),
                        'source_line_type' => ProductionMaterialRequirement::class,
                        'source_line_id' => $requirement->getKey(),
                    ];
                }

                $issuedRequirements[$requirement->getKey()] = $quantity;
            }

            if ($movementLines === []) {
                throw new DomainException(__('No positive material issue quantities were supplied.'));
            }

            $document = $this->movements->createAndPost([
                ...$this->movementContext($locked, $branchStoreId),
                'destination_branch_store_id' => $branchStoreId,
                'document_type' => $additional
                    ? InventoryDocument::TypeAdditionalMaterialIssue
                    : InventoryDocument::TypeMaterialIssue,
                'purpose' => $additional ? 'Additional production material issue' : 'Planned production material issue',
                'source_stock_status' => InventoryTransaction::StatusAvailable,
                'destination_stock_status' => InventoryTransaction::StatusProductionStaging,
            ], $movementLines);

            foreach ($issuedRequirements as $requirementId => $quantity) {
                ProductionMaterialRequirement::query()->whereKey($requirementId)->increment(
                    $additional ? 'additional_issued_quantity' : 'issued_quantity',
                    $quantity,
                );
            }

            return $document;
        });
    }

    /** @param array<int, string|int|float> $quantitiesByRequirementId */
    public function returnMaterials(
        ProductionRun $run,
        int $branchStoreId,
        array $quantitiesByRequirementId,
        ?int $warehouseLocationId = null,
    ): InventoryDocument {
        return DB::transaction(function () use ($run, $branchStoreId, $quantitiesByRequirementId, $warehouseLocationId): InventoryDocument {
            $locked = ProductionRun::query()->with(['requirements', 'order'])->lockForUpdate()->findOrFail($run->getKey());
            $lines = [];

            foreach ($locked->requirements as $requirement) {
                $quantity = (string) ($quantitiesByRequirementId[$requirement->getKey()] ?? 0);

                if (bccomp($quantity, '0', 8) <= 0) {
                    continue;
                }

                $returnable = bcsub(
                    bcsub(
                        bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8),
                        (string) $requirement->returned_quantity,
                        8,
                    ),
                    bcadd((string) $requirement->consumed_quantity, (string) $requirement->waste_quantity, 8),
                    8,
                );

                if (bccomp($quantity, $returnable, 8) > 0) {
                    throw new DomainException(__('Material return exceeds the unaccounted issued quantity.'));
                }

                $position = $this->materialPosition($requirement, $warehouseLocationId);
                $lines[] = [
                    'product_id' => $requirement->product_id,
                    'unit_id' => $requirement->unit_id,
                    'quantity' => $quantity,
                    'warehouse_location_id' => $position['warehouse_location_id'],
                    'destination_warehouse_location_id' => $position['warehouse_location_id'],
                    'batch_lot' => $position['batch_lot'],
                    'source_line_type' => ProductionMaterialRequirement::class,
                    'source_line_id' => $requirement->getKey(),
                ];
            }

            if ($lines === []) {
                throw new DomainException(__('No positive material return quantities were supplied.'));
            }

            $document = $this->movements->createAndPost([
                ...$this->movementContext($locked, $branchStoreId),
                'destination_branch_store_id' => $branchStoreId,
                'document_type' => InventoryDocument::TypeMaterialReturn,
                'purpose' => 'Unused production material return',
                'source_stock_status' => InventoryTransaction::StatusProductionStaging,
                'destination_stock_status' => InventoryTransaction::StatusAvailable,
            ], $lines);

            foreach ($lines as $line) {
                ProductionMaterialRequirement::query()->whereKey($line['source_line_id'])->increment('returned_quantity', $line['quantity']);
            }

            return $document;
        });
    }

    public function startSetup(ProductionRun $run): ProductionRun
    {
        return $this->transitionRun($run, [ProductionRun::StatusPlanned], ProductionRun::StatusSetup, [
            'setup_status' => 'in_progress',
            'setup_started_at' => now(),
        ]);
    }

    public function completeSetup(ProductionRun $run): ProductionRun
    {
        return $this->transitionRun($run, [ProductionRun::StatusSetup], ProductionRun::StatusReady, [
            'setup_status' => 'completed',
            'setup_completed_at' => now(),
        ]);
    }

    public function startRun(ProductionRun $run): ProductionRun
    {
        return DB::transaction(function () use ($run): ProductionRun {
            $locked = ProductionRun::query()->with('requirements')->lockForUpdate()->findOrFail($run->getKey());

            if ($locked->status !== ProductionRun::StatusReady || $locked->setup_status !== 'completed') {
                throw new DomainException(__('A run must complete setup before production can start.'));
            }

            if ($locked->requirements->contains(fn (ProductionMaterialRequirement $requirement): bool => bccomp((string) $requirement->issued_quantity, '0', 8) <= 0)) {
                throw new DomainException(__('Every material requirement must have an issue before the run starts.'));
            }

            $locked->update([
                'status' => ProductionRun::StatusRunning,
                'actual_start_at' => now(),
                'started_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            $locked->order()->update(['status' => ProductionOrder::StatusInProgress, 'updated_by' => auth()->id()]);

            return $locked->refresh();
        });
    }

    public function resumeRun(ProductionRun $run): ProductionRun
    {
        return DB::transaction(function () use ($run): ProductionRun {
            $locked = ProductionRun::query()->with('inspections')->lockForUpdate()->findOrFail($run->getKey());

            $latestInspection = $locked->inspections()->reorder()->latest('sampled_at')->latest('id')->first();

            if ($locked->status !== ProductionRun::StatusHeld || $latestInspection?->result !== 'passed') {
                throw new DomainException(__('A held run requires a later passed quality inspection before it can resume.'));
            }

            $locked->update(['status' => ProductionRun::StatusRunning, 'updated_by' => auth()->id()]);

            return $locked->refresh();
        });
    }

    public function cancelRun(ProductionRun $run, string $reason): ProductionRun
    {
        return DB::transaction(function () use ($run, $reason): ProductionRun {
            $locked = ProductionRun::query()->with('requirements')->lockForUpdate()->findOrFail($run->getKey());

            if (trim($reason) === ''
                || in_array($locked->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)) {
                throw new DomainException(__('An open run and a cancellation reason are required.'));
            }

            if ($locked->requirements->contains(fn (ProductionMaterialRequirement $requirement): bool => bccomp(
                bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8),
                (string) $requirement->returned_quantity,
                8,
            ) > 0)) {
                throw new DomainException(__('All issued materials must be returned before a run can be cancelled.'));
            }

            if (bccomp((string) $locked->total_output_base_quantity, '0', 8) > 0) {
                throw new DomainException(__('A run with recorded output cannot be cancelled.'));
            }

            $this->reservations->releaseRun((int) $locked->getKey(), 'Run cancelled: '.trim($reason));
            $locked->update([
                'status' => ProductionRun::StatusCancelled,
                'notes' => trim(implode("\n", array_filter([$locked->notes, 'Cancellation: '.trim($reason)]))),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function recordProgress(ProductionRun $run, array $data): ProductionProgressEntry
    {
        return DB::transaction(function () use ($run, $data): ProductionProgressEntry {
            $locked = ProductionRun::query()->with('order')->lockForUpdate()->findOrFail($run->getKey());

            if ($locked->status !== ProductionRun::StatusRunning) {
                throw new DomainException(__('Progress can only be recorded against a running production run.'));
            }

            $values = collect(['good_base_quantity', 'rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity'])
                ->mapWithKeys(fn (string $field): array => [$field => (string) ($data[$field] ?? 0)])
                ->all();

            if (collect($values)->contains(fn (string $value): bool => bccomp($value, '0', 8) < 0)
                || collect($values)->every(fn (string $value): bool => bccomp($value, '0', 8) === 0)) {
                throw new DomainException(__('Progress quantities must be non-negative and at least one must be positive.'));
            }

            $entryTotal = array_reduce($values, fn (string $carry, string $value): string => bcadd($carry, $value, 8), '0');
            $allowed = bcmul(
                (string) $locked->planned_base_quantity,
                bcadd('1', bcdiv((string) $locked->order->overproduction_tolerance_percent, '100', 8), 8),
                8,
            );

            if (bccomp(bcadd((string) $locked->total_output_base_quantity, $entryTotal, 8), $allowed, 8) > 0) {
                throw new DomainException(__('Recorded output exceeds the configured overproduction tolerance.'));
            }

            $entry = $locked->progressEntries()->create([
                ...$values,
                'recorded_at' => now(),
                'notes' => $data['notes'] ?? null,
                'recorded_by' => auth()->id(),
            ]);

            foreach ($values as $field => $value) {
                $locked->increment($field, $value);
            }

            return $entry;
        });
    }

    /** @param array<string, mixed> $data */
    public function recordInspection(ProductionRun $run, array $data): ProductionQualityInspection
    {
        return DB::transaction(function () use ($run, $data): ProductionQualityInspection {
            $locked = ProductionRun::query()->with('order')->lockForUpdate()->findOrFail($run->getKey());

            if (! in_array($locked->status, [ProductionRun::StatusRunning, ProductionRun::StatusHeld], true)) {
                throw new DomainException(__('Quality inspections require a running or held production run.'));
            }

            $inspectionTypeId = $data['quality_inspection_type_id'] ?? null;

            if ($inspectionTypeId !== null && ! QualityInspectionType::query()
                ->whereKey($inspectionTypeId)
                ->where('company_id', $locked->company_id)
                ->where('is_active', true)
                ->exists()) {
                throw new DomainException(__('The selected quality inspection type is not active for the operating company.'));
            }

            $checkpointIds = collect($data['results'] ?? [])
                ->pluck('quality_checkpoint_id')
                ->map(fn (mixed $checkpointId): int => (int) $checkpointId)
                ->unique()
                ->values();

            if ($checkpointIds->isNotEmpty()) {
                $validCheckpointCount = DB::table('quality_checkpoints')
                    ->whereIn('id', $checkpointIds)
                    ->where('company_id', $locked->company_id)
                    ->where('quality_inspection_type_id', $inspectionTypeId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->count();

                if ($inspectionTypeId === null || $validCheckpointCount !== $checkpointIds->count()) {
                    throw new DomainException(__('Quality checkpoints must be active and belong to the selected inspection type and operating company.'));
                }
            }

            $numbers = $this->documents->nextForCompany(
                'quality_inspections',
                ProductionQualityInspection::class,
                (int) $locked->company_id,
                fn ($query) => $query->where('financial_period_id', $locked->financial_period_id),
            );
            $inspection = ProductionQualityInspection::query()->create([
                ...$numbers,
                'company_id' => $locked->company_id,
                'financial_period_id' => $locked->financial_period_id,
                'branch_id' => $locked->branch_id,
                'production_order_id' => $locked->production_order_id,
                'production_run_id' => $locked->getKey(),
                'quality_inspection_type_id' => $inspectionTypeId,
                'version' => 1,
                'inspection_date' => now()->toDateString(),
                'sampled_at' => now(),
                'status' => 'approved',
                'result' => $data['result'],
                'defect_code' => $data['defect_code'] ?? null,
                'affected_base_quantity' => $data['affected_base_quantity'] ?? null,
                'inspector_id' => auth()->id(),
                'notes' => $data['notes'] ?? null,
                'rework_notes' => $data['rework_notes'] ?? null,
                'corrective_action' => $data['corrective_action'] ?? null,
                'evidence' => $data['evidence'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach (array_values($data['results'] ?? []) as $index => $result) {
                $inspection->results()->create([
                    'quality_checkpoint_id' => $result['quality_checkpoint_id'],
                    'sequence' => $index + 1,
                    'result' => $result['result'],
                    'measured_value' => $result['measured_value'] ?? null,
                    'notes' => $result['notes'] ?? null,
                    'recorded_by' => auth()->id(),
                    'recorded_at' => now(),
                ]);
            }

            if ($data['result'] === 'failed') {
                $locked->update(['status' => ProductionRun::StatusHeld, 'updated_by' => auth()->id()]);
            }

            return $inspection->load('results');
        });
    }

    /** @param array<int, array{consumed_quantity: string|int|float, waste_quantity: string|int|float}> $accountingByRequirementId */
    public function accountMaterials(
        ProductionRun $run,
        int $branchStoreId,
        array $accountingByRequirementId,
        ?int $warehouseLocationId = null,
    ): array {
        return DB::transaction(function () use ($run, $branchStoreId, $accountingByRequirementId, $warehouseLocationId): array {
            $locked = ProductionRun::query()->with(['requirements', 'order'])->lockForUpdate()->findOrFail($run->getKey());
            $consumptionLines = [];
            $wasteLines = [];
            $requirementIds = $locked->requirements->modelKeys();
            $submittedRequirementIds = array_map('intval', array_keys($accountingByRequirementId));
            sort($requirementIds);
            sort($submittedRequirementIds);

            if ($requirementIds !== $submittedRequirementIds) {
                throw new DomainException(__('Material accounting lines must belong exclusively to this production run.'));
            }

            foreach ($locked->requirements as $requirement) {
                $accounting = $accountingByRequirementId[$requirement->getKey()] ?? null;

                if (! is_array($accounting)) {
                    throw new DomainException(__('Every material requirement must be reconciled.'));
                }

                $consumed = (string) $accounting['consumed_quantity'];
                $waste = (string) $accounting['waste_quantity'];
                $unaccounted = bcsub(
                    bcsub(
                        bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8),
                        (string) $requirement->returned_quantity,
                        8,
                    ),
                    bcadd((string) $requirement->consumed_quantity, (string) $requirement->waste_quantity, 8),
                    8,
                );

                if (bccomp($consumed, '0', 8) < 0
                    || bccomp($waste, '0', 8) < 0
                    || bccomp(bcadd($consumed, $waste, 8), $unaccounted, 8) !== 0) {
                    throw new DomainException(__('Consumed plus waste must exactly reconcile issued less returned material.'));
                }

                $position = $this->materialPosition($requirement, $warehouseLocationId);
                $baseLine = [
                    'product_id' => $requirement->product_id,
                    'unit_id' => $requirement->unit_id,
                    'warehouse_location_id' => $position['warehouse_location_id'],
                    'batch_lot' => $position['batch_lot'],
                    'source_line_type' => ProductionMaterialRequirement::class,
                    'source_line_id' => $requirement->getKey(),
                ];

                if (bccomp($consumed, '0', 8) > 0) {
                    $consumptionLines[] = [...$baseLine, 'quantity' => $consumed];
                }

                if (bccomp($waste, '0', 8) > 0) {
                    $wasteLines[] = [...$baseLine, 'quantity' => $waste];
                }
            }

            $documents = [];

            if ($consumptionLines !== []) {
                $documents['consumption'] = $this->movements->createAndPost([
                    ...$this->movementContext($locked, $branchStoreId),
                    'document_type' => InventoryDocument::TypeMaterialConsumption,
                    'purpose' => 'Production material consumption',
                    'source_stock_status' => InventoryTransaction::StatusProductionStaging,
                ], $consumptionLines);
            }

            if ($wasteLines !== []) {
                $documents['waste'] = $this->movements->createAndPost([
                    ...$this->movementContext($locked, $branchStoreId),
                    'document_type' => InventoryDocument::TypeProductionWaste,
                    'purpose' => 'Production process waste',
                    'source_stock_status' => InventoryTransaction::StatusProductionStaging,
                ], $wasteLines);
            }

            foreach ($locked->requirements as $requirement) {
                $accounting = $accountingByRequirementId[$requirement->getKey()];
                $requirement->increment('consumed_quantity', (string) $accounting['consumed_quantity']);
                $requirement->increment('waste_quantity', (string) $accounting['waste_quantity']);
            }

            return $documents;
        });
    }

    public function receiveFinishedGoods(
        ProductionRun $run,
        int $branchStoreId,
        string $baseQuantity,
        ?int $warehouseLocationId = null,
    ): InventoryDocument {
        return DB::transaction(function () use ($run, $branchStoreId, $baseQuantity, $warehouseLocationId): InventoryDocument {
            $locked = ProductionRun::query()->with(['order', 'orderLine.product', 'product'])->lockForUpdate()->findOrFail($run->getKey());

            if ($locked->order->sales_order_id) {
                SalesOrder::query()->lockForUpdate()->findOrFail($locked->order->sales_order_id);
            }
            $salesLine = $locked->orderLine->sales_order_line_id
                ? SalesOrderLine::query()->with('order')->lockForUpdate()->findOrFail($locked->orderLine->sales_order_line_id)
                : null;
            if ($salesLine && (! $salesLine->order->isApprovedForFulfillment() || (int) $salesLine->order->branch_store_id !== $branchStoreId)) {
                throw new DomainException(__('Receive sales production into the source order warehouse while the order is open.'));
            }
            BranchStore::query()->lockForUpdate()->findOrFail($branchStoreId);
            Product::query()->lockForUpdate()->findOrFail($locked->product_id);

            if ($locked->status !== ProductionRun::StatusRunning) {
                throw new DomainException(__('Finished goods can only be received from a running production run that is not on quality hold.'));
            }

            $remainingGood = bcsub((string) $locked->good_base_quantity, (string) $locked->received_base_quantity, 8);

            if (bccomp($baseQuantity, '0', 8) <= 0 || bccomp($baseQuantity, $remainingGood, 8) > 0) {
                throw new DomainException(__('Finished-goods receipt exceeds recorded good output.'));
            }

            foreach ($locked->requirements as $requirement) {
                $issuedLessReturned = bcsub(
                    bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8),
                    (string) $requirement->returned_quantity,
                    8,
                );
                $accounted = bcadd((string) $requirement->consumed_quantity, (string) $requirement->waste_quantity, 8);

                if (bccomp($issuedLessReturned, $accounted, 8) !== 0) {
                    throw new DomainException(__('All issued material must be consumed, returned, or recorded as waste before finished goods are received.'));
                }
            }

            $finalInspectionRequired = QualityInspectionType::query()
                ->where('company_id', $locked->company_id)
                ->where('is_final_production', true)
                ->where('is_active', true)
                ->exists();
            $latestFinalInspection = $finalInspectionRequired
                ? $locked->inspections()
                    ->whereHas('qualityType', fn ($query) => $query->where('is_final_production', true))
                    ->reorder()
                    ->latest('sampled_at')
                    ->latest('id')
                    ->first()
                : null;

            if ($finalInspectionRequired && $latestFinalInspection?->result !== 'passed') {
                throw new DomainException(__('A final passed quality inspection is required before finished goods become available.'));
            }

            $receiptCost = $this->costs->receiptCost($locked, $baseQuantity);

            if (bccomp($receiptCost, '0', 8) <= 0) {
                throw new DomainException(__('Finished goods cannot be received without a positive reconciled WIP material value.'));
            }

            $unitCost = bcdiv($receiptCost, $baseQuantity, 8);
            $manufactureDate = ($locked->actual_end_at ?? now())->toDateString();
            $expiryDate = null;
            if ($locked->product?->tracks_expiry) {
                if (blank($locked->batch_lot) || ! $locked->product->default_shelf_life_days) {
                    throw new DomainException(__('Expiry-tracked finished goods require a batch and a default shelf life before receipt.'));
                }
                $expiryDate = CarbonImmutable::parse($manufactureDate)
                    ->addDays((int) $locked->product->default_shelf_life_days)
                    ->toDateString();
            }
            $document = $this->movements->createAndPost([
                ...$this->movementContext($locked, $branchStoreId),
                'document_type' => InventoryDocument::TypeProductionReceipt,
                'purpose' => 'Finished production receipt',
                'destination_stock_status' => InventoryTransaction::StatusAvailable,
            ], [[
                'product_id' => $locked->product_id,
                'unit_id' => $locked->orderLine->product?->item_unit_id ?? $locked->unit_id,
                'quantity' => $baseQuantity,
                'transaction_quantity' => bcdiv($baseQuantity, (string) $locked->conversion_factor, 8),
                'conversion_factor' => $locked->conversion_factor,
                'warehouse_location_id' => $warehouseLocationId,
                'destination_warehouse_location_id' => $warehouseLocationId,
                'batch_lot' => $locked->batch_lot,
                'manufacture_date' => $manufactureDate,
                'expiry_date' => $expiryDate,
                'source_line_type' => ProductionRun::class,
                'source_line_id' => $locked->getKey(),
                'unit_cost' => $unitCost,
            ]]);

            $locked->increment('received_base_quantity', $baseQuantity);
            $locked->orderLine()->increment('received_base_quantity', $baseQuantity);

            if ($salesLine) {
                $transactionQuantity = bcdiv($baseQuantity, (string) $salesLine->conversion_factor, 8);
                $salesLine->increment('produced_quantity', $transactionQuantity);
                $salesLine->increment('produced_base_quantity', $baseQuantity);
                $remaining = bcsub($salesLine->remainingDeliveryQuantity(), $salesLine->activeReservedQuantity(), 8);
                $allocateQuantity = bccomp($transactionQuantity, $remaining, 8) > 0 ? $remaining : $transactionQuantity;
                if (bccomp($allocateQuantity, '0', 8) > 0) {
                    $allocateBase = bcmul($allocateQuantity, (string) $salesLine->conversion_factor, 8);
                    InventoryReservation::query()->create([
                        'company_id' => $document->company_id, 'financial_period_id' => $document->financial_period_id,
                        'branch_id' => $document->branch_id, 'branch_store_id' => $branchStoreId,
                        'warehouse_location_id' => $warehouseLocationId, 'batch_lot' => $locked->batch_lot,
                        'sales_order_id' => $salesLine->sales_order_id, 'sales_order_line_id' => $salesLine->getKey(),
                        'production_order_id' => $locked->production_order_id, 'production_run_id' => $locked->getKey(),
                        'customer_id' => $salesLine->order->customer_id, 'product_id' => $salesLine->product_id,
                        'unit_id' => $locked->product->item_unit_id, 'transaction_unit_id' => $salesLine->unit_id,
                        'conversion_factor' => $salesLine->conversion_factor, 'transaction_quantity' => $allocateQuantity,
                        'quantity' => $allocateBase, 'stock_status' => InventoryTransaction::StatusAvailable,
                        'status' => InventoryReservation::StatusActive, 'created_by' => auth()->id(),
                    ]);
                    $salesLine->increment('reserved_quantity', $allocateQuantity);
                    $salesLine->increment('reserved_base_quantity', $allocateBase);
                }
            }

            return $document;
        });
    }

    public function completeRun(ProductionRun $run): ProductionRun
    {
        return DB::transaction(function () use ($run): ProductionRun {
            $locked = ProductionRun::query()->with(['requirements', 'inspections', 'order.lines'])->lockForUpdate()->findOrFail($run->getKey());

            if (! in_array($locked->status, [ProductionRun::StatusRunning, ProductionRun::StatusHeld], true)) {
                throw new DomainException(__('Only an active production run can be completed.'));
            }

            foreach ($locked->requirements as $requirement) {
                $issuedLessReturned = bcsub(
                    bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8),
                    (string) $requirement->returned_quantity,
                    8,
                );
                $accounted = bcadd((string) $requirement->consumed_quantity, (string) $requirement->waste_quantity, 8);

                if (bccomp($issuedLessReturned, $accounted, 8) !== 0) {
                    throw new DomainException(__('All issued material must be consumed, returned, or recorded as waste before completion.'));
                }
            }

            if (bccomp((string) $locked->good_base_quantity, '0', 8) <= 0
                || bccomp((string) $locked->received_base_quantity, (string) $locked->good_base_quantity, 8) !== 0) {
                throw new DomainException(__('All recorded good output must be received into finished-goods stock before completion.'));
            }

            $latestInspection = $locked->inspections()->reorder()->latest('sampled_at')->latest('id')->first();

            if ($locked->status === ProductionRun::StatusHeld || $latestInspection?->result === 'failed') {
                throw new DomainException(__('Failed quality inspections must be resolved before run completion.'));
            }

            $locked->update([
                'status' => ProductionRun::StatusCompleted,
                'actual_end_at' => now(),
                'completed_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            $this->reservations->releaseRun((int) $locked->getKey(), 'Production run completed');
            $this->refreshOrderStatus($locked->order);

            return $locked->refresh();
        });
    }

    public function shortCloseOrder(ProductionOrder $order, string $reason): ProductionOrder
    {
        return DB::transaction(function () use ($order, $reason): ProductionOrder {
            $locked = ProductionOrder::query()->with('runs')->lockForUpdate()->findOrFail($order->getKey());

            if (trim($reason) === '' || in_array($locked->status, [ProductionOrder::StatusCompleted, ProductionOrder::StatusCancelled], true)) {
                throw new DomainException(__('An open production order and a short-close reason are required.'));
            }

            foreach ($locked->runs->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled]) as $run) {
                $this->cancelRun($run, 'Production order short-closed: '.trim($reason));
            }

            $locked->update([
                'status' => ProductionOrder::StatusShortClosed,
                'short_close_reason' => trim($reason),
                'short_closed_by' => auth()->id(),
                'short_closed_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh();
        });
    }

    /** @param list<string> $fromStatuses @param array<string, mixed> $extra */
    private function transitionRun(ProductionRun $run, array $fromStatuses, string $toStatus, array $extra = []): ProductionRun
    {
        return DB::transaction(function () use ($run, $fromStatuses, $toStatus, $extra): ProductionRun {
            $locked = ProductionRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if (! in_array($locked->status, $fromStatuses, true)) {
                throw new DomainException(__('The production run is not in a valid state for this transition.'));
            }

            $locked->update([...$extra, 'status' => $toStatus, 'updated_by' => auth()->id()]);

            return $locked->refresh();
        });
    }

    private function assertResources(
        ProductionOrder $order,
        int $productId,
        ?int $machineId,
        ?int $moldId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        if ($machineId !== null) {
            $machine = ProductionMachine::query()->lockForUpdate()->findOrFail($machineId);

            if ((int) $machine->company_id !== (int) $order->company_id
                || (int) $machine->branch_id !== (int) $order->branch_id
                || $machine->status !== ProductionMachine::StatusAvailable) {
                throw new DomainException(__('The selected production machine is not available in this operating context.'));
            }
        }

        if ($moldId !== null) {
            $mold = ProductionMold::query()->lockForUpdate()->findOrFail($moldId);

            if ((int) $mold->company_id !== (int) $order->company_id
                || (int) $mold->branch_id !== (int) $order->branch_id
                || $mold->status !== ProductionMold::StatusAvailable
                || ! $mold->products()->whereKey($productId)->exists()) {
                throw new DomainException(__('The selected mold is not available or is not compatible with the finished product.'));
            }

            if ($machineId !== null && ! $mold->machines()->whereKey($machineId)->exists()) {
                throw new DomainException(__('The selected machine and mold are not compatible.'));
            }
        }

        $conflictQuery = ProductionRun::query()
            ->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled])
            ->where('planned_start_at', '<', $endsAt)
            ->where('planned_end_at', '>', $startsAt)
            ->when($machineId !== null && $moldId !== null, fn ($query) => $query->where(function ($nested) use ($machineId, $moldId): void {
                $nested->where('production_machine_id', $machineId)->orWhere('production_mold_id', $moldId);
            }))
            ->when($machineId !== null && $moldId === null, fn ($query) => $query->where('production_machine_id', $machineId))
            ->when($machineId === null && $moldId !== null, fn ($query) => $query->where('production_mold_id', $moldId));

        if (($machineId !== null || $moldId !== null) && $conflictQuery->lockForUpdate()->exists()) {
            throw new DomainException(__('The selected machine or mold has an overlapping production run.'));
        }
    }

    /** @param array<string, mixed> $data */
    private function runCostCenterId(array $data, ?int $machineId, int $companyId): ?int
    {
        $docNum = trim((string) ($data['cost_center_doc_num'] ?? ''));
        $costCenterId = $docNum === ''
            ? ProductionMachine::query()->whereKey($machineId)->value('cost_center_id')
            : CostCenter::query()->forCompany($companyId)->where('doc_num', $docNum)->value('id');

        if ($costCenterId === null) {
            return null;
        }

        return CostCenter::query()
            ->forCompany($companyId)
            ->active()
            ->where('is_group', false)
            ->whereKey($costCenterId)
            ->valueOrFail('id');
    }

    /** @return array<string, mixed> */
    private function movementContext(ProductionRun $run, int $branchStoreId): array
    {
        $period = app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $run->company_id, now()->toDateString(), lockForUpdate: true);

        return [
            'company_id' => $run->company_id,
            'financial_period_id' => $period->getKey(),
            'branch_id' => $run->branch_id,
            'branch_store_id' => $branchStoreId,
            'document_date' => now()->toDateString(),
            'source_document_type' => ProductionRun::class,
            'source_document_id' => $run->getKey(),
            'source_doc_num' => $run->run_number,
            'production_order_id' => $run->production_order_id,
            'production_run_id' => $run->getKey(),
        ];
    }

    /** @return array{warehouse_location_id: int|null, batch_lot: string|null} */
    private function materialPosition(ProductionMaterialRequirement $requirement, ?int $fallbackLocationId): array
    {
        $positions = InventoryReservation::query()
            ->where('production_material_requirement_id', $requirement->getKey())
            ->select(['warehouse_location_id', 'batch_lot'])
            ->distinct()
            ->get();

        if ($positions->count() > 1) {
            throw new DomainException(__('A material requirement spanning multiple batches or locations must be split before return or accountability.'));
        }

        $position = $positions->first();

        return [
            'warehouse_location_id' => $position?->warehouse_location_id === null
                ? $fallbackLocationId
                : (int) $position->warehouse_location_id,
            'batch_lot' => $position?->batch_lot,
        ];
    }

    private function refreshOrderStatus(ProductionOrder $order): void
    {
        $lines = $order->lines()->get();
        $complete = $lines->every(fn (ProductionOrderLine $line): bool => bccomp(
            (string) $line->received_base_quantity,
            (string) $line->base_quantity,
            8,
        ) >= 0);
        $hasReceipt = $lines->contains(fn (ProductionOrderLine $line): bool => bccomp((string) $line->received_base_quantity, '0', 8) > 0);

        $order->update([
            'status' => $complete
                ? ProductionOrder::StatusCompleted
                : ($hasReceipt ? ProductionOrder::StatusPartiallyCompleted : ProductionOrder::StatusInProgress),
            'updated_by' => auth()->id(),
        ]);
    }
}

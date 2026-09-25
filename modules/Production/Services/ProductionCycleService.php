<?php

namespace Modules\Production\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\HR\Models\HrEmployee;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryReservationService;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Models\ProductionProgressEntry;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionRunBatch;
use Modules\Production\Models\QualityInspectionType;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
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
        private readonly InventoryAvailabilityService $availability,
        private readonly ProductionCostService $costs,
        private readonly QualityInspectionPlanSnapshotService $planSnapshots,
        private readonly CrudAuditService $audit,
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
                'sales_order_id' => $header['sales_order_id'] ?? null,
                'customer_id' => $header['customer_id'] ?? null,
                'source_type' => $header['source_type'] ?? 'make_to_stock',
                'source_id' => $header['source_id'] ?? null,
                'production_order_date' => $header['production_order_date'] ?? now()->toDateString(),
                'expected_start_date' => $header['expected_start_date'] ?? null,
                'expected_finish_date' => $header['expected_finish_date'] ?? null,
                'expected_delivery_date' => $header['expected_delivery_date'] ?? null,
                'priority' => $header['priority'] ?? 'normal',
                'overproduction_tolerance_percent' => $header['overproduction_tolerance_percent'] ?? 0,
                'status' => ProductionOrder::StatusDraft,
                'production_notes' => $header['production_notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            app(ProductionRoutingService::class)->snapshotOrderRoute(
                $order,
                $header['order_stage_public_ids'] ?? [],
            );
            $this->createOrderLines($order, $lines);

            return $order->load('lines.product');
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function updateDraftOrder(ProductionOrder $order, array $header, array $lines): ProductionOrder
    {
        return DB::transaction(function () use ($order, $header, $lines): ProductionOrder {
            $locked = ProductionOrder::query()->withCount('runs')->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status !== ProductionOrder::StatusDraft || $locked->runs_count > 0) {
                throw new DomainException(__('production_execution.messages.order_draft_only'));
            }
            if ($lines === []) {
                throw new DomainException(__('A production order requires at least one finished-product line.'));
            }

            $locked->update([
                'sales_order_id' => $header['sales_order_id'] ?? null,
                'customer_id' => $header['customer_id'] ?? null,
                'source_type' => $header['source_type'] ?? 'make_to_stock',
                'source_id' => $header['source_id'] ?? null,
                'production_order_date' => $header['production_order_date'],
                'expected_start_date' => $header['expected_start_date'] ?? null,
                'expected_finish_date' => $header['expected_finish_date'] ?? null,
                'expected_delivery_date' => $header['expected_delivery_date'] ?? null,
                'priority' => $header['priority'] ?? 'normal',
                'overproduction_tolerance_percent' => $header['overproduction_tolerance_percent'] ?? 0,
                'production_notes' => $header['production_notes'] ?? null,
                'updated_by' => auth()->id(),
            ]);
            $this->lockProductionSource($locked);
            $this->adjustSalesDemand($locked, subtract: true);
            $locked->lines()->forceDelete();
            app(ProductionRoutingService::class)->snapshotOrderRoute(
                $locked,
                $header['order_stage_public_ids'] ?? [],
            );
            $this->createOrderLines($locked, $lines);

            return $locked->refresh()->load('lines.product');
        });
    }

    public function deleteDraftOrder(ProductionOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $locked = ProductionOrder::query()->withCount('runs')->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status !== ProductionOrder::StatusDraft || $locked->runs_count > 0) {
                throw new DomainException(__('production_execution.messages.order_draft_delete_only'));
            }

            $this->lockProductionSource($locked);
            $locked->update(['deleted_by' => auth()->id()]);
            $this->adjustSalesDemand($locked, subtract: true);
            $locked->delete();
        });
    }

    public function restoreDraftOrder(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order): ProductionOrder {
            $locked = ProductionOrder::withTrashed()->lockForUpdate()->findOrFail($order->getKey());

            if (! $locked->trashed()) {
                throw new DomainException(__('production_execution.messages.order_not_deleted'));
            }

            $this->assertRestorableSourceDemand($locked);
            $locked->restore();
            $this->adjustSalesDemand($locked, subtract: false);
            $locked->update(['restored_by' => auth()->id(), 'restored_at' => now(), 'updated_by' => auth()->id()]);

            return $locked->refresh();
        });
    }

    /** @param list<array<string, mixed>> $lines */
    private function createOrderLines(ProductionOrder $order, array $lines): void
    {
        $source = $this->lockProductionSource($order);

        foreach (array_values($lines) as $index => $input) {
            $product = Product::withTrashed()->lockForUpdate()->find($input['product_id']);

            if (! $product || $product->trashed() || $product->status !== 'active'
                || (int) $product->company_id !== (int) $order->company_id
                || $product->item_classification !== Product::ClassificationFinishedProduct) {
                throw new DomainException(__('production_execution.messages.source_product_unavailable'));
            }

            $snapshot = $this->units->snapshot($product, $input['unit_id'] ?? null, $input['quantity']);

            if (bccomp($snapshot['base_quantity'], '0', 8) <= 0) {
                throw new DomainException(__('Production quantity must be greater than zero.'));
            }

            [$salesLine, $invoiceLine] = $this->sourceLinesForInput($source, $product, $input);
            $this->assertSourceDemand($order, $product, $snapshot['base_quantity'], $salesLine, $invoiceLine);

            $line = $order->lines()->create([
                'sales_order_line_id' => $salesLine?->getKey(),
                'customer_invoice_line_id' => $invoiceLine?->getKey(),
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
            app(ProductionRoutingService::class)->snapshotLine($line, $input['stage_public_ids'] ?? []);

            if ($line->sales_order_line_id !== null) {
                $salesLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($line->sales_order_line_id);
                $salesLine->increment('production_requested_quantity', $line->quantity);
                $salesLine->increment('production_requested_base_quantity', $line->base_quantity);
            }
        }
    }

    private function adjustSalesDemand(ProductionOrder $order, bool $subtract): void
    {
        $order->loadMissing('lines');

        foreach ($order->lines->whereNotNull('sales_order_line_id') as $line) {
            $salesLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($line->sales_order_line_id);
            $method = $subtract ? 'decrement' : 'increment';
            $salesLine->{$method}('production_requested_quantity', $line->quantity);
            $salesLine->{$method}('production_requested_base_quantity', $line->base_quantity);
        }
    }

    private function lockProductionSource(ProductionOrder $order): SalesOrder|CustomerInvoice|null
    {
        if ($order->source_type === 'make_to_stock') {
            if ($order->source_id !== null || $order->sales_order_id !== null) {
                throw new DomainException(__('production_execution.messages.standalone_source_invalid'));
            }

            return null;
        }

        if ($order->source_type === 'sales_order') {
            $source = SalesOrder::query()
                ->where('company_id', $order->company_id)
                ->whereIn('status', [SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled])
                ->lockForUpdate()
                ->find($order->source_id);

            if (! $source || (int) $source->getKey() !== (int) $order->sales_order_id) {
                throw new DomainException(__('production_execution.messages.production_source_invalid'));
            }

            return $source;
        }

        if ($order->source_type === 'customer_invoice') {
            $source = CustomerInvoice::query()
                ->where('company_id', $order->company_id)
                ->where('document_type', CustomerInvoice::TypeInvoice)
                ->where('status', CustomerInvoice::StatusPosted)
                ->lockForUpdate()
                ->find($order->source_id);

            if (! $source) {
                throw new DomainException(__('production_execution.messages.production_source_invalid'));
            }

            return $source;
        }

        throw new DomainException(__('production_execution.messages.production_source_invalid'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: ?SalesOrderLine, 1: ?CustomerInvoiceLine}
     */
    private function sourceLinesForInput(
        SalesOrder|CustomerInvoice|null $source,
        Product $product,
        array $input,
    ): array {
        if ($source === null) {
            if (! empty($input['sales_order_line_id']) || ! empty($input['customer_invoice_line_id'])) {
                throw new DomainException(__('production_execution.messages.invalid_source_line'));
            }

            return [null, null];
        }

        if ($source instanceof SalesOrder) {
            $salesLine = SalesOrderLine::query()
                ->where('sales_order_id', $source->getKey())
                ->where('product_id', $product->getKey())
                ->lockForUpdate()
                ->find($input['sales_order_line_id'] ?? null);

            if (! $salesLine
                || $salesLine->isService()
                || $salesLine->product_classification_snapshot !== Product::ClassificationFinishedProduct
                || ! empty($input['customer_invoice_line_id'])) {
                throw new DomainException(__('production_execution.messages.invalid_source_line'));
            }

            return [$salesLine, null];
        }

        $invoiceLine = CustomerInvoiceLine::query()
            ->where('customer_invoice_id', $source->getKey())
            ->where('product_id', $product->getKey())
            ->where('is_service', false)
            ->lockForUpdate()
            ->find($input['customer_invoice_line_id'] ?? null);

        if (! $invoiceLine) {
            throw new DomainException(__('production_execution.messages.invalid_source_line'));
        }

        $salesLine = $invoiceLine->sales_order_line_id === null
            ? null
            : SalesOrderLine::query()->where('product_id', $product->getKey())->lockForUpdate()->find($invoiceLine->sales_order_line_id);

        if (($input['sales_order_line_id'] ?? null) !== $salesLine?->getKey()) {
            throw new DomainException(__('production_execution.messages.invalid_source_line'));
        }

        return [$salesLine, $invoiceLine];
    }

    private function assertSourceDemand(
        ProductionOrder $order,
        Product $product,
        string $requestedBaseQuantity,
        ?SalesOrderLine $salesLine,
        ?CustomerInvoiceLine $invoiceLine,
    ): void {
        if ($salesLine !== null) {
            $salesOrder = SalesOrder::query()->lockForUpdate()->findOrFail($salesLine->sales_order_id);

            if (! $salesOrder->isApprovedForFulfillment()) {
                throw new DomainException(__('production_execution.messages.production_source_invalid'));
            }

            if (bccomp($requestedBaseQuantity, $salesLine->remainingProductionDemandBaseQuantity(), 8) > 0) {
                throw new DomainException(__('production_execution.messages.source_quantity_exceeds_remaining'));
            }
        }

        if ($invoiceLine !== null) {
            $this->assertSourceQuantityAvailable(
                'customer_invoice_line_id',
                $invoiceLine->getKey(),
                $requestedBaseQuantity,
                (string) $invoiceLine->base_quantity,
            );
        }
    }

    private function assertSourceQuantityAvailable(
        string $sourceColumn,
        int $sourceLineId,
        string $requestedBaseQuantity,
        string $sourceBaseQuantity,
    ): void {
        $alreadyLinkedBaseQuantity = (string) ProductionOrderLine::query()
            ->where($sourceColumn, $sourceLineId)
            ->whereHas('order')
            ->sum('base_quantity');
        $remainingBaseQuantity = $this->nonnegative(bcsub($sourceBaseQuantity, $alreadyLinkedBaseQuantity, 8));

        if (bccomp($requestedBaseQuantity, $remainingBaseQuantity, 8) > 0) {
            throw new DomainException(__('production_execution.messages.source_quantity_exceeds_remaining'));
        }
    }

    private function assertRestorableSourceDemand(ProductionOrder $order): void
    {
        $source = $this->lockProductionSource($order);
        $order->loadMissing('lines.product');

        foreach ($order->lines as $line) {
            [$salesLine, $invoiceLine] = $this->sourceLinesForInput($source, $line->product, [
                'sales_order_line_id' => $line->sales_order_line_id,
                'customer_invoice_line_id' => $line->customer_invoice_line_id,
            ]);
            $this->assertSourceDemand($order, $line->product, (string) $line->base_quantity, $salesLine, $invoiceLine);
        }
    }

    private function nonnegative(string $quantity): string
    {
        return bccomp($quantity, '0', 8) < 0 ? '0.00000000' : $quantity;
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
                        'source_unit_name' => $component->unit?->name,
                        'base_unit_id' => $unitSnapshot['base_unit_id'],
                        'base_unit_name' => $componentProduct->unit?->name,
                        'production_stage_id' => $component->production_stage_id,
                        'calculation_method' => $component->calculation_method,
                        'quantity' => (string) $component->quantity,
                        'base_quantity_per_output' => $unitSnapshot['base_quantity'],
                        'percentage' => $component->percentage,
                        'reference_component_id' => $component->reference_component_id,
                    ];
                })->all();

                $equivalentUnit = $line->product?->equivalentUnit;
                $equivalentFactor = $line->product?->equivalent_value;
                $equivalentFactor = filled($equivalentFactor) && bccomp((string) $equivalentFactor, '0', 8) > 0
                    ? (string) $equivalentFactor
                    : '1.00000000';
                $line->update([
                    'bom_snapshot' => [
                        'captured_at' => now()->toIso8601String(),
                        'finished_product_id' => $line->product_id,
                        'basis_base_quantity' => $equivalentFactor,
                        'basis_unit_id' => $equivalentUnit?->getKey() ?? $line->product?->unit?->getKey(),
                        'basis_unit_name' => $equivalentUnit?->name ?? $line->product?->unit?->name,
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
    public function createRunBatch(ProductionOrder $productionOrder, array $data): ProductionRunBatch
    {
        return DB::transaction(function () use ($productionOrder, $data): ProductionRunBatch {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($productionOrder->getKey());
            $lines = collect($data['lines'] ?? [])->values();

            if ($lines->isEmpty()) {
                throw new DomainException(__('production_execution.messages.run_batch_lines_required'));
            }

            $lineIds = $lines->pluck('production_order_line_id')->map(fn (mixed $id): int => (int) $id);
            if ($lineIds->unique()->count() !== $lineIds->count()) {
                throw new DomainException(__('production_execution.messages.run_batch_duplicate_lines'));
            }

            $batchSequence = ProductionRunBatch::query()
                ->where('production_order_id', $order->getKey())
                ->count() + 1;
            $batch = ProductionRunBatch::query()->create([
                'batch_number' => sprintf('%s-B%03d', $order->doc_num, $batchSequence),
                'company_id' => $order->company_id,
                'financial_period_id' => $order->financial_period_id,
                'branch_id' => $order->branch_id,
                'production_order_id' => $order->getKey(),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $sharedRunData = collect($data)->except(['lines', 'notes'])->all();
            foreach ($lines as $lineData) {
                $line = ProductionOrderLine::query()
                    ->lockForUpdate()
                    ->findOrFail($lineData['production_order_line_id']);
                if ((int) $line->production_order_id !== (int) $order->getKey()) {
                    throw new DomainException(__('production_execution.messages.run_batch_same_order_required'));
                }
                $run = $this->createRun($line, [
                    ...$sharedRunData,
                    'production_run_batch_id' => $batch->getKey(),
                    'production_order_stage_snapshot_id' => $lineData['production_order_stage_snapshot_id'] ?? null,
                    'planned_quantity' => $lineData['planned_quantity'],
                ]);

            }

            return $batch->load(['order', 'runs.orderLine.product', 'runs.stageSnapshot', 'runs.requirements.product', 'runs.requirements.unit']);
        });
    }

    /** @param array<string, mixed> $data */
    public function createRun(ProductionOrderLine $orderLine, array $data): ProductionRun
    {
        return DB::transaction(function () use ($orderLine, $data): ProductionRun {
            $line = ProductionOrderLine::query()->lockForUpdate()->findOrFail($orderLine->getKey());
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($line->production_order_id);
            $line->setRelation('order', $order);

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
            $stageSnapshotId = isset($data['production_order_stage_snapshot_id'])
                ? (int) $data['production_order_stage_snapshot_id']
                : null;
            $hasConfiguredRoute = $this->hasConfiguredRoute($order, $line);
            $stageSnapshot = $this->runStageSnapshot($order, $line, $stageSnapshotId);

            if ($stageSnapshotId !== null && ! $stageSnapshot instanceof ProductionOrderStageSnapshot) {
                throw new DomainException(__('production_execution.messages.order_stage_selection_invalid'));
            }

            if ($stageSnapshot instanceof ProductionOrderStageSnapshot
                && ! $this->effectiveStageSnapshots($order, $line)->contains(fn (ProductionOrderStageSnapshot $stage): bool => (int) $stage->getKey() === $stageSnapshotId)) {
                throw new DomainException(__('production_execution.messages.order_stage_selection_invalid'));
            }

            if ($hasConfiguredRoute && ! $stageSnapshot instanceof ProductionOrderStageSnapshot) {
                throw new DomainException(__('production_execution.messages.run_stage_required'));
            }

            $remaining = bcsub((string) $line->base_quantity, $this->committedRunQuantity($line, $stageSnapshotId), 8);

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
            $fixedAssetId = isset($data['fixed_asset_id']) ? (int) $data['fixed_asset_id'] : null;
            $batchId = isset($data['production_run_batch_id']) ? (int) $data['production_run_batch_id'] : null;
            $this->assertFixedAsset($order, $fixedAssetId, $startsAt, $endsAt, null, $batchId);
            $this->assertResources($order, (int) $line->product_id, $machineId, $moldId, $startsAt, $endsAt, null, $batchId);
            $runSequence = ProductionRun::withTrashed()
                ->where('production_order_id', $order->getKey())
                ->count() + 1;
            $run = ProductionRun::query()->create([
                'run_number' => sprintf('%s-R%03d', $order->doc_num, $runSequence),
                'company_id' => $order->company_id,
                'financial_period_id' => $order->financial_period_id,
                'branch_id' => $order->branch_id,
                'production_order_id' => $order->getKey(),
                'production_run_batch_id' => $data['production_run_batch_id'] ?? null,
                'production_order_line_id' => $line->getKey(),
                'production_order_stage_snapshot_id' => $stageSnapshotId,
                'product_id' => $line->product_id,
                'unit_id' => $line->unit_id,
                'conversion_factor' => $line->conversion_factor,
                'planned_quantity' => $plannedQuantity,
                'planned_base_quantity' => $plannedBaseQuantity,
                'planned_start_at' => $startsAt,
                'planned_end_at' => $endsAt,
                'production_machine_id' => $machineId,
                'fixed_asset_id' => $fixedAssetId,
                'production_mold_id' => $moldId,
                'batch_lot' => $data['batch_lot'] ?? null,
                'work_description' => $data['work_description'] ?? null,
                'planned_labor_count' => $data['planned_labor_count'] ?? null,
                'labor_details' => $this->laborDetails($order, $data['labor_details'] ?? [], false),
                'status' => ProductionRun::StatusPlanned,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $this->audit->clearCreationUpdateAudit($run);

            $this->replaceRunRequirements($run, $line, $stageSnapshot, $plannedBaseQuantity);

            return $run->load(['requirements.product', 'order', 'orderLine']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updatePlannedRun(ProductionRun $run, array $data): ProductionRun
    {
        return DB::transaction(function () use ($run, $data): ProductionRun {
            $locked = ProductionRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $this->assertRunPlanCanBeChanged($locked);

            $line = ProductionOrderLine::query()->lockForUpdate()->findOrFail($locked->production_order_line_id);
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($line->production_order_id);
            $line->setRelation('order', $order);
            if ((int) ($data['production_order_line_id'] ?? $line->getKey()) !== (int) $line->getKey()) {
                throw new DomainException(__('production_execution.messages.run_order_line_locked'));
            }
            if (! in_array($order->status, [
                ProductionOrder::StatusReleased,
                ProductionOrder::StatusInProgress,
                ProductionOrder::StatusPartiallyCompleted,
            ], true)) {
                throw new DomainException(__('production_execution.messages.run_requires_released_order'));
            }
            if (! is_array($line->bom_snapshot) || empty($line->bom_snapshot['components'])) {
                throw new DomainException(__('production_execution.messages.run_requires_bom_snapshot'));
            }

            $stageSnapshotId = isset($data['production_order_stage_snapshot_id'])
                ? (int) $data['production_order_stage_snapshot_id']
                : null;
            $hasConfiguredRoute = $this->hasConfiguredRoute($order, $line);
            $stageSnapshot = $this->runStageSnapshot($order, $line, $stageSnapshotId);
            if ($stageSnapshotId !== null && ! $stageSnapshot instanceof ProductionOrderStageSnapshot) {
                throw new DomainException(__('production_execution.messages.order_stage_selection_invalid'));
            }

            if ($stageSnapshot instanceof ProductionOrderStageSnapshot
                && ! $this->effectiveStageSnapshots($order, $line)->contains(fn (ProductionOrderStageSnapshot $stage): bool => (int) $stage->getKey() === $stageSnapshotId)) {
                throw new DomainException(__('production_execution.messages.order_stage_selection_invalid'));
            }
            if ($hasConfiguredRoute && ! $stageSnapshot instanceof ProductionOrderStageSnapshot) {
                throw new DomainException(__('production_execution.messages.run_stage_required'));
            }

            $plannedQuantity = (string) $data['planned_quantity'];
            $plannedBaseQuantity = bcmul($plannedQuantity, (string) $line->conversion_factor, 8);
            $remaining = bcsub(
                (string) $line->base_quantity,
                $this->committedRunQuantity($line, $stageSnapshotId, (int) $locked->getKey()),
                8,
            );
            if (bccomp($plannedBaseQuantity, '0', 8) <= 0 || bccomp($plannedBaseQuantity, $remaining, 8) > 0) {
                throw new DomainException(__('production_execution.messages.run_quantity_exceeds_remaining'));
            }

            $startsAt = CarbonImmutable::parse($data['planned_start_at']);
            $endsAt = CarbonImmutable::parse($data['planned_end_at']);
            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw new DomainException(__('production_execution.messages.run_end_after_start'));
            }

            $machineId = isset($data['production_machine_id']) ? (int) $data['production_machine_id'] : null;
            $moldId = $locked->production_mold_id === null ? null : (int) $locked->production_mold_id;
            $fixedAssetId = isset($data['fixed_asset_id']) ? (int) $data['fixed_asset_id'] : null;
            $this->assertFixedAsset($order, $fixedAssetId, $startsAt, $endsAt, (int) $locked->getKey(), $locked->production_run_batch_id === null ? null : (int) $locked->production_run_batch_id);
            $this->assertResources($order, (int) $line->product_id, $machineId, $moldId, $startsAt, $endsAt, (int) $locked->getKey(), $locked->production_run_batch_id === null ? null : (int) $locked->production_run_batch_id);

            $this->audit->saveUpdate($locked, [
                'production_order_stage_snapshot_id' => $stageSnapshotId,
                'planned_quantity' => $plannedQuantity,
                'planned_base_quantity' => $plannedBaseQuantity,
                'planned_start_at' => $startsAt,
                'planned_end_at' => $endsAt,
                'production_machine_id' => $machineId,
                'fixed_asset_id' => $fixedAssetId,
                'production_shift_id' => null,
                'cost_center_id' => null,
                'batch_lot' => $data['batch_lot'] ?? null,
                'work_description' => $data['work_description'] ?? null,
                'planned_labor_count' => $data['planned_labor_count'] ?? null,
                'labor_details' => $this->laborDetails($order, $data['labor_details'] ?? [], false),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->replaceRunRequirements($locked, $line, $stageSnapshot, $plannedBaseQuantity);

            return $locked->refresh()->load(['requirements.product', 'order', 'orderLine']);
        });
    }

    public function deletePlannedRun(ProductionRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = ProductionRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $this->assertRunPlanCanBeChanged($locked);
            $this->audit->softDelete($locked);
        });
    }

    public function restorePlannedRun(ProductionRun $run): ProductionRun
    {
        return DB::transaction(function () use ($run): ProductionRun {
            $locked = ProductionRun::withTrashed()->lockForUpdate()->findOrFail($run->getKey());
            if (! $locked->trashed()) {
                throw new DomainException(__('production_execution.messages.run_not_deleted'));
            }
            if ($locked->status !== ProductionRun::StatusPlanned) {
                throw new DomainException(__('production_execution.messages.run_plan_only'));
            }

            $line = ProductionOrderLine::query()->lockForUpdate()->findOrFail($locked->production_order_line_id);
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($line->production_order_id);
            $line->setRelation('order', $order);
            if (! in_array($order->status, [ProductionOrder::StatusReleased, ProductionOrder::StatusInProgress, ProductionOrder::StatusPartiallyCompleted], true)) {
                throw new DomainException(__('production_execution.messages.run_requires_released_order'));
            }
            $remaining = bcsub(
                (string) $line->base_quantity,
                $this->committedRunQuantity($line, $locked->production_order_stage_snapshot_id),
                8,
            );
            if (bccomp((string) $locked->planned_base_quantity, $remaining, 8) > 0) {
                throw new DomainException(__('production_execution.messages.run_quantity_exceeds_remaining'));
            }

            $startsAt = CarbonImmutable::parse($locked->planned_start_at);
            $endsAt = CarbonImmutable::parse($locked->planned_end_at);
            $this->assertFixedAsset($order, $locked->fixed_asset_id, $startsAt, $endsAt, (int) $locked->getKey());
            $this->assertResources($order, (int) $line->product_id, $locked->production_machine_id, $locked->production_mold_id, $startsAt, $endsAt, (int) $locked->getKey());

            $this->audit->restore($locked);

            return $locked->refresh();
        });
    }

    public function reserveRun(ProductionRun $run, int $branchStoreId, ?int $warehouseLocationId = null): ProductionRun
    {
        return DB::transaction(function () use ($run, $branchStoreId, $warehouseLocationId): ProductionRun {
            $locked = ProductionRun::query()
                ->with(['requirements', 'stageSnapshot', 'orderLine'])
                ->lockForUpdate()
                ->findOrFail($run->getKey());

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

    public function issueRunBatchMaterials(
        ProductionRunBatch $batch,
        int $branchStoreId,
        ?int $warehouseLocationId = null,
    ): InventoryDocument {
        return DB::transaction(function () use ($batch, $branchStoreId, $warehouseLocationId): InventoryDocument {
            $lockedBatch = ProductionRunBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($lockedBatch->production_order_id);
            $store = BranchStore::query()->where('branch_id', $order->branch_id)->lockForUpdate()->findOrFail($branchStoreId);
            if ((int) $store->branch_id !== (int) $order->branch_id) {
                throw new DomainException(__('production_execution.messages.document_outside_context'));
            }

            $runs = ProductionRun::query()
                ->where('production_run_batch_id', $lockedBatch->getKey())
                ->with('requirements.run')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($runs->isEmpty()) {
                throw new DomainException(__('production_execution.messages.run_batch_lines_required'));
            }

            $movementLines = [];
            $issuedByRequirement = [];
            foreach ($runs as $run) {
                if (! in_array($run->status, [
                    ProductionRun::StatusPlanned,
                    ProductionRun::StatusSetup,
                    ProductionRun::StatusReady,
                ], true)) {
                    throw new DomainException(__('production_execution.messages.batch_issue_before_production'));
                }

                foreach ($run->requirements as $requirement) {
                    $remainingPlanned = bcsub((string) $requirement->planned_quantity, (string) $requirement->issued_quantity, 8);
                    if (bccomp($remainingPlanned, '0', 8) <= 0) {
                        continue;
                    }

                    $activeReservations = InventoryReservation::query()
                        ->where('production_material_requirement_id', $requirement->getKey())
                        ->where('status', InventoryReservation::StatusActive)
                        ->whereRaw('(quantity - consumed_quantity - released_quantity) > 0')
                        ->lockForUpdate()
                        ->get();
                    $reservedOutsideStore = $activeReservations->contains(fn (InventoryReservation $reservation): bool => (int) $reservation->branch_store_id !== (int) $store->getKey());
                    if ($reservedOutsideStore) {
                        throw new DomainException(__('production_execution.messages.batch_reservations_same_store'));
                    }
                    $reserved = $activeReservations->reduce(
                        fn (string $total, InventoryReservation $reservation): string => bcadd($total, (string) $reservation->remaining_quantity, 8),
                        '0.00000000',
                    );
                    $targetQuantity = bccomp($remainingPlanned, $reserved, 8) <= 0 ? $remainingPlanned : $reserved;
                    $stillNeeded = bcsub($remainingPlanned, $targetQuantity, 8);
                    if (bccomp($stillNeeded, '0', 8) > 0) {
                        $newlyReserved = $this->reserveAvailableForRequirement(
                            $requirement,
                            (int) $store->getKey(),
                            $stillNeeded,
                            $warehouseLocationId,
                        );
                        $targetQuantity = bcadd($targetQuantity, $newlyReserved, 8);
                    }
                    if (bccomp($targetQuantity, '0', 8) <= 0) {
                        continue;
                    }

                    $issuedByRequirement[$requirement->getKey()] = $targetQuantity;
                }
            }

            foreach ($runs as $run) {
                foreach ($run->requirements as $requirement) {
                    $targetQuantity = $issuedByRequirement[$requirement->getKey()] ?? null;
                    if ($targetQuantity === null) {
                        continue;
                    }

                    foreach ($this->reservations->consumeForRequirement($requirement, $targetQuantity) as $consumption) {
                        $reservation = $consumption['reservation'];
                        $movementLines[] = [
                            'product_id' => $requirement->product_id,
                            'unit_id' => $requirement->unit_id,
                            'quantity' => $consumption['quantity'],
                            'warehouse_location_id' => $reservation->warehouse_location_id,
                            'destination_warehouse_location_id' => $reservation->warehouse_location_id,
                            'batch_lot' => $reservation->batch_lot,
                            'inventory_reservation_id' => $reservation->getKey(),
                            'production_run_id' => $run->getKey(),
                            'source_line_type' => ProductionMaterialRequirement::class,
                            'source_line_id' => $requirement->getKey(),
                        ];
                    }
                }
            }

            if ($movementLines === []) {
                throw new DomainException(__('No positive material issue quantities were supplied.'));
            }

            $context = $this->movementContext($runs->first(), (int) $store->getKey());
            $context['production_run_id'] = null;
            $context['production_run_batch_id'] = $lockedBatch->getKey();
            $context['production_order_id'] = $order->getKey();
            $context['source_document_type'] = ProductionRunBatch::class;
            $context['source_document_id'] = $lockedBatch->getKey();
            $context['source_doc_num'] = $lockedBatch->batch_number;
            $document = $this->movements->createAndPost([
                ...$context,
                'destination_branch_store_id' => $store->getKey(),
                'document_type' => InventoryDocument::TypeMaterialIssue,
                'purpose' => 'Production batch material issue '.$lockedBatch->batch_number,
                'source_stock_status' => InventoryTransaction::StatusAvailable,
                'destination_stock_status' => InventoryTransaction::StatusProductionStaging,
            ], $movementLines);

            foreach ($issuedByRequirement as $requirementId => $quantity) {
                ProductionMaterialRequirement::query()->whereKey($requirementId)->increment('issued_quantity', $quantity);
            }

            return $document;
        });
    }

    /** @return list<InventoryDocument> */
    public function receiveRunBatchFinishedGoods(
        ProductionRunBatch $batch,
        int $branchStoreId,
        ?int $warehouseLocationId = null,
    ): array {
        return DB::transaction(function () use ($batch, $branchStoreId, $warehouseLocationId): array {
            $lockedBatch = ProductionRunBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            $runs = ProductionRun::query()
                ->where('production_run_batch_id', $lockedBatch->getKey())
                ->with(['order', 'orderLine.product', 'orderLine.unit', 'stageSnapshot'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $documents = [];

            foreach ($runs as $run) {
                $remainingGood = bcsub((string) $run->good_base_quantity, (string) $run->received_base_quantity, 8);
                if (bccomp($remainingGood, '0', 8) <= 0) {
                    continue;
                }

                if ($run->status !== ProductionRun::StatusRunning) {
                    throw new DomainException(__('production_execution.messages.run_batch_output_not_receivable'));
                }

                $stages = $this->effectiveStageSnapshots($run->order, $run->orderLine);
                if ($stages->isNotEmpty()) {
                    $currentStage = $stages->firstWhere('id', $run->production_order_stage_snapshot_id);
                    $finalStage = $stages->last();
                    if (! $currentStage || ! $finalStage || (int) $currentStage->getKey() !== (int) $finalStage->getKey()) {
                        throw new DomainException(__('production_execution.messages.run_batch_output_not_receivable'));
                    }
                }

                $documents[] = $this->receiveFinishedGoods($run, $branchStoreId, $remainingGood, $warehouseLocationId);
            }

            if ($documents === []) {
                throw new DomainException(__('production_execution.messages.run_batch_output_not_receivable'));
            }

            return $documents;
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

            $this->assertPreviousStageOutputAvailable($locked);

            $locked->update([
                'status' => ProductionRun::StatusRunning,
                'actual_start_at' => now(),
                'started_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            if ($locked->production_order_stage_snapshot_id !== null) {
                $stage = ProductionOrderStageSnapshot::query()->lockForUpdate()->findOrFail($locked->production_order_stage_snapshot_id);
                $previousStatus = $stage->status;
                $stage->update([
                    'status' => ProductionOrderStageSnapshot::StatusInProgress,
                    'started_at' => $stage->started_at ?? now(),
                ]);
                app(ProductionRoutingService::class)->recordStageEvent(
                    $stage,
                    'run_started',
                    $previousStatus,
                    $stage->status,
                    (int) $locked->getKey(),
                );
            }
            $locked->order()->update(['status' => ProductionOrder::StatusInProgress, 'updated_by' => auth()->id()]);

            return $locked->refresh();
        });
    }

    public function resumeRun(ProductionRun $run): ProductionRun
    {
        return DB::transaction(function () use ($run): ProductionRun {
            $locked = ProductionRun::query()->with('inspections')->lockForUpdate()->findOrFail($run->getKey());

            $latestInspection = $locked->inspections()->reorder()->latest('sampled_at')->latest('id')->first();

            if ($locked->status !== ProductionRun::StatusHeld
                || $latestInspection?->status !== ProductionQualityInspection::StatusClosed
                || $latestInspection?->approved_at === null
                || $latestInspection?->result !== 'passed'
                || $latestInspection?->disposition !== 'release') {
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
    public function recordLabor(ProductionRun $run, array $data): ProductionRun
    {
        return DB::transaction(function () use ($run, $data): ProductionRun {
            $locked = ProductionRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if (! in_array($locked->status, [ProductionRun::StatusRunning, ProductionRun::StatusHeld], true)) {
                throw new DomainException(__('production_execution.messages.labor_running_only'));
            }

            $laborDetails = $this->laborDetails($locked, $data['labor_details'] ?? [], true);

            $locked->update([
                'actual_labor_count' => (int) $data['actual_labor_count'],
                'labor_details' => $laborDetails,
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $details
     * @return list<array<string, mixed>>
     */
    private function laborDetails(ProductionOrder|ProductionRun $context, array $details, bool $actual): array
    {
        if ($details === []) {
            return [];
        }

        $employees = HrEmployee::query()
            ->where('company_id', $context->company_id)
            ->where('branch_id', $context->branch_id)
            ->whereIn('person_type', ['regular_labor', 'casual_labor'])
            ->where('status', 'active')
            ->whereIn('id', collect($details)->pluck('employee_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($employees->count() !== count($details)) {
            throw new DomainException(__('production_execution.messages.run_labor_invalid'));
        }

        return collect($details)->map(function (array $labor) use ($employees, $actual): array {
            $employee = $employees->get((int) $labor['employee_id']);

            return [
                'employee_id' => $employee->getKey(),
                'employee_doc_num' => $employee->doc_num,
                'name' => $employee->full_name ?: $employee->name,
                'role' => filled($labor['role'] ?? null) ? trim((string) $labor['role']) : $employee->job_title,
                'planned_hours' => filled($labor['planned_hours'] ?? null) ? (string) $labor['planned_hours'] : null,
                'actual_hours' => $actual ? (string) $labor['actual_hours'] : null,
                'notes' => filled($labor['notes'] ?? null) ? trim((string) $labor['notes']) : null,
                ...($actual ? ['recorded_by' => auth()->id(), 'recorded_at' => now()->toIso8601String()] : []),
            ];
        })->values()->all();
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

            if ($inspectionTypeId !== null) {
                $activeCheckpoints = DB::table('quality_checkpoints')
                    ->where('company_id', $locked->company_id)
                    ->where('quality_inspection_type_id', $inspectionTypeId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->get(['id', 'response_type', 'is_required']);
                $validCheckpointCount = $activeCheckpoints->whereIn('id', $checkpointIds)->count();

                if ($validCheckpointCount !== $checkpointIds->count()) {
                    throw new DomainException(__('Quality checkpoints must be active and belong to the selected inspection type and operating company.'));
                }

                $missingRequiredCheckpoints = $activeCheckpoints
                    ->where('is_required', true)
                    ->pluck('id')
                    ->diff($checkpointIds);

                if ($missingRequiredCheckpoints->isNotEmpty()) {
                    throw new DomainException(__('production_execution.messages.required_quality_checkpoints_missing'));
                }

                $resultsByCheckpoint = collect($data['results'] ?? [])->keyBy(fn (array $result): int => (int) $result['quality_checkpoint_id']);
                $missingMeasurements = $activeCheckpoints
                    ->where('is_required', true)
                    ->where('response_type', 'numeric')
                    ->pluck('id')
                    ->filter(fn (int $checkpointId): bool => blank($resultsByCheckpoint->get($checkpointId)['measured_value'] ?? null));

                if ($missingMeasurements->isNotEmpty()) {
                    throw new DomainException(__('production_execution.messages.required_quality_measurements_missing'));
                }
            } elseif ($checkpointIds->isNotEmpty()) {
                throw new DomainException(__('Quality checkpoints must be active and belong to the selected inspection type and operating company.'));
            }

            $checkpointResults = collect($data['results'] ?? [])->pluck('result');
            if (($checkpointResults->contains('failed') && $data['result'] !== 'failed')
                || ($checkpointResults->contains('conditional') && $data['result'] === 'passed')) {
                throw new DomainException(__('production_execution.messages.quality_overall_result_inconsistent'));
            }

            if ($data['result'] === 'failed' && ($data['disposition'] ?? 'hold') === 'release') {
                throw new DomainException(__('production_execution.messages.failed_quality_cannot_release'));
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
                'production_order_stage_id' => $locked->production_order_stage_snapshot_id,
                'subject_type' => ProductionQualityInspection::SubjectProductionRun,
                'quality_inspection_type_id' => $inspectionTypeId,
                'inspection_plan_snapshot' => $this->planSnapshots->capture((int) $locked->company_id, $inspectionTypeId),
                'version' => 1,
                'reinspection_number' => 0,
                'inspection_date' => now()->toDateString(),
                'sampled_at' => now(),
                'status' => ProductionQualityInspection::StatusSubmitted,
                'result' => $data['result'],
                'disposition' => $data['disposition'] ?? ($data['result'] === 'passed' ? 'release' : 'hold'),
                'defect_code' => $data['defect_code'] ?? null,
                'affected_base_quantity' => $data['affected_base_quantity'] ?? null,
                'inspector_id' => auth()->id(),
                'notes' => $data['notes'] ?? null,
                'rework_notes' => $data['rework_notes'] ?? null,
                'corrective_action' => $data['corrective_action'] ?? null,
                'evidence' => $data['evidence'] ?? null,
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
                'requested_by' => auth()->id(),
                'requested_at' => now(),
                'received_by' => auth()->id(),
                'received_at' => now(),
                'started_by' => auth()->id(),
                'started_at' => now(),
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

            if ($inspection->result === 'passed'
                && $inspection->disposition === 'release'
                && auth()->user()?->can('production.quality.release_normal')) {
                $inspection->update([
                    'status' => ProductionQualityInspection::StatusApproved,
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                    'released_by' => auth()->id(),
                    'released_at' => now(),
                    'updated_by' => auth()->id(),
                ]);
            }

            if ($data['result'] === 'failed') {
                $locked->update(['status' => ProductionRun::StatusHeld, 'updated_by' => auth()->id()]);
            }

            return $inspection->load('results');
        });
    }

    public function reviewInspection(ProductionQualityInspection $inspection, bool $approved, ?string $reason = null): ProductionQualityInspection
    {
        return DB::transaction(function () use ($inspection, $approved, $reason): ProductionQualityInspection {
            $locked = ProductionQualityInspection::query()->with('run')->lockForUpdate()->findOrFail($inspection->getKey());

            if ($locked->status !== ProductionQualityInspection::StatusSubmitted) {
                throw new DomainException(__('production_execution.messages.quality_review_submitted_only'));
            }

            if (! $approved && blank($reason)) {
                throw new DomainException(__('production_execution.messages.quality_rejection_reason_required'));
            }

            $locked->update($approved ? [
                'status' => ProductionQualityInspection::StatusApproved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'updated_by' => auth()->id(),
            ] : [
                'status' => ProductionQualityInspection::StatusRejected,
                'rejected_by' => auth()->id(),
                'rejected_at' => now(),
                'rejection_reason' => trim((string) $reason),
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            if ($approved && $locked->result === 'passed' && $locked->disposition === 'release' && $locked->run?->status === ProductionRun::StatusHeld) {
                $locked->update(['released_by' => auth()->id(), 'released_at' => now()]);
            }

            return $locked->refresh();
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
            if ($salesLine && (! $salesLine->order->isApprovedForFulfillment()
                || ($salesLine->order->branch_store_id !== null && (int) $salesLine->order->branch_store_id !== $branchStoreId))) {
                throw new DomainException(__('Receive sales production into the source order warehouse while the order is open.'));
            }
            BranchStore::query()->lockForUpdate()->findOrFail($branchStoreId);
            Product::query()->lockForUpdate()->findOrFail($locked->product_id);

            if ($locked->status !== ProductionRun::StatusRunning) {
                throw new DomainException(__('Finished goods can only be received from a running production run that is not on quality hold.'));
            }

            $stageSnapshots = $this->effectiveStageSnapshots($locked->order, $locked->orderLine);
            if ($stageSnapshots->isNotEmpty()) {
                $currentStage = $stageSnapshots->firstWhere('id', $locked->production_order_stage_snapshot_id);
                $finalStage = $stageSnapshots->last();

                if (! $currentStage || ! $finalStage || (int) $currentStage->getKey() !== (int) $finalStage->getKey()) {
                    throw new DomainException(__('production_execution.messages.finished_goods_final_stage_only'));
                }

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

            if ($finalInspectionRequired
                && (! in_array($latestFinalInspection?->status, [ProductionQualityInspection::StatusApproved, ProductionQualityInspection::StatusClosed], true)
                    || $latestFinalInspection?->approved_at === null
                    || $latestFinalInspection?->result !== 'passed'
                    || $latestFinalInspection?->disposition !== 'release')) {
                throw new DomainException(__('A final passed quality inspection is required before finished goods become available.'));
            }

            $receiptCost = $this->costs->receiptCost($locked, $baseQuantity);
            $unitCost = bccomp($receiptCost, '0', 8) > 0 ? bcdiv($receiptCost, $baseQuantity, 8) : null;
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
            $locked = ProductionRun::query()
                ->with(['requirements', 'inspections', 'order.lines', 'order.orderStageSnapshots', 'orderLine.stageSnapshots'])
                ->lockForUpdate()
                ->findOrFail($run->getKey());

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

            $stages = $this->effectiveStageSnapshots($locked->order, $locked->orderLine);
            $currentStage = $stages->firstWhere('id', $locked->production_order_stage_snapshot_id);
            $isFinalStage = $stages->isEmpty()
                || ($currentStage && (int) $stages->last()->getKey() === (int) $currentStage->getKey());

            if (bccomp((string) $locked->good_base_quantity, '0', 8) <= 0) {
                throw new DomainException(__('production_execution.messages.run_good_output_required'));
            }
            if ($isFinalStage && bccomp((string) $locked->received_base_quantity, (string) $locked->good_base_quantity, 8) !== 0) {
                throw new DomainException(__('production_execution.messages.final_run_receipt_required'));
            }

            $latestInspection = $locked->inspections()->reorder()->latest('sampled_at')->latest('id')->first();

            if ($locked->status === ProductionRun::StatusHeld
                || ($latestInspection && (! in_array($latestInspection->status, [ProductionQualityInspection::StatusApproved, ProductionQualityInspection::StatusClosed], true)
                    || $latestInspection->approved_at === null
                    || $latestInspection->result === 'failed'))) {
                throw new DomainException(__('Failed quality inspections must be resolved before run completion.'));
            }

            $locked->update([
                'status' => ProductionRun::StatusCompleted,
                'actual_end_at' => now(),
                'completed_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            if ($locked->production_order_stage_snapshot_id !== null) {
                $stage = ProductionOrderStageSnapshot::query()->lockForUpdate()->findOrFail($locked->production_order_stage_snapshot_id);
                $completedByLine = ProductionRun::query()
                    ->where('production_order_stage_snapshot_id', $stage->getKey())
                    ->where('status', ProductionRun::StatusCompleted)
                    ->get(['production_order_line_id', 'good_base_quantity'])
                    ->groupBy('production_order_line_id')
                    ->map(fn ($runs): string => $runs->reduce(
                        fn (string $total, ProductionRun $completedRun): string => bcadd(
                            $total,
                            (string) $completedRun->good_base_quantity,
                            8,
                        ),
                        '0.00000000',
                    ));
                $stageQuantityIsComplete = $stage->production_order_line_id === null
                    ? $locked->order->lines->every(fn (ProductionOrderLine $line): bool => bccomp(
                        (string) $completedByLine->get((string) $line->getKey(), '0.00000000'),
                        (string) $line->base_quantity,
                        8,
                    ) >= 0)
                    : bccomp(
                        (string) $completedByLine->get((string) $locked->orderLine->getKey(), '0.00000000'),
                        (string) $locked->orderLine->base_quantity,
                        8,
                    ) >= 0;

                if ($stageQuantityIsComplete
                    && $stage->status !== ProductionOrderStageSnapshot::StatusCompleted) {
                    $previousStatus = $stage->status;
                    $stage->update([
                        'status' => ProductionOrderStageSnapshot::StatusCompleted,
                        'completed_at' => now(),
                        'completed_by' => auth()->id(),
                    ]);

                    app(ProductionRoutingService::class)->recordStageEvent(
                        $stage,
                        'stage_completed',
                        $previousStatus,
                        $stage->status,
                        (int) $locked->getKey(),
                    );
                } else {
                    app(ProductionRoutingService::class)->recordStageEvent(
                        $stage,
                        'run_completed',
                        $stage->status,
                        $stage->status,
                        (int) $locked->getKey(),
                    );
                }
            }
            $this->reservations->releaseRun((int) $locked->getKey(), 'Production run completed');
            $this->refreshOrderStatus($locked->order);

            return $locked->refresh();
        });
    }

    public function shortCloseOrder(ProductionOrder $order, string $reason): ProductionOrder
    {
        return DB::transaction(function () use ($order, $reason): ProductionOrder {
            $locked = ProductionOrder::query()->with(['runs', 'lines'])->lockForUpdate()->findOrFail($order->getKey());

            if (trim($reason) === '' || in_array($locked->status, [ProductionOrder::StatusCompleted, ProductionOrder::StatusCancelled], true)) {
                throw new DomainException(__('An open production order and a short-close reason are required.'));
            }

            foreach ($locked->runs->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled]) as $run) {
                $this->cancelRun($run, 'Production order short-closed: '.trim($reason));
            }

            $this->releaseUnproducedSalesDemand($locked);

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

    private function releaseUnproducedSalesDemand(ProductionOrder $order): void
    {
        foreach ($order->lines->whereNotNull('sales_order_line_id') as $productionLine) {
            $salesLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($productionLine->sales_order_line_id);
            $unproducedBase = $this->nonnegative(bcsub(
                (string) $productionLine->base_quantity,
                (string) $productionLine->received_base_quantity,
                8,
            ));

            if (bccomp($unproducedBase, '0', 8) <= 0) {
                continue;
            }

            $unproducedQuantity = bcdiv($unproducedBase, (string) $productionLine->conversion_factor, 8);
            $salesLine->update([
                'production_requested_quantity' => $this->nonnegative(bcsub(
                    (string) $salesLine->production_requested_quantity,
                    $unproducedQuantity,
                    8,
                )),
                'production_requested_base_quantity' => $this->nonnegative(bcsub(
                    (string) $salesLine->production_requested_base_quantity,
                    $unproducedBase,
                    8,
                )),
            ]);
        }
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
        ?int $excludeRunId = null,
        ?int $excludeRunBatchId = null,
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
            ->when($excludeRunId !== null, fn ($query) => $query->whereKeyNot($excludeRunId))
            ->when($excludeRunBatchId !== null, fn ($query) => $query->where(fn ($scope) => $scope
                ->whereNull('production_run_batch_id')
                ->orWhere('production_run_batch_id', '<>', $excludeRunBatchId)))
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

    private function assertFixedAsset(
        ProductionOrder $order,
        ?int $fixedAssetId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $excludeRunId = null,
        ?int $excludeRunBatchId = null,
    ): void {
        if ($fixedAssetId === null) {
            return;
        }

        $asset = FixedAsset::query()->lockForUpdate()->findOrFail($fixedAssetId);
        if ((int) $asset->company_id !== (int) $order->company_id
            || (int) $asset->branch_id !== (int) $order->branch_id
            || $asset->status !== FixedAsset::StatusActive) {
            throw new DomainException(__('production_execution.messages.fixed_asset_unavailable'));
        }

        $conflict = ProductionRun::query()
            ->when($excludeRunId !== null, fn ($query) => $query->whereKeyNot($excludeRunId))
            ->when($excludeRunBatchId !== null, fn ($query) => $query->where(fn ($scope) => $scope
                ->whereNull('production_run_batch_id')
                ->orWhere('production_run_batch_id', '<>', $excludeRunBatchId)))
            ->where('fixed_asset_id', $fixedAssetId)
            ->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled])
            ->where('planned_start_at', '<', $endsAt)
            ->where('planned_end_at', '>', $startsAt)
            ->lockForUpdate()
            ->exists();

        if ($conflict) {
            throw new DomainException(__('production_execution.messages.fixed_asset_schedule_conflict'));
        }
    }

    private function assertRunPlanCanBeChanged(ProductionRun $run): void
    {
        $hasMaterialActivity = $run->requirements()
            ->where(function ($query): void {
                $query->where('reserved_quantity', '>', 0)
                    ->orWhere('issued_quantity', '>', 0)
                    ->orWhere('additional_issued_quantity', '>', 0)
                    ->orWhere('returned_quantity', '>', 0)
                    ->orWhere('consumed_quantity', '>', 0)
                    ->orWhere('waste_quantity', '>', 0);
            })
            ->exists();
        $hasLinkedActivity = InventoryReservation::query()->where('production_run_id', $run->getKey())->exists()
            || InventoryTransaction::query()->where('production_run_id', $run->getKey())->exists()
            || $run->progressEntries()->exists()
            || $run->inspections()->exists()
            || $run->inventoryDocuments()->exists()
            || $run->materialRequests()->exists()
            || $run->expenseRequests()->exists();

        if ($run->status !== ProductionRun::StatusPlanned || $hasMaterialActivity || $hasLinkedActivity) {
            throw new DomainException(__('production_execution.messages.run_plan_only'));
        }
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
            'production_run_batch_id' => $run->production_run_batch_id,
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

    private function reserveAvailableForRequirement(
        ProductionMaterialRequirement $requirement,
        int $branchStoreId,
        string $requestedQuantity,
        ?int $warehouseLocationId,
    ): string {
        $run = $requirement->run;
        $remaining = bcadd($requestedQuantity, '0', 8);
        $reservedTotal = '0.00000000';

        while (bccomp($remaining, '0', 8) > 0) {
            $positions = InventoryTransaction::query()
                ->where('company_id', $run->company_id)
                ->where('branch_store_id', $branchStoreId)
                ->where('product_id', $requirement->product_id)
                ->where('stock_status', InventoryTransaction::StatusAvailable)
                ->when($warehouseLocationId !== null, fn ($query) => $query->where('warehouse_location_id', $warehouseLocationId))
                ->groupBy(['warehouse_location_id', 'batch_lot'])
                ->havingRaw('sum(quantity_in - quantity_out) > 0')
                ->orderByRaw('min(transaction_date), min(id)')
                ->get(['warehouse_location_id', 'batch_lot']);

            $selectedPosition = null;
            $positionAvailability = '0.00000000';
            foreach ($positions as $position) {
                $available = $this->availability->forProduct(
                    (int) $run->company_id,
                    $branchStoreId,
                    (int) $requirement->product_id,
                    null,
                    $position->warehouse_location_id === null ? null : (int) $position->warehouse_location_id,
                    InventoryTransaction::StatusAvailable,
                    $position->batch_lot,
                    true,
                )['available'];
                if (bccomp($available, '0', 8) > 0) {
                    $selectedPosition = $position;
                    $positionAvailability = $available;
                    break;
                }
            }

            if (! $selectedPosition || bccomp($positionAvailability, '0', 8) <= 0) {
                break;
            }

            $reserveQuantity = bccomp($remaining, $positionAvailability, 8) > 0
                ? $positionAvailability
                : $remaining;
            $this->reservations->reserveForProduction(
                $requirement,
                $branchStoreId,
                $reserveQuantity,
                $selectedPosition->warehouse_location_id === null ? null : (int) $selectedPosition->warehouse_location_id,
            );
            $remaining = bcsub($remaining, $reserveQuantity, 8);
            $reservedTotal = bcadd($reservedTotal, $reserveQuantity, 8);
        }

        return $reservedTotal;
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

    private function committedRunQuantity(
        ProductionOrderLine $line,
        ?int $stageSnapshotId,
        ?int $excludeRunId = null,
    ): string {
        return ProductionRun::query()
            ->where('production_order_line_id', $line->getKey())
            ->when($excludeRunId !== null, fn ($query) => $query->whereKeyNot($excludeRunId))
            ->when(
                $stageSnapshotId !== null,
                fn ($query) => $query->where('production_order_stage_snapshot_id', $stageSnapshotId),
                fn ($query) => $query->whereNull('production_order_stage_snapshot_id'),
            )
            ->where('status', '<>', ProductionRun::StatusCancelled)
            ->lockForUpdate()
            ->get(['status', 'planned_base_quantity', 'good_base_quantity'])
            ->reduce(
                fn (string $total, ProductionRun $run): string => bcadd(
                    $total,
                    $run->status === ProductionRun::StatusCompleted
                        ? (string) $run->good_base_quantity
                        : (string) $run->planned_base_quantity,
                    8,
                ),
                '0.00000000',
            );
    }

    private function hasConfiguredRoute(ProductionOrder $order, ProductionOrderLine $line): bool
    {
        return $order->orderStageSnapshots()->where('is_required', true)->exists()
            || $line->stageSnapshots()->where('is_required', true)->exists();
    }

    private function runStageSnapshot(
        ProductionOrder $order,
        ProductionOrderLine $line,
        ?int $stageSnapshotId,
    ): ?ProductionOrderStageSnapshot {
        if ($stageSnapshotId === null) {
            return null;
        }

        return ProductionOrderStageSnapshot::query()
            ->whereKey($stageSnapshotId)
            ->where('production_order_id', $order->getKey())
            ->where(fn ($query) => $query
                ->whereNull('production_order_line_id')
                ->orWhere('production_order_line_id', $line->getKey()))
            ->lockForUpdate()
            ->first();
    }

    /** @return Collection<int, ProductionOrderStageSnapshot> */
    public function stagesForLine(ProductionOrder $order, ProductionOrderLine $line): Collection
    {
        return $order->orderStageSnapshots()->where('is_required', true)->get()
            ->concat($line->stageSnapshots()->where('is_required', true)->get())
            ->unique('production_stage_id')
            ->values();
    }

    private function effectiveStageSnapshots(ProductionOrder $order, ProductionOrderLine $line): Collection
    {
        return $this->stagesForLine($order, $line);
    }

    private function replaceRunRequirements(
        ProductionRun $run,
        ProductionOrderLine $line,
        ?ProductionOrderStageSnapshot $stage,
        string $plannedBaseQuantity,
    ): void {
        $run->requirements()->delete();
        $components = collect($line->bom_snapshot['components'] ?? []);

        if ($stage instanceof ProductionOrderStageSnapshot) {
            $firstStageId = $line->order->orderStageSnapshots()
                ->where('is_required', true)
                ->orderBy('sequence')
                ->value('production_stage_id')
                ?? $line->stageSnapshots()
                    ->where('is_required', true)
                    ->orderBy('sequence')
                    ->value('production_stage_id');
            $components = $components->filter(function (array $component) use ($stage, $firstStageId): bool {
                $assignedStageId = isset($component['production_stage_id'])
                    ? (int) $component['production_stage_id']
                    : null;

                return $assignedStageId !== null
                    ? $assignedStageId === (int) $stage->production_stage_id
                    : (int) $stage->production_stage_id === (int) $firstStageId;
            });
        }

        foreach ($components->values() as $index => $component) {
            $equivalentFactor = (string) ($line->bom_snapshot['basis_base_quantity'] ?? '1.00000000');
            $plannedEquivalentQuantity = bcmul($plannedBaseQuantity, $equivalentFactor, 8);
            $plannedMaterial = bcmul((string) $component['base_quantity_per_output'], $plannedEquivalentQuantity, 8);
            $run->requirements()->create([
                'production_order_id' => $line->production_order_id,
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
    }

    private function assertPreviousStageOutputAvailable(ProductionRun $run): void
    {
        if (! $run->stageSnapshot instanceof ProductionOrderStageSnapshot) {
            return;
        }

        $line = ProductionOrderLine::query()->whereKey($run->production_order_line_id)->lockForUpdate()->firstOrFail();
        $order = ProductionOrder::query()->with('orderStageSnapshots')->findOrFail($run->production_order_id);
        $stages = $this->effectiveStageSnapshots($order, $line);
        $stageIndex = $stages->search(fn (ProductionOrderStageSnapshot $stage): bool => (int) $stage->getKey() === (int) $run->production_order_stage_snapshot_id);
        $previousStage = $stageIndex === false || $stageIndex === 0 ? null : $stages->get($stageIndex - 1);

        if (! $previousStage instanceof ProductionOrderStageSnapshot) {
            return;
        }

        $availableOutput = (string) ProductionRun::query()
            ->where('production_order_stage_snapshot_id', $previousStage->getKey())
            ->where('production_order_line_id', $line->getKey())
            ->where('status', ProductionRun::StatusCompleted)
            ->sum('good_base_quantity');
        $claimedOutput = ProductionRun::query()
            ->where('production_order_stage_snapshot_id', $run->production_order_stage_snapshot_id)
            ->where('production_order_line_id', $line->getKey())
            ->whereKeyNot($run->getKey())
            ->whereIn('status', [ProductionRun::StatusRunning, ProductionRun::StatusHeld, ProductionRun::StatusCompleted])
            ->lockForUpdate()
            ->get(['status', 'planned_base_quantity', 'good_base_quantity'])
            ->reduce(
                fn (string $total, ProductionRun $claimedRun): string => bcadd(
                    $total,
                    $claimedRun->status === ProductionRun::StatusCompleted
                        ? (string) $claimedRun->good_base_quantity
                        : (string) $claimedRun->planned_base_quantity,
                    8,
                ),
                '0.00000000',
            );

        if (bccomp(bcadd($claimedOutput, (string) $run->planned_base_quantity, 8), $availableOutput, 8) > 0) {
            throw new DomainException(__('production_execution.messages.previous_stage_output_insufficient'));
        }
    }
}

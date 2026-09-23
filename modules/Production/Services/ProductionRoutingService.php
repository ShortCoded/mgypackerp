<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionOrderStageEvent;
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Models\ProductProductionStage;

class ProductionRoutingService
{
    public function __construct(
        private readonly CrudAuditService $audit,
        private readonly DocumentNumberService $numbers,
        private readonly OperatingContextService $context,
    ) {}

    /** @param array<string, mixed> $data */
    public function createStage(array $data): ProductionStage
    {
        return DB::transaction(function () use ($data): ProductionStage {
            $context = $this->requireFactoryContext();
            $companyId = $context['company_id'];
            $code = $this->numbers->nextForCompany('production_stages', ProductionStage::class, $companyId)['code'];
            $this->assertUniqueStageCode($companyId, $code);
            $stage = ProductionStage::query()->create([
                ...$this->stageValues($data),
                'code' => $code,
                'company_id' => $companyId,
                'branch_id' => $context['branch_id'],
                'created_by' => auth()->id(),
            ]);
            $this->audit->clearCreationUpdateAudit($stage);

            return $stage->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateStage(ProductionStage $stage, array $data): ProductionStage
    {
        return DB::transaction(function () use ($stage, $data): ProductionStage {
            $locked = $this->lockStage($stage);
            $values = $this->stageValues($data);
            unset($values['code']);
            $values['branch_id'] = $locked->branch_id ?? $this->requireFactoryContext()['branch_id'];
            $this->audit->saveUpdate($locked, $values);

            return $locked->refresh();
        });
    }

    public function deleteStage(ProductionStage $stage): void
    {
        DB::transaction(function () use ($stage): void {
            $locked = $this->lockStage($stage);

            if ($locked->branch_id === null) {
                $this->audit->saveUpdate($locked, ['branch_id' => $this->requireFactoryContext()['branch_id']]);
            }

            if ($locked->productStages()->where('status', ProductionStage::StatusActive)->exists()) {
                throw new DomainException(__('production_execution.messages.stage_in_use'));
            }

            $this->audit->softDelete($locked);
        });
    }

    /**
     * @param  list<array{production_stage_id: int, standard_duration_value?: mixed, standard_duration_unit?: string|null, notes?: string|null}>  $rows
     * @param  array<string, int|string|null>  $componentStageAssignments
     */
    public function replaceProductRoute(Product $product, array $rows, array $componentStageAssignments = []): void
    {
        DB::transaction(function () use ($product, $rows, $componentStageAssignments): void {
            $context = $this->requireFactoryContext();
            $companyId = $context['company_id'];
            $lockedProduct = Product::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($product->getKey());
            $stageIds = collect($rows)->pluck('production_stage_id')->map(fn (mixed $id): int => (int) $id)->all();

            if (count($stageIds) !== count(array_unique($stageIds))) {
                throw new DomainException(__('production_execution.messages.route_stage_duplicate'));
            }

            $stages = ProductionStage::query()
                ->forCompany($companyId)
                ->visibleInBranch($context['branch_id'])
                ->whereIn('id', $stageIds)
                ->where('status', ProductionStage::StatusActive)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($stages->count() !== count($stageIds)) {
                throw new DomainException(__('production_execution.messages.route_stage_invalid'));
            }

            ProductProductionStage::query()
                ->forCompany($companyId)
                ->where('product_id', $lockedProduct->getKey())
                ->delete();

            foreach (array_values($rows) as $index => $row) {
                ProductProductionStage::query()->create([
                    'company_id' => $companyId,
                    'product_id' => $lockedProduct->getKey(),
                    'production_stage_id' => $row['production_stage_id'],
                    'sequence' => $index + 1,
                    'standard_duration_value' => $row['standard_duration_value'] ?? null,
                    'standard_duration_unit' => $row['standard_duration_unit'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'status' => ProductionStage::StatusActive,
                    'created_by' => auth()->id(),
                ]);
            }

            if ($componentStageAssignments === []) {
                return;
            }

            $components = ProductComponent::query()
                ->forCompany($companyId)
                ->where('product_id', $lockedProduct->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy('public_id');

            if (array_diff(array_keys($componentStageAssignments), $components->keys()->all()) !== []) {
                throw new DomainException(__('production_execution.messages.component_stage_invalid'));
            }

            foreach ($components as $component) {
                $stageId = $componentStageAssignments[(string) $component->public_id] ?? null;
                $stageId = filled($stageId) ? (int) $stageId : null;

                if ($stageId !== null && ! in_array($stageId, $stageIds, true)) {
                    throw new DomainException(__('production_execution.messages.component_stage_must_be_selected'));
                }

                if ((int) ($component->production_stage_id ?? 0) !== (int) ($stageId ?? 0)) {
                    $this->audit->saveUpdate($component, ['production_stage_id' => $stageId]);
                }
            }
        });
    }

    /** @param null|list<string> $selectedStagePublicIds */
    public function snapshotLine(ProductionOrderLine $line, ?array $selectedStagePublicIds = null): void
    {
        $line = ProductionOrderLine::query()->with('order')->lockForUpdate()->findOrFail($line->getKey());

        if ($line->stageSnapshots()->exists()) {
            return;
        }

        $route = ProductProductionStage::query()
            ->forCompany((int) $line->order->company_id)
            ->where('product_id', $line->product_id)
            ->where('status', ProductionStage::StatusActive)
            ->whereHas('stage', fn ($stages) => $stages->visibleInBranch((int) $line->order->branch_id))
            ->with('stage')
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();

        $selectedStagePublicIds = collect($selectedStagePublicIds ?? [])->filter()->unique()->values()->all();
        $route = $route->whereIn('public_id', $selectedStagePublicIds)->values();

        if ($route->count() !== count($selectedStagePublicIds)) {
            throw new DomainException(__('production_execution.messages.order_stage_selection_invalid'));
        }

        $orderStageIds = $line->order->orderStageSnapshots()
            ->where('is_required', true)
            ->pluck('production_stage_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $route = $route->reject(fn (ProductProductionStage $routeStage): bool => in_array(
            (int) $routeStage->production_stage_id,
            $orderStageIds,
            true,
        ))->values();

        foreach ($route as $routeStage) {
            $stage = $routeStage->stage;

            if (! $stage || $stage->status !== ProductionStage::StatusActive) {
                throw new DomainException(__('production_execution.messages.route_stage_invalid'));
            }

            ProductionOrderStageSnapshot::query()->create([
                'company_id' => $line->order->company_id,
                'production_order_id' => $line->production_order_id,
                'production_order_line_id' => $line->getKey(),
                'route_scope_key' => 'line:'.$line->getKey(),
                'production_stage_id' => $stage->getKey(),
                'product_production_stage_id' => $routeStage->getKey(),
                'sequence' => $routeStage->sequence,
                'stage_code' => $stage->code,
                'stage_name' => $stage->name,
                'description' => $stage->description,
                'output_type' => $stage->output_type,
                'standard_duration_value' => $routeStage->standard_duration_value ?? $stage->standard_duration_value,
                'standard_duration_unit' => $routeStage->standard_duration_unit ?? $stage->standard_duration_unit,
                'is_required' => true,
                'status' => ProductionOrderStageSnapshot::StatusPending,
                'created_by' => auth()->id(),
            ]);
        }
    }

    /** @param list<string>|null $selectedStagePublicIds */
    public function snapshotOrderRoute(ProductionOrder $order, ?array $selectedStagePublicIds): void
    {
        $lockedOrder = ProductionOrder::query()->lockForUpdate()->findOrFail($order->getKey());
        $existingStages = $lockedOrder->orderStageSnapshots()->get();

        if ($existingStages->contains(fn (ProductionOrderStageSnapshot $stage): bool => $stage->events()->exists()
            || $stage->runs()->exists())) {
            throw new DomainException(__('production_execution.messages.order_route_locked'));
        }

        $existingStages->each->delete();
        $selectedIds = collect($selectedStagePublicIds ?? [])->filter()->unique()->values()->all();

        if ($selectedIds === []) {
            return;
        }

        $stages = ProductionStage::query()
            ->forCompany((int) $lockedOrder->company_id)
            ->visibleInBranch((int) $lockedOrder->branch_id)
            ->whereIn('public_id', $selectedIds)
            ->where('status', ProductionStage::StatusActive)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('public_id');

        if ($stages->count() !== count($selectedIds)) {
            throw new DomainException(__('production_execution.messages.order_stage_selection_invalid'));
        }

        foreach ($selectedIds as $index => $publicId) {
            /** @var ProductionStage $stage */
            $stage = $stages->get($publicId);
            ProductionOrderStageSnapshot::query()->create([
                'company_id' => $lockedOrder->company_id,
                'production_order_id' => $lockedOrder->getKey(),
                'production_order_line_id' => null,
                'route_scope_key' => 'order',
                'production_stage_id' => $stage->getKey(),
                'product_production_stage_id' => null,
                'sequence' => $index + 1,
                'stage_code' => $stage->code,
                'stage_name' => $stage->name,
                'description' => $stage->description,
                'output_type' => $stage->output_type,
                'standard_duration_value' => $stage->standard_duration_value,
                'standard_duration_unit' => $stage->standard_duration_unit,
                'is_required' => true,
                'status' => ProductionOrderStageSnapshot::StatusPending,
                'created_by' => auth()->id(),
            ]);

        }
    }

    public function recordStageEvent(
        ProductionOrderStageSnapshot $stage,
        string $eventType,
        ?string $previousStatus,
        string $status,
        ?int $runId = null,
        ?string $notes = null,
    ): void {
        ProductionOrderStageEvent::query()->create([
            'production_order_stage_snapshot_id' => $stage->getKey(),
            'production_run_id' => $runId,
            'changed_by' => auth()->id(),
            'event_type' => $eventType,
            'previous_status' => $previousStatus,
            'status' => $status,
            'notes' => $notes,
            'occurred_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function stageValues(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'output_type' => $data['output_type'] ?? null,
            'standard_duration_value' => $data['standard_duration_value'] ?? null,
            'standard_duration_unit' => $data['standard_duration_unit'] ?? null,
            'display_order' => $data['display_order'] ?? 1,
            'status' => $data['status'] ?? ProductionStage::StatusActive,
        ];
    }

    private function lockStage(ProductionStage $stage): ProductionStage
    {
        $context = $this->requireFactoryContext();

        return ProductionStage::query()
            ->forCompany($context['company_id'])
            ->visibleInBranch($context['branch_id'])
            ->lockForUpdate()
            ->findOrFail($stage->getKey());
    }

    /** @return array{company_id: int, branch_id: int} */
    private function requireFactoryContext(): array
    {
        $context = $this->context->snapshot(request());
        abort_unless($context['company_id'] && $context['branch_id'], 409, __('production_execution.messages.operating_context_required'));
        abort_unless(Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeFactory)
            ->exists(), 403, __('production_execution.messages.factory_context_required'));

        return ['company_id' => $context['company_id'], 'branch_id' => $context['branch_id']];
    }

    private function assertUniqueStageCode(int $companyId, string $code, ?int $exceptId = null): void
    {
        $exists = ProductionStage::withTrashed()
            ->forCompany($companyId)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw new DomainException(__('production_execution.messages.stage_code_exists'));
        }
    }
}

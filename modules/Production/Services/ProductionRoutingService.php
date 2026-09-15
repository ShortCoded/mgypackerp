<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Models\ProductProductionStage;

class ProductionRoutingService
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly CrudAuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function createStage(array $data): ProductionStage
    {
        return DB::transaction(function () use ($data): ProductionStage {
            $companyId = $this->companies->requireCompanyId();
            $this->assertUniqueStageCode($companyId, $data['code']);
            $stage = ProductionStage::query()->create([
                ...$this->stageValues($data),
                'company_id' => $companyId,
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
            $this->assertUniqueStageCode((int) $locked->company_id, $data['code'], (int) $locked->getKey());
            $this->audit->saveUpdate($locked, $this->stageValues($data));

            return $locked->refresh();
        });
    }

    public function deleteStage(ProductionStage $stage): void
    {
        DB::transaction(function () use ($stage): void {
            $locked = $this->lockStage($stage);

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
            $companyId = $this->companies->requireCompanyId();
            $lockedProduct = Product::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($product->getKey());
            $stageIds = collect($rows)->pluck('production_stage_id')->map(fn (mixed $id): int => (int) $id)->all();

            if (count($stageIds) !== count(array_unique($stageIds))) {
                throw new DomainException(__('production_execution.messages.route_stage_duplicate'));
            }

            $stages = ProductionStage::query()
                ->forCompany($companyId)
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
            ->with('stage')
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();

        if ($selectedStagePublicIds !== null) {
            $selectedStagePublicIds = collect($selectedStagePublicIds)->filter()->unique()->values()->all();
            $route = $route->whereIn('public_id', $selectedStagePublicIds)->values();

            if ($route->count() !== count($selectedStagePublicIds)) {
                throw new DomainException(__('production_execution.messages.order_stage_selection_invalid'));
            }
        }

        foreach ($route as $routeStage) {
            $stage = $routeStage->stage;

            if (! $stage || $stage->status !== ProductionStage::StatusActive) {
                throw new DomainException(__('production_execution.messages.route_stage_invalid'));
            }

            ProductionOrderStageSnapshot::query()->create([
                'company_id' => $line->order->company_id,
                'production_order_id' => $line->production_order_id,
                'production_order_line_id' => $line->getKey(),
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
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function stageValues(array $data): array
    {
        return [
            'code' => trim($data['code']),
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
        return ProductionStage::query()
            ->forCompany($this->companies->requireCompanyId())
            ->lockForUpdate()
            ->findOrFail($stage->getKey());
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

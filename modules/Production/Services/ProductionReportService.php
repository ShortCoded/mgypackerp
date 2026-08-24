<?php

namespace Modules\Production\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionRun;

class ProductionReportService
{
    /** @param array<string, mixed> $filters */
    public function runs(int $companyId, array $filters = []): Collection
    {
        return ProductionRun::query()
            ->where('company_id', $companyId)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['production_order_id'] ?? null, fn ($query, $orderId) => $query->where('production_order_id', $orderId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('planned_start_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('planned_start_at', '<=', $to))
            ->with(['order.salesOrder', 'orderLine', 'product', 'machine', 'mold', 'shift', 'inspections'])
            ->orderByDesc('planned_start_at')
            ->get();
    }

    public function materialReconciliation(int $companyId, ?int $runId = null): Collection
    {
        return ProductionMaterialRequirement::query()
            ->whereHas('run', fn ($query) => $query->where('company_id', $companyId))
            ->when($runId, fn ($query) => $query->where('production_run_id', $runId))
            ->with(['run.order', 'product', 'unit'])
            ->orderBy('production_run_id')
            ->orderBy('line_number')
            ->get();
    }

    /** @return array<string, string|int> */
    public function keyPerformanceIndicators(int $companyId): array
    {
        $runs = ProductionRun::query()->where('company_id', $companyId);
        $totals = (clone $runs)->selectRaw(
            'count(*) as runs, coalesce(sum(planned_base_quantity), 0) as planned, coalesce(sum(good_base_quantity), 0) as good, coalesce(sum(rejected_base_quantity + scrap_base_quantity), 0) as loss'
        )->first();

        return [
            'runs' => (int) ($totals?->runs ?? 0),
            'planned_base_quantity' => (string) ($totals?->planned ?? 0),
            'good_base_quantity' => (string) ($totals?->good ?? 0),
            'loss_base_quantity' => (string) ($totals?->loss ?? 0),
        ];
    }
}

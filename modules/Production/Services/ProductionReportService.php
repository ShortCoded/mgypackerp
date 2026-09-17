<?php

namespace Modules\Production\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionMaterialRequestLine;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;

class ProductionReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, array<string, string|int>|Collection|SupportCollection>
     */
    public function report(
        int $companyId,
        int $financialPeriodId,
        int $branchId,
        array $filters = [],
        bool $includeFinancial = false,
    ): array {
        $contextFilters = [
            ...$filters,
            'financial_period_id' => $financialPeriodId,
            'branch_id' => $branchId,
        ];
        $runs = $this->runs($companyId, $contextFilters);
        $runs->each(function (ProductionRun $run): void {
            $recorded = bcadd(
                bcadd((string) $run->good_base_quantity, (string) $run->rejected_base_quantity, 8),
                bcadd((string) $run->rework_base_quantity, (string) $run->scrap_base_quantity, 8),
                8,
            );
            $remaining = bcsub((string) $run->planned_base_quantity, $recorded, 8);
            $receiptRemaining = bcsub((string) $run->good_base_quantity, (string) $run->received_base_quantity, 8);

            $run->setAttribute('recorded_base_quantity', $recorded);
            $run->setAttribute('remaining_base_quantity', bccomp($remaining, '0', 8) > 0 ? $remaining : '0.00000000');
            $run->setAttribute('receipt_remaining_base_quantity', bccomp($receiptRemaining, '0', 8) > 0 ? $receiptRemaining : '0.00000000');
            $run->setAttribute('yield_percent', bccomp((string) $run->planned_base_quantity, '0', 8) > 0
                ? bcmul(bcdiv((string) $run->good_base_quantity, (string) $run->planned_base_quantity, 8), '100', 4)
                : '0.0000');
        });
        $materials = $this->materialReconciliation($companyId, $filters['production_run_id'] ?? null, $contextFilters);
        $this->applyMaterialMetrics($materials, $includeFinancial);
        $allQualityInspections = $this->qualityInspections($companyId, $contextFilters);
        $qualityInspections = ($filters['operational_focus'] ?? null)
            ? $this->filterQualityExceptions($allQualityInspections, (string) $filters['operational_focus'])
            : $allQualityInspections;

        return [
            'orders' => $this->orders($companyId, $contextFilters),
            'runs' => $runs,
            'materials' => $materials,
            'materialShortages' => $this->materialShortages($companyId, $contextFilters),
            'qualityInspections' => $qualityInspections,
            'qualitySummary' => $this->qualitySummary($allQualityInspections),
            'finishedGoodsReceipts' => $this->finishedGoodsReceipts($companyId, $contextFilters),
            'kpis' => $this->keyPerformanceIndicators($companyId, $contextFilters),
            'runCosts' => $includeFinancial ? $this->runCosts($runs) : collect(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function orders(int $companyId, array $filters = []): Collection
    {
        $orders = ProductionOrder::query()
            ->where('company_id', $companyId)
            ->when($filters['financial_period_id'] ?? null, fn ($query, $periodId) => $query->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('production_order_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('production_order_date', '<=', $to))
            ->with(['branch', 'salesOrder', 'lines.product.unit', 'lines.unit', 'runs'])
            ->orderByDesc('production_order_date')
            ->get();

        $orders->each(function (ProductionOrder $order): void {
            $planned = $order->lines->reduce(
                fn (string $total, $line): string => bcadd($total, (string) $line->base_quantity, 8),
                '0.00000000',
            );
            $received = $order->lines->reduce(
                fn (string $total, $line): string => bcadd($total, (string) $line->received_base_quantity, 8),
                '0.00000000',
            );
            $remaining = bcsub($planned, $received, 8);

            $order->setAttribute('planned_base_quantity', $planned);
            $order->setAttribute('received_base_quantity', $received);
            $order->setAttribute('remaining_base_quantity', bccomp($remaining, '0', 8) > 0 ? $remaining : '0.00000000');
        });

        if (($filters['operational_focus'] ?? null) === 'remaining') {
            return $orders
                ->whereIn('status', [
                    ProductionOrder::StatusPlanned,
                    ProductionOrder::StatusReleased,
                    ProductionOrder::StatusInProgress,
                    ProductionOrder::StatusPartiallyCompleted,
                ])
                ->filter(fn (ProductionOrder $order): bool => bccomp((string) $order->remaining_base_quantity, '0', 8) > 0)
                ->values();
        }

        return $orders;
    }

    /** @param array<string, mixed> $filters */
    public function remainingOrders(int $companyId, array $filters = []): Collection
    {
        return $this->orders($companyId, [...$filters, 'operational_focus' => 'remaining']);
    }

    /** @param array<string, mixed> $filters */
    public function materialShortages(int $companyId, array $filters = []): Collection
    {
        return ProductionMaterialRequestLine::query()
            ->where('shortage_quantity', '>', 0)
            ->whereHas('request', function ($query) use ($companyId, $filters): void {
                $query->where('company_id', $companyId)
                    ->whereIn('status', [ProductionMaterialRequest::StatusShortage, ProductionMaterialRequest::StatusPartiallyIssued])
                    ->when($filters['financial_period_id'] ?? null, fn ($requestQuery, $periodId) => $requestQuery->where('financial_period_id', $periodId))
                    ->when($filters['branch_id'] ?? null, fn ($requestQuery, $branchId) => $requestQuery->where('branch_id', $branchId))
                    ->when($filters['from'] ?? null, fn ($requestQuery, $from) => $requestQuery->whereDate('request_date', '>=', $from))
                    ->when($filters['to'] ?? null, fn ($requestQuery, $to) => $requestQuery->whereDate('request_date', '<=', $to));
            })
            ->with(['request.run', 'request.order', 'request.store', 'product', 'unit'])
            ->orderBy('production_material_request_id')
            ->orderBy('line_number')
            ->get();
    }

    /** @param array<string, mixed> $filters */
    public function qualityExceptions(int $companyId, array $filters = []): Collection
    {
        $focus = $filters['operational_focus'] ?? null;
        $rows = $this->qualityInspections($companyId, $filters);

        return $this->filterQualityExceptions($rows, is_string($focus) ? $focus : null);
    }

    /** @return array<string, int|string> */
    public function qualitySummary(Collection $rows): array
    {
        $quantity = fn (Collection $inspections): string => $inspections->reduce(
            fn (string $total, ProductionQualityInspection $inspection): string => bcadd($total, (string) ($inspection->affected_base_quantity ?? 0), 8),
            '0.00000000',
        );
        $pending = $this->filterQualityExceptions($rows, 'pending');
        $rejected = $this->filterQualityExceptions($rows, 'rejected');
        $accepted = $rows->filter(fn (ProductionQualityInspection $inspection): bool => in_array($inspection->result, ['passed', 'conditional'], true));

        return [
            'pending' => $pending->count(),
            'accepted_quantity' => $quantity($accepted),
            'rejected_quantity' => $quantity($rejected),
            'on_hold' => $this->filterQualityExceptions($rows, 'on_hold')->count(),
            'reinspections' => $rows->where('reinspection_number', '>', 0)->count(),
        ];
    }

    private function filterQualityExceptions(Collection $rows, ?string $focus): Collection
    {
        return match ($focus) {
            'pending' => $rows->whereIn('status', [
                ProductionQualityInspection::StatusDraft,
                ProductionQualityInspection::StatusReceived,
                ProductionQualityInspection::StatusInProgress,
                ProductionQualityInspection::StatusSubmitted,
            ])->values(),
            'rejected' => $rows->filter(fn (ProductionQualityInspection $inspection): bool => $inspection->status === ProductionQualityInspection::StatusRejected || $inspection->result === 'failed')->values(),
            'on_hold' => $rows->filter(fn (ProductionQualityInspection $inspection): bool => $inspection->disposition === 'hold')->values(),
            default => $rows,
        };
    }

    /** @param array<string, mixed> $filters */
    public function runs(int $companyId, array $filters = []): Collection
    {
        return ProductionRun::query()
            ->where('company_id', $companyId)
            ->when($filters['financial_period_id'] ?? null, fn ($query, $periodId) => $query->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['production_order_id'] ?? null, fn ($query, $orderId) => $query->where('production_order_id', $orderId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('planned_start_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('planned_start_at', '<=', $to))
            ->with(['order.salesOrder', 'orderLine', 'product', 'fixedAsset', 'stageSnapshot', 'mold', 'shift', 'inspections'])
            ->orderByDesc('planned_start_at')
            ->get();
    }

    /** @param array<string, mixed> $filters */
    public function materialReconciliation(int $companyId, ?int $runId = null, array $filters = []): Collection
    {
        return ProductionMaterialRequirement::query()
            ->whereHas('run', function ($query) use ($companyId, $filters): void {
                $query->where('company_id', $companyId)
                    ->when($filters['financial_period_id'] ?? null, fn ($runQuery, $periodId) => $runQuery->where('financial_period_id', $periodId))
                    ->when($filters['branch_id'] ?? null, fn ($runQuery, $branchId) => $runQuery->where('branch_id', $branchId));
            })
            ->when($runId, fn ($query) => $query->where('production_run_id', $runId))
            ->with(['run.order', 'product', 'unit'])
            ->orderBy('production_run_id')
            ->orderBy('line_number')
            ->get();
    }

    /** @return array<string, string|int> */
    /** @param array<string, mixed> $filters */
    public function keyPerformanceIndicators(int $companyId, array $filters = []): array
    {
        $runs = ProductionRun::query()
            ->where('company_id', $companyId)
            ->when($filters['financial_period_id'] ?? null, fn ($query, $periodId) => $query->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('planned_start_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('planned_start_at', '<=', $to));
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

    /** @param array<string, mixed> $filters */
    public function finishedGoodsReceipts(int $companyId, array $filters = []): Collection
    {
        return InventoryDocument::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $filters['financial_period_id'])
            ->where('branch_id', $filters['branch_id'])
            ->where('document_type', InventoryDocument::TypeProductionReceipt)
            ->where('status', InventoryDocument::StatusPosted)
            ->when($filters['production_run_id'] ?? null, fn ($query, $runId) => $query->where('production_run_id', $runId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('document_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('document_date', '<=', $to))
            ->with(['productionRun.order.salesOrder', 'productionRun.product', 'branchStore', 'lines.product', 'journalEntry'])
            ->orderByDesc('document_date')
            ->get();
    }

    /** @param array<string, mixed> $filters */
    public function qualityInspections(int $companyId, array $filters = []): Collection
    {
        return ProductionQualityInspection::query()
            ->where('company_id', $companyId)
            ->when($filters['financial_period_id'] ?? null, fn ($query, $periodId) => $query->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['production_run_id'] ?? null, fn ($query, $runId) => $query->where('production_run_id', $runId))
            ->when($filters['subject_type'] ?? null, fn ($query, $subjectType) => $query->where('subject_type', $subjectType))
            ->when($filters['quality_status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['result'] ?? null, fn ($query, $result) => $query->where('result', $result))
            ->when($filters['disposition'] ?? null, fn ($query, $disposition) => $query->where('disposition', $disposition))
            ->when($filters['product_id'] ?? null, fn ($query, $productId) => $query->where('product_id', $productId))
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('requested_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('requested_at', '<=', $to))
            ->with(['run.order.salesOrder', 'run.product', 'product', 'branchStore', 'stageSnapshot', 'qualityType', 'reports'])
            ->orderByDesc('requested_at')
            ->get();
    }

    private function runCosts(Collection $runs): SupportCollection
    {
        if ($runs->isEmpty()) {
            return collect();
        }

        $costs = DB::table('inventory_document_lines')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->whereIn('inventory_documents.production_run_id', $runs->modelKeys())
            ->where('inventory_documents.status', InventoryDocument::StatusPosted)
            ->whereNull('inventory_document_lines.deleted_at')
            ->groupBy('inventory_documents.production_run_id')
            ->selectRaw(
                'inventory_documents.production_run_id,
                coalesce(sum(case when inventory_documents.document_type in (?, ?) then inventory_document_lines.total_cost else 0 end), 0) as issued,
                coalesce(sum(case when inventory_documents.document_type = ? then inventory_document_lines.total_cost else 0 end), 0) as returned,
                coalesce(sum(case when inventory_documents.document_type = ? then inventory_document_lines.total_cost else 0 end), 0) as waste,
                coalesce(sum(case when inventory_documents.document_type = ? then inventory_document_lines.total_cost else 0 end), 0) as finished_goods',
                [
                    InventoryDocument::TypeMaterialIssue,
                    InventoryDocument::TypeAdditionalMaterialIssue,
                    InventoryDocument::TypeMaterialReturn,
                    InventoryDocument::TypeProductionWaste,
                    InventoryDocument::TypeProductionReceipt,
                ],
            )
            ->get()
            ->keyBy('production_run_id');

        return $runs->map(function (ProductionRun $run) use ($costs): object {
            $cost = $costs->get($run->getKey());
            $issued = bcadd((string) ($cost->issued ?? 0), '0', 8);
            $returned = bcadd((string) ($cost->returned ?? 0), '0', 8);
            $waste = bcadd((string) ($cost->waste ?? 0), '0', 8);
            $finishedGoods = bcadd((string) ($cost->finished_goods ?? 0), '0', 8);
            $capitalizable = bcsub(bcsub($issued, $returned, 8), $waste, 8);

            return (object) [
                'run' => $run,
                'issued' => $issued,
                'returned' => $returned,
                'waste' => $waste,
                'capitalizable' => $capitalizable,
                'finished_goods' => $finishedGoods,
                'wip' => bcsub($capitalizable, $finishedGoods, 8),
            ];
        });
    }

    private function applyMaterialMetrics(Collection $materials, bool $includeFinancial): void
    {
        $costs = $includeFinancial && $materials->isNotEmpty()
            ? DB::table('inventory_document_lines')
                ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
                ->join('inventory_reservations', 'inventory_reservations.id', '=', 'inventory_document_lines.inventory_reservation_id')
                ->whereIn('inventory_reservations.production_material_requirement_id', $materials->modelKeys())
                ->where('inventory_documents.status', InventoryDocument::StatusPosted)
                ->whereNull('inventory_document_lines.deleted_at')
                ->groupBy('inventory_reservations.production_material_requirement_id')
                ->selectRaw(
                    'inventory_reservations.production_material_requirement_id,
                    coalesce(sum(case when inventory_documents.document_type in (?, ?) then inventory_document_lines.total_cost else 0 end), 0) as issued_cost,
                    coalesce(sum(case when inventory_documents.document_type = ? then inventory_document_lines.total_cost else 0 end), 0) as returned_cost,
                    coalesce(sum(case when inventory_documents.document_type = ? then inventory_document_lines.total_cost else 0 end), 0) as waste_cost',
                    [
                        InventoryDocument::TypeMaterialIssue,
                        InventoryDocument::TypeAdditionalMaterialIssue,
                        InventoryDocument::TypeMaterialReturn,
                        InventoryDocument::TypeProductionWaste,
                    ],
                )
                ->get()
                ->keyBy('production_material_requirement_id')
            : collect();

        $materials->each(function (ProductionMaterialRequirement $line) use ($costs, $includeFinancial): void {
            $issued = bcadd((string) $line->issued_quantity, (string) $line->additional_issued_quantity, 8);
            $accounted = bcadd(bcadd((string) $line->returned_quantity, (string) $line->consumed_quantity, 8), (string) $line->waste_quantity, 8);
            $actualUsed = bcadd((string) $line->consumed_quantity, (string) $line->waste_quantity, 8);
            $line->setAttribute('issued_total_quantity', $issued);
            $line->setAttribute('accountability_variance', bcsub($issued, $accounted, 8));
            $line->setAttribute('quantity_variance', bcsub($actualUsed, (string) $line->planned_quantity, 8));

            if (! $includeFinancial) {
                return;
            }

            $cost = $costs->get($line->getKey());
            $issuedCost = bcadd((string) ($cost->issued_cost ?? 0), '0', 8);
            $returnedCost = bcadd((string) ($cost->returned_cost ?? 0), '0', 8);
            $wasteCost = bcadd((string) ($cost->waste_cost ?? 0), '0', 8);
            $unitCost = bccomp($issued, '0', 8) > 0 ? bcdiv($issuedCost, $issued, 8) : '0.00000000';
            $plannedCost = bcmul((string) $line->planned_quantity, $unitCost, 8);
            $actualCost = bcsub($issuedCost, $returnedCost, 8);
            $line->setAttribute('planned_cost', $plannedCost);
            $line->setAttribute('actual_cost', $actualCost);
            $line->setAttribute('waste_cost', $wasteCost);
            $line->setAttribute('cost_variance', bcsub($actualCost, $plannedCost, 8));
        });
    }
}

<?php

namespace Modules\Production\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionMaterialRequestLine;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;

class ProductionReportService
{
    public function __construct(private readonly ProductionCostService $costs) {}

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

    /**
     * One monitoring dataset for every factory branch and production process.
     * Quantities remain on their own product or material lines because summing different units is misleading.
     *
     * @param  list<int>  $allowedBranchIds
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function controlReport(int $companyId, int $financialPeriodId, array $allowedBranchIds, array $filters = []): array
    {
        $hasDateFilter = filled($filters['from'] ?? null) || filled($filters['to'] ?? null);
        $runs = ProductionRun::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $financialPeriodId)
            ->whereIn('branch_id', $allowedBranchIds !== [] ? $allowedBranchIds : [0])
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['production_shift_id'] ?? null, fn ($query, $shiftId) => $query->where('production_shift_id', $shiftId))
            ->when($hasDateFilter, function ($query) use ($filters): void {
                $query->where(function ($dateQuery) use ($filters): void {
                    $dateQuery->where(function ($startQuery) use ($filters): void {
                        $startQuery
                            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereRaw('date(coalesce(actual_start_at, planned_start_at)) >= ?', [$from]))
                            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereRaw('date(coalesce(actual_start_at, planned_start_at)) <= ?', [$to]));
                    })->orWhereHas('progressEntries', function ($progressQuery) use ($filters): void {
                        $progressQuery
                            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('recorded_at', '>=', $from))
                            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('recorded_at', '<=', $to));
                    })->orWhereHas('inventoryDocuments', function ($documentQuery) use ($filters): void {
                        $documentQuery->where('status', InventoryDocument::StatusPosted)
                            ->whereIn('document_type', [InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste, InventoryDocument::TypeProductionReceipt])
                            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('document_date', '>=', $from))
                            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('document_date', '<=', $to));
                    });
                });
            })
            ->when($filters['product'] ?? null, fn ($query, $term) => $query->whereHas('product', fn ($productQuery) => $productQuery->where(function ($searchQuery) use ($term): void {
                $search = '%'.addcslashes($term, '%_\\').'%';
                $searchQuery->where('doc_num', 'like', $search)->orWhere('name', 'like', $search);
            })))
            ->when($filters['machine'] ?? null, fn ($query, $term) => $query->where(function ($machineQuery) use ($term): void {
                $search = '%'.addcslashes($term, '%_\\').'%';
                $machineQuery->whereHas('fixedAsset', fn ($assetQuery) => $assetQuery->where('asset_name', 'like', $search))
                    ->orWhereHas('machine', fn ($runMachineQuery) => $runMachineQuery->where('name', 'like', $search)->orWhere('code', 'like', $search));
            }))
            ->when($filters['shift'] ?? null, function ($query, $term): void {
                $search = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($query) => $query->whereHas('shift', fn ($shift) => $shift->where('name', 'like', $search))
                    ->orWhereExists(fn ($entries) => $entries->selectRaw('1')->from('production_shift_entries as entry')
                        ->whereColumn('entry.production_run_id', 'production_runs.id')->whereColumn('entry.company_id', 'production_runs.company_id')
                        ->whereColumn('entry.branch_id', 'production_runs.branch_id')->where('entry.sheet_fields->shift_name', 'like', $search)));
            })
            ->when($filters['stage'] ?? null, fn ($query, $term) => $query->whereHas('stageSnapshot', fn ($stageQuery) => $stageQuery->where('stage_name', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['order'] ?? null, fn ($query, $term) => $query->whereHas('order', fn ($orderQuery) => $orderQuery->where('doc_num', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->with([
                'order.branch', 'order.salesOrder.customer', 'order.orderStageSnapshots', 'orderLine.stageSnapshots',
                'product.unit', 'product.equivalentUnit', 'product.color', 'fixedAsset', 'machine', 'shift', 'progressEntries',
                'stageSnapshot', 'mold', 'requirements.product.unit', 'inventoryDocuments.lines.product', 'inventoryDocuments.lines.unit',
                'inspections',
            ])
            ->orderByDesc('planned_start_at')
            ->orderByDesc('id')
            ->get();

        $runs->each(function (ProductionRun $run) use ($filters, $hasDateFilter): void {
            $run->setRelation('inventoryDocuments', $run->inventoryDocuments->merge($run->lineInventoryDocuments)->unique('id'));
            $recorded = bcadd(bcadd((string) $run->good_base_quantity, (string) $run->rejected_base_quantity, 8), bcadd((string) $run->rework_base_quantity, (string) $run->scrap_base_quantity, 8), 8);
            $unreceived = bcsub((string) $run->good_base_quantity, (string) $run->received_base_quantity, 8);
            $run->setAttribute('recorded_base_quantity', $recorded);
            $run->setAttribute('receipt_remaining_base_quantity', bccomp($unreceived, '0', 8) > 0 ? $unreceived : '0.00000000');
            $run->setAttribute('yield_percent', bccomp($recorded, '0', 8) > 0
                ? bcmul(bcdiv((string) $run->good_base_quantity, $recorded, 8), '100', 4)
                : null);
            $run->setAttribute('material_exception_count', $run->requirements->filter(function (ProductionMaterialRequirement $line): bool {
                $issued = bcadd((string) $line->issued_quantity, (string) $line->additional_issued_quantity, 8);
                $accounted = bcadd(bcadd((string) $line->returned_quantity, (string) $line->consumed_quantity, 8), (string) $line->waste_quantity, 8);

                return bccomp($issued, $accounted, 8) !== 0;
            })->count());
            $run->setAttribute('quality_hold_count', $run->inspections->filter(fn (ProductionQualityInspection $inspection): bool => $inspection->disposition === 'hold' || $inspection->result === 'failed')->count());
            $run->setAttribute('receipt_document_count', $run->inventoryDocuments->filter(fn (InventoryDocument $document): bool => $document->document_type === InventoryDocument::TypeProductionReceipt
                && $document->status === InventoryDocument::StatusPosted
                && (! $hasDateFilter || $this->controlDateMatches($document->document_date?->toDateString(), $filters)))->count());
            $stages = $run->order->orderStageSnapshots->where('is_required', true)
                ->concat($run->orderLine->stageSnapshots->where('is_required', true))
                ->unique('production_stage_id')->values();
            $run->setAttribute('is_final_output_stage', $stages->isEmpty()
                || (int) $stages->last()->getKey() === (int) $run->production_order_stage_snapshot_id);
            $snapshot = $run->orderLine?->bom_snapshot;
            $run->setAttribute('output_factor', is_array($snapshot) ? ($snapshot['basis_base_quantity'] ?? null) : null);
            $run->setAttribute('output_unit_name', is_array($snapshot) ? ($snapshot['basis_unit_name'] ?? null) : null);
            $run->setAttribute('output_color_name', is_array($snapshot) && array_key_exists('finished_product_color_name', $snapshot)
                ? $snapshot['finished_product_color_name']
                : $run->product?->color?->name);
            $reportEntries = $run->progressEntries->filter(fn ($entry): bool => $this->controlDateMatches($entry->recorded_at?->toDateString(), $filters));
            $run->setRelation('reportProgressEntries', $reportEntries);
            $run->setAttribute('report_good_weight_kg', $this->controlMeasuredWeight($reportEntries, 'good_weight_kg', 'good_base_quantity'));
            $run->setAttribute('report_production_scrap_weight_kg', $this->controlMeasuredWeight($reportEntries, 'production_scrap_weight_kg', 'scrap_base_quantity'));
            $runStart = $run->actual_start_at?->toDateString();
            foreach (['good_base_quantity', 'rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity'] as $field) {
                $reportQuantity = ! $hasDateFilter || ($run->progressEntries->isEmpty() && $this->controlDateMatches($runStart, $filters))
                    ? (string) $run->{$field}
                    : $reportEntries->reduce(fn (string $total, $entry): string => bcadd($total, (string) $entry->{$field}, 8), '0.00000000');
                $run->setAttribute('report_'.$field, $reportQuantity);
            }
            $run->setAttribute('report_recorded_base_quantity', array_reduce(
                ['good_base_quantity', 'rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity'],
                fn (string $total, string $field): string => bcadd($total, (string) $run->{'report_'.$field}, 8),
                '0.00000000',
            ));
            $reportRecorded = (string) $run->report_recorded_base_quantity;
            $run->setAttribute('report_yield_percent', bccomp($reportRecorded, '0', 8) > 0
                ? bcmul(bcdiv((string) $run->report_good_base_quantity, $reportRecorded, 8), '100', 4)
                : null);
            $reportReceived = $hasDateFilter
                ? $run->inventoryDocuments
                    ->filter(fn (InventoryDocument $document): bool => $document->document_type === InventoryDocument::TypeProductionReceipt
                        && $document->status === InventoryDocument::StatusPosted
                        && $this->controlDateMatches($document->document_date?->toDateString(), $filters))
                    ->flatMap(fn (InventoryDocument $document) => $document->lines->filter(fn ($line): bool => (int) $line->production_run_id === (int) $run->id || ($line->production_run_id === null && (int) $document->production_run_id === (int) $run->id)))
                    ->reduce(fn (string $total, $line): string => bcadd($total, (string) $line->base_quantity, 8), '0.00000000')
                : (string) $run->received_base_quantity;
            $run->setAttribute('report_received_base_quantity', $reportReceived);
            $reportUnreceived = bcsub((string) $run->report_good_base_quantity, $reportReceived, 8);
            $run->setAttribute('report_receipt_remaining_base_quantity', bccomp($reportUnreceived, '0', 8) > 0
                ? $reportUnreceived
                : '0.00000000');
        });

        $materials = $runs->flatMap(fn (ProductionRun $run) => $run->requirements->map(function (ProductionMaterialRequirement $line) use ($run): array {
            $issued = bcadd((string) $line->issued_quantity, (string) $line->additional_issued_quantity, 8);
            $accounted = bcadd(bcadd((string) $line->returned_quantity, (string) $line->consumed_quantity, 8), (string) $line->waste_quantity, 8);
            $variance = bcsub($issued, $accounted, 8);
            $status = bccomp($issued, '0', 8) === 0 && bccomp($accounted, '0', 8) === 0
                ? 'pending'
                : (bccomp($variance, '0', 8) === 0 ? 'balanced' : 'variance');

            return [
                'run' => $run,
                'line' => $line,
                'basis_quantity' => is_array($run->orderLine?->bom_snapshot)
                    ? bcmul((string) $run->planned_base_quantity, (string) ($run->orderLine->bom_snapshot['basis_base_quantity'] ?? '1'), 8)
                    : null,
                'issued' => $issued,
                'variance' => $variance,
                'status' => $status,
            ];
        }))->values();

        $daily = $this->controlDailyOutput($runs);
        $dailyMaterials = $this->controlDailyMaterials($runs, $filters);

        return [
            'controlRuns' => $runs,
            'controlMaterials' => $materials,
            'controlProducts' => $this->controlProductSummary($runs),
            'controlDaily' => $daily,
            'controlDailyMaterials' => $dailyMaterials,
            'controlMachines' => $this->controlMachineSummary($runs),
            'controlMaterialSummary' => $this->controlMaterialSummary($materials),
            'controlKpis' => [
                'runs' => $runs->count(),
                'products' => $runs->pluck('product_id')->unique()->count(),
                'recorded_days' => $daily->pluck('date')->unique()->count(),
                'unreceived_runs' => $runs->filter(fn (ProductionRun $run): bool => bccomp((string) $run->report_receipt_remaining_base_quantity, '0', 8) > 0)->count(),
                'material_exception_runs' => $runs->filter(fn (ProductionRun $run): bool => $run->material_exception_count > 0)->count(),
                'quality_hold_runs' => $runs->filter(fn (ProductionRun $run): bool => $run->quality_hold_count > 0)->count(),
                'receipt_documents' => $runs->sum('receipt_document_count'),
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function controlDateMatches(?string $date, array $filters): bool
    {
        return $date !== null
            && (! filled($filters['from'] ?? null) || $date >= $filters['from'])
            && (! filled($filters['to'] ?? null) || $date <= $filters['to']);
    }

    /** @return SupportCollection<int, array<string, mixed>> */
    private function controlProductSummary(Collection $runs): SupportCollection
    {
        return $runs
            ->groupBy(fn (ProductionRun $run): string => implode(':', [
                $run->branch_id, $run->product_id, $run->order?->salesOrder?->customer_id ?? 0,
                $run->output_factor ?? '', $run->output_unit_name ?? '', $run->output_color_name ?? '',
                $run->stageSnapshot?->production_stage_id ?? 0, $run->stageSnapshot?->stage_name ?? '',
                $run->is_final_output_stage ? 1 : 0,
            ]))
            ->map(function (SupportCollection $group): array {
                $first = $group->first();
                $recorded = $this->controlQuantitySum($group, 'report_recorded_base_quantity');
                $good = $this->controlQuantitySum($group, 'report_good_base_quantity');
                $received = $this->controlQuantitySum($group, 'report_received_base_quantity');
                $hasOutput = bccomp($recorded, '0', 8) > 0;
                $factor = $first->output_factor;
                $goodWeight = $this->controlMeasuredWeight($group, 'report_good_weight_kg', 'report_good_base_quantity');
                $scrapWeight = $this->controlMeasuredWeight($group, 'report_production_scrap_weight_kg', 'report_scrap_base_quantity');
                $equivalentGood = $hasOutput && $factor !== null ? bcmul($good, (string) $factor, 8) : null;
                $weightBasis = $equivalentGood ?? $good;
                $totalMeasuredWeight = $goodWeight !== null && $scrapWeight !== null ? bcadd($goodWeight, $scrapWeight, 8) : null;
                $components = $group->flatMap(fn (ProductionRun $run): array => $run->orderLine?->bom_snapshot['components'] ?? [])
                    ->pluck('product_name')->filter()->unique()->implode('، ');

                return [
                    'branch' => $first->order?->branch?->name,
                    'product' => $first->product,
                    'color' => $first->output_color_name,
                    'customer' => $first->order?->salesOrder?->customer?->name,
                    'stage' => $first->stageSnapshot?->stage_name,
                    'is_final_stage' => $first->is_final_output_stage,
                    'components' => $components,
                    'unit' => $first->product?->unit?->name,
                    'pack_size' => $factor,
                    'equivalent_unit' => $first->output_unit_name,
                    'runs' => $group->count(),
                    'planned' => $this->controlQuantitySum($group, 'planned_base_quantity'),
                    'good' => $hasOutput ? $good : null,
                    'equivalent_good' => $equivalentGood,
                    'good_weight_kg' => $goodWeight,
                    'unit_weight_kg' => $goodWeight !== null && bccomp($weightBasis, '0', 8) > 0 ? bcdiv($goodWeight, $weightBasis, 8) : null,
                    'production_scrap_weight_kg' => $scrapWeight,
                    'production_scrap_percent' => $totalMeasuredWeight !== null && bccomp($totalMeasuredWeight, '0', 8) > 0 ? bcmul(bcdiv($scrapWeight, $totalMeasuredWeight, 8), '100', 4) : null,
                    'rejected' => $hasOutput ? $this->controlQuantitySum($group, 'report_rejected_base_quantity') : null,
                    'rework' => $hasOutput ? $this->controlQuantitySum($group, 'report_rework_base_quantity') : null,
                    'scrap' => $hasOutput ? $this->controlQuantitySum($group, 'report_scrap_base_quantity') : null,
                    'received' => bccomp($received, '0', 8) > 0 ? $received : null,
                    'yield' => $hasOutput ? bcmul(bcdiv($good, $recorded, 8), '100', 4) : null,
                ];
            })->values();
    }

    /** @return SupportCollection<int, array<string, mixed>> */
    private function controlDailyOutput(Collection $runs): SupportCollection
    {
        return $runs->flatMap(function (ProductionRun $run): SupportCollection {
            if ($run->progressEntries->isEmpty()) {
                $date = $run->actual_start_at?->toDateString();
                if ($date === null || bccomp((string) $run->report_recorded_base_quantity, '0', 8) <= 0) {
                    return collect();
                }

                return collect([[
                    'run' => $run,
                    'entry' => null,
                    'date' => $date,
                    'date_basis' => 'run_start',
                    'good' => (string) $run->report_good_base_quantity,
                    'rejected' => (string) $run->report_rejected_base_quantity,
                    'rework' => (string) $run->report_rework_base_quantity,
                    'scrap' => (string) $run->report_scrap_base_quantity,
                ]]);
            }

            return $run->reportProgressEntries->map(fn ($entry): array => [
                'run' => $run,
                'entry' => $entry,
                'date' => $entry->recorded_at?->toDateString(),
                'date_basis' => 'progress',
                'good' => (string) $entry->good_base_quantity,
                'rejected' => (string) $entry->rejected_base_quantity,
                'rework' => (string) $entry->rework_base_quantity,
                'scrap' => (string) $entry->scrap_base_quantity,
            ]);
        })
            ->groupBy(fn (array $row): string => $row['run']->getKey().':'.$row['date'])
            ->map(function (SupportCollection $group): array {
                $first = $group->first();
                $good = $this->controlArrayQuantitySum($group, 'good');
                $factor = $first['run']->output_factor;
                $entries = $group->pluck('entry')->filter();
                $goodWeight = $this->controlMeasuredWeight($entries, 'good_weight_kg', 'good_base_quantity');
                $scrapWeight = $this->controlMeasuredWeight($entries, 'production_scrap_weight_kg', 'scrap_base_quantity');

                return [
                    'run' => $first['run'],
                    'date' => $first['date'],
                    'date_basis' => $first['date_basis'],
                    'good' => $good,
                    'equivalent_good' => $factor !== null ? bcmul($good, (string) $factor, 8) : null,
                    'good_weight_kg' => $goodWeight,
                    'production_scrap_weight_kg' => $scrapWeight,
                    'rejected' => $this->controlArrayQuantitySum($group, 'rejected'),
                    'rework' => $this->controlArrayQuantitySum($group, 'rework'),
                    'scrap' => $this->controlArrayQuantitySum($group, 'scrap'),
                ];
            })->sortBy('date')->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return SupportCollection<int, array<string, mixed>>
     */
    private function controlDailyMaterials(Collection $runs, array $filters): SupportCollection
    {
        return $runs->flatMap(fn (ProductionRun $run) => $run->inventoryDocuments
            ->filter(fn (InventoryDocument $document): bool => $document->status === InventoryDocument::StatusPosted
                && in_array($document->document_type, [InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste], true)
                && $this->controlDateMatches($document->document_date?->toDateString(), $filters))
            ->flatMap(fn (InventoryDocument $document) => $document->lines->map(fn ($line): array => [
                'run' => $run,
                'date' => $document->document_date?->toDateString(),
                'document' => $document->doc_num,
                'material' => $line->product,
                'unit' => $line->unit?->name,
                'product_id' => $line->product_id,
                'unit_id' => $line->unit_id,
                'consumed' => $document->document_type === InventoryDocument::TypeMaterialConsumption ? (string) $line->quantity : '0.00000000',
                'waste' => $document->document_type === InventoryDocument::TypeProductionWaste ? (string) $line->quantity : '0.00000000',
            ])))
            ->groupBy(fn (array $row): string => implode(':', [$row['run']->getKey(), $row['date'], $row['product_id'], $row['unit_id'] ?? 0]))
            ->map(function (SupportCollection $group): array {
                $first = $group->first();
                $consumed = $this->controlArrayQuantitySum($group, 'consumed');
                $waste = $this->controlArrayQuantitySum($group, 'waste');
                $totalUsed = bcadd($consumed, $waste, 8);

                return [
                    'run' => $first['run'],
                    'date' => $first['date'],
                    'material' => $first['material'],
                    'unit' => $first['unit'],
                    'documents' => $group->pluck('document')->unique()->implode('، '),
                    'consumed' => $consumed,
                    'waste' => $waste,
                    'total_used' => $totalUsed,
                    'waste_percent' => bccomp($totalUsed, '0', 8) > 0 ? bcmul(bcdiv($waste, $totalUsed, 8), '100', 4) : null,
                ];
            })->sortBy('date')->values();
    }

    /** @return SupportCollection<int, array<string, mixed>> */
    private function controlMachineSummary(Collection $runs): SupportCollection
    {
        return $runs->groupBy(fn (ProductionRun $run): string => implode(':', [
            $run->branch_id, $run->fixed_asset_id ?? 0, $run->production_machine_id ?? 0,
            $run->stageSnapshot?->production_stage_id ?? 0, $run->stageSnapshot?->stage_name ?? '',
            $run->product_id, $run->output_color_name ?? '',
        ]))->map(function (Collection $group): array {
            $first = $group->first();
            $recorded = $this->controlQuantitySum($group, 'report_recorded_base_quantity');
            $hasOutput = bccomp($recorded, '0', 8) > 0;
            $good = $this->controlQuantitySum($group, 'report_good_base_quantity');
            $goodWeight = $this->controlMeasuredWeight($group, 'report_good_weight_kg', 'report_good_base_quantity');
            $scrapWeight = $this->controlMeasuredWeight($group, 'report_production_scrap_weight_kg', 'report_scrap_base_quantity');

            return [
                'branch' => $first->order?->branch?->name,
                'machine' => $first->fixedAsset?->asset_name ?? $first->machine?->name,
                'stage' => $first->stageSnapshot?->stage_name,
                'product' => $first->product,
                'color' => $first->output_color_name,
                'unit' => $first->product?->unit?->name,
                'runs' => $group->count(),
                'planned' => $this->controlQuantitySum($group, 'planned_base_quantity'),
                'good' => $hasOutput ? $good : null,
                'good_weight_kg' => $goodWeight,
                'production_scrap_weight_kg' => $scrapWeight,
                'scrap' => $hasOutput ? $this->controlQuantitySum($group, 'report_scrap_base_quantity') : null,
                'yield' => $hasOutput ? bcmul(bcdiv($good, $recorded, 8), '100', 4) : null,
            ];
        })->values();
    }

    /** @return SupportCollection<int, array<string, mixed>> */
    private function controlMaterialSummary(SupportCollection $materials): SupportCollection
    {
        return $materials->groupBy(fn (array $entry): string => implode(':', [
            $entry['run']->branch_id, $entry['run']->product_id,
            $entry['run']->order?->salesOrder?->customer_id ?? 0,
            $entry['run']->stageSnapshot?->production_stage_id ?? 0,
            $entry['run']->stageSnapshot?->stage_name ?? '', $entry['run']->output_color_name ?? '',
            $entry['run']->output_factor ?? '', $entry['run']->output_unit_name ?? '',
            $entry['line']->product_id, $entry['line']->unit_id,
        ]))->map(function (SupportCollection $group): array {
            $first = $group->first();
            $issued = $this->controlArrayQuantitySum($group->map(fn (array $entry): array => [
                'quantity' => bcadd((string) $entry['line']->issued_quantity, (string) $entry['line']->additional_issued_quantity, 8),
            ]), 'quantity');
            $returned = $this->controlArrayQuantitySum($group->map(fn (array $entry): array => ['quantity' => (string) $entry['line']->returned_quantity]), 'quantity');
            $consumed = $this->controlArrayQuantitySum($group->map(fn (array $entry): array => ['quantity' => (string) $entry['line']->consumed_quantity]), 'quantity');
            $waste = $this->controlArrayQuantitySum($group->map(fn (array $entry): array => ['quantity' => (string) $entry['line']->waste_quantity]), 'quantity');
            $used = bcadd($consumed, $waste, 8);
            $usageRecorded = bccomp($used, '0', 8) > 0;
            $hasMovement = bccomp($issued, '0', 8) !== 0 || bccomp($returned, '0', 8) !== 0 || bccomp($used, '0', 8) !== 0;
            $groupRuns = $group->map(fn (array $entry): ProductionRun => $entry['run'])->unique(fn (ProductionRun $run): mixed => $run->getKey());
            $hasEquivalentBasis = $groupRuns->every(fn (ProductionRun $run): bool => $run->output_factor !== null && bccomp((string) $run->output_factor, '0', 8) > 0);
            $equivalentOutput = $hasEquivalentBasis
                ? $groupRuns->reduce(
                    fn (string $total, ProductionRun $run): string => bcadd($total, bcmul((string) $run->good_base_quantity, (string) $run->output_factor, 8), 8),
                    '0.00000000',
                )
                : null;

            return [
                'branch' => $first['run']->order?->branch?->name,
                'product' => $first['run']->product,
                'color' => $first['run']->output_color_name,
                'customer' => $first['run']->order?->salesOrder?->customer?->name,
                'stage' => $first['run']->stageSnapshot?->stage_name,
                'material' => $first['line']->product,
                'unit' => $first['line']->unit?->name,
                'consumption_unit' => $first['line']->unit?->name && $first['run']->output_unit_name
                    ? $first['line']->unit->name.' / '.$first['run']->output_unit_name
                    : null,
                'planned' => $this->controlArrayQuantitySum($group->map(fn (array $entry): array => ['quantity' => (string) $entry['line']->planned_quantity]), 'quantity'),
                'issued' => $hasMovement ? $issued : null,
                'returned' => $hasMovement ? $returned : null,
                'consumed' => $usageRecorded ? $consumed : null,
                'waste' => $usageRecorded ? $waste : null,
                'total_used' => $usageRecorded ? $used : null,
                'consumed_per_equivalent' => $usageRecorded && $equivalentOutput !== null && bccomp($equivalentOutput, '0', 8) > 0
                    ? bcdiv($consumed, $equivalentOutput, 8)
                    : null,
                'waste_percent' => $usageRecorded ? bcmul(bcdiv($waste, $used, 8), '100', 4) : null,
                'variance' => $hasMovement ? bcsub(bcsub($issued, $returned, 8), $used, 8) : null,
            ];
        })->values();
    }

    private function controlQuantitySum(SupportCollection $runs, string $field): string
    {
        return $runs->reduce(fn (string $total, ProductionRun $run): string => bcadd($total, (string) $run->{$field}, 8), '0.00000000');
    }

    private function controlMeasuredWeight(SupportCollection $records, string $weightField, string $quantityField): ?string
    {
        $relevant = $records->filter(fn ($record): bool => $record->{$weightField} !== null
            || bccomp((string) $record->{$quantityField}, '0', 8) > 0);
        if ($relevant->isEmpty() || $relevant->contains(fn ($record): bool => $record->{$weightField} === null)) {
            return null;
        }

        return $relevant->reduce(fn (string $total, $record): string => bcadd($total, (string) $record->{$weightField}, 8), '0.00000000');
    }

    private function controlArrayQuantitySum(SupportCollection $rows, string $field): string
    {
        return $rows->reduce(fn (string $total, array $row): string => bcadd($total, (string) $row[$field], 8), '0.00000000');
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
            ->when($filters['production_run_id'] ?? null, fn ($query, $runId) => $query->where(fn ($source) => $source->where('production_run_id', $runId)->orWhereHas('lines', fn ($line) => $line->where('production_run_id', $runId))))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('document_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('document_date', '<=', $to))
            ->with(['productionRun.order.salesOrder', 'productionRun.product', 'branchStore', 'lines.product', 'lines.productionRun.order', 'journalEntry'])
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
        $positions = $this->costs->positions($runs);

        return $runs->map(fn (ProductionRun $run): object => (object) [
            'run' => $run,
            ...$positions->get($run->getKey()),
        ]);
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

<?php

namespace Modules\Accounting\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\ProductionReportService;
use Modules\Sales\Models\CustomerInvoice;

final class CostingReportService
{
    public const ProductCost = 'product_cost';

    public const WorkOrderCost = 'work_order_cost';

    public const EstimatedVsActual = 'estimated_vs_actual';

    public const CostVariance = 'cost_variance';

    public const Profitability = 'profitability';

    public const WorkInProgress = 'work_in_progress';

    public const FinishedGoodsCost = 'finished_goods_cost';

    public const AllocationAnalysis = 'allocation_analysis';

    public function __construct(
        private readonly OperatingContextService $context,
        private readonly DateFormatService $dates,
        private readonly ProductionReportService $production,
        private readonly CostAccountingReportService $costAccounting,
    ) {}

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::ProductCost,
            self::WorkOrderCost,
            self::EstimatedVsActual,
            self::CostVariance,
            self::Profitability,
            self::WorkInProgress,
            self::FinishedGoodsCost,
            self::AllocationAnalysis,
        ];
    }

    /** @return array<string, mixed> */
    public function filters(Request $request, ?string $defaultType = null): array
    {
        $filters = $request->only([
            'type', 'from_date', 'to_date', 'status', 'product_doc_num',
            'production_order_doc_num', 'cost_center_doc_num',
        ]);
        $requestedType = filled($defaultType)
            ? (string) $defaultType
            : (string) ($filters['type'] ?? self::ProductCost);
        $filters['type'] = in_array($requestedType, self::types(), true) ? $requestedType : self::ProductCost;

        foreach (['from_date', 'to_date'] as $field) {
            $filters[$field] = $this->dates->normalizeForStorage(trim((string) ($filters[$field] ?? '')));
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== null && trim((string) $value) !== '');
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $context = $this->context->snapshot(request());
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('costing_reports.operating_context_required'));

        $companyId = (int) $context['company_id'];
        $periodId = (int) $context['financial_period_id'];
        $branchId = (int) $context['branch_id'];
        $productId = $this->scopedId(Product::query()->withTrashed()->where('company_id', $companyId), $filters['product_doc_num'] ?? null);
        $orderId = $this->scopedId(ProductionOrder::query()->where('company_id', $companyId)->where('financial_period_id', $periodId)->where('branch_id', $branchId), $filters['production_order_doc_num'] ?? null);
        $costCenterId = $this->scopedId(CostCenter::query()->withTrashed()->forCompany($companyId), $filters['cost_center_doc_num'] ?? null);
        $type = (string) ($filters['type'] ?? self::ProductCost);

        if ($type === self::AllocationAnalysis) {
            return $this->allocationAnalysis($companyId, $periodId, $branchId, $costCenterId, $filters);
        }

        $production = $this->production->report($companyId, $periodId, $branchId, [
            'from' => $filters['from_date'] ?? null,
            'to' => $filters['to_date'] ?? null,
            'status' => $filters['status'] ?? null,
            'production_order_id' => $orderId,
        ], true);
        $runs = collect($production['runs'])
            ->when($productId, fn (Collection $rows) => $rows->where('product_id', $productId))
            ->when($costCenterId, fn (Collection $rows) => $rows->where('cost_center_id', $costCenterId))
            ->values();
        $runIds = $runs->pluck('id');
        $materialsByRun = collect($production['materials'])->whereIn('production_run_id', $runIds)->groupBy('production_run_id');
        $costsByRun = collect($production['runCosts'])->where(fn (object $row): bool => $runIds->contains($row->run->getKey()))->keyBy(fn (object $row): int => (int) $row->run->getKey());
        $baseRows = $runs->map(function ($run) use ($materialsByRun, $costsByRun): array {
            $materials = $materialsByRun->get($run->getKey(), collect());
            $cost = $costsByRun->get($run->getKey());
            $planned = $this->sum($materials, 'planned_cost');
            $recognized = (string) ($cost?->finished_goods ?? '0');
            $capitalizable = (string) ($cost?->capitalizable ?? '0');
            $actual = $capitalizable;
            $goodQuantity = (string) $run->good_base_quantity;
            $actualBasis = bccomp($recognized, '0', 8) > 0 ? $recognized : $capitalizable;
            $costComplete = (bool) ($cost?->material_valuation_complete ?? false)
                && bccomp($recognized, '0', 8) !== 0;

            return [
                '_run_id' => $run->getKey(),
                '_order_id' => $run->production_order_id,
                '_product_id' => $run->product_id,
                '_sales_order_id' => $run->order?->sales_order_id,
                '_profit_key' => ($run->order?->sales_order_id ?: 'production-'.$run->production_order_id).':'.$run->product_id,
                '_cost_complete' => $costComplete,
                '_url' => auth()->user()?->can('production.runs.view')
                    ? route('admin.production.runs.show', $run)
                    : null,
                'run' => $run->run_number,
                'work_order' => $run->order?->doc_num,
                'sales_order' => $run->order?->salesOrder?->doc_num,
                'product' => trim(($run->product?->doc_num ?? '').' / '.($run->product?->name ?? ''), ' /'),
                'cost_center' => trim(($run->costCenter?->cost_center_code ?? '').' / '.($run->costCenter?->name ?? ''), ' /'),
                'status' => __('production_execution.statuses.'.$run->status),
                'planned_quantity' => $run->planned_base_quantity,
                'good_quantity' => $goodQuantity,
                'planned_cost' => $planned,
                'actual_cost' => $actual,
                'variance' => bcsub($actual, $planned, 8),
                'issued_cost' => (string) ($cost?->issued ?? '0'),
                'returned_cost' => (string) ($cost?->returned ?? '0'),
                'waste_cost' => (string) ($cost?->waste ?? '0'),
                'other_direct_cost' => (string) ($cost?->other_direct_cost ?? '0'),
                'allocated_overhead' => (string) ($cost?->allocated_overhead ?? '0'),
                'capitalizable_cost' => $capitalizable,
                'recognized_cost' => $recognized,
                'wip' => (string) ($cost?->wip ?? '0'),
                'unit_cost' => bccomp($goodQuantity, '0', 8) > 0 ? bcdiv($actualBasis, $goodQuantity, 8) : '0.00000000',
            ];
        });

        $rows = match ($type) {
            self::ProductCost => $this->aggregate($baseRows, '_product_id', ['product']),
            self::WorkOrderCost => $this->aggregate($baseRows, '_order_id', ['work_order', 'sales_order']),
            self::Profitability => $this->profitability($baseRows, $companyId, $periodId, $branchId, $filters),
            self::WorkInProgress => $baseRows->filter(fn (array $row): bool => bccomp($row['wip'], '0', 8) !== 0)->values(),
            self::FinishedGoodsCost => $this->aggregate(
                $baseRows->filter(fn (array $row): bool => bccomp($row['recognized_cost'], '0', 8) !== 0),
                '_product_id',
                ['product'],
            ),
            default => $baseRows->values(),
        };
        $columns = $this->columns($type);
        $numericColumns = array_values(array_intersect(array_keys($columns), [
            'planned_quantity', 'good_quantity', 'planned_cost', 'actual_cost', 'variance',
            'issued_cost', 'returned_cost', 'waste_cost', 'allocated_overhead', 'capitalizable_cost',
            'other_direct_cost', 'recognized_cost', 'wip', 'unit_cost', 'revenue', 'gross_profit', 'margin_percent',
        ]));
        $totals = collect($numericColumns)->reject(fn (string $column): bool => in_array($column, ['unit_cost', 'margin_percent'], true))
            ->mapWithKeys(fn (string $column): array => [
                $column => $rows->contains(fn (array $row): bool => array_key_exists($column, $row) && ! is_numeric($row[$column]))
                    ? __('costing_reports.values.unavailable')
                    : $this->sum($rows, $column),
            ])->all();

        $unallocatedCount = $this->costAccounting->unallocatedRequiredTransactions(
            $companyId,
            $periodId,
            $branchId,
            $filters['from_date'] ?? null,
            $filters['to_date'] ?? null,
        )->count();
        $notices = [__('costing_reports.notices.posted_inventory_basis')];
        if ($unallocatedCount > 0) {
            $notices[] = __('costing_reports.notices.unallocated_transactions', ['count' => $unallocatedCount]);
        }
        if ($type === self::Profitability) {
            $notices[] = __('costing_reports.notices.profitability_recognition');
            $incompleteCount = $rows->where('_cost_complete', false)->count();
            if ($incompleteCount > 0) {
                $notices[] = __('costing_reports.notices.incomplete_profitability', ['count' => $incompleteCount]);
            }
        }

        return [
            'type' => $type,
            'title' => __("costing_reports.types.{$type}.title"),
            'description' => __("costing_reports.types.{$type}.description"),
            'columns' => $columns,
            'numeric_columns' => $numericColumns,
            'rows' => $rows,
            'totals' => $totals,
            'notices' => $notices,
        ];
    }

    /** @return array{products: Collection, orders: Collection, cost_centers: Collection} */
    public function filterOptions(array $selected = []): array
    {
        $context = $this->context->snapshot(request());
        $companyId = (int) $context['company_id'];
        $periodId = (int) $context['financial_period_id'];
        $branchId = (int) $context['branch_id'];

        $products = Product::query()->where('company_id', $companyId)->active()
            ->whereIn('item_classification', Product::salesItemClassifications())->orderBy('name')->get(['id', 'doc_num', 'name']);
        $orders = ProductionOrder::query()->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->where('branch_id', $branchId)->latest('production_order_date')->get(['id', 'doc_num']);
        $costCenters = CostCenter::query()->forCompany($companyId)->active()
            ->orderBy('cost_center_code')->get(['id', 'doc_num', 'cost_center_code', 'name']);

        if (filled($selected['product_doc_num'] ?? null)) {
            $products = $products->concat(Product::withTrashed()->where('company_id', $companyId)
                ->whereIn('item_classification', Product::salesItemClassifications())
                ->where('doc_num', $selected['product_doc_num'])->get(['id', 'doc_num', 'name']));
        }
        if (filled($selected['cost_center_doc_num'] ?? null)) {
            $costCenters = $costCenters->concat(CostCenter::withTrashed()->forCompany($companyId)
                ->where('doc_num', $selected['cost_center_doc_num'])->get(['id', 'doc_num', 'cost_center_code', 'name']));
        }

        return [
            'products' => $products->unique('id')->sortBy('name')->values(),
            'orders' => $orders,
            'cost_centers' => $costCenters->unique('id')->sortBy('cost_center_code')->values(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @param  list<string>  $labels
     * @return Collection<int, array<string, mixed>>
     */
    private function aggregate(Collection $rows, string $groupKey, array $labels): Collection
    {
        return $rows->groupBy($groupKey)->map(function (Collection $group) use ($labels): array {
            $first = $group->first();
            $row = collect($labels)->mapWithKeys(fn (string $label): array => [$label => $first[$label] ?? null])->all();
            foreach (['planned_quantity', 'good_quantity', 'planned_cost', 'actual_cost', 'variance', 'issued_cost', 'returned_cost', 'waste_cost', 'other_direct_cost', 'allocated_overhead', 'capitalizable_cost', 'recognized_cost', 'wip'] as $column) {
                $row[$column] = $this->sum($group, $column);
            }
            $basis = bccomp($row['recognized_cost'], '0', 8) > 0 ? $row['recognized_cost'] : $row['capitalizable_cost'];
            $row['unit_cost'] = bccomp($row['good_quantity'], '0', 8) > 0 ? bcdiv($basis, $row['good_quantity'], 8) : '0.00000000';
            $row['_url'] = $group->count() === 1 ? $first['_url'] : null;

            return $row;
        })->values();
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function profitability(Collection $rows, int $companyId, int $periodId, int $branchId, array $filters): Collection
    {
        $salesOrderIds = $rows->pluck('_sales_order_id')->filter()->unique()->values();
        $productIds = $rows->pluck('_product_id')->filter()->unique()->values();
        $revenues = collect();
        if ($salesOrderIds->isNotEmpty() && $productIds->isNotEmpty()) {
            $revenues = DB::table('customer_invoice_lines as lines')
                ->join('customer_invoices as invoices', 'invoices.id', '=', 'lines.customer_invoice_id')
                ->whereNull('invoices.deleted_at')
                ->where('invoices.company_id', $companyId)->where('invoices.financial_period_id', $periodId)->where('invoices.branch_id', $branchId)
                ->where('invoices.posting_status', CustomerInvoice::StatusPosted)
                ->whereIn('invoices.sales_order_id', $salesOrderIds)->whereIn('lines.product_id', $productIds)
                ->when($filters['from_date'] ?? null, fn ($query, $date) => $query->whereDate('invoices.invoice_date', '>=', $date))
                ->when($filters['to_date'] ?? null, fn ($query, $date) => $query->whereDate('invoices.invoice_date', '<=', $date))
                ->groupBy('invoices.sales_order_id', 'lines.product_id')
                ->selectRaw('invoices.sales_order_id, lines.product_id, sum(case when invoices.document_type = ? then -lines.line_total * invoices.exchange_rate else lines.line_total * invoices.exchange_rate end) as revenue', [CustomerInvoice::TypeCreditNote])
                ->get()->keyBy(fn (object $row): string => $row->sales_order_id.':'.$row->product_id);
        }

        return $rows->filter(fn (array $row): bool => filled($row['_sales_order_id']))
            ->groupBy('_profit_key')->map(function (Collection $group, string $key) use ($revenues): array {
                $first = $group->first();
                $revenue = (string) ($revenues->get($key)?->revenue ?? '0');
                $costComplete = $group->every(fn (array $row): bool => (bool) ($row['_cost_complete'] ?? false));
                if (! $costComplete) {
                    $unavailable = __('costing_reports.values.unavailable');

                    return [
                        '_url' => $group->count() === 1 ? $first['_url'] : null,
                        '_cost_complete' => false,
                        'sales_order' => $first['sales_order'],
                        'product' => $first['product'],
                        'good_quantity' => $this->sum($group, 'good_quantity'),
                        'revenue' => $revenue,
                        'recognized_cost' => $unavailable,
                        'wip' => $unavailable,
                        'gross_profit' => $unavailable,
                        'margin_percent' => $unavailable,
                    ];
                }

                $recognizedCost = $this->sum($group, 'recognized_cost');
                $profit = bcsub($revenue, $recognizedCost, 8);

                return [
                    '_url' => $group->count() === 1 ? $first['_url'] : null,
                    '_cost_complete' => true,
                    'sales_order' => $first['sales_order'],
                    'product' => $first['product'],
                    'good_quantity' => $this->sum($group, 'good_quantity'),
                    'revenue' => $revenue,
                    'recognized_cost' => $recognizedCost,
                    'wip' => $this->sum($group, 'wip'),
                    'gross_profit' => $profit,
                    'margin_percent' => bccomp($revenue, '0', 8) !== 0 ? bcmul(bcdiv($profit, $revenue, 8), '100', 4) : '0.0000',
                ];
            })->values();
    }

    /** @return array<string, string> */
    private function columns(string $type): array
    {
        $keys = match ($type) {
            self::ProductCost => ['product', 'planned_quantity', 'good_quantity', 'planned_cost', 'actual_cost', 'variance', 'waste_cost', 'allocated_overhead', 'recognized_cost', 'wip', 'unit_cost'],
            self::WorkOrderCost => ['work_order', 'sales_order', 'planned_quantity', 'good_quantity', 'issued_cost', 'returned_cost', 'waste_cost', 'other_direct_cost', 'allocated_overhead', 'capitalizable_cost', 'recognized_cost', 'wip', 'unit_cost'],
            self::Profitability => ['sales_order', 'product', 'good_quantity', 'revenue', 'recognized_cost', 'wip', 'gross_profit', 'margin_percent'],
            self::CostVariance => ['run', 'work_order', 'product', 'cost_center', 'status', 'planned_cost', 'actual_cost', 'variance', 'waste_cost'],
            self::WorkInProgress => ['run', 'work_order', 'product', 'cost_center', 'status', 'issued_cost', 'returned_cost', 'waste_cost', 'other_direct_cost', 'allocated_overhead', 'capitalizable_cost', 'recognized_cost', 'wip'],
            self::FinishedGoodsCost => ['product', 'good_quantity', 'other_direct_cost', 'allocated_overhead', 'recognized_cost', 'unit_cost'],
            default => ['run', 'work_order', 'product', 'cost_center', 'status', 'planned_quantity', 'good_quantity', 'planned_cost', 'actual_cost', 'allocated_overhead', 'variance'],
        };

        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => __('costing_reports.columns.'.$key)])->all();
    }

    private function scopedId($query, mixed $docNum): ?int
    {
        if (! filled($docNum)) {
            return null;
        }

        $id = $query->where('doc_num', $docNum)->value('id');

        return $id === null ? -1 : (int) $id;
    }

    /** @param Collection<int, mixed> $rows */
    private function sum(Collection $rows, string $key): string
    {
        return $rows->reduce(fn (string $sum, mixed $row): string => bcadd($sum, (string) data_get($row, $key, '0'), 8), '0.00000000');
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function allocationAnalysis(int $companyId, int $periodId, int $branchId, ?int $costCenterId, array $filters): array
    {
        $runs = OverheadAllocationRun::query()
            ->forContext($companyId, $periodId, $branchId)
            ->where('status', '!=', OverheadAllocationRun::StatusSuperseded)
            ->when($filters['from_date'] ?? null, fn ($query, $date) => $query->whereDate('to_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($query, $date) => $query->whereDate('from_date', '<=', $date))
            ->when($costCenterId, fn ($query, $id) => $query->where(function ($scope) use ($id): void {
                $scope->whereHas('rule', fn ($rule) => $rule->where('source_cost_center_id', $id))
                    ->orWhereHas('lines', fn ($lines) => $lines->where('cost_center_id', $id));
            }))
            ->with([
                'rule.sourceCostCenter',
                'sources.journalEntryLine.journalEntry',
                'lines.productionRun.order',
            ])
            ->latest('to_date')
            ->latest('id')
            ->get();

        $rows = $runs->map(function (OverheadAllocationRun $run): array {
            $sourceEntries = $run->sources->map(fn ($source): ?string => $source->journalEntryLine?->journalEntry?->doc_num)->filter()->unique()->values();
            $productionRuns = $run->lines->map(fn ($line): ?string => $line->productionRun?->run_number)->filter()->unique()->values();
            $workOrders = $run->lines->map(fn ($line): ?string => $line->productionRun?->order?->doc_num)->filter()->unique()->values();
            $unusedCapacity = $run->unusedCapacityCost();

            return [
                '_allocation_url' => auth()->user()?->can('costing.overhead_allocation_run.view')
                    ? route('admin.costing.overhead-allocation-run.index', ['run' => $run->public_id])
                    : null,
                '_source_url' => auth()->user()?->can('journal_entries.view') && $sourceEntries->count() === 1
                    ? route('admin.accounting.journal-entries.show', $sourceEntries->first())
                    : null,
                '_url' => auth()->user()?->can('production.runs.view') && $run->lines->count() === 1 && $run->lines->first()?->productionRun
                    ? route('admin.production.runs.show', $run->lines->first()->productionRun)
                    : null,
                'allocation_run' => $run->doc_num,
                'source_cost_center' => $run->rule?->sourceCostCenter?->codeNameLabel(),
                'source_entry' => $sourceEntries->join(', '),
                'production_run' => $productionRuns->join(', '),
                'work_order' => $workOrders->join(', '),
                'eligible_cost' => (string) $run->eligible_cost,
                'allocatable_cost' => (string) $run->allocatable_cost,
                'allocated_cost' => (string) $run->allocated_cost,
                'unallocated_cost' => (string) $run->unallocated_cost,
                'unused_capacity_cost' => $unusedCapacity,
                'allocation_difference' => bcsub((string) $run->allocatable_cost, (string) $run->allocated_cost, 8),
                'actual_capacity' => (string) ($run->actual_capacity ?? '0'),
                'normal_capacity' => (string) ($run->rule?->normal_capacity_hours ?? '0'),
                'utilization_percent' => (string) ($run->utilization_percent ?? '0'),
                'status' => __('overhead_allocations.statuses.'.$run->status),
            ];
        });
        $columns = collect([
            'allocation_run', 'source_cost_center', 'source_entry', 'production_run', 'work_order',
            'eligible_cost', 'allocatable_cost', 'allocated_cost', 'unallocated_cost', 'unused_capacity_cost',
            'allocation_difference', 'actual_capacity', 'normal_capacity', 'utilization_percent', 'status',
        ])->mapWithKeys(fn (string $key): array => [$key => __('costing_reports.columns.'.$key)])->all();
        $numericColumns = [
            'eligible_cost', 'allocatable_cost', 'allocated_cost', 'unallocated_cost', 'unused_capacity_cost',
            'allocation_difference', 'actual_capacity', 'normal_capacity', 'utilization_percent',
        ];

        return [
            'type' => self::AllocationAnalysis,
            'title' => __('costing_reports.types.allocation_analysis.title'),
            'description' => __('costing_reports.types.allocation_analysis.description'),
            'columns' => $columns,
            'numeric_columns' => $numericColumns,
            'rows' => $rows,
            'totals' => collect($numericColumns)->reject(fn (string $column): bool => in_array($column, ['actual_capacity', 'normal_capacity', 'utilization_percent'], true))
                ->mapWithKeys(fn (string $column): array => [$column => $this->sum($rows, $column)])->all(),
            'notices' => [__('costing_reports.notices.allocation_trace')],
        ];
    }
}

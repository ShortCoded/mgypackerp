<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\Reports\ProductDataReport;
use Modules\Finance\Services\FinanceReportService;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Maintenance\Models\MaintenancePlanDue;
use Modules\Maintenance\Models\MaintenanceRequest;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Production\Services\ProductionReportService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesCycleReadService;

class PlasticsDashboardService
{
    /**
     * Dashboard tables and the columns inspected while building context queries.
     *
     * @var array<string, array<string, true>>
     */
    private const DASHBOARD_SCHEMA = [
        'branch_stores' => ['deleted_at' => true],
        'branches' => ['company_id' => true, 'type' => true],
        'customers' => ['company_id' => true, 'deleted_at' => true, 'status' => true],
        'financial_periods' => [],
        'inventory_opening_stocks' => ['branch_id' => true, 'company_id' => true, 'deleted_at' => true, 'financial_period_id' => true],
        'item_units' => [],
        'product_components' => [],
        'products' => [],
        'purchase_invoices' => ['branch_id' => true, 'company_id' => true, 'deleted_at' => true, 'financial_period_id' => true],
        'purchase_orders' => ['branch_id' => true, 'company_id' => true, 'deleted_at' => true, 'financial_period_id' => true],
        'purchase_requisitions' => ['branch_id' => true, 'company_id' => true, 'deleted_at' => true, 'financial_period_id' => true, 'status' => true],
        'quotations' => ['company_id' => true, 'deleted_at' => true],
        'suppliers' => ['company_id' => true, 'deleted_at' => true, 'status' => true],
        'unpriced_inventory_receipts' => ['branch_id' => true, 'company_id' => true, 'deleted_at' => true, 'financial_period_id' => true],
        'user_task_assignees' => [],
        'user_tasks' => [],
    ];

    /**
     * @var list<string>
     */
    private array $chartColors = [
        '#2c7be5',
        '#00d27a',
        '#f5803e',
        '#e63757',
        '#39afd1',
        '#6c757d',
        '#f7c948',
        '#27bcfd',
    ];

    public function __construct(
        private readonly OperatingContextService $operatingContext,
        private readonly DateFormatService $dates,
        private readonly NumericFormatService $numbers,
        private readonly ScreenDataVisibilityService $visibility,
        private readonly InventoryReportService $inventoryReports,
        private readonly ProductionReportService $productionReports,
        private readonly ProcurementCycleReport $procurementReports,
        private readonly SalesCycleReadService $salesCycleReports,
        private readonly FinanceReportService $financeReports,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forRequest(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $currentContext = $this->operatingContext->current($request);
        $snapshot = $this->operatingContext->snapshot($request);
        $period = $this->periodPayload($snapshot);

        $dashboard = [
            'context' => $this->contextPayload($currentContext, $snapshot, $period),
            'lastUpdatedAt' => $this->dates->formatDateTime(now()),
            'metrics' => [],
            'charts' => [],
            'quickActions' => [],
            'alerts' => [],
            'limitations' => [],
        ];

        if (! $dashboard['context']['complete']) {
            $dashboard['limitations'][] = __('dashboard.plastics.limitations.context_required');

            return $dashboard;
        }

        $this->appendProductMasterData($dashboard, $user, $snapshot);
        $this->appendPurchasing($dashboard, $user, $snapshot, $period);
        $this->appendInventory($dashboard, $user, $snapshot, $period);
        $this->appendSales($dashboard, $user, $snapshot, $period);
        $this->appendOperationalExceptions($dashboard, $user, $snapshot, $period);
        $this->appendTasks($dashboard, $user);

        if ($dashboard['metrics'] === []) {
            $dashboard['limitations'][] = __('dashboard.plastics.limitations.no_visible_metrics');
        }

        return $dashboard;
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForRequest(Request $request, string $type): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless(in_array($type, ['sales', 'purchases'], true), 404);

        $snapshot = $this->operatingContext->snapshot($request);
        if (! $snapshot['company_doc_num'] || ! $snapshot['branch_doc_num'] || ! $snapshot['financial_period_doc_num']) {
            return ['metrics' => [], 'empty' => true, 'message' => __('dashboard.summaries.context_required')];
        }

        $validated = $request->validate([
            'branch_doc_num' => ['nullable', 'string'],
            'financial_period_doc_num' => ['nullable', 'string'],
            'currency_doc_num' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $resolved = $this->operatingContext->resolveForUser(
            $user,
            (string) $snapshot['company_doc_num'],
            (string) ($validated['branch_doc_num'] ?? $snapshot['branch_doc_num']),
            (string) ($validated['financial_period_doc_num'] ?? $snapshot['financial_period_doc_num']),
        );
        $context = [
            ...$snapshot,
            'branch_id' => (int) $resolved['branch']->getKey(),
            'branch_doc_num' => $resolved['branch']->doc_num,
            'branch_name' => $resolved['branch']->name,
            'financial_period_id' => (int) $resolved['financial_period']->getKey(),
            'financial_period_doc_num' => $resolved['financial_period']->doc_num,
            'financial_period_name' => $resolved['financial_period']->name,
        ];
        $period = $this->periodPayload($context);
        $from = isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : $period['from'];
        $to = isset($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : $period['to'];

        if ($from->lt($period['from']) || $to->gt($period['to'])) {
            throw ValidationException::withMessages([
                'date_from' => __('dashboard.summaries.date_outside_period'),
            ]);
        }

        $period['from'] = $from;
        $period['to'] = $to;
        $currencyDocNum = $request->exists('currency_doc_num')
            ? ($validated['currency_doc_num'] ?? null)
            : Currency::query()
                ->forCompany((int) $context['company_id'])
                ->active()
                ->where('is_main', true)
                ->value('doc_num');

        $currency = null;
        if (filled($currencyDocNum)) {
            $currency = Currency::query()
                ->forCompany((int) $context['company_id'])
                ->active()
                ->where('doc_num', $currencyDocNum)
                ->first();

            if (! $currency) {
                throw ValidationException::withMessages([
                    'currency_doc_num' => __('dashboard.summaries.currency_invalid'),
                ]);
            }
        }

        $metrics = $type === 'sales'
            ? $this->salesSummaryMetrics($context, $period, $currency)
            : $this->purchaseSummaryMetrics($context, $period, $currency);

        return [
            'metrics' => $metrics,
            'empty' => $metrics === [],
            'message' => $metrics === [] ? __('dashboard.summaries.empty') : null,
            'filters' => [
                'branch' => $context['branch_doc_num'],
                'financial_period' => $context['financial_period_doc_num'],
                'currency' => $currency?->code,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function salesSummaryMetrics(array $context, array $period, ?Currency $currency): array
    {
        $filters = [
            'financial_period_id' => (int) $context['financial_period_id'],
            'currency_id' => $currency?->getKey(),
            'from' => $period['from']->toDateString(),
            'to' => $period['to']->toDateString(),
        ];
        $invoices = $this->salesCycleReports->ledger((int) $context['company_id'], (int) $context['branch_id'], $filters);
        $invoiceTotals = (clone $invoices)->reorder()->selectRaw('count(*) as invoice_count, coalesce(sum(total_amount), 0) as invoiced_value, coalesce(sum(remaining_amount), 0) as outstanding_value')->first();
        $orders = SalesOrder::query()->forCompany((int) $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])->where('branch_id', $context['branch_id'])
            ->whereBetween('order_date', [$filters['from'], $filters['to']])
            ->when($currency, fn (Builder $query) => $query->where('currency_id', $currency->getKey()))->count();
        $returns = SalesReturn::query()->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])->where('branch_id', $context['branch_id'])
            ->where('status', '<>', SalesReturn::StatusCancelled)->whereBetween('return_date', [$filters['from'], $filters['to']])
            ->when($currency, fn (Builder $query) => $query->where(function (Builder $query) use ($currency): void {
                $query->whereHas('invoice', fn (Builder $invoice) => $invoice->where('currency_id', $currency->getKey()))
                    ->orWhereHas('order', fn (Builder $order) => $order->where('currency_id', $currency->getKey()));
            }));

        return [
            $this->summaryMetric('sales_orders', $orders),
            $this->summaryMetric('sales_invoices', (int) ($invoiceTotals?->invoice_count ?? 0)),
            $this->summaryMetric('sales_value', $currency ? (string) ($invoiceTotals?->invoiced_value ?? 0) : null, $currency),
            $this->summaryMetric('sales_returns', (clone $returns)->count(), $currency, $currency ? (string) (clone $returns)->sum('total_amount') : null),
            $this->summaryMetric('sales_outstanding', $currency ? (string) ($invoiceTotals?->outstanding_value ?? 0) : null, $currency),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function purchaseSummaryMetrics(array $context, array $period, ?Currency $currency): array
    {
        $filters = [
            'branch_id' => (int) $context['branch_id'], 'currency_doc_num' => $currency?->doc_num,
            'date_from' => $period['from']->toDateString(), 'date_to' => $period['to']->toDateString(),
        ];
        $orders = $this->procurementReports->rows(ProcurementCycleReport::PurchaseOrderStatus, $filters, (int) $context['company_id'], (int) $context['financial_period_id']);
        $invoices = $this->procurementReports->rows(ProcurementCycleReport::PurchaseInvoices, $filters, (int) $context['company_id'], (int) $context['financial_period_id']);
        $returns = $this->procurementReports->rows(ProcurementCycleReport::Returns, $filters, (int) $context['company_id'], (int) $context['financial_period_id']);

        return [
            $this->summaryMetric('purchase_orders', $orders->pluck('document')->filter()->unique()->count()),
            $this->summaryMetric('purchase_invoices', $invoices->pluck('document')->filter()->unique()->count()),
            $this->summaryMetric('purchase_value', $currency ? (string) $invoices->sum('amount') : null, $currency),
            $this->summaryMetric('purchase_returns', $returns->pluck('document')->filter()->unique()->count(), $currency, $currency ? (string) $returns->sum('amount') : null),
            $this->summaryMetric('purchase_outstanding', $currency ? (string) $invoices->sum('outstanding') : null, $currency),
        ];
    }

    /** @return array<string, mixed> */
    private function summaryMetric(string $key, int|string|null $value, ?Currency $currency = null, ?string $secondaryValue = null): array
    {
        $formatted = $value === null ? __('dashboard.summaries.select_currency_for_value') : (is_int($value) ? $this->formatCount($value) : $this->numbers->format($value));
        $meta = $currency?->code ?? __('dashboard.summaries.count_only');
        if ($secondaryValue !== null) {
            $meta = __('dashboard.summaries.return_value', ['value' => $this->numbers->format($secondaryValue), 'currency' => $currency?->code]);
        }

        return ['key' => $key, 'title' => __('dashboard.summaries.metrics.'.$key), 'value' => $formatted, 'meta' => $meta, 'icon' => 'chart-bar', 'color' => 'primary', 'url' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryFilterOptions(Request $request): array
    {
        $options = $this->operatingContext->options($request);
        $companyId = $this->operatingContext->selectedCompanyId($request);
        $currencies = $companyId ? Currency::query()
            ->forCompany($companyId)
            ->active()
            ->orderByDesc('is_main')
            ->orderBy('code')
            ->get(['doc_num', 'code', 'is_main']) : collect();

        return [
            'branches' => $options['branches'],
            'financial_periods' => $options['financial_periods'],
            'currencies' => $currencies
                ->map(fn (Currency $currency): array => ['id' => $currency->doc_num, 'text' => $currency->code])
                ->all(),
            'default_currency_doc_num' => $currencies->firstWhere('is_main', true)?->doc_num,
            'current' => $options['current'],
        ];
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $context
     */
    private function appendProductMasterData(array &$dashboard, User $user, array $context): void
    {
        if (! $this->tableExists('products')) {
            return;
        }

        $items = [];
        $companyId = (int) $context['company_id'];
        $canViewProducts = $this->can($user, 'products.view');
        $canViewRawMaterials = $this->can($user, 'raw_materials.view');
        $canViewPackagingMaterials = $this->can($user, 'packaging_materials.view');
        $rawMaterials = $canViewRawMaterials
            ? $this->activeProductCount($user, Product::ContextRawMaterials, $companyId)
            : null;
        $packagingMaterials = $canViewPackagingMaterials
            ? $this->activeProductCount($user, Product::ContextPackagingMaterials, $companyId)
            : null;

        if ($canViewProducts) {
            [$products, $withComponents, $productTypes] = $this->productSummary($user, $companyId);
            $withoutComponents = max(0, $products - $withComponents);
            $componentLines = $this->tableExists('product_components')
                ? ProductComponent::query()
                    ->forCompany($companyId)
                    ->whereIn('product_id', $this->productQuery($user, 'products', $companyId)
                        ->productItems()
                        ->where('products.status', 'active')
                        ->select('products.id'))
                    ->count()
                : 0;

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.products.title'),
                $products,
                __('dashboard.plastics.metrics.products.meta'),
                'box',
                'primary',
                $this->routeUrl('admin.products.index', ['status' => 'active']),
            );
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.products_with_bom.title'),
                $withComponents,
                __('dashboard.plastics.metrics.products_with_bom.meta', ['without' => $this->formatCount($withoutComponents)]),
                'sitemap',
                $withoutComponents > 0 ? 'warning' : 'success',
                $this->routeUrl('admin.products.index', [
                    'status' => 'active',
                    'components_state' => 'with',
                ]),
            );
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.bom_lines.title'),
                $componentLines,
                __('dashboard.plastics.metrics.bom_lines.meta'),
                'layer-group',
                'info',
                $this->can($user, 'reports.products_data.view')
                    ? $this->routeUrl('admin.reports.products-data.index', [
                        'result_mode' => ProductDataReport::ModeDetailed,
                        'item_scope' => ProductDataReport::ItemScopeProducts,
                        'record_state' => 'active',
                        'status' => 'active',
                        'components_state' => 'with',
                    ])
                    : $this->routeUrl('admin.products.index', [
                        'status' => 'active',
                        'components_state' => 'with',
                    ]),
            );

            if ($withoutComponents > 0) {
                $dashboard['alerts'][] = $this->alert(
                    'warning',
                    __('dashboard.plastics.alerts.products_without_components.title'),
                    __('dashboard.plastics.alerts.products_without_components.body', ['count' => $this->formatCount($withoutComponents)]),
                    $this->routeUrl('admin.products.index'),
                );
            }

            $this->appendProductCharts(
                $dashboard,
                $productTypes,
                $withComponents,
                $withoutComponents,
                $rawMaterials,
                $packagingMaterials,
            );
            $this->appendQuickAction($dashboard, $user, 'products.create', 'admin.products.create', __('dashboard.plastics.quick_actions.product'), 'plus');
            $this->appendQuickAction($dashboard, $user, 'reports.products_data.view', 'admin.reports.products-data.index', __('dashboard.plastics.quick_actions.products_report'), 'chart-bar');
        }

        if ($rawMaterials !== null) {
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.raw_materials.title'),
                $rawMaterials,
                __('dashboard.plastics.metrics.raw_materials.meta'),
                'cubes',
                'warning',
                $this->routeUrl('admin.raw-materials.index', ['status' => 'active']),
            );

            $this->appendMaterialUnitChart(
                $dashboard,
                $companyId,
                $user,
                Product::ContextRawMaterials,
                'dashboard-raw-material-units',
                'dashboard.plastics.charts.raw_material_units',
            );
            $this->appendQuickAction($dashboard, $user, 'raw_materials.create', 'admin.raw-materials.create', __('dashboard.plastics.quick_actions.raw_material'), 'plus');
        }

        if ($packagingMaterials !== null) {
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.packaging_materials.title'),
                $packagingMaterials,
                __('dashboard.plastics.metrics.packaging_materials.meta'),
                'boxes',
                'info',
                $this->routeUrl('admin.packaging-materials.index', ['status' => 'active']),
            );

            $this->appendMaterialUnitChart(
                $dashboard,
                $companyId,
                $user,
                Product::ContextPackagingMaterials,
                'dashboard-packaging-material-units',
                'dashboard.plastics.charts.packaging_material_units',
            );
            $this->appendQuickAction($dashboard, $user, 'packaging_materials.create', 'admin.packaging-materials.create', __('dashboard.plastics.quick_actions.packaging_material'), 'plus');
        }

        if ($items !== []) {
            $this->appendMetrics($dashboard, __('dashboard.plastics.sections.product_master'), $items);
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  list<array{name: string, value: int}>  $productTypes
     */
    private function appendProductCharts(
        array &$dashboard,
        array $productTypes,
        int $withComponents,
        int $withoutComponents,
        ?int $rawMaterials,
        ?int $packagingMaterials,
    ): void {
        $typeData = $productTypes;

        if ($rawMaterials !== null && $rawMaterials > 0) {
            $typeData[] = [
                'name' => __('products.classifications.raw_material'),
                'value' => $rawMaterials,
            ];
        }

        if ($packagingMaterials !== null && $packagingMaterials > 0) {
            $typeData[] = [
                'name' => __('products.classifications.packaging'),
                'value' => $packagingMaterials,
            ];
        }

        if ($typeData !== []) {
            $dashboard['charts'][] = $this->pieChart(
                'dashboard-product-types',
                __('dashboard.plastics.charts.product_types'),
                $typeData,
            );
        }

        if (($withComponents + $withoutComponents) > 0) {
            $dashboard['charts'][] = $this->pieChart(
                'dashboard-bom-coverage',
                __('dashboard.plastics.charts.bom_coverage'),
                [
                    ['name' => __('dashboard.plastics.chart_labels.with_components'), 'value' => $withComponents],
                    ['name' => __('dashboard.plastics.chart_labels.without_components'), 'value' => $withoutComponents],
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     */
    private function appendMaterialUnitChart(
        array &$dashboard,
        int $companyId,
        User $user,
        string $context,
        string $chartId,
        string $chartTitle,
    ): void {
        if (! $this->tableExists('item_units')) {
            return;
        }

        $unspecifiedLabel = __('dashboard.plastics.chart_labels.unspecified');

        $rows = $this->productQuery($user, $context, $companyId)
            ->forProductContext($context)
            ->where('products.status', 'active')
            ->leftJoin('item_units', 'item_units.id', '=', 'products.item_unit_id')
            ->selectRaw('item_units.name as label, COUNT(*) as aggregate')
            ->groupBy('item_units.name')
            ->orderByDesc('aggregate')
            ->limit(8)
            ->get();

        $data = $rows
            ->map(fn (Product $row): array => [
                'name' => trim((string) $row->label) !== '' ? (string) $row->label : $unspecifiedLabel,
                'value' => (int) $row->aggregate,
            ])
            ->filter(fn (array $row): bool => $row['value'] > 0)
            ->values()
            ->all();

        if ($data !== []) {
            $dashboard['charts'][] = $this->pieChart(
                $chartId,
                __($chartTitle),
                $data,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $period
     */
    private function appendPurchasing(array &$dashboard, User $user, array $context, array $period, bool $summaryMode = false, ?int $currencyId = null): void
    {
        $items = [];

        if (($summaryMode || $this->can($user, 'purchases.purchase_requisitions.view')) && $this->tableExists('purchase_requisitions')) {
            $query = $this->contextQuery(
                'purchase_requisitions',
                $context,
                branch: ! $this->isAdministrativeBranchContext($context),
            )->whereBetween('request_date', [$period['from']->toDateString(), $period['to']->toDateString()]);
            [$count, $pendingApproval] = $this->countWithValues($query, 'status', ['pending_approval']);

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.purchase_requisitions.title'),
                $count,
                __('dashboard.plastics.metrics.purchase_requisitions.meta', ['count' => $this->formatCount($pendingApproval)]),
                'clipboard-check',
                $pendingApproval > 0 ? 'warning' : 'primary',
                $this->routeUrl('admin.purchases.purchase-requisitions.index'),
            );
        }

        if (($summaryMode || $this->can($user, 'suppliers.view')) && $this->tableExists('suppliers')) {
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.suppliers.title'),
                $this->activeCompanyCount('suppliers', $context, $user, 'suppliers'),
                __('dashboard.plastics.metrics.suppliers.meta'),
                'truck',
                'secondary',
                $this->routeUrl('admin.purchases.suppliers.index'),
            );
        }

        if (($summaryMode || $this->can($user, 'purchase_invoices.view')) && $this->tableExists('purchase_invoices')) {
            $query = $this->restrictedContextQuery('purchase_invoices', $context, $user, 'purchase_invoices')
                ->whereBetween('invoice_date', [$period['from']->toDateString(), $period['to']->toDateString()]);
            if ($currencyId) {
                $query->where('currency_id', $currencyId);
            }
            [$count, $unpaid] = $this->countWithValues($query, 'payment_status', ['unpaid', 'partially_paid']);

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.purchase_invoices.title'),
                $count,
                __('dashboard.plastics.metrics.purchase_invoices.meta', ['count' => $this->formatCount($unpaid)]),
                'file-invoice-dollar',
                $unpaid > 0 ? 'warning' : 'info',
                $this->routeUrl('admin.purchases.purchase-invoices.index'),
            );
        }

        if (($summaryMode || $this->can($user, 'purchase_orders.view')) && $this->tableExists('purchase_orders')) {
            $query = $this->restrictedContextQuery('purchase_orders', $context, $user, 'purchase_orders')
                ->whereBetween('document_date', [$period['from']->toDateString(), $period['to']->toDateString()]);
            if ($currencyId) {
                $query->where('currency_id', $currencyId);
            }
            [$count, $draft] = $this->countWithValues($query, 'status', ['draft']);

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.purchase_orders.title'),
                $count,
                __('dashboard.plastics.metrics.purchase_orders.meta', ['count' => $this->formatCount($draft)]),
                'clipboard-list',
                $draft > 0 ? 'warning' : 'primary',
                $this->routeUrl('admin.purchases.purchase-orders.index'),
            );
        }

        if ($items !== []) {
            $this->appendMetrics($dashboard, __('dashboard.plastics.sections.purchasing'), $items);
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $period
     */
    private function appendInventory(array &$dashboard, User $user, array $context, array $period): void
    {
        $items = [];

        if ($this->can($user, 'inventory.unpriced_inventory_receipts.view') && $this->tableExists('unpriced_inventory_receipts')) {
            $query = $this->restrictedContextQuery('unpriced_inventory_receipts', $context, $user, 'unpriced_inventory_receipts')
                ->whereBetween('document_date', [$period['from']->toDateString(), $period['to']->toDateString()]);
            $awaitingPricing = (clone $query)
                ->where('pricing_status', 'unpriced')
                ->where('status', '!=', 'cancelled')
                ->count();

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.unpriced_receipts.title'),
                $awaitingPricing,
                __('dashboard.plastics.metrics.unpriced_receipts.meta'),
                'dolly',
                $awaitingPricing > 0 ? 'warning' : 'secondary',
                $this->routeUrl('admin.inventory.unpriced-inventory-receipts.index'),
            );

            if ($awaitingPricing > 0) {
                $dashboard['alerts'][] = $this->alert(
                    'warning',
                    __('dashboard.plastics.alerts.unpriced_receipts.title'),
                    __('dashboard.plastics.alerts.unpriced_receipts.body', ['count' => $this->formatCount($awaitingPricing)]),
                    $this->routeUrl('admin.inventory.unpriced-inventory-receipts.index'),
                );
            }
        }

        if ($this->can($user, 'branches.view') && $this->tableExists('branch_stores')) {
            $storesQuery = DB::table('branch_stores')
                ->where('branch_id', $context['branch_id']);

            if ($this->hasColumn('branch_stores', 'deleted_at')) {
                $storesQuery->whereNull('deleted_at');
            }

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.stores.title'),
                $storesQuery->count(),
                __('dashboard.plastics.metrics.stores.meta'),
                'warehouse',
                'info',
                $this->routeUrl('admin.branches.index'),
            );
        }

        if ($this->can($user, 'inventory.opening_stocks.view') && $this->tableExists('inventory_opening_stocks')) {
            $query = $this->restrictedContextQuery('inventory_opening_stocks', $context, $user, 'opening_stocks')
                ->whereBetween('document_date', [$period['from']->toDateString(), $period['to']->toDateString()]);
            [$count, $approved] = $this->countWithValues($query, 'status', ['approved']);

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.opening_stocks.title'),
                $count,
                __('dashboard.plastics.metrics.opening_stocks.meta', ['count' => $this->formatCount($approved)]),
                'boxes',
                'success',
                $this->routeUrl('admin.inventory.opening-stocks.index'),
            );
        }

        if ($items !== []) {
            $this->appendMetrics($dashboard, __('dashboard.plastics.sections.inventory'), $items);
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $period
     */
    private function appendSales(array &$dashboard, User $user, array $context, array $period, bool $summaryMode = false, ?int $currencyId = null): void
    {
        $items = [];

        if (($summaryMode || $this->can($user, 'customers.view')) && $this->tableExists('customers')) {
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.customers.title'),
                $this->activeCompanyCount('customers', $context, $user, 'customers'),
                __('dashboard.plastics.metrics.customers.meta'),
                'user-tie',
                'primary',
                $this->routeUrl('admin.sales.customers.index'),
            );
        }

        if (($summaryMode || $this->can($user, 'quotations.view')) && $this->tableExists('quotations')) {
            $query = $this->restrictedContextQuery('quotations', $context, $user, 'quotations', branch: false, financialPeriod: false)
                ->whereBetween('quotation_date', [$period['from']->toDateString(), $period['to']->toDateString()]);
            if ($currencyId) {
                $query->where('currency_id', $currencyId);
            }
            [$count, $accepted] = $this->countWithValues($query, 'status', ['accepted']);

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.quotations.title'),
                $count,
                __('dashboard.plastics.metrics.quotations.meta', ['count' => $this->formatCount($accepted)]),
                'file-signature',
                'success',
                $this->routeUrl('admin.sales.quotations.index'),
            );
        }

        if ($items !== []) {
            $this->appendMetrics($dashboard, __('dashboard.plastics.sections.sales'), $items);
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $period
     */
    private function appendOperationalExceptions(array &$dashboard, User $user, array $context, array $period): void
    {
        $items = [];
        $companyId = (int) $context['company_id'];
        $periodId = (int) $context['financial_period_id'];
        $branchId = (int) $context['branch_id'];
        $asOf = today();
        if ($asOf->greaterThan($period['to'])) {
            $asOf = $period['to']->copy()->startOfDay();
        } elseif ($asOf->lessThan($period['from'])) {
            $asOf = $period['from']->copy()->startOfDay();
        }
        $contextFilters = [
            'financial_period_id' => $periodId,
            'branch_id' => $branchId,
            'as_of' => $asOf->toDateString(),
        ];

        if ($this->can($user, 'inventory.reports.operational')) {
            $hasReorderSetup = Product::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->whereIn('item_classification', Product::stockableItemClassifications())
                ->where('reorder_point', '>', 0)
                ->exists();
            $hasStockStore = BranchStore::query()
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $hasStockStore) {
                $dashboard['limitations'][] = __('dashboard.plastics.limitations.inventory_store_missing');
            } elseif ($hasReorderSetup) {
                $balances = $this->inventoryReports->balances($companyId, $contextFilters);
                $reservations = $this->inventoryReports->reservations($companyId, [
                    ...$contextFilters,
                    'status' => InventoryReservation::StatusActive,
                ]);
                $lowStock = $this->inventoryReports->lowStockRows($companyId, $branchId, $balances, $reservations, $contextFilters);
                $items[] = $this->metric(
                    __('dashboard.plastics.metrics.low_stock.title'),
                    $lowStock->count(),
                    __('dashboard.plastics.metrics.low_stock.meta'),
                    'boxes-stacked',
                    $lowStock->isNotEmpty() ? 'danger' : 'success',
                    $this->routeUrl('admin.inventory.reports.index', ['as_of' => $asOf->toDateString(), 'operational_focus' => 'low_stock']),
                    'low_stock',
                );
            } else {
                $dashboard['limitations'][] = __('dashboard.plastics.limitations.reorder_setup_missing');
            }
        }

        if ($this->can($user, 'production.reports.operational')) {
            $materialShortages = $this->productionReports->materialShortages($companyId, $contextFilters);
            $remainingOrders = $this->productionReports->remainingOrders($companyId, $contextFilters);
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.material_shortages.title'),
                $materialShortages->count(),
                __('dashboard.plastics.metrics.material_shortages.meta'),
                'triangle-exclamation',
                $materialShortages->isNotEmpty() ? 'danger' : 'success',
                $this->routeUrl('admin.production.reports.materials', ['operational_focus' => 'shortage']),
                'material_shortages',
            );
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.production_remaining.title'),
                $remainingOrders->count(),
                __('dashboard.plastics.metrics.production_remaining.meta'),
                'industry',
                $remainingOrders->isNotEmpty() ? 'warning' : 'success',
                $this->routeUrl('admin.production.reports.orders', ['operational_focus' => 'remaining']),
                'production_remaining',
            );

            $qualityFilters = ['financial_period_id' => $periodId, 'branch_id' => $branchId];
            $pendingQuality = $this->productionReports->qualityExceptions($companyId, [...$qualityFilters, 'operational_focus' => 'pending']);
            $rejectedQuality = $this->productionReports->qualityExceptions($companyId, [...$qualityFilters, 'operational_focus' => 'rejected']);
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.quality_pending.title'),
                $pendingQuality->count(),
                __('dashboard.plastics.metrics.quality_pending.meta'),
                'clipboard-check',
                $pendingQuality->isNotEmpty() ? 'warning' : 'success',
                $this->routeUrl('admin.production.reports.quality', ['operational_focus' => 'pending']),
                'quality_pending',
            );
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.quality_rejected.title'),
                $rejectedQuality->count(),
                __('dashboard.plastics.metrics.quality_rejected.meta'),
                'ban',
                $rejectedQuality->isNotEmpty() ? 'danger' : 'success',
                $this->routeUrl('admin.production.reports.quality', ['operational_focus' => 'rejected']),
                'quality_rejected',
            );
        }

        if ($this->can($user, 'reports.sales.sales_orders.view')) {
            $currencyId = Currency::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderByDesc('is_main')
                ->value('id');
            if ($currencyId) {
                $salesRequests = SalesRequest::query()->where('company_id', $companyId)->where('financial_period_id', $periodId)
                    ->where('branch_id', $branchId)->operationallyOpen()->count();
                $quotations = Quotation::query()->where('company_id', $companyId)->where('branch_id', $branchId)
                    ->where('currency_id', $currencyId)->operationallyPending()->count();
                $salesOrders = SalesOrder::query()->where('company_id', $companyId)->where('financial_period_id', $periodId)
                    ->where('branch_id', $branchId)->where('currency_id', $currencyId)->operationallyOpen()->count();
                $salesActions = $salesRequests + $quotations + $salesOrders;
                $items[] = $this->metric(
                    __('dashboard.plastics.metrics.pending_sales_actions.title'),
                    $salesActions,
                    __('dashboard.plastics.metrics.pending_sales_actions.meta', [
                        'requests' => $this->formatCount($salesRequests),
                        'quotations' => $this->formatCount($quotations),
                        'orders' => $this->formatCount($salesOrders),
                    ]),
                    'cart-shopping',
                    $salesActions > 0 ? 'warning' : 'success',
                    $this->routeUrl('admin.reports.sales.sales-orders.index', ['report' => 'operational', 'operational_focus' => 'pending_sales_actions']),
                    'pending_sales_actions',
                );
            } else {
                $dashboard['limitations'][] = __('dashboard.plastics.limitations.sales_currency_missing');
            }
        }

        if ($this->can($user, 'reports.purchases.view')) {
            $purchaseFilters = ['branch_id' => $branchId];
            $pendingRequests = $this->procurementReports->rows(ProcurementCycleReport::PendingPurchaseRequests, $purchaseFilters, $companyId, $periodId);
            $pendingSourcing = $this->procurementReports->rows(ProcurementCycleReport::PendingSourcingActions, $purchaseFilters, $companyId, $periodId);
            $openOrders = $this->procurementReports->rows(ProcurementCycleReport::OpenPurchaseOrders, $purchaseFilters, $companyId, $periodId);
            $partiallyReceived = $this->procurementReports->rows(ProcurementCycleReport::PartiallyReceivedOrders, $purchaseFilters, $companyId, $periodId);
            $overdueSupply = $this->procurementReports->rows(ProcurementCycleReport::OverduePoDeliveries, $purchaseFilters, $companyId, $periodId);
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.pending_purchase_requests.title'),
                $pendingRequests->count(),
                __('dashboard.plastics.metrics.pending_purchase_requests.meta'),
                'file-circle-question',
                $pendingRequests->isNotEmpty() ? 'warning' : 'success',
                $this->routeUrl('admin.purchases.procurement-cycle-report.index', ['report_type' => ProcurementCycleReport::PendingPurchaseRequests, 'branch_id' => $branchId]),
                'pending_purchase_requests',
            );
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.pending_purchase_sourcing.title'),
                $pendingSourcing->count(),
                __('dashboard.plastics.metrics.pending_purchase_sourcing.meta'),
                'scale-balanced',
                $pendingSourcing->isNotEmpty() ? 'warning' : 'success',
                $this->routeUrl('admin.purchases.procurement-cycle-report.index', ['report_type' => ProcurementCycleReport::PendingSourcingActions, 'branch_id' => $branchId]),
                'pending_purchase_sourcing',
            );
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.open_purchase_orders.title'),
                $openOrders->count(),
                __('dashboard.plastics.metrics.open_purchase_orders.meta', [
                    'partial' => $this->formatCount($partiallyReceived->count()),
                    'overdue' => $this->formatCount($overdueSupply->count()),
                ]),
                'truck-ramp-box',
                $overdueSupply->isNotEmpty() ? 'danger' : ($openOrders->isNotEmpty() ? 'warning' : 'success'),
                $this->routeUrl('admin.purchases.procurement-cycle-report.index', ['report_type' => ProcurementCycleReport::OpenPurchaseOrders, 'branch_id' => $branchId]),
                'open_purchase_orders',
            );
        }

        if ($this->can($user, 'maintenance.reports.view')) {
            $maintenanceContext = fn ($query) => $query->forContext($companyId, $periodId, $branchId);
            $breakdowns = $maintenanceContext(MaintenanceRequest::query())->operationallyOpen()->breakdowns();
            $breakdownCount = (clone $breakdowns)->count();
            $stoppedCount = (clone $breakdowns)->where('is_machine_stopped', true)->count();
            $overdueOrders = $maintenanceContext(MaintenanceWorkOrder::query())->overdue()->count();
            $overdueDues = $maintenanceContext(MaintenancePlanDue::query())->overdue()->count();
            $maintenanceOverdue = $overdueOrders + $overdueDues;
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.maintenance_breakdowns.title'),
                $breakdownCount,
                __('dashboard.plastics.metrics.maintenance_breakdowns.meta', ['stopped' => $this->formatCount($stoppedCount)]),
                'screwdriver-wrench',
                $stoppedCount > 0 ? 'danger' : ($breakdownCount > 0 ? 'warning' : 'success'),
                $this->routeUrl('admin.maintenance.reports.index', ['operational_focus' => 'breakdown']),
                'maintenance_breakdowns',
            );
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.maintenance_overdue.title'),
                $maintenanceOverdue,
                __('dashboard.plastics.metrics.maintenance_overdue.meta'),
                'clock',
                $maintenanceOverdue > 0 ? 'danger' : 'success',
                $this->routeUrl('admin.maintenance.reports.index', ['operational_focus' => 'overdue']),
                'maintenance_overdue',
            );
        }

        $financeContext = [
            'as_of_date' => $asOf->toDateString(),
            'branch_id' => $branchId,
            'financial_period_id' => $periodId,
        ];
        if ($this->can($user, 'reports.finance.view')) {
            $filters = [...$financeContext, 'type' => FinanceReportService::UnapprovedDocuments];
            $rows = $this->financeReports->report($filters)['rows'];
            $items[] = $this->metric(__('dashboard.plastics.metrics.pending_finance_approvals.title'), $rows->count(), __('dashboard.plastics.metrics.pending_finance_approvals.meta'), 'stamp', $rows->isNotEmpty() ? 'warning' : 'success', $this->routeUrl('admin.reports.finance.index', $filters), 'pending_finance_approvals');
        }
        if ($this->canAny($user, ['reports.finance.view', 'reports.finance.customer_aging.view'])) {
            $filters = [...$financeContext, 'type' => FinanceReportService::CustomerAging, 'due_state' => 'due_or_overdue'];
            $rows = $this->financeReports->report($filters)['rows'];
            $items[] = $this->metric(__('dashboard.plastics.metrics.due_receivables.title'), $rows->count(), __('dashboard.plastics.metrics.due_receivables.meta'), 'money-bill-wave', $rows->isNotEmpty() ? 'danger' : 'success', $this->routeUrl('admin.reports.finance.index', $filters), 'due_receivables');
        }
        if ($this->canAny($user, ['reports.finance.view', 'reports.finance.supplier_aging.view'])) {
            $filters = [...$financeContext, 'type' => FinanceReportService::SupplierAging, 'due_state' => 'due_or_overdue'];
            $rows = $this->financeReports->report($filters)['rows'];
            $items[] = $this->metric(__('dashboard.plastics.metrics.due_payables.title'), $rows->count(), __('dashboard.plastics.metrics.due_payables.meta'), 'hand-holding-dollar', $rows->isNotEmpty() ? 'danger' : 'success', $this->routeUrl('admin.reports.finance.index', $filters), 'due_payables');
        }
        if ($this->canAny($user, ['reports.finance.view', 'reports.finance.cheque_transit.view'])) {
            $dueFilters = [...$financeContext, 'type' => FinanceReportService::DueCheques, 'to_date' => $asOf->toDateString()];
            $returnedFilters = [...$financeContext, 'type' => FinanceReportService::ReturnedCheques];
            $dueCheques = $this->financeReports->report($dueFilters)['rows'];
            $returnedCheques = $this->financeReports->report($returnedFilters)['rows'];
            $items[] = $this->metric(__('dashboard.plastics.metrics.due_cheques.title'), $dueCheques->count(), __('dashboard.plastics.metrics.due_cheques.meta'), 'money-check-dollar', $dueCheques->isNotEmpty() ? 'warning' : 'success', $this->routeUrl('admin.reports.finance.index', $dueFilters), 'due_cheques');
            $items[] = $this->metric(__('dashboard.plastics.metrics.returned_cheques.title'), $returnedCheques->count(), __('dashboard.plastics.metrics.returned_cheques.meta'), 'rotate-left', $returnedCheques->isNotEmpty() ? 'danger' : 'success', $this->routeUrl('admin.reports.finance.index', $returnedFilters), 'returned_cheques');
        }

        if ($items !== []) {
            $this->appendMetrics($dashboard, __('dashboard.plastics.sections.operational_exceptions'), $items);
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     */
    private function appendTasks(array &$dashboard, User $user): void
    {
        $canViewMyBoard = $this->can($user, 'my_board.view');
        $canViewTeamTasks = $this->can($user, 'my_board.tasks.view_all');

        if (! $this->tableExists('user_tasks') || (! $canViewMyBoard && ! $canViewTeamTasks)) {
            return;
        }

        $items = [];
        $myTasks = $this->taskQueryForUser($user)
            ->where('type', UserTask::TypeTask)
            ->where('is_active', true)
            ->where('status', '!=', UserTask::StatusDone);
        $myTaskCounts = (clone $myTasks)
            ->selectRaw('COUNT(*) as aggregate')
            ->selectRaw('COALESCE(SUM(CASE WHEN due_at IS NOT NULL AND due_at < ? THEN 1 ELSE 0 END), 0) as secondary_aggregate', [now()])
            ->first();
        $myOpen = (int) ($myTaskCounts?->aggregate ?? 0);
        $myOverdue = (int) ($myTaskCounts?->secondary_aggregate ?? 0);

        if ($canViewMyBoard) {
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.my_tasks.title'),
                $myOpen,
                __('dashboard.plastics.metrics.my_tasks.meta', ['count' => $this->formatCount($myOverdue)]),
                'tasks',
                $myOverdue > 0 ? 'danger' : 'secondary',
                $this->routeUrl('admin.my-board.index'),
            );
        }

        if ($canViewTeamTasks) {
            $teamOpen = DB::table('user_tasks')
                ->whereNull('deleted_at')
                ->where('type', UserTask::TypeTask)
                ->where('is_active', true)
                ->where('status', '!=', UserTask::StatusDone)
                ->count();

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.team_tasks.title'),
                $teamOpen,
                __('dashboard.plastics.metrics.team_tasks.meta'),
                'users',
                'info',
                $this->routeUrl('admin.tools.team-board.index'),
            );
        }

        $statusQuery = $canViewTeamTasks
            ? DB::table('user_tasks')->whereNull('user_tasks.deleted_at')
            : $this->taskQueryForUser($user);

        $statusData = $statusQuery
            ->where('user_tasks.type', UserTask::TypeTask)
            ->where('user_tasks.is_active', true)
            ->select('user_tasks.status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('user_tasks.status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count, string $status): array => [
                'name' => __("user_tasks.statuses.{$status}"),
                'value' => (int) $count,
            ])
            ->values()
            ->all();

        if ($statusData !== []) {
            $dashboard['charts'][] = $this->pieChart('dashboard-task-status', __('dashboard.plastics.charts.task_status'), $statusData);
        }

        if ($myOverdue > 0) {
            $dashboard['alerts'][] = $this->alert(
                'danger',
                __('dashboard.plastics.alerts.my_tasks_overdue.title'),
                __('dashboard.plastics.alerts.my_tasks_overdue.body', ['count' => $this->formatCount($myOverdue)]),
                $this->routeUrl('admin.my-board.index'),
            );
        }

        $this->appendQuickAction($dashboard, $user, 'my_board.create', 'admin.my-board.index', __('dashboard.plastics.quick_actions.task'), 'plus');

        if ($items !== []) {
            $this->appendMetrics($dashboard, __('dashboard.plastics.sections.follow_up'), $items);
        }
    }

    private function activeCompanyCount(string $table, array $context, User $user, string $screenKey): int
    {
        $query = $this->restrictedContextQuery($table, $context, $user, $screenKey, branch: false, financialPeriod: false);

        if ($this->hasColumn($table, 'status')) {
            $query->where('status', 'active');
        }

        return $query->count();
    }

    /** @return Builder<Product> */
    private function productQuery(User $user, string $screenKey, int $companyId): Builder
    {
        return $this->visibility->applyToEloquent(
            Product::query()->forCompany($companyId),
            $user,
            $screenKey,
        );
    }

    private function restrictedContextQuery(
        string $table,
        array $context,
        User $user,
        string $screenKey,
        bool $branch = true,
        bool $financialPeriod = true,
    ): QueryBuilder {
        if ($branch && $this->isAdministrativeBranchContext($context)) {
            $branch = false;
        }

        return $this->visibility->applyToQuery(
            $this->contextQuery($table, $context, $branch, $financialPeriod),
            $user,
            $screenKey,
        );
    }

    private function isAdministrativeBranchContext(array $context): bool
    {
        return DB::table('branches')
            ->where('id', $context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeAdministrative)
            ->exists();
    }

    private function taskQueryForUser(User $user): QueryBuilder
    {
        $query = DB::table('user_tasks')->whereNull('user_tasks.deleted_at');

        return $query->where(function (QueryBuilder $query) use ($user): void {
            $query->where('user_tasks.assigned_to', $user->getKey());

            if ($this->tableExists('user_task_assignees')) {
                $query->orWhereExists(function (QueryBuilder $exists) use ($user): void {
                    $exists
                        ->selectRaw('1')
                        ->from('user_task_assignees')
                        ->whereColumn('user_task_assignees.user_task_id', 'user_tasks.id')
                        ->where('user_task_assignees.user_id', $user->getKey());
                });
            }
        });
    }

    private function contextQuery(string $table, array $context, bool $branch = true, bool $financialPeriod = true): QueryBuilder
    {
        $query = DB::table($table);

        if ($this->hasColumn($table, 'deleted_at')) {
            $query->whereNull("{$table}.deleted_at");
        }

        if ($this->hasColumn($table, 'company_id')) {
            $query->where("{$table}.company_id", $context['company_id']);
        }

        if ($branch && $this->hasColumn($table, 'branch_id')) {
            $query->where("{$table}.branch_id", $context['branch_id']);
        }

        if ($financialPeriod && $this->hasColumn($table, 'financial_period_id')) {
            $query->where("{$table}.financial_period_id", $context['financial_period_id']);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function periodPayload(array $snapshot): array
    {
        $fallbackFrom = now()->startOfMonth();
        $fallbackTo = now()->endOfMonth();
        $period = null;

        if ($this->tableExists('financial_periods') && $snapshot['financial_period_id']) {
            $period = DB::table('financial_periods')
                ->where('id', $snapshot['financial_period_id'])
                ->first(['from_date', 'to_date']);
        }

        $from = $period?->from_date ? Carbon::parse($period->from_date)->startOfDay() : $fallbackFrom;
        $to = $period?->to_date ? Carbon::parse($period->to_date)->endOfDay() : $fallbackTo;

        return [
            'from' => $from,
            'to' => $to,
            'label' => __('dashboard.plastics.context.period_dates', [
                'from' => $this->dates->formatDate($from),
                'to' => $this->dates->formatDate($to),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contextPayload(array $currentContext, array $snapshot, array $period): array
    {
        $notSelected = __('dashboard.plastics.context.not_selected');

        return [
            'complete' => $snapshot['company_id'] !== null && $snapshot['branch_id'] !== null && $snapshot['financial_period_id'] !== null,
            'company' => $snapshot['company_name'] ?: ($currentContext['company']['label'] ?? $notSelected),
            'branch' => $snapshot['branch_name'] ?: ($currentContext['branch']['label'] ?? $notSelected),
            'financialPeriod' => $snapshot['financial_period_name'] ?: ($currentContext['financial_period']['label'] ?? $notSelected),
            'financialPeriodDates' => $period['label'],
        ];
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  list<array<string, mixed>>  $items
     */
    private function appendMetrics(array &$dashboard, string $category, array $items): void
    {
        foreach ($items as $item) {
            $item['category'] = $category;
            $dashboard['metrics'][] = $item;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function metric(string $title, int $value, string $meta, string $icon, string $color, ?string $url, ?string $key = null): array
    {
        return [
            'title' => $title,
            'value' => $this->formatCount($value),
            'meta' => $meta,
            'icon' => $icon,
            'color' => $color,
            'url' => $url,
            'key' => $key,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function alert(string $severity, string $title, string $body, ?string $url): array
    {
        return [
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ];
    }

    /**
     * @param  list<array{name: string, value: int}>  $data
     * @return array<string, mixed>
     */
    private function pieChart(string $id, string $title, array $data): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'options' => [
                'color' => $this->chartColors,
                'tooltip' => ['trigger' => 'item'],
                'legend' => [
                    'type' => 'scroll',
                    'bottom' => 0,
                    'left' => 'center',
                ],
                'series' => [[
                    'type' => 'pie',
                    'radius' => ['42%', '70%'],
                    'center' => ['50%', '44%'],
                    'avoidLabelOverlap' => true,
                    'itemStyle' => [
                        'borderRadius' => 4,
                        'borderColor' => '#fff',
                        'borderWidth' => 2,
                    ],
                    'label' => ['formatter' => '{b}: {c}'],
                    'data' => $data,
                ]],
            ],
        ];
    }

    private function classificationLabel(string $classification): string
    {
        $key = "products.classifications.{$classification}";

        return trans()->has($key) ? __($key) : $classification;
    }

    private function appendQuickAction(array &$dashboard, User $user, string $permission, string $route, string $label, string $icon): void
    {
        $url = $this->can($user, $permission) ? $this->routeUrl($route) : null;

        if ($url === null) {
            return;
        }

        $dashboard['quickActions'][] = [
            'label' => $label,
            'icon' => $icon,
            'url' => $url,
        ];
    }

    /** @param array<string, mixed> $parameters */
    private function routeUrl(string $route, array $parameters = []): ?string
    {
        return Route::has($route) ? route($route, $parameters) : null;
    }

    private function can(User $user, string $permission): bool
    {
        return (bool) $user->can($permission);
    }

    /** @param list<string> $permissions */
    private function canAny(User $user, array $permissions): bool
    {
        return (bool) $user->canAny($permissions);
    }

    private function tableExists(string $table): bool
    {
        return isset(self::DASHBOARD_SCHEMA[$table]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return isset(self::DASHBOARD_SCHEMA[$table][$column]);
    }

    private function activeProductCount(User $user, string $context, int $companyId): int
    {
        return $this->productQuery($user, $context, $companyId)
            ->forProductContext($context)
            ->where('status', 'active')
            ->count();
    }

    /** @return array{0: int, 1: int, 2: list<array{name: string, value: int}>} */
    private function productSummary(User $user, int $companyId): array
    {
        $visibleProducts = $this->productQuery($user, Product::ContextProducts, $companyId)
            ->productItems()
            ->where('status', 'active')
            ->select(['products.id', 'products.item_classification'])
            ->withExists('components');
        $summary = DB::query()
            ->fromSub($visibleProducts, 'visible_products')
            ->select('item_classification')
            ->selectRaw('COUNT(*) as aggregate')
            ->selectRaw('COALESCE(SUM(CASE WHEN components_exists THEN 1 ELSE 0 END), 0) as secondary_aggregate')
            ->groupBy('item_classification')
            ->orderByDesc('aggregate')
            ->get();
        $productTypes = $summary
            ->map(fn (object $row): array => [
                'name' => $this->classificationLabel((string) $row->item_classification),
                'value' => (int) $row->aggregate,
            ])
            ->filter(fn (array $row): bool => $row['value'] > 0)
            ->values()
            ->all();

        return [
            (int) $summary->sum(fn (object $row): int => (int) $row->aggregate),
            (int) $summary->sum(fn (object $row): int => (int) $row->secondary_aggregate),
            $productTypes,
        ];
    }

    /**
     * @param  list<string>  $values
     * @return array{0: int, 1: int}
     */
    private function countWithValues(QueryBuilder $query, string $column, array $values): array
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $counts = (clone $query)
            ->selectRaw('COUNT(*) as aggregate')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$column} IN ({$placeholders}) THEN 1 ELSE 0 END), 0) as secondary_aggregate", $values)
            ->first();

        return [
            (int) ($counts?->aggregate ?? 0),
            (int) ($counts?->secondary_aggregate ?? 0),
        ];
    }

    private function formatCount(int $value): string
    {
        return $this->numbers->format($value);
    }
}

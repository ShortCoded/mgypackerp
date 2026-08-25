<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Models\UserTask;

class PlasticsDashboardService
{
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

    /**
     * @var array<string, bool>
     */
    private array $tableCache = [];

    /** @var list<string>|null */
    private ?array $tableListing = null;

    /** @var array<string, list<string>> */
    private array $columnListingCache = [];

    public function __construct(
        private readonly OperatingContextService $operatingContext,
        private readonly DateFormatService $dates,
        private readonly NumericFormatService $numbers,
        private readonly ScreenDataVisibilityService $visibility,
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
        $this->appendTasks($dashboard, $user);

        if ($dashboard['metrics'] === []) {
            $dashboard['limitations'][] = __('dashboard.plastics.limitations.no_visible_metrics');
        }

        return $dashboard;
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

        if ($this->can($user, 'products.view')) {
            $products = $this->productQuery($user, 'products', $companyId)
                ->productItems()
                ->where('status', 'active')
                ->count();
            $withComponents = $this->productQuery($user, 'products', $companyId)
                ->productItems()
                ->where('status', 'active')
                ->whereHas('components')
                ->count();
            $withoutComponents = max(0, $products - $withComponents);
            $componentLines = $this->tableExists('product_components')
                ? ProductComponent::query()
                    ->forCompany($companyId)
                    ->whereIn('product_id', $this->productQuery($user, 'products', $companyId)->select('products.id'))
                    ->count()
                : 0;

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.products.title'),
                $products,
                __('dashboard.plastics.metrics.products.meta'),
                'box',
                'primary',
                $this->routeUrl('admin.products.index'),
            );
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.products_with_bom.title'),
                $withComponents,
                __('dashboard.plastics.metrics.products_with_bom.meta', ['without' => $this->formatCount($withoutComponents)]),
                'sitemap',
                $withoutComponents > 0 ? 'warning' : 'success',
                $this->routeUrl('admin.products.index'),
            );
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.bom_lines.title'),
                $componentLines,
                __('dashboard.plastics.metrics.bom_lines.meta'),
                'layer-group',
                'info',
                $this->routeUrl('admin.products.index'),
            );

            if ($withoutComponents > 0) {
                $dashboard['alerts'][] = $this->alert(
                    'warning',
                    __('dashboard.plastics.alerts.products_without_components.title'),
                    __('dashboard.plastics.alerts.products_without_components.body', ['count' => $this->formatCount($withoutComponents)]),
                    $this->routeUrl('admin.products.index'),
                );
            }

            $this->appendProductCharts($dashboard, $companyId, $user, $withComponents, $withoutComponents);
            $this->appendQuickAction($dashboard, $user, 'products.create', 'admin.products.create', __('dashboard.plastics.quick_actions.product'), 'plus');
            $this->appendQuickAction($dashboard, $user, 'reports.products_data.view', 'admin.reports.products-data.index', __('dashboard.plastics.quick_actions.products_report'), 'chart-bar');
        }

        if ($this->can($user, 'raw_materials.view')) {
            $rawMaterials = $this->productQuery($user, 'raw_materials', $companyId)
                ->rawMaterials()
                ->where('status', 'active')
                ->count();

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.raw_materials.title'),
                $rawMaterials,
                __('dashboard.plastics.metrics.raw_materials.meta'),
                'cubes',
                'warning',
                $this->routeUrl('admin.raw-materials.index'),
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

        if ($this->can($user, 'packaging_materials.view')) {
            $packagingMaterials = $this->productQuery($user, 'packaging_materials', $companyId)
                ->packagingMaterials()
                ->where('status', 'active')
                ->count();

            $items[] = $this->metric(
                __('dashboard.plastics.metrics.packaging_materials.title'),
                $packagingMaterials,
                __('dashboard.plastics.metrics.packaging_materials.meta'),
                'boxes',
                'info',
                $this->routeUrl('admin.packaging-materials.index'),
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
     */
    private function appendProductCharts(array &$dashboard, int $companyId, User $user, int $withComponents, int $withoutComponents): void
    {
        $typeRows = $this->productQuery($user, 'products', $companyId)
            ->productItems()
            ->where('status', 'active')
            ->select('item_classification', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('item_classification')
            ->orderByDesc('aggregate')
            ->get();

        $typeData = $typeRows
            ->map(fn (Product $row): array => [
                'name' => $this->classificationLabel((string) $row->item_classification),
                'value' => (int) $row->aggregate,
            ])
            ->filter(fn (array $row): bool => $row['value'] > 0)
            ->values()
            ->all();

        if ($this->can($user, 'raw_materials.view')) {
            $rawCount = $this->productQuery($user, 'raw_materials', $companyId)
                ->rawMaterials()
                ->where('status', 'active')
                ->count();

            if ($rawCount > 0) {
                $typeData[] = [
                    'name' => __('products.classifications.raw_material'),
                    'value' => $rawCount,
                ];
            }
        }

        if ($this->can($user, 'packaging_materials.view')) {
            $packagingCount = $this->productQuery($user, 'packaging_materials', $companyId)
                ->packagingMaterials()
                ->where('status', 'active')
                ->count();

            if ($packagingCount > 0) {
                $typeData[] = [
                    'name' => __('products.classifications.packaging'),
                    'value' => $packagingCount,
                ];
            }
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
    private function appendPurchasing(array &$dashboard, User $user, array $context, array $period): void
    {
        $items = [];

        if ($this->can($user, 'suppliers.view') && $this->tableExists('suppliers')) {
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.suppliers.title'),
                $this->activeCompanyCount('suppliers', $context, $user, 'suppliers'),
                __('dashboard.plastics.metrics.suppliers.meta'),
                'truck',
                'secondary',
                $this->routeUrl('admin.purchases.suppliers.index'),
            );
        }

        if ($this->can($user, 'purchase_invoices.view') && $this->tableExists('purchase_invoices')) {
            $query = $this->restrictedContextQuery('purchase_invoices', $context, $user, 'purchase_invoices')
                ->whereBetween('invoice_date', [$period['from']->toDateString(), $period['to']->toDateString()]);
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

        if ($this->can($user, 'purchase_orders.view') && $this->tableExists('purchase_orders')) {
            $query = $this->restrictedContextQuery('purchase_orders', $context, $user, 'purchase_orders')
                ->whereBetween('document_date', [$period['from']->toDateString(), $period['to']->toDateString()]);
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
    private function appendSales(array &$dashboard, User $user, array $context, array $period): void
    {
        $items = [];

        if ($this->can($user, 'customers.view') && $this->tableExists('customers')) {
            $items[] = $this->metric(
                __('dashboard.plastics.metrics.customers.title'),
                $this->activeCompanyCount('customers', $context, $user, 'customers'),
                __('dashboard.plastics.metrics.customers.meta'),
                'user-tie',
                'primary',
                $this->routeUrl('admin.sales.customers.index'),
            );
        }

        if ($this->can($user, 'quotations.view') && $this->tableExists('quotations')) {
            $query = $this->restrictedContextQuery('quotations', $context, $user, 'quotations', branch: false, financialPeriod: false)
                ->whereBetween('quotation_date', [$period['from']->toDateString(), $period['to']->toDateString()]);
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
        return $this->visibility->applyToQuery(
            $this->contextQuery($table, $context, $branch, $financialPeriod),
            $user,
            $screenKey,
        );
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
    private function metric(string $title, int $value, string $meta, string $icon, string $color, ?string $url): array
    {
        return [
            'title' => $title,
            'value' => $this->formatCount($value),
            'meta' => $meta,
            'icon' => $icon,
            'color' => $color,
            'url' => $url,
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

    private function routeUrl(string $route): ?string
    {
        return Route::has($route) ? route($route) : null;
    }

    private function can(User $user, string $permission): bool
    {
        return (bool) $user->can($permission);
    }

    private function tableExists(string $table): bool
    {
        $this->tableListing ??= array_map(
            fn (string $listedTable): string => Str::afterLast($listedTable, '.'),
            Schema::getTableListing(),
        );

        return $this->tableCache[$table] ??= in_array($table, $this->tableListing, true);
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (! $this->tableExists($table)) {
            return false;
        }

        $columns = $this->columnListingCache[$table] ??= Schema::getColumnListing($table);

        return in_array($column, $columns, true);
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

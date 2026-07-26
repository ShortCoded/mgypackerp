<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Models\UserTask;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\OpeningBalance;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Production\Models\ProductionIdentifier;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\Quotation;

class ExpandedDashboardService
{
    /**
     * @var array<int, string>
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
        private readonly NumericFormatService $numericFormatter,
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
        $period = $this->financialPeriod($snapshot);
        $range = $this->resolveRange($request, $period);

        $dashboard = [
            'context' => $this->contextPayload($currentContext, $snapshot, $period),
            'range' => $range,
            'lastUpdatedAt' => $this->dates->formatDateTime(now()),
            'kpis' => [],
            'charts' => [],
            'alerts' => [],
            'recentSections' => [],
            'quickActions' => [],
            'sources' => [],
            'limitations' => [],
        ];

        if (! $dashboard['context']['complete']) {
            $dashboard['limitations'][] = __('dashboard.expanded.limitations.context_required');

            return $dashboard;
        }

        $this->appendPurchasing($dashboard, $user, $snapshot, $range);
        $this->appendInventory($dashboard, $user, $snapshot, $range);
        $this->appendProductionReadiness($dashboard, $user, $snapshot);
        $this->appendSales($dashboard, $user, $snapshot, $range);
        $this->appendFinance($dashboard, $user, $snapshot, $range);
        $this->appendFixedAssets($dashboard, $user, $snapshot, $range);
        $this->appendTasks($dashboard, $user);

        if ($dashboard['kpis'] === []) {
            $dashboard['limitations'][] = __('dashboard.expanded.limitations.no_visible_widgets');
        }

        return $dashboard;
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $range
     */
    private function appendPurchasing(array &$dashboard, User $user, array $context, array $range): void
    {
        if ($this->can($user, 'purchase_orders.view')) {
            $query = PurchaseOrder::query()
                ->where('purchase_orders.company_id', $context['company_id'])
                ->where('purchase_orders.financial_period_id', $context['financial_period_id'])
                ->where('purchase_orders.branch_id', $context['branch_id'])
                ->whereBetween('purchase_orders.document_date', [$range['from']->toDateString(), $range['to']->toDateString()]);

            $count = (clone $query)->count();
            $statusCounts = $this->statusCounts((clone $query), PurchaseOrder::statuses());
            $awaitingApproval = (int) ($statusCounts[PurchaseOrder::StatusDraft] ?? 0);
            $overdue = (clone $query)
                ->where('status', PurchaseOrder::StatusApproved)
                ->whereDate('expected_delivery_date', '<', now()->toDateString())
                ->where('total_remaining_quantity', '>', 0)
                ->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.purchase_orders.title'),
                $count,
                __('dashboard.expanded.kpis.purchase_orders.meta', ['count' => $this->formatCount($awaitingApproval)]),
                'clipboard-list',
                'primary',
                $this->routeUrl('admin.purchases.purchase-orders.index'),
            );

            if ($count > 0) {
                $dashboard['charts'][] = $this->statusChart(
                    'purchase-order-status-chart',
                    __('dashboard.expanded.charts.purchase_order_status'),
                    $statusCounts,
                    'purchase_orders.statuses',
                );
            }

            if ($awaitingApproval > 0) {
                $dashboard['alerts'][] = $this->alert(
                    'warning',
                    __('dashboard.expanded.alerts.purchase_orders_awaiting_approval.title'),
                    __('dashboard.expanded.alerts.purchase_orders_awaiting_approval.body', ['count' => $this->formatCount($awaitingApproval)]),
                    $this->routeUrl('admin.purchases.purchase-orders.index'),
                );
            }

            if ($overdue > 0) {
                $dashboard['alerts'][] = $this->alert(
                    'danger',
                    __('dashboard.expanded.alerts.purchase_orders_overdue.title'),
                    __('dashboard.expanded.alerts.purchase_orders_overdue.body', ['count' => $this->formatCount($overdue)]),
                    $this->routeUrl('admin.purchases.purchase-orders.index'),
                );
            }

            $this->appendCurrencyTotals($dashboard, __('dashboard.expanded.recent.purchase_order_totals'), $query, 'purchase_orders', 'total_amount');
            $this->appendRecentPurchaseOrders($dashboard, $query);
            $this->appendQuickAction($dashboard, $user, 'purchase_orders.create', 'admin.purchases.purchase-orders.create', __('dashboard.expanded.quick_actions.purchase_order'), 'plus');
            $dashboard['sources'][] = __('dashboard.expanded.sources.purchase_orders');
        }

        if ($this->can($user, 'purchase_invoices.view')) {
            $query = PurchaseInvoice::query()
                ->where('purchase_invoices.company_id', $context['company_id'])
                ->where('purchase_invoices.financial_period_id', $context['financial_period_id'])
                ->where('purchase_invoices.branch_id', $context['branch_id'])
                ->whereBetween('purchase_invoices.invoice_date', [$range['from']->toDateString(), $range['to']->toDateString()]);

            $count = (clone $query)->count();
            $statusCounts = $this->statusCounts((clone $query), PurchaseInvoice::statuses());
            $paymentCounts = $this->statusCounts((clone $query), PurchaseInvoice::paymentStatuses(), 'payment_status');
            $unpaid = (int) (($paymentCounts[PurchaseInvoice::PaymentStatusUnpaid] ?? 0) + ($paymentCounts[PurchaseInvoice::PaymentStatusPartiallyPaid] ?? 0));

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.purchase_invoices.title'),
                $count,
                __('dashboard.expanded.kpis.purchase_invoices.meta', ['count' => $this->formatCount($unpaid)]),
                'file-invoice-dollar',
                'info',
                $this->routeUrl('admin.purchases.purchase-invoices.index'),
            );

            if ($count > 0) {
                $dashboard['charts'][] = $this->statusChart(
                    'purchase-invoice-status-chart',
                    __('dashboard.expanded.charts.purchase_invoice_status'),
                    $statusCounts,
                    'purchase_invoices.statuses',
                );
            }

            $this->appendCurrencyTotals($dashboard, __('dashboard.expanded.recent.purchase_invoice_totals'), $query, 'purchase_invoices', 'total_amount');
            $this->appendRecentPurchaseInvoices($dashboard, $query);
            $this->appendQuickAction($dashboard, $user, 'purchase_invoices.create', 'admin.purchases.purchase-invoices.create', __('dashboard.expanded.quick_actions.purchase_invoice'), 'plus');
            $dashboard['sources'][] = __('dashboard.expanded.sources.purchase_invoices');
        }

        if ($this->can($user, 'suppliers.view')) {
            $activeSuppliers = Supplier::query()
                ->where('company_id', $context['company_id'])
                ->where('status', 'active')
                ->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.suppliers.title'),
                $activeSuppliers,
                __('dashboard.expanded.kpis.suppliers.meta'),
                'truck',
                'secondary',
                $this->routeUrl('admin.purchases.suppliers.index'),
            );

            $this->appendQuickAction($dashboard, $user, 'suppliers.create', 'admin.purchases.suppliers.create', __('dashboard.expanded.quick_actions.supplier'), 'plus');
            $dashboard['sources'][] = __('dashboard.expanded.sources.suppliers');
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $range
     */
    private function appendInventory(array &$dashboard, User $user, array $context, array $range): void
    {
        if ($this->can($user, 'inventory.unpriced_inventory_receipts.view')) {
            $query = UnpricedInventoryReceipt::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereBetween('document_date', [$range['from']->toDateString(), $range['to']->toDateString()]);

            $awaitingPricing = (clone $query)
                ->where('pricing_status', UnpricedInventoryReceipt::PricingStatusUnpriced)
                ->where('status', '!=', UnpricedInventoryReceipt::StatusCancelled)
                ->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.unpriced_receipts.title'),
                $awaitingPricing,
                __('dashboard.expanded.kpis.unpriced_receipts.meta'),
                'dolly',
                'warning',
                $this->routeUrl('admin.inventory.unpriced-inventory-receipts.index'),
            );

            if ($awaitingPricing > 0) {
                $dashboard['alerts'][] = $this->alert(
                    'warning',
                    __('dashboard.expanded.alerts.unpriced_receipts.title'),
                    __('dashboard.expanded.alerts.unpriced_receipts.body', ['count' => $this->formatCount($awaitingPricing)]),
                    $this->routeUrl('admin.inventory.unpriced-inventory-receipts.index'),
                );
            }

            $this->appendRecentUnpricedReceipts($dashboard, $query);
            $this->appendQuickAction($dashboard, $user, 'inventory.unpriced_inventory_receipts.create', 'admin.inventory.unpriced-inventory-receipts.create', __('dashboard.expanded.quick_actions.unpriced_receipt'), 'plus');
            $dashboard['sources'][] = __('dashboard.expanded.sources.unpriced_inventory_receipts');
        }

        if ($this->can($user, 'inventory.opening_stocks.view')) {
            $query = OpeningStock::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereBetween('document_date', [$range['from']->toDateString(), $range['to']->toDateString()]);

            $statusCounts = $this->statusCounts((clone $query), [
                OpeningStock::StatusDraft,
                OpeningStock::StatusClosed,
                OpeningStock::StatusApproved,
            ]);

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.opening_stock.title'),
                array_sum($statusCounts),
                __('dashboard.expanded.kpis.opening_stock.meta', ['count' => $this->formatCount((int) ($statusCounts[OpeningStock::StatusApproved] ?? 0))]),
                'boxes',
                'success',
                $this->routeUrl('admin.inventory.opening-stocks.index'),
            );

            $dashboard['sources'][] = __('dashboard.expanded.sources.opening_stock');
        }

        if ($this->can($user, 'branches.view')) {
            $stores = BranchStore::query()
                ->where('branch_id', $context['branch_id'])
                ->count();
            $halls = BranchHall::query()
                ->where('branch_id', $context['branch_id'])
                ->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.branch_storage.title'),
                $stores,
                __('dashboard.expanded.kpis.branch_storage.meta', ['count' => $this->formatCount($halls)]),
                'warehouse',
                'info',
                $this->routeUrl('admin.branches.index'),
            );

            $dashboard['sources'][] = __('dashboard.expanded.sources.branch_storage');
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $context
     */
    private function appendProductionReadiness(array &$dashboard, User $user, array $context): void
    {
        if ($this->can($user, 'products.view')) {
            $withoutComponents = Product::query()
                ->where('company_id', $context['company_id'])
                ->where('status', 'active')
                ->whereIn('item_classification', [
                    Product::ClassificationFinishedProduct,
                    Product::ClassificationSemiFinished,
                ])
                ->whereDoesntHave('components')
                ->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.bom_readiness.title'),
                $withoutComponents,
                __('dashboard.expanded.kpis.bom_readiness.meta'),
                'sitemap',
                'warning',
                $this->routeUrl('admin.products.index'),
            );

            if ($withoutComponents > 0) {
                $dashboard['alerts'][] = $this->alert(
                    'warning',
                    __('dashboard.expanded.alerts.products_without_components.title'),
                    __('dashboard.expanded.alerts.products_without_components.body', ['count' => $this->formatCount($withoutComponents)]),
                    $this->routeUrl('admin.products.index'),
                );
            }

            $dashboard['sources'][] = __('dashboard.expanded.sources.product_components');
        }

        if ($this->can($user, 'raw_materials.view')) {
            $rawMissingUnit = Product::query()
                ->where('company_id', $context['company_id'])
                ->where('status', 'active')
                ->where('item_classification', Product::ClassificationRawMaterial)
                ->whereNull('item_unit_id')
                ->count();

            $rawMissingMetadata = Product::query()
                ->where('company_id', $context['company_id'])
                ->where('status', 'active')
                ->where('item_classification', Product::ClassificationRawMaterial)
                ->where(function (Builder $query): void {
                    $query->whereNull('item_category_id')
                        ->orWhereNull('item_origin_country_id');
                })
                ->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.raw_material_readiness.title'),
                $rawMissingUnit,
                __('dashboard.expanded.kpis.raw_material_readiness.meta', ['count' => $this->formatCount($rawMissingMetadata)]),
                'cubes',
                'warning',
                $this->routeUrl('admin.raw-materials.index'),
            );

            if ($rawMissingUnit > 0 || $rawMissingMetadata > 0) {
                $dashboard['alerts'][] = $this->alert(
                    'warning',
                    __('dashboard.expanded.alerts.raw_materials_missing_metadata.title'),
                    __('dashboard.expanded.alerts.raw_materials_missing_metadata.body', [
                        'units' => $this->formatCount($rawMissingUnit),
                        'metadata' => $this->formatCount($rawMissingMetadata),
                    ]),
                    $this->routeUrl('admin.raw-materials.index'),
                );
            }

            $dashboard['sources'][] = __('dashboard.expanded.sources.raw_materials');
        }

        if ($this->can($user, 'production.identifiers.view')) {
            $activeIdentifiers = ProductionIdentifier::query()
                ->where('company_id', $context['company_id'])
                ->where('status', 'active')
                ->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.production_identifiers.title'),
                $activeIdentifiers,
                __('dashboard.expanded.kpis.production_identifiers.meta'),
                'tags',
                'secondary',
                $this->routeUrl('admin.production.identifiers.index'),
            );

            $dashboard['sources'][] = __('dashboard.expanded.sources.production_identifiers');
        }

        if ($this->can($user, 'branches.view')) {
            $factoryBranchesWithoutStorage = Branch::query()
                ->where('company_id', $context['company_id'])
                ->where('type', Branch::TypeFactory)
                ->where('status', 'active')
                ->where(function (Builder $query): void {
                    $query->whereDoesntHave('halls')
                        ->orWhereDoesntHave('stores');
                })
                ->count();

            if ($factoryBranchesWithoutStorage > 0) {
                $dashboard['alerts'][] = $this->alert(
                    'warning',
                    __('dashboard.expanded.alerts.factory_branches_without_storage.title'),
                    __('dashboard.expanded.alerts.factory_branches_without_storage.body', ['count' => $this->formatCount($factoryBranchesWithoutStorage)]),
                    $this->routeUrl('admin.branches.index'),
                );
            }

            $dashboard['sources'][] = __('dashboard.expanded.sources.factory_branches');
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $range
     */
    private function appendSales(array &$dashboard, User $user, array $context, array $range): void
    {
        if ($this->can($user, 'customers.view')) {
            $activeCustomers = Customer::query()
                ->where('company_id', $context['company_id'])
                ->where('status', 'active')
                ->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.customers.title'),
                $activeCustomers,
                __('dashboard.expanded.kpis.customers.meta'),
                'user-tie',
                'primary',
                $this->routeUrl('admin.sales.customers.index'),
            );

            $this->appendQuickAction($dashboard, $user, 'customers.create', 'admin.sales.customers.create', __('dashboard.expanded.quick_actions.customer'), 'plus');
            $dashboard['sources'][] = __('dashboard.expanded.sources.customers');
        }

        if ($this->can($user, 'quotations.view')) {
            $query = Quotation::query()
                ->where('company_id', $context['company_id'])
                ->whereBetween('quotation_date', [$range['from']->toDateString(), $range['to']->toDateString()]);

            $count = (clone $query)->count();
            $statusCounts = $this->statusCounts((clone $query), Quotation::Statuses);

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.quotations.title'),
                $count,
                __('dashboard.expanded.kpis.quotations.meta', ['count' => $this->formatCount((int) ($statusCounts[Quotation::StatusAccepted] ?? 0))]),
                'file-signature',
                'success',
                $this->routeUrl('admin.sales.quotations.index'),
            );

            if ($count > 0) {
                $dashboard['charts'][] = $this->statusChart(
                    'quotation-status-chart',
                    __('dashboard.expanded.charts.quotation_status'),
                    $statusCounts,
                    'quotations.statuses',
                );
            }

            $this->appendRecentQuotations($dashboard, $query);
            $this->appendQuickAction($dashboard, $user, 'quotations.create', 'admin.sales.quotations.create', __('dashboard.expanded.quick_actions.quotation'), 'plus');
            $dashboard['sources'][] = __('dashboard.expanded.sources.quotations');
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $range
     */
    private function appendFinance(array &$dashboard, User $user, array $context, array $range): void
    {
        if ($this->can($user, 'bank_accounts.view')) {
            $activeBankAccounts = BankAccount::query()
                ->where('company_id', $context['company_id'])
                ->where('status', 'active')
                ->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.bank_accounts.title'),
                $activeBankAccounts,
                __('dashboard.expanded.kpis.bank_accounts.meta'),
                'university',
                'info',
                $this->routeUrl('admin.finance.bank-accounts.index'),
            );

            $this->appendQuickAction($dashboard, $user, 'bank_accounts.create', 'admin.finance.bank-accounts.create', __('dashboard.expanded.quick_actions.bank_account'), 'plus');
            $dashboard['sources'][] = __('dashboard.expanded.sources.bank_accounts');
        }

        if ($this->can($user, 'cashboxes.view')) {
            $activeCashboxes = Cashbox::query()
                ->where('company_id', $context['company_id'])
                ->where('branch_id', $context['branch_id'])
                ->where('status', 'active')
                ->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.cashboxes.title'),
                $activeCashboxes,
                __('dashboard.expanded.kpis.cashboxes.meta'),
                'cash-register',
                'success',
                $this->routeUrl('admin.finance.cashboxes.index'),
            );

            $this->appendQuickAction($dashboard, $user, 'cashboxes.create', 'admin.finance.cashboxes.create', __('dashboard.expanded.quick_actions.cashbox'), 'plus');
            $dashboard['sources'][] = __('dashboard.expanded.sources.cashboxes');
        }

        if ($this->can($user, 'opening_balances.view')) {
            $query = OpeningBalance::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->whereBetween('document_date', [$range['from']->toDateString(), $range['to']->toDateString()]);

            $statusCounts = $this->statusCounts((clone $query), [
                OpeningBalance::StatusDraft,
                OpeningBalance::StatusApproved,
                OpeningBalance::StatusCancelled,
                OpeningBalance::StatusReversed,
            ]);
            $draft = (int) ($statusCounts[OpeningBalance::StatusDraft] ?? 0);

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.opening_balances.title'),
                array_sum($statusCounts),
                __('dashboard.expanded.kpis.opening_balances.meta', ['count' => $this->formatCount($draft)]),
                'balance-scale',
                'secondary',
                $this->routeUrl('admin.finance.opening-balances.index'),
            );

            if ($draft > 0) {
                $dashboard['alerts'][] = $this->alert(
                    'info',
                    __('dashboard.expanded.alerts.opening_balances_draft.title'),
                    __('dashboard.expanded.alerts.opening_balances_draft.body', ['count' => $this->formatCount($draft)]),
                    $this->routeUrl('admin.finance.opening-balances.index'),
                );
            }

            $this->appendQuickAction($dashboard, $user, 'opening_balances.create', 'admin.finance.opening-balances.create', __('dashboard.expanded.quick_actions.opening_balance'), 'plus');
            $dashboard['sources'][] = __('dashboard.expanded.sources.opening_balances');
        }
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $range
     */
    private function appendFixedAssets(array &$dashboard, User $user, array $context, array $range): void
    {
        if (! $this->can($user, 'fixed_assets.view')) {
            return;
        }

        $query = FixedAsset::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('period_id', $context['financial_period_id'])
            ->whereBetween('asset_date', [$range['from']->toDateString(), $range['to']->toDateString()]);

        $activeAssets = (clone $query)->where('status', 'active')->count();
        $missingDepreciation = (clone $query)
            ->where('status', 'active')
            ->where('is_depreciable', true)
            ->where(function (Builder $query): void {
                $query->whereNull('depreciation_method')
                    ->orWhereNull('depreciation_start_date')
                    ->orWhereNull('useful_life')
                    ->orWhereNull('annual_depreciation_rate');
            })
            ->count();

        $dashboard['kpis'][] = $this->kpi(
            __('dashboard.expanded.kpis.fixed_assets.title'),
            $activeAssets,
            __('dashboard.expanded.kpis.fixed_assets.meta', ['count' => $this->formatCount($missingDepreciation)]),
            'building',
            'primary',
            $this->routeUrl('admin.fixed-assets.assets.index'),
        );

        if ($missingDepreciation > 0) {
            $dashboard['alerts'][] = $this->alert(
                'warning',
                __('dashboard.expanded.alerts.assets_missing_depreciation.title'),
                __('dashboard.expanded.alerts.assets_missing_depreciation.body', ['count' => $this->formatCount($missingDepreciation)]),
                $this->routeUrl('admin.fixed-assets.assets.index'),
            );
        }

        $this->appendRecentFixedAssets($dashboard, $query);
        $this->appendQuickAction($dashboard, $user, 'fixed_assets.create', 'admin.fixed-assets.assets.create', __('dashboard.expanded.quick_actions.fixed_asset'), 'plus');
        $dashboard['sources'][] = __('dashboard.expanded.sources.fixed_assets');
    }

    /**
     * @param  array<string, mixed>  $dashboard
     */
    private function appendTasks(array &$dashboard, User $user): void
    {
        if ($this->can($user, 'my_board.view')) {
            $myTasks = UserTask::query()
                ->where('type', UserTask::TypeTask)
                ->where('is_active', true)
                ->where('status', '!=', UserTask::StatusDone)
                ->where(function (Builder $query) use ($user): void {
                    $query->where('assigned_to', $user->getKey())
                        ->orWhereHas('assignees', fn (Builder $query): Builder => $query->where('users.id', $user->getKey()));
                });

            $open = (clone $myTasks)->count();
            $overdue = (clone $myTasks)->whereNotNull('due_at')->where('due_at', '<', now())->count();
            $dueSoon = (clone $myTasks)->whereBetween('due_at', [now(), now()->addDays(7)])->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.my_tasks.title'),
                $open,
                __('dashboard.expanded.kpis.my_tasks.meta', [
                    'overdue' => $this->formatCount($overdue),
                    'due_soon' => $this->formatCount($dueSoon),
                ]),
                'tasks',
                $overdue > 0 ? 'danger' : 'info',
                $this->routeUrl('admin.my-board.index'),
            );

            if ($overdue > 0) {
                $dashboard['alerts'][] = $this->alert(
                    'danger',
                    __('dashboard.expanded.alerts.my_tasks_overdue.title'),
                    __('dashboard.expanded.alerts.my_tasks_overdue.body', ['count' => $this->formatCount($overdue)]),
                    $this->routeUrl('admin.my-board.index'),
                );
            }

            $this->appendRecentTasks($dashboard, $myTasks, __('dashboard.expanded.recent.my_tasks'));
            $this->appendQuickAction($dashboard, $user, 'my_board.create', 'admin.my-board.tasks.create', __('dashboard.expanded.quick_actions.task'), 'plus');
            $dashboard['sources'][] = __('dashboard.expanded.sources.my_tasks');
        }

        if ($this->can($user, 'my_board.tasks.view_all')) {
            $teamOpen = UserTask::query()
                ->where('type', UserTask::TypeTask)
                ->where('is_active', true)
                ->where('status', '!=', UserTask::StatusDone)
                ->count();

            $dashboard['kpis'][] = $this->kpi(
                __('dashboard.expanded.kpis.team_tasks.title'),
                $teamOpen,
                __('dashboard.expanded.kpis.team_tasks.meta'),
                'users',
                'secondary',
                $this->routeUrl('admin.tools.team-board.index'),
            );

            $dashboard['sources'][] = __('dashboard.expanded.sources.team_tasks');
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function financialPeriod(array $snapshot): ?FinancialPeriod
    {
        $periodId = $snapshot['financial_period_id'] ?? null;

        return is_numeric($periodId)
            ? FinancialPeriod::query()->whereKey((int) $periodId)->first()
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRange(Request $request, ?FinancialPeriod $period): array
    {
        $range = $request->string('range')->trim()->toString();
        $range = in_array($range, ['today', 'last_7', 'last_30', 'current_period', 'custom'], true)
            ? $range
            : 'current_period';

        [$periodStart, $periodEnd] = $this->periodBounds($period);
        $today = $this->anchorToday($periodStart, $periodEnd);

        if ($range === 'custom') {
            $from = $this->dates->parseDate($request->string('date_from')->toString());
            $to = $this->dates->parseDate($request->string('date_to')->toString());

            if (! $from instanceof Carbon || ! $to instanceof Carbon) {
                throw ValidationException::withMessages([
                    'date_from' => __('dashboard.expanded.validation.date_range_invalid'),
                ]);
            }

            if ($from->gt($to)) {
                throw ValidationException::withMessages([
                    'date_to' => __('dashboard.expanded.validation.date_to_before_from'),
                ]);
            }

            $from = $this->clamp($from, $periodStart, $periodEnd);
            $to = $this->clamp($to, $periodStart, $periodEnd);
        } elseif ($range === 'today') {
            $from = $today->copy();
            $to = $today->copy();
        } elseif ($range === 'last_7') {
            $to = $today->copy();
            $from = $this->clamp($today->copy()->subDays(6), $periodStart, $periodEnd);
        } elseif ($range === 'last_30') {
            $to = $today->copy();
            $from = $this->clamp($today->copy()->subDays(29), $periodStart, $periodEnd);
        } else {
            $from = $periodStart?->copy() ?? $today->copy()->subDays(29);
            $to = $periodEnd?->copy() ?? $today->copy();
        }

        if ($from->gt($to)) {
            $from = $to->copy();
        }

        return [
            'key' => $range,
            'from' => $from->copy()->startOfDay(),
            'to' => $to->copy()->startOfDay(),
            'fromValue' => $from->toDateString(),
            'toValue' => $to->toDateString(),
            'fromLabel' => $this->dates->formatDate($from),
            'toLabel' => $this->dates->formatDate($to),
            'label' => __('dashboard.expanded.range_label', [
                'from' => $this->dates->formatDate($from),
                'to' => $this->dates->formatDate($to),
            ]),
            'options' => [
                'today' => __('dashboard.expanded.ranges.today'),
                'last_7' => __('dashboard.expanded.ranges.last_7'),
                'last_30' => __('dashboard.expanded.ranges.last_30'),
                'current_period' => __('dashboard.expanded.ranges.current_period'),
                'custom' => __('dashboard.expanded.ranges.custom'),
            ],
        ];
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function periodBounds(?FinancialPeriod $period): array
    {
        return [
            $period?->from_date instanceof Carbon ? $period->from_date->copy()->startOfDay() : null,
            $period?->to_date instanceof Carbon ? $period->to_date->copy()->startOfDay() : null,
        ];
    }

    private function anchorToday(?Carbon $periodStart, ?Carbon $periodEnd): Carbon
    {
        $today = now()->startOfDay();

        if ($periodStart instanceof Carbon && $today->lt($periodStart)) {
            return $periodStart->copy();
        }

        if ($periodEnd instanceof Carbon && $today->gt($periodEnd)) {
            return $periodEnd->copy();
        }

        return $today;
    }

    private function clamp(Carbon $date, ?Carbon $min, ?Carbon $max): Carbon
    {
        if ($min instanceof Carbon && $date->lt($min)) {
            return $min->copy();
        }

        if ($max instanceof Carbon && $date->gt($max)) {
            return $max->copy();
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $currentContext
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function contextPayload(array $currentContext, array $snapshot, ?FinancialPeriod $period): array
    {
        $missing = __('dashboard.expanded.context.not_selected');

        return [
            'complete' => $snapshot['company_id'] !== null && $snapshot['branch_id'] !== null && $snapshot['financial_period_id'] !== null,
            'requiresSelection' => (bool) ($currentContext['requires_selection'] ?? false),
            'company' => $snapshot['company_name'] ?: $missing,
            'branch' => $snapshot['branch_name'] ?: $missing,
            'financialPeriod' => $snapshot['financial_period_name'] ?: $missing,
            'financialPeriodDates' => $period instanceof FinancialPeriod
                ? __('dashboard.expanded.context.period_dates', [
                    'from' => $this->dates->formatDate($period->from_date),
                    'to' => $this->dates->formatDate($period->to_date),
                ])
                : $missing,
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    private function statusCounts(Builder $query, array $statuses, string $column = 'status'): array
    {
        $counts = array_fill_keys($statuses, 0);

        $query
            ->select($column, DB::raw('COUNT(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->each(function (mixed $count, mixed $status) use (&$counts): void {
                if (is_string($status)) {
                    $counts[$status] = (int) $count;
                }
            });

        return $counts;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $dashboard
     */
    private function appendCurrencyTotals(array &$dashboard, string $title, Builder $query, string $table, string $amountColumn): void
    {
        $totals = (clone $query)
            ->leftJoin('currencies', "{$table}.currency_id", '=', 'currencies.id')
            ->select("{$table}.currency_id", 'currencies.code', 'currencies.name', DB::raw("SUM({$table}.{$amountColumn}) as total_amount"))
            ->groupBy("{$table}.currency_id", 'currencies.code', 'currencies.name')
            ->orderBy('currencies.code')
            ->get()
            ->map(fn (object $row): array => [
                'label' => $this->currencyLabel($row->code ?? null, $row->name ?? null),
                'value' => $this->formatAmount($row->total_amount ?? 0),
            ])
            ->values()
            ->all();

        if ($totals === []) {
            return;
        }

        $dashboard['recentSections'][] = [
            'title' => $title,
            'items' => collect($totals)
                ->map(fn (array $total): array => [
                    'title' => $total['label'],
                    'meta' => $total['value'],
                    'badge' => null,
                    'url' => null,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  Builder<Model>  $query
     */
    private function appendRecentPurchaseOrders(array &$dashboard, Builder $query): void
    {
        $items = (clone $query)
            ->with(['supplier:id,name', 'currency:id,code,name'])
            ->latest('document_date')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (PurchaseOrder $record): array => [
                'title' => $record->doc_num,
                'meta' => trim(implode(' / ', array_filter([
                    $this->dates->formatDate($record->document_date),
                    $record->supplier?->name,
                    $this->formatAmount($record->total_amount).' '.$this->currencyLabel($record->currency?->code, $record->currency?->name),
                ]))),
                'badge' => __("purchase_orders.statuses.{$record->status}"),
                'url' => $this->routeUrl('admin.purchases.purchase-orders.show', $record),
            ])
            ->all();

        $this->appendRecentSection($dashboard, __('dashboard.expanded.recent.purchase_orders'), $items);
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  Builder<Model>  $query
     */
    private function appendRecentPurchaseInvoices(array &$dashboard, Builder $query): void
    {
        $items = (clone $query)
            ->with(['supplier:id,name', 'currency:id,code,name'])
            ->latest('invoice_date')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (PurchaseInvoice $record): array => [
                'title' => $record->doc_num,
                'meta' => trim(implode(' / ', array_filter([
                    $this->dates->formatDate($record->invoice_date),
                    $record->supplier?->name,
                    $this->formatAmount($record->total_amount).' '.$this->currencyLabel($record->currency?->code, $record->currency?->name),
                ]))),
                'badge' => __("purchase_invoices.statuses.{$record->status}"),
                'url' => $this->routeUrl('admin.purchases.purchase-invoices.show', $record),
            ])
            ->all();

        $this->appendRecentSection($dashboard, __('dashboard.expanded.recent.purchase_invoices'), $items);
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  Builder<Model>  $query
     */
    private function appendRecentUnpricedReceipts(array &$dashboard, Builder $query): void
    {
        $items = (clone $query)
            ->with(['supplier:id,name'])
            ->latest('document_date')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (UnpricedInventoryReceipt $record): array => [
                'title' => $record->doc_num,
                'meta' => trim(implode(' / ', array_filter([
                    $this->dates->formatDate($record->document_date),
                    $record->supplier?->name,
                ]))),
                'badge' => __("inventory.unpriced_inventory_receipts.pricing_statuses.{$record->pricing_status}"),
                'url' => $this->routeUrl('admin.inventory.unpriced-inventory-receipts.show', $record),
            ])
            ->all();

        $this->appendRecentSection($dashboard, __('dashboard.expanded.recent.unpriced_receipts'), $items);
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  Builder<Model>  $query
     */
    private function appendRecentQuotations(array &$dashboard, Builder $query): void
    {
        $items = (clone $query)
            ->with(['customer:id,name'])
            ->latest('quotation_date')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (Quotation $record): array => [
                'title' => $record->doc_num,
                'meta' => trim(implode(' / ', array_filter([
                    $this->dates->formatDate($record->quotation_date),
                    $record->customer?->name,
                    $record->subject ?: $record->project_name,
                ]))),
                'badge' => __("quotations.statuses.{$record->status}"),
                'url' => $this->routeUrl('admin.sales.quotations.show', $record),
            ])
            ->all();

        $this->appendRecentSection($dashboard, __('dashboard.expanded.recent.quotations'), $items);
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  Builder<Model>  $query
     */
    private function appendRecentFixedAssets(array &$dashboard, Builder $query): void
    {
        $items = (clone $query)
            ->latest('asset_date')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (FixedAsset $record): array => [
                'title' => $record->doc_num,
                'meta' => trim(implode(' / ', array_filter([
                    $this->dates->formatDate($record->asset_date),
                    $record->asset_name,
                ]))),
                'badge' => __("fixed_assets.statuses.{$record->status}"),
                'url' => $this->routeUrl('admin.fixed-assets.assets.show', $record),
            ])
            ->all();

        $this->appendRecentSection($dashboard, __('dashboard.expanded.recent.fixed_assets'), $items);
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  Builder<Model>  $query
     */
    private function appendRecentTasks(array &$dashboard, Builder $query, string $title): void
    {
        $items = (clone $query)
            ->latest('due_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn ($record): array => [
                'title' => (string) $record->title,
                'meta' => $record->due_at ? $this->dates->formatDateTime($record->due_at) : __('dashboard.expanded.no_due_date'),
                'badge' => __("user_tasks.priorities.{$record->priority}"),
                'url' => $this->routeUrl('admin.my-board.tasks.show', $record),
            ])
            ->all();

        $this->appendRecentSection($dashboard, $title, $items);
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @param  list<array<string, mixed>>  $items
     */
    private function appendRecentSection(array &$dashboard, string $title, array $items): void
    {
        if ($items === []) {
            return;
        }

        $dashboard['recentSections'][] = [
            'title' => $title,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusChart(string $id, string $title, array $counts, string $translationPrefix): array
    {
        $data = [];
        $index = 0;

        foreach ($counts as $status => $count) {
            if ((int) $count <= 0) {
                continue;
            }

            $data[] = [
                'name' => __($translationPrefix.'.'.$status),
                'value' => (int) $count,
                'itemStyle' => [
                    'color' => $this->chartColors[$index % count($this->chartColors)],
                ],
            ];
            $index++;
        }

        return [
            'id' => $id,
            'title' => $title,
            'options' => [
                'tooltip' => [
                    'trigger' => 'item',
                ],
                'legend' => [
                    'bottom' => 0,
                    'left' => 'center',
                    'type' => 'scroll',
                ],
                'series' => [[
                    'type' => 'pie',
                    'radius' => ['48%', '72%'],
                    'center' => ['50%', '42%'],
                    'avoidLabelOverlap' => true,
                    'label' => [
                        'formatter' => '{b}: {c}',
                    ],
                    'data' => $data,
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kpi(string $title, int|float|string $value, string $meta, string $icon, string $color, ?string $url = null): array
    {
        return [
            'title' => $title,
            'value' => is_numeric($value) ? $this->formatCount((int) $value) : (string) $value,
            'meta' => $meta,
            'icon' => $icon,
            'color' => $color,
            'url' => $url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function alert(string $severity, string $title, string $body, ?string $url = null): array
    {
        return [
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ];
    }

    /**
     * @param  array<string, mixed>  $dashboard
     */
    private function appendQuickAction(array &$dashboard, User $user, string $permission, string $route, string $label, string $icon): void
    {
        if (! $this->can($user, $permission) || ! Route::has($route)) {
            return;
        }

        $dashboard['quickActions'][] = [
            'label' => $label,
            'icon' => $icon,
            'url' => route($route),
        ];
    }

    private function can(User $user, string $permission): bool
    {
        try {
            return $user->can($permission);
        } catch (\Throwable) {
            return false;
        }
    }

    private function routeUrl(string $route, mixed $parameters = []): ?string
    {
        if (! Route::has($route)) {
            return null;
        }

        return route($route, $parameters);
    }

    private function formatCount(int $value): string
    {
        return $this->numericFormatter->format($value);
    }

    private function formatAmount(mixed $value): string
    {
        return $this->numericFormatter->format($value);
    }

    private function currencyLabel(mixed $code, mixed $name): string
    {
        $code = trim((string) $code);
        $name = trim((string) $name);

        return $code !== ''
            ? $code
            : ($name !== '' ? $name : __('dashboard.expanded.currency_unspecified'));
    }
}

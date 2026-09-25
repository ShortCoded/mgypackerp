<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\MenuConfigFileOrder;
use Modules\Core\Services\MenuService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @param  list<array<string, mixed>>  $items
 * @return list<array<string, mixed>>
 */
function businessDomainMenuLeaves(array $items, int $depth = 0): array
{
    $leaves = [];

    foreach ($items as $item) {
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];

        if ($children !== []) {
            $leaves = [
                ...$leaves,
                ...businessDomainMenuLeaves($children, $depth + 1),
            ];

            continue;
        }

        if (is_string($item['route'] ?? null) && $item['route'] !== '') {
            $item['depth'] = $depth;
            $leaves[] = $item;
        }
    }

    return $leaves;
}

/**
 * @param  list<array<string, mixed>>  $items
 * @return list<string>
 */
function businessDomainDestinations(array $items): array
{
    $destinations = array_map(
        fn (array $item): string => $item['route'].'|'.json_encode($item['route_params'] ?? [], JSON_THROW_ON_ERROR),
        businessDomainMenuLeaves($items),
    );

    sort($destinations);

    return $destinations;
}

/**
 * @param  list<array<string, mixed>>  $nodes
 * @return list<string>
 */
function businessDomainPermissionNames(array $nodes): array
{
    $permissions = [];

    foreach ($nodes as $node) {
        $permissions = [
            ...$permissions,
            ...array_column($node['permissions'] ?? [], 'name'),
            ...businessDomainPermissionNames($node['children'] ?? []),
        ];
    }

    return array_values(array_unique($permissions));
}

function businessDomainActor(string $permission): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate($permission, 'web');

    $actor = User::factory()->create();
    $actor->givePermissionTo($permission);

    return $actor;
}

function businessDomainRequest(string $routeName): void
{
    $request = Request::create('/_business-domain-menu');
    $request->setRouteResolver(fn (): RoutingRoute => new RoutingRoute(
        ['GET'],
        '/_business-domain-menu',
        ['as' => $routeName],
    ));

    app()->instance('request', $request);
}

test('configured and expanded menu destinations are preserved exactly once in the domain hierarchy', function (string $phaseMode, bool $includeExpanded): void {
    config()->set('erp.phase_mode', $phaseMode);

    $sourceItems = [];

    foreach (app(MenuConfigFileOrder::class)->files() as $file) {
        $items = require $file;

        if (is_array($items)) {
            $sourceItems = [...$sourceItems, ...$items];
        }
    }

    if ($includeExpanded) {
        $sourceItems = [
            ...$sourceItems,
            ...app(ErpUiScreenRegistry::class)->menuItems(),
        ];
    }

    $sourceDestinations = businessDomainDestinations($sourceItems);
    $organized = app(MenuService::class)->structure();
    $organizedDestinations = businessDomainDestinations($organized);
    $organizedLeaves = businessDomainMenuLeaves($organized);

    expect($organizedDestinations)
        ->toBe(array_values(array_unique($sourceDestinations)))
        ->and(array_unique($organizedDestinations))->toHaveCount(count($organizedDestinations));

    collect($organizedLeaves)->each(fn (array $item) => expect(Route::has($item['route']))->toBeTrue());
})->with([
    'legacy configuration' => ['legacy', false],
    'expanded configuration' => ['expanded', true],
]);

test('inventory and manufacturing navigation exposes canonical workflows without retired shell destinations', function (): void {
    config()->set('erp.phase_mode', 'legacy');

    $leaves = collect(businessDomainMenuLeaves(app(MenuService::class)->structure()));
    $destinations = $leaves->pluck('route')->all();
    $labels = $leaves->pluck('label')->all();
    $requiredDestinations = [
        'admin.inventory.documents.index',
        'admin.inventory.stock-counts.index',
        'admin.inventory.reports.index',
        'admin.inventory.opening-stocks.index',
        'admin.production.stages.index',
        'admin.production.work-orders.index',
        'admin.production.runs.index',
        'admin.production.material-requests.index',
        'admin.production.quality.index',
        'admin.production.reports.index',
    ];

    expect($destinations)->toContain(...$requiredDestinations)
        ->and($destinations)->not->toContain('admin.inventory.accounting.index')
        ->and($labels)->toContain(
            'inventory_movements',
            'inventory_stock_counts',
            'inventory_operational_reports',
            'production_stages',
            'production_work_orders',
            'production_runs',
            'production_material_requests',
            'production_quality',
            'production_reports_overview',
        )
        ->and($labels)->not->toContain(
            'inventory_stock_receipts',
            'inventory_finished_goods_receipt',
            'inventory_production_material_issue',
            'production_material_issues',
            'production_output_receipts',
        );

    collect($requiredDestinations)->each(fn (string $routeName) => expect(Route::has($routeName))->toBeTrue());
});

test('menu uses the required business domain order and maps representative screens correctly', function (string $phaseMode): void {
    config()->set('erp.phase_mode', $phaseMode);

    $menu = app(MenuService::class)->structure();
    $domains = collect($menu)->keyBy('label');
    $expectedDomains = [
        'dashboard',
        'basic_data',
        'sales',
        'purchases',
        'inventory',
        'production',
        'quality',
        'maintenance',
        'finance',
        'accounting_costing',
        'fixed_assets',
        'human_resources',
        'tools',
    ];

    expect(collect($menu)->pluck('label')->all())->toBe($expectedDomains)
        ->and($domains['dashboard']['route'])->toBe('dashboard')
        ->and($domains['dashboard']['children'])->toBe([]);

    $leavesFor = fn (string $domain): array => collect(businessDomainMenuLeaves([$domains[$domain]]))->pluck('label')->all();

    expect($leavesFor('basic_data'))->toContain('companies', 'branches', 'users', 'roles')
        ->and($leavesFor('sales'))->toContain('customers', 'sales_orders', 'sales_invoices', 'customers_report')
        ->and($leavesFor('purchases'))->toContain('suppliers', 'purchase_orders', 'suppliers_report', 'report_purchase_requests', 'report_supply_orders')
        ->and($leavesFor('inventory'))->toContain(
            'products',
            'raw_materials',
            'packaging_materials',
            'item_categories',
            'item_units',
            'item_sizes',
            'item_colors',
            'item_decals',
            'item_models',
            'item_groups',
            'item_origin_countries',
        )
        ->and($leavesFor('inventory'))->toContain('opening_stocks', 'inventory_stock_balance_inquiry', 'inventory_operational_reports', 'production_reports_receipts')
        ->and($leavesFor('production'))->toContain('production_stages', 'product_production_stages', 'production_work_orders', 'production_runs', 'production_material_requests')
        ->and($leavesFor('quality'))->toContain('production_quality', 'production_quality_reports')
        ->and($leavesFor('finance'))->toContain('currencies', 'bank_accounts', 'cashboxes', 'cash_receipt_vouchers', 'cash_payment_vouchers', 'cheques', 'fund_transfers', 'finance_report_overview')
        ->and($leavesFor('accounting_costing'))->toContain('chart_of_accounts', 'opening_balances', 'financial_periods', 'period_closing', 'cost_centers', 'account_ledger', 'trial_balance')
        ->and($leavesFor('fixed_assets'))->toContain('fixed_assets_register', 'fixed_asset_reports')
        ->and($leavesFor('maintenance'))->toContain('maintenance_plans', 'maintenance_requests', 'maintenance_orders')
        ->and($leavesFor('human_resources'))->toContain('hr_employees', 'hr_shifts', 'hr_requests')
        ->and($leavesFor('tools'))->toContain('open_documents', 'file_manager', 'calendar', 'my_board', 'task_boards', 'team_board', 'chat')
        ->and(collect($menu)->pluck('label')->all())->not->toContain(
            'administration',
            'item_data',
            'general_ledger',
            'costing',
            'planning_production',
            'reports',
        );

    if ($phaseMode === 'expanded') {
        $visibleLabels = collect(businessDomainMenuLeaves($menu))->pluck('label');

        expect($visibleLabels)->toContain('sales_orders', 'sales_invoices', 'sales_returns', 'quotations', 'purchase_orders', 'purchase_invoices')
            ->and($visibleLabels)->toContain(
                'inventory_movements',
                'inventory_stock_balance_inquiry',
                'inventory_stock_counts',
                'inventory_operational_reports',
                'production_stages',
                'product_production_stages',
                'production_work_orders',
                'production_runs',
                'production_material_requests',
                'production_expenses',
                'production_quality',
                'production_reports_overview',
                'maintenance_requests',
                'maintenance_orders',
            )
            ->and($visibleLabels)->not->toContain(
                'sales_sales_orders',
                'sales_sales_order_lines',
                'purchases_purchase_order_lines',
                'production_work_order_lines',
                'quality_incoming_inspection_lines',
                'sales_sales_order_change_requests',
                'purchases_purchase_order_change_requests',
                'inventory_stock_receipts',
                'inventory_finished_goods_receipt',
                'production_material_requirements_planning',
                'reports_production_production_plan',
            );
    }
})->with(['legacy', 'expanded']);

test('inventory item data exposes only real persisted screens and keeps product data shells disabled', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $menu = app(MenuService::class)->structure();
    $inventory = collect($menu)->firstWhere('label', 'inventory');
    $visibleKeys = collect(businessDomainMenuLeaves($menu))->pluck('label')->all();
    $registry = app(ErpUiScreenRegistry::class);
    $productDataShells = collect($registry->screens())
        ->filter(fn ($screen): bool => $screen->module() === 'product_data');

    expect(collect(businessDomainMenuLeaves([$inventory]))->pluck('label'))->toContain(
        'products',
        'raw_materials',
        'packaging_materials',
        'item_categories',
        'item_units',
        'item_sizes',
        'item_colors',
        'item_decals',
        'item_models',
        'item_groups',
        'item_origin_countries',
    )->and($productDataShells)->not->toBeEmpty()
        ->and($visibleKeys)->not->toContain(...$productDataShells->map->key()->all());

    $productDataShells->each(function ($screen): void {
        expect($screen->get('menu_visible', true))->toBeFalse()
            ->and($screen->get('shell_enabled', true))->toBeFalse()
            ->and(Route::has($screen->route('index')))->toBeFalse();
    });

    app()->setLocale('ar');
    $arabicMenu = app(MenuService::class)->structure();
    $arabicInventory = collect($arabicMenu)->firstWhere('label', 'inventory');

    expect(collect(businessDomainMenuLeaves([$arabicInventory]))->pluck('text'))->toContain(__('menu.item_origin_countries'));

    app()->setLocale('en');
});

test('phase-gated purchase orders keep their route and visibility behavior', function (): void {
    $actor = businessDomainActor('purchase_orders.view');

    config()->set('erp.phase_mode', 'legacy');
    $legacyMenu = app(MenuService::class)->getMenu($actor);

    expect(collect($legacyMenu)->firstWhere('label', 'purchases'))->toBeNull();

    config()->set('erp.phase_mode', 'expanded');
    $expandedPurchases = collect(app(MenuService::class)->getMenu($actor))->firstWhere('label', 'purchases');
    $purchaseOrders = collect(businessDomainMenuLeaves([$expandedPurchases]))->firstWhere('label', 'purchase_orders');

    expect($expandedPurchases)->not->toBeNull()
        ->and($purchaseOrders['route'])->toBe('admin.purchases.purchase-orders.index')
        ->and($purchaseOrders['phase_modes'])->toBe(['expanded']);
});

test('retired costing placeholder permissions are absent from navigation and role forms', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $legacyPermission = 'costing.estimated_cost_sheets.view';
    $actor = businessDomainActor($legacyPermission);
    $menu = app(MenuService::class)->getMenu($actor);
    $accounting = collect($menu)->firstWhere('label', 'accounting_costing');
    expect($accounting)->toBeNull();

    $groups = app(PermissionRegistryService::class)->groupedForForm([$legacyPermission]);
    expect($groups)->toBeEmpty();
});

test('only the owning business domain and functional subgroup open for child routes', function (string $routeName, string $permission, string $expectedDomain, ?string $expectedSubgroup, string $phaseMode): void {
    config()->set('erp.phase_mode', $phaseMode);

    $actor = businessDomainActor($permission);
    businessDomainRequest($routeName);

    $menu = app(MenuService::class)->getMenu($actor);
    $openDomains = collect($menu)->where('open', true)->pluck('label')->all();
    $activeDomains = collect($menu)
        ->where('label', '!=', 'dashboard')
        ->where('active', true)
        ->pluck('label')
        ->all();
    $domain = collect($menu)->firstWhere('label', $expectedDomain);
    $openSubgroups = collect($domain['children'])->where('open', true)->pluck('label')->all();

    expect($openDomains)->toBe([$expectedDomain])
        ->and($activeDomains)->toBe([$expectedDomain])
        ->and($openSubgroups)->toBe($expectedSubgroup === null ? [] : [$expectedSubgroup]);
})->with([
    'inventory item data create' => ['admin.item-units.create', 'item_units.view', 'inventory', 'item_data', 'legacy'],
    'sales customer show' => ['admin.sales.customers.show', 'customers.view', 'sales', null, 'legacy'],
    'purchases supplier edit' => ['admin.purchases.suppliers.edit', 'suppliers.view', 'purchases', null, 'legacy'],
    'inventory item edit' => ['admin.products.edit', 'products.view', 'inventory', 'item_data', 'legacy'],
    'production stages' => ['admin.production.stages.index', 'production.stages.view', 'production', 'production_management', 'legacy'],
    'accounting treasury edit' => ['admin.finance.bank-accounts.edit', 'bank_accounts.view', 'accounting_costing', 'finance', 'legacy'],
    'fixed assets register' => ['admin.fixed-assets.assets.index', 'fixed_assets.view', 'accounting_costing', 'fixed_assets', 'expanded'],
    'quality inspections' => ['admin.production.quality.index', 'production.quality.view', 'production', 'quality', 'expanded'],
    'maintenance work orders' => ['admin.maintenance.orders.index', 'maintenance.orders.view', 'production', 'maintenance', 'expanded'],
    'tools files index' => ['admin.file-manager.index', 'file_manager.view', 'tools', 'files_documents', 'legacy'],
    'reports index' => ['admin.reports.customers.index', 'reports.customers.view', 'sales', 'sales_cycle_reports', 'legacy'],
    'expanded sales edit' => ['admin.sales.sales-orders.edit', 'sales_orders.view', 'sales', null, 'expanded'],
]);

test('permission filtering hides unauthorized children and empty business domains', function (): void {
    config()->set('erp.phase_mode', 'legacy');

    $actor = businessDomainActor('customers.view');
    $menu = app(MenuService::class)->getMenu($actor);
    $sales = collect($menu)->firstWhere('label', 'sales');

    expect(collect($menu)->pluck('label')->all())->toBe(['dashboard', 'sales', 'human_resources'])
        ->and($sales)->not->toBeNull()
        ->and(collect($sales['children'])->pluck('label')->all())->toBe(['customers', 'customer_terms']);
});

test('permission form uses the same recursive business domain hierarchy', function (): void {
    app()->setLocale('en');

    $groups = app(PermissionRegistryService::class)->groupedForForm([
        'dashboard.view',
        'roles.view',
        'item_units.view',
        'customers.view',
        'reports.customers.view',
        'suppliers.view',
        'products.view',
        'production.quality.view',
        'production.orders.view',
        'accounts.view',
        'fixed_assets.view',
        'hr.employees.view',
        'file_manager.view',
    ]);

    expect(array_column($groups, 'key'))->toBe([
        'general',
        'basic_data',
        'sales',
        'purchases',
        'inventory',
        'production',
        'accounting_costing',
        'human_resources',
        'tools',
    ])->and(array_column($groups, 'label'))->toBe([
        __('common.groups.general'),
        __('menu.basic_data'),
        __('menu.sales'),
        __('menu.purchases'),
        __('menu.inventory'),
        __('menu.production'),
        __('menu.accounting_costing'),
        __('menu.human_resources'),
        __('menu.tools'),
    ]);

    $permissionsByDomain = collect($groups)->mapWithKeys(
        fn (array $group): array => [$group['key'] => businessDomainPermissionNames([$group])],
    );

    expect($permissionsByDomain['basic_data'])->toContain('roles.view')
        ->and($permissionsByDomain['sales'])->toContain('customers.view', 'reports.customers.view')
        ->and($permissionsByDomain['purchases'])->toContain('suppliers.view')
        ->and($permissionsByDomain['inventory'])->toContain('item_units.view', 'products.view')
        ->and($permissionsByDomain['production'])->toContain('production.orders.view', 'production.quality.view')
        ->and($permissionsByDomain['production'])->not->toContain('production.identifiers.view', 'production.resources.view')
        ->and($permissionsByDomain['accounting_costing'])->toContain('accounts.view', 'fixed_assets.view')
        ->and($permissionsByDomain['human_resources'])->toContain('hr.employees.view')
        ->and($permissionsByDomain['tools'])->toContain('file_manager.view');

    expect($permissionsByDomain['inventory'])->toContain('item_units.view', 'products.view');
});

test('domain labels are localized exactly and navigation renderers keep unique collapse ids and scrolling', function (): void {
    config()->set('erp.phase_mode', 'legacy');

    app()->setLocale('en');
    $englishMenu = app(MenuService::class)->structure();
    $english = collect($englishMenu)->pluck('text', 'label');

    expect($english['basic_data'])->toBe('Basic Data')
        ->and($english['sales'])->toBe('Sales')
        ->and($english['purchases'])->toBe('Purchases')
        ->and($english['inventory'])->toBe('Inventory')
        ->and($english['production'])->toBe('Manufacturing & Production')
        ->and($english['accounting_costing'])->toBe('Accounting & Costing')
        ->and($english['fixed_assets'])->toBe('Fixed Assets')
        ->and($english['human_resources'])->toBe('Human Resources')
        ->and($english['tools'])->toBe('Tools');

    app()->setLocale('ar');
    $arabicMenu = app(MenuService::class)->structure();
    $arabic = collect($arabicMenu)->pluck('text', 'label');

    expect($arabic['basic_data'])->toBe('البيانات الأساسية')
        ->and($arabic['sales'])->toBe('المبيعات')
        ->and($arabic['purchases'])->toBe('المشتريات')
        ->and($arabic['inventory'])->toBe('المخزون')
        ->and($arabic['production'])->toBe('التصنيع والإنتاج')
        ->and($arabic['accounting_costing'])->toBe('الحسابات والتكاليف')
        ->and($arabic['fixed_assets'])->toBe('الأصول الثابتة')
        ->and($arabic['human_resources'])->toBe('الموارد البشرية')
        ->and($arabic['tools'])->toBe('الأدوات');

    $englishVerticalHtml = view('layouts.partials.menu.vertical-items', [
        'items' => $englishMenu,
        'menuPath' => [],
    ])->render();
    preg_match_all('/\\sid="(vertical-menu-[^"]+)"/', $englishVerticalHtml, $englishCollapseIds);

    $verticalHtml = view('layouts.partials.menu.vertical-items', [
        'items' => $arabicMenu,
        'menuPath' => [],
    ])->render();
    preg_match_all('/\\sid="(vertical-menu-[^"]+)"/', $verticalHtml, $collapseIds);
    preg_match_all('/\\saria-controls="(vertical-menu-[^"]+)"/', $verticalHtml, $controlledIds);

    $topHtml = view('layouts.partials.menu.top-items', [
        'items' => $arabicMenu,
        'menuPath' => [],
    ])->render();
    preg_match_all('/\\sid="(top-menu-[^"]+)"/', $topHtml, $topIds);
    preg_match_all('/\\sid="(top-dropdown-menu-[^"]+)"/', $topHtml, $topDropdownIds);

    expect(array_unique($collapseIds[1]))->toHaveCount(count($collapseIds[1]))
        ->and($controlledIds[1])->toBe($collapseIds[1])
        ->and($englishCollapseIds[1])->toBe($collapseIds[1])
        ->and(array_unique($topIds[1]))->toHaveCount(count($topIds[1]))
        ->and(array_unique($topDropdownIds[1]))->toHaveCount(count($topDropdownIds[1]))
        ->and(file_get_contents(resource_path('views/layouts/partials/navbar-vertical.blade.php')))
        ->toContain('navbar-vertical-content scrollbar')
        ->and(file_get_contents(resource_path('views/layouts/app.blade.php')))
        ->toContain("dir=\"{{ config('languages.available.' . app()->getLocale() . '.dir', 'ltr') }}\"");

    app()->setLocale('en');
});

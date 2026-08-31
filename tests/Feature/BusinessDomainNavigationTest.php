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
        'admin.inventory.accounting.index',
        'admin.inventory.documents.index',
        'admin.inventory.stock-counts.index',
        'admin.inventory.reports.index',
        'admin.inventory.opening-stocks.index',
        'admin.production.resources.index',
        'admin.production.work-orders.index',
        'admin.production.runs.index',
        'admin.production.reports.index',
    ];

    expect($destinations)->toContain(...$requiredDestinations)
        ->and($labels)->toContain(
            'inventory_accounting',
            'inventory_movements',
            'inventory_stock_counts',
            'inventory_operational_reports',
            'production_resources',
            'production_work_orders',
            'production_runs',
            'production_operational_reports',
        )
        ->and($labels)->not->toContain(
            'inventory_stock_receipts',
            'inventory_finished_goods_receipt',
            'inventory_production_material_issue',
            'production_material_requests',
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
        'accounting_costing',
        'fixed_assets',
        ...($phaseMode === 'expanded' ? ['maintenance'] : []),
        'human_resources',
        'reports',
        'tools',
    ];

    expect(collect($menu)->pluck('label')->all())->toBe($expectedDomains)
        ->and($domains['dashboard']['route'])->toBe('dashboard')
        ->and($domains['dashboard']['children'])->toBe([]);

    $subgroupsFor = fn (string $domain): array => collect($domains[$domain]['children'])->pluck('label')->all();
    $leavesFor = fn (string $domain, string $subgroup): array => collect(
        collect($domains[$domain]['children'])->firstWhere('label', $subgroup)['children'],
    )->pluck('label')->all();

    expect($subgroupsFor('basic_data'))->toContain('organization_setup', 'users_permissions')
        ->and($leavesFor('basic_data', 'organization_setup'))->toContain('companies', 'branches', 'financial_periods')
        ->and($leavesFor('basic_data', 'users_permissions'))->toContain('users', 'roles')
        ->and($subgroupsFor('sales'))->toContain('customer_data', 'sales_cycle')
        ->and($leavesFor('sales', 'customer_data'))->toContain('customers')
        ->and($leavesFor('sales', 'sales_cycle'))->toContain('quotations')
        ->and($subgroupsFor('purchases'))->toContain('supplier_data', 'purchase_cycle')
        ->and($leavesFor('purchases', 'supplier_data'))->toContain('suppliers')
        ->and($leavesFor('purchases', 'purchase_cycle'))->toContain('purchase_orders', 'purchase_invoices')
        ->and($subgroupsFor('inventory'))->toContain('item_data', 'opening_inventory')
        ->and($leavesFor('inventory', 'item_data'))->toContain(
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
        ->and($leavesFor('inventory', 'opening_inventory'))->toContain('opening_stocks', 'unpriced_inventory_receipts', 'opening_stock_pricings')
        ->and($leavesFor('production', 'production_setup'))->toContain('production_identifier_types', 'production_identifiers')
        ->and($subgroupsFor('accounting_costing'))->toContain('general_accounting', 'treasury_banks', 'cost_accounting')
        ->and($leavesFor('accounting_costing', 'general_accounting'))->toContain('chart_of_accounts', 'opening_balances')
        ->and($leavesFor('accounting_costing', 'treasury_banks'))->toContain('currencies', 'bank_accounts', 'cashboxes')
        ->and($leavesFor('accounting_costing', 'cost_accounting'))->toContain('cost_centers')
        ->and($leavesFor('fixed_assets', 'asset_data'))->toContain('fixed_assets_register')
        ->and($leavesFor('human_resources', 'employee_data'))->toContain('hr_employees')
        ->and($subgroupsFor('reports'))->toContain('sales_reports', 'purchase_reports', 'inventory_reports')
        ->and($leavesFor('reports', 'sales_reports'))->toContain('customers_report')
        ->and($leavesFor('reports', 'purchase_reports'))->toContain('suppliers_report')
        ->and($leavesFor('reports', 'inventory_reports'))->toContain('products_data_report')
        ->and($subgroupsFor('tools'))->toContain('files_documents', 'work_management', 'communication', 'application_tools')
        ->and($leavesFor('tools', 'files_documents'))->toContain('open_documents', 'file_manager')
        ->and($leavesFor('tools', 'work_management'))->toContain('calendar', 'my_board', 'task_boards', 'team_board')
        ->and($leavesFor('tools', 'communication'))->toContain('chat')
        ->and($leavesFor('tools', 'application_tools'))->toContain('pwa_settings', 'activity_logs', 'auth_logs', 'auth_sessions');

    expect($subgroupsFor('tools'))->not->toContain('sales', 'purchases', 'inventory', 'production', 'human_resources')
        ->and(collect($menu)->pluck('label')->all())->not->toContain(
            'administration',
            'item_data',
            'general_ledger',
            'finance',
            'costing',
            'planning_production',
            'quality',
        );

    if ($phaseMode === 'expanded') {
        $visibleLabels = collect(businessDomainMenuLeaves($menu))->pluck('label');

        expect($visibleLabels)->toContain('sales_orders', 'sales_invoices', 'sales_returns')
            ->and($leavesFor('sales', 'sales_cycle'))->toContain('quotations')
            ->and($leavesFor('purchases', 'purchase_cycle'))->toContain('purchase_orders', 'purchase_invoices')
            ->and($visibleLabels)->toContain(
                'sales_sales_order_change_requests',
                'purchases_purchase_order_change_requests',
                'inventory_stock_receipts',
                'inventory_finished_goods_receipt',
                'production_material_requirements_planning',
                'reports_production_production_plan',
            )
            ->and($visibleLabels)->not->toContain(
                'sales_sales_orders',
                'sales_sales_order_lines',
                'purchases_purchase_order_lines',
                'production_work_order_lines',
                'quality_incoming_inspection_lines',
            );
    }
})->with(['legacy', 'expanded']);

test('inventory item data restores business setup surfaces while keeping embedded product children out of navigation', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $restoredKeys = [
        'product_data_product_types',
        'product_data_raw_material_types',
        'product_data_semi_finished_product_types',
        'product_data_finished_product_types',
        'product_data_packaging_material_types',
        'product_data_service_types',
        'product_data_product_families',
        'product_data_product_brands',
        'product_data_product_grades',
        'product_data_product_specifications',
        'product_data_product_technical_properties',
        'product_data_product_packaging_definitions',
        'product_data_product_storage_requirements',
        'product_data_product_reorder_policies',
        'product_data_product_safety_stock_policies',
        'product_data_product_batch_policies',
        'product_data_product_shelf_life_policies',
    ];
    $embeddedChildKeys = [
        'product_data_product_units',
        'product_data_product_equivalent_units',
        'product_data_product_barcodes',
        'product_data_product_images',
        'product_data_product_documents',
    ];
    $menu = app(MenuService::class)->structure();
    $inventory = collect($menu)->firstWhere('label', 'inventory');
    $itemData = collect($inventory['children'])->firstWhere('label', 'item_data');
    $visibleKeys = collect(businessDomainMenuLeaves($menu))->pluck('label')->all();
    $registry = app(ErpUiScreenRegistry::class);
    $permissionRegistry = app(PermissionRegistryService::class);

    expect(collect($itemData['children'])->pluck('label'))->toContain(
        'products',
        'raw_materials',
        'packaging_materials',
        ...$restoredKeys,
    )->and($visibleKeys)->not->toContain(...$embeddedChildKeys);

    collect($restoredKeys)->each(function (string $key) use ($registry, $permissionRegistry): void {
        $screen = $registry->find($key);

        expect($screen)->not->toBeNull()
            ->and($screen->get('menu_visible', true))->toBeTrue()
            ->and(Route::has($screen->route('index')))->toBeTrue()
            ->and($permissionRegistry->all())->toContain($screen->permission('view'));
    });

    collect($embeddedChildKeys)->each(function (string $key) use ($registry): void {
        expect($registry->find($key)?->get('menu_visible', true))->toBeFalse();
    });

    $restoredPermissionGroups = $permissionRegistry->groupedForForm([
        'product_data.product_units.view',
        'product_data.product_types.view',
    ]);

    expect($restoredPermissionGroups)->not->toBeEmpty();

    app()->setLocale('ar');
    $arabicMenu = app(MenuService::class)->structure();
    $arabicInventory = collect($arabicMenu)->firstWhere('label', 'inventory');
    $arabicItemData = collect($arabicInventory['children'])->firstWhere('label', 'item_data');

    expect(collect($arabicItemData['children'])->pluck('text'))->toContain(__('menu.item_origin_countries'));

    app()->setLocale('en');
});

test('phase-gated purchase orders keep their route and visibility behavior', function (): void {
    $actor = businessDomainActor('purchase_orders.view');

    config()->set('erp.phase_mode', 'legacy');
    $legacyMenu = app(MenuService::class)->getMenu($actor);

    expect(collect($legacyMenu)->firstWhere('label', 'purchases'))->toBeNull();

    config()->set('erp.phase_mode', 'expanded');
    $expandedPurchases = collect(app(MenuService::class)->getMenu($actor))->firstWhere('label', 'purchases');
    $purchaseCycle = collect($expandedPurchases['children'])->firstWhere('label', 'purchase_cycle');
    $purchaseOrders = collect($purchaseCycle['children'])->firstWhere('label', 'purchase_orders');

    expect($expandedPurchases)->not->toBeNull()
        ->and($purchaseCycle['open'])->toBeFalse()
        ->and($purchaseOrders['route'])->toBe('admin.purchases.purchase-orders.index')
        ->and($purchaseOrders['phase_modes'])->toBe(['expanded']);
});

test('legacy placeholder permissions resolve to the visible canonical costing surface', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $legacyPermission = 'costing.estimated_cost_sheets.view';
    $actor = businessDomainActor($legacyPermission);
    $menu = app(MenuService::class)->getMenu($actor);
    $accounting = collect($menu)->firstWhere('label', 'accounting_costing');
    expect($accounting)->not->toBeNull()
        ->and(collect(businessDomainMenuLeaves([$accounting]))->pluck('label'))->toContain('costing_work_order_estimated_cost');

    $groups = app(PermissionRegistryService::class)->groupedForForm([$legacyPermission]);
    expect($groups)->not->toBeEmpty();
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
    'sales customer show' => ['admin.sales.customers.show', 'customers.view', 'sales', 'customer_data', 'legacy'],
    'purchases supplier edit' => ['admin.purchases.suppliers.edit', 'suppliers.view', 'purchases', 'supplier_data', 'legacy'],
    'inventory item edit' => ['admin.products.edit', 'products.view', 'inventory', 'item_data', 'legacy'],
    'production setup show' => ['admin.production.identifiers.show', 'production.identifiers.view', 'production', 'production_setup', 'legacy'],
    'accounting treasury edit' => ['admin.finance.bank-accounts.edit', 'bank_accounts.view', 'accounting_costing', 'treasury_banks', 'legacy'],
    'fixed assets register' => ['admin.fixed-assets.assets.index', 'fixed_assets.view', 'accounting_costing', 'fixed_assets', 'expanded'],
    'maintenance work orders' => ['admin.maintenance.maintenance-work-orders.index', 'maintenance.maintenance_work_orders.view', 'production', 'maintenance', 'expanded'],
    'tools files index' => ['admin.file-manager.index', 'file_manager.view', 'tools', 'files_documents', 'legacy'],
    'reports index' => ['admin.reports.customers.index', 'reports.customers.view', 'reports', 'sales_reports', 'legacy'],
    'expanded sales edit' => ['admin.sales.sales-orders.edit', 'sales_orders.view', 'sales', null, 'expanded'],
]);

test('permission filtering hides unauthorized children and empty business domains', function (): void {
    config()->set('erp.phase_mode', 'legacy');

    $actor = businessDomainActor('customers.view');
    $menu = app(MenuService::class)->getMenu($actor);
    $sales = collect($menu)->firstWhere('label', 'sales');

    expect(collect($menu)->pluck('label')->all())->toBe(['dashboard', 'sales'])
        ->and($sales)->not->toBeNull()
        ->and(collect($sales['children'])->pluck('label')->all())->toBe(['customer_data'])
        ->and(collect($sales['children'][0]['children'])->pluck('label')->all())->toBe(['customers']);
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
        'production.identifiers.view',
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
        'fixed_assets',
        'human_resources',
        'reports',
        'tools',
    ])->and(array_column($groups, 'label'))->toBe([
        __('common.groups.general'),
        __('menu.basic_data'),
        __('menu.sales'),
        __('menu.purchases'),
        __('menu.inventory'),
        __('menu.production'),
        __('menu.accounting_costing'),
        __('menu.fixed_assets'),
        __('menu.human_resources'),
        __('menu.reports'),
        __('menu.tools'),
    ]);

    $permissionsByDomain = collect($groups)->mapWithKeys(
        fn (array $group): array => [$group['key'] => businessDomainPermissionNames([$group])],
    );

    expect($permissionsByDomain['basic_data'])->toContain('roles.view')
        ->and($permissionsByDomain['sales'])->toContain('customers.view')
        ->and($permissionsByDomain['purchases'])->toContain('suppliers.view')
        ->and($permissionsByDomain['inventory'])->toContain('item_units.view', 'products.view')
        ->and($permissionsByDomain['production'])->toContain('production.identifiers.view', 'production.orders.view')
        ->and($permissionsByDomain['accounting_costing'])->toContain('accounts.view')
        ->and($permissionsByDomain['fixed_assets'])->toContain('fixed_assets.view')
        ->and($permissionsByDomain['human_resources'])->toContain('hr.employees.view')
        ->and($permissionsByDomain['reports'])->toContain('reports.customers.view')
        ->and($permissionsByDomain['tools'])->toContain('file_manager.view');

    $inventoryGroup = collect($groups)->firstWhere('key', 'inventory');
    $itemData = collect($inventoryGroup['children'])->firstWhere('key', 'inventory_item_data');
    $reportsGroup = collect($groups)->firstWhere('key', 'reports');
    $salesReports = collect($reportsGroup['children'])->firstWhere('key', 'reports_sales_reports');

    expect($itemData['label'])->toBe(__('menu.item_data'))
        ->and(businessDomainPermissionNames([$itemData]))->toContain('item_units.view', 'products.view')
        ->and($salesReports['label'])->toBe(__('menu.sales_reports'))
        ->and(businessDomainPermissionNames([$salesReports]))->toContain('reports.customers.view');
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
        ->and($english['reports'])->toBe('Reports')
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
        ->and($arabic['reports'])->toBe('التقارير')
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

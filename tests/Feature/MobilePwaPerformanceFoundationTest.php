<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\PlasticsDashboardService;
use Modules\Core\Services\ScreenDataVisibilityService;
use Modules\Finance\Services\FinanceReportService;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Production\Services\ProductionReportService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;

test('authenticated layout uses one directional theme and one user stylesheet', function () {
    $response = $this->withSession(['locale' => 'en'])
        ->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('width=device-width, initial-scale=1, viewport-fit=cover', false)
        ->assertSee('assets/css/theme.min.css', false)
        ->assertDontSee('assets/css/theme-rtl.min.css', false)
        ->assertDontSee('fonts.googleapis.com', false)
        ->assertDontSee('fonts.gstatic.com', false)
        ->assertSee('data-erp-pwa-navigation hidden', false)
        ->assertSee('data-erp-pwa-back', false)
        ->assertSee('data-erp-pwa-forward', false)
        ->assertSee('data-erp-pwa-page-reload', false)
        ->assertSee('vendors/sweetalert2/sweetalert2.all.min.js', false)
        ->assertSee('aria-label="App navigation"', false);

    expect(substr_count($response->getContent(), 'assets/css/user.css'))->toBe(1);
});

test('arabic layout sends only the rtl theme stylesheet', function () {
    $response = $this->withSession(['locale' => 'ar'])
        ->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('assets/css/theme-rtl.min.css', false)
        ->assertDontSee('assets/css/theme.min.css', false);

    $response->assertSee('aria-label="التنقل داخل التطبيق"', false);

    expect(substr_count($response->getContent(), 'assets/css/user.css'))->toBe(1);
});

test('authentication layout uses the same mobile viewport and deduplicated styles', function () {
    $response = $this->withSession(['locale' => 'en'])
        ->get('/login')
        ->assertOk()
        ->assertSee('width=device-width, initial-scale=1, viewport-fit=cover', false)
        ->assertSee('assets/css/theme.min.css', false)
        ->assertDontSee('assets/css/theme-rtl.min.css', false)
        ->assertDontSee('fonts.googleapis.com', false);

    expect(substr_count($response->getContent(), 'assets/css/user.css'))->toBe(1);
});

test('mobile and pwa assets expose the shared interaction contracts', function () {
    $css = file_get_contents(public_path('assets/css/user.css'));
    $connectivity = file_get_contents(public_path('assets/js/modules/Core/connectivity.js'));
    $navigationGuard = file_get_contents(public_path('assets/js/modules/Core/navigation-guard.js'));
    $navbarPreference = file_get_contents(public_path('assets/js/modules/Core/navbar-preference.js'));
    $pwaRuntime = file_get_contents(public_path('assets/js/modules/Core/pwa-runtime.js'));
    $notificationSound = file_get_contents(public_path('assets/js/modules/Core/notification-sound.js'));
    $notifications = file_get_contents(public_path('assets/js/modules/Core/notifications.js'));
    $pushNotifications = file_get_contents(public_path('assets/js/modules/Core/push-notifications.js'));
    $alerts = file_get_contents(public_path('assets/js/modules/Core/alerts.js'));
    $salesIndex = file_get_contents(resource_path('views/modules/sales/cycle/index.blade.php'));

    expect($css)
        ->toContain('@media screen and (max-width: 767.98px)')
        ->toContain('font-size: 1rem')
        ->toContain('min-block-size: 2.75rem')
        ->toContain('overflow-x: clip')
        ->toContain('.select2-container')
        ->toContain('.dropdown-menu-notification')
        ->toContain('100dvh')
        ->toContain('env(safe-area-inset-bottom)')
        ->and($connectivity)
        ->toContain("window.addEventListener('offline'")
        ->toContain("window.addEventListener('online', verifyServer)")
        ->toContain("method: 'HEAD'")
        ->toContain(".on('ajaxError.erpConnectivity'")
        ->toContain("retryButton.addEventListener('click', verifyServer)")
        ->not->toContain("document.addEventListener('submit'")
        ->and($navigationGuard)
        ->toContain("document.addEventListener('submit'")
        ->toContain("window.addEventListener('beforeunload'")
        ->toContain('window.confirm(')
        ->toContain('event.isTrusted')
        ->and($navbarPreference)
        ->toContain("cookieName = 'erp_navbar_position'")
        ->toContain('SameSite=Lax')
        ->toContain('[data-theme-control="navbarPosition"]')
        ->and($pwaRuntime)
        ->toContain("registration.addEventListener('updatefound'")
        ->toContain("registration.waiting.postMessage({ type: 'SKIP_WAITING' })")
        ->toContain("'(display-mode: standalone)'")
        ->toContain('window.navigator.standalone === true')
        ->toContain('window.history.back()')
        ->toContain('window.history.forward()')
        ->toContain('window.location.reload()')
        ->toContain('navigationApi.entries()')
        ->toContain('navigationFallbackUrl')
        ->not->toContain('history.length')
        ->toContain('reloadRequested')
        ->and($notificationSound)
        ->toContain("return storedValue(enabledStorageKey) !== '0'")
        ->toContain('if (!isEnabled() || (!unlocked && !force))')
        ->toContain('now - lastPlayedAt < throttleMs')
        ->toContain("['pointerdown', 'keydown', 'touchstart']")
        ->toContain("icon.setAttribute('class'")
        ->and($notifications)
        ->toContain('baselineReady')
        ->toContain("AppNotificationSound.play(soundNotification?.sound_key || 'action')")
        ->toContain('hiddenIntervalMs')
        ->and($pushNotifications)
        ->toContain("setControlsAvailable(baseSupported() && notificationPermission() !== 'denied')")
        ->toContain('messages?.unavailable')
        ->toContain("icon.setAttribute('class'")
        ->not->toContain('icon.className =')
        ->and($alerts)
        ->toContain("setAttribute('data-swal2-theme', currentTheme())")
        ->toContain("attributeFilter: ['data-bs-theme']")
        ->and($salesIndex)
        ->toContain('id="sales-cycle-table"')
        ->not->toContain('data-datatables');
});

test('dashboard schema checks do not query database metadata at runtime', function () {
    Schema::shouldReceive('getTableListing')
        ->never();
    Schema::shouldReceive('getColumnListing')
        ->never();

    $dashboard = app(PlasticsDashboardService::class);
    $hasColumn = new ReflectionMethod($dashboard, 'hasColumn');
    $tableExists = new ReflectionMethod($dashboard, 'tableExists');

    expect($hasColumn->invoke($dashboard, 'purchase_orders', 'company_id'))->toBeTrue()
        ->and($hasColumn->invoke($dashboard, 'purchase_orders', 'branch_id'))->toBeTrue()
        ->and($hasColumn->invoke($dashboard, 'purchase_orders', 'financial_period_id'))->toBeTrue()
        ->and($hasColumn->invoke($dashboard, 'purchase_orders', 'missing_column'))->toBeFalse()
        ->and($tableExists->invoke($dashboard, 'products'))->toBeTrue()
        ->and($tableExists->invoke($dashboard, 'missing_table'))->toBeFalse();
});

test('dashboard product master data stays within its query budget', function () {
    $company = Company::factory()->create();
    $finishedProduct = Product::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Finished product',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);
    $productWithoutComponents = Product::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Product without components',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);
    $inactiveProduct = Product::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Inactive product',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'inactive',
    ]);
    $rawMaterial = Product::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Raw material',
        'item_classification' => Product::ClassificationRawMaterial,
        'status' => 'active',
    ]);
    Product::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Packaging material',
        'item_classification' => Product::ClassificationPackaging,
        'status' => 'active',
    ]);
    ProductComponent::query()->create([
        'company_id' => $company->getKey(),
        'product_id' => $finishedProduct->getKey(),
        'component_product_id' => $rawMaterial->getKey(),
        'quantity' => 1,
    ]);
    ProductComponent::query()->create([
        'company_id' => $company->getKey(),
        'product_id' => $productWithoutComponents->getKey(),
        'component_product_id' => $rawMaterial->getKey(),
        'quantity' => 1,
    ])->delete();
    ProductComponent::query()->create([
        'company_id' => $company->getKey(),
        'product_id' => $inactiveProduct->getKey(),
        'component_product_id' => $rawMaterial->getKey(),
        'quantity' => 1,
    ]);

    $user = new class extends User
    {
        public function can($abilities, $arguments = []): bool
        {
            return in_array($abilities, ['products.view', 'raw_materials.view', 'packaging_materials.view', 'reports.products_data.view'], true);
        }
    };
    $visibility = Mockery::mock(ScreenDataVisibilityService::class);
    $visibility->shouldReceive('applyToEloquent')
        ->andReturnUsing(fn (Builder $query, User $user, string $screenKey): Builder => $query);
    $service = new PlasticsDashboardService(
        Mockery::mock(OperatingContextService::class),
        Mockery::mock(DateFormatService::class),
        new NumericFormatService,
        $visibility,
        Mockery::mock(InventoryReportService::class),
        Mockery::mock(ProductionReportService::class),
        Mockery::mock(ProcurementCycleReport::class),
        Mockery::mock(FinanceReportService::class),
    );
    $dashboard = [
        'metrics' => [],
        'charts' => [],
        'quickActions' => [],
        'alerts' => [],
    ];
    $appendProductMasterData = new ReflectionMethod($service, 'appendProductMasterData');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $arguments = [&$dashboard, $user, ['company_id' => $company->getKey()]];
    $appendProductMasterData->invokeArgs($service, $arguments);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    $charts = collect($dashboard['charts'])->keyBy('id');
    $productTypeData = $charts->get('dashboard-product-types')['options']['series'][0]['data'];
    $bomCoverageData = $charts->get('dashboard-bom-coverage')['options']['series'][0]['data'];
    $metrics = collect($dashboard['metrics'])->keyBy('title');

    expect($queryCount)->toBe(6)
        ->and($charts->keys()->all())->toContain(
            'dashboard-product-types',
            'dashboard-bom-coverage',
            'dashboard-raw-material-units',
            'dashboard-packaging-material-units',
        )
        ->and($dashboard['metrics'])->toHaveCount(5)
        ->and(collect($dashboard['metrics'])->pluck('value')->all())->toBe(['2', '1', '1', '1', '1'])
        ->and($metrics->get(__('dashboard.plastics.metrics.products.title'))['url'])->toBe(route('admin.products.index', [
            'status' => 'active',
        ]))
        ->and($metrics->get(__('dashboard.plastics.metrics.products_with_bom.title'))['url'])->toBe(route('admin.products.index', [
            'status' => 'active',
            'components_state' => 'with',
        ]))
        ->and($metrics->get(__('dashboard.plastics.metrics.bom_lines.title'))['url'])->toBe(route('admin.reports.products-data.index', [
            'result_mode' => 'detailed',
            'item_scope' => 'products',
            'record_state' => 'active',
            'status' => 'active',
            'components_state' => 'with',
        ]))
        ->and($metrics->get(__('dashboard.plastics.metrics.raw_materials.title'))['url'])->toBe(route('admin.raw-materials.index', [
            'status' => 'active',
        ]))
        ->and($metrics->get(__('dashboard.plastics.metrics.packaging_materials.title'))['url'])->toBe(route('admin.packaging-materials.index', [
            'status' => 'active',
        ]))
        ->and(array_sum(array_column($productTypeData, 'value')))->toBe(4)
        ->and(array_column($bomCoverageData, 'value'))->toBe([1, 1]);
});

<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Core\Services\MenuService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('dashboard requires authentication', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('dashboard renders falcon authenticated layout', function () {
    $user = User::factory()->create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
    ]);

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertHeader('Pragma', 'no-cache')
        ->assertHeader('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT')
        ->assertSee('dir="ltr"', false)
        ->assertSee('navbar-vertical', false)
        ->assertSee('data-navbar-top="combo"', false)
        ->assertSee('data-double-top-nav', false)
        ->assertSee('settings-offcanvas', false)
        ->assertSee('js-app-language-select', false)
        ->assertSee('data-language-switch-url', false)
        ->assertSee('data-theme-control="navbarPosition"', false)
        ->assertSee('data-theme-control="navbarStyle"', false)
        ->assertDontSee('data-theme-control="isRTL"', false)
        ->assertSee('assets/css/user.css', false)
        ->assertSee('assets/js/modules/Core/page-cache-guard.js', false)
        ->assertSee('assets/img/logos/Logo.svg', false)
        ->assertSee('Admin User')
        ->assertSee('admin@example.com')
        ->assertSee('Short Coded')
        ->assertSee('https://shortcoded.com', false)
        ->assertSee(__('layout.digital_transformation_partner'))
        ->assertSee(__('dashboard.create_something_beautiful'))
        ->assertSee(__('dashboard.getting_started'))
        ->assertSee('data-move-target="#navbarVerticalNav"', false)
        ->assertSee('var navbarPosition = localStorage.getItem', false)
        ->assertDontSee('Themewagon')
        ->assertDontSee('Emma Watson')
        ->assertDontSee('Mia Khalifa');

    $comboNavbar = Str::between(
        $response->getContent(),
        'data-move-target="#navbarVerticalNav" data-navbar-top="combo">',
        '</nav>'
    );

    expect($comboNavbar)
        ->toContain('<div class="collapse navbar-collapse scrollbar" id="navbarStandard"></div>')
        ->toContain('navbar-collapse scrollbar d-none d-lg-flex')
        ->toContain('navbar-nav navbar-nav-icons ms-auto flex-row align-items-center')
        ->toContain('theme-control-dropdown')
        ->toContain('navbarDropdownNotification')
        ->toContain('navbarDropdownUser')
        ->toContain('admin@example.com');

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('no-cache')
        ->toContain('must-revalidate')
        ->toContain('max-age=0');
});

test('dynamic page cache middleware skips non html responses', function () {
    Route::middleware('web')->get('/_cache-guard-json', fn () => response()->json(['ok' => true]));

    Route::middleware('web')->get('/_cache-guard-download', fn () => response('report')
        ->header('Content-Type', 'text/plain')
        ->header('Content-Disposition', 'attachment; filename="report.txt"'));

    $jsonResponse = $this->getJson('/_cache-guard-json')
        ->assertOk();

    expect($jsonResponse->headers->get('Cache-Control'))->not->toBe('no-store, no-cache, must-revalidate, max-age=0')
        ->and($jsonResponse->headers->get('Pragma'))->toBeNull()
        ->and($jsonResponse->headers->get('Expires'))->toBeNull();

    $downloadResponse = $this->get('/_cache-guard-download')
        ->assertOk();

    expect($downloadResponse->headers->get('Cache-Control'))->not->toBe('no-store, no-cache, must-revalidate, max-age=0')
        ->and($downloadResponse->headers->get('Pragma'))->toBeNull()
        ->and($downloadResponse->headers->get('Expires'))->toBeNull();
});

test('dashboard supports arabic rtl direction', function () {
    $this->withSession(['locale' => 'ar'])
        ->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('assets/css/user.css', false);

    $userCss = file_get_contents(public_path('assets/css/user.css'));

    expect($userCss)
        ->toContain('Inter')
        ->toContain('IBM Plex Sans Arabic')
        ->toContain('--falcon-font-sans-serif')
        ->toContain('html[dir="ltr"]')
        ->toContain('html[dir="rtl"]')
        ->toContain('font-family: var(--falcon-font-sans-serif)')
        ->not->toContain('.ttf')
        ->not->toContain('/Roboto-Regular.ttf');

    preg_match_all('/url\\("\\.\\.\\/([^"]+)"\\)/', $userCss, $matches);

    foreach ($matches[1] as $assetPath) {
        expect(public_path('assets/'.$assetPath))->toBeFile();
    }
});

test('app shell sets ERP Falcon default preferences for fresh browsers and reset', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('assets/js/modules/Core/falcon-defaults.js', false)
        ->assertSee('assets/js/config.js', false)
        ->assertSee('window.ErpFalconDefaults.apply();', false)
        ->assertSee('data-theme-control="reset"', false)
        ->assertSee('data-theme-control="theme"', false)
        ->assertSee('data-theme-control="isFluid"', false)
        ->assertSee('data-theme-control="navbarPosition"', false);

    $defaults = file_get_contents(public_path('assets/js/modules/Core/falcon-defaults.js'));

    expect($defaults)
        ->toContain("theme: 'auto'")
        ->toContain('isFluid: true')
        ->toContain("navbarPosition: 'double-top'")
        ->toContain('initializeMissingPreferences')
        ->toContain('syncConfigDefaults');

    $config = file_get_contents(public_path('assets/js/config.js'));

    expect($config)
        ->toContain("theme: 'light'")
        ->toContain('isFluid: false')
        ->toContain("navbarPosition: 'vertical'");
});

test('dashboard navigation renders from menu config and filters permissions', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Permission::findOrCreate('users.view');

    $user = User::factory()->create();
    $user->givePermissionTo('users.view');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee(__('menu.dashboard'))
        ->assertSee(__('menu.basic_data'))
        ->assertSee(__('menu.users'))
        ->assertDontSee(__('menu.administration'))
        ->assertDontSee(__('menu.roles'))
        ->assertDontSee(__('menu.permissions'))
        ->assertDontSee(__('menu.auth_logs'))
        ->assertDontSee('href="#!"', false);
});

test('menu service marks active items and hides empty parents', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $menu = app(MenuService::class)->getMenu($user);

    expect($menu)
        ->toHaveCount(1)
        ->and($menu[0]['label'])->toBe('dashboard')
        ->and($menu[0]['active'])->toBeTrue()
        ->and($menu[0]['open'])->toBeFalse();
});

test('menu service opens basic data when a moved administration child route is active', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Route::middleware('web')->get('/_menu-test/users', fn () => response('ok'))->name('admin.users.index');

    Permission::findOrCreate('users.view');

    $user = User::factory()->create();
    $user->givePermissionTo('users.view');

    $this->actingAs($user)->get('/_menu-test/users')->assertOk();

    $menu = app(MenuService::class)->getMenu($user);
    $basicData = collect($menu)->firstWhere('label', 'basic_data');

    expect($basicData)
        ->not->toBeNull()
        ->and($basicData['active'])->toBeTrue()
        ->and($basicData['open'])->toBeTrue()
        ->and($basicData['children'][0]['label'])->toBe('users')
        ->and($basicData['children'][0]['active'])->toBeTrue();
});

test('main navigation keeps business order and nests utility pages under tools', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $permissions = [
        'companies.view',
        'item_units.view',
        'file_manager.view',
        'calendar.view',
        'my_board.view',
        'chat.view',
        'settings.pwa.view',
        'accounts.view',
        'users.view',
        'currencies.view',
        'hr.employees.view',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    $menu = app(MenuService::class)->getMenu($user);
    $topLevelLabels = collect($menu)->pluck('label')->all();
    $tools = collect($menu)->firstWhere('label', 'tools');
    $humanResources = collect($menu)->firstWhere('label', 'human_resources');

    expect($topLevelLabels)->toBe([
        'dashboard',
        'basic_data',
        'accounting_costing',
        'human_resources',
        'tools',
    ])->not->toContain('file_manager', 'calendar', 'my_board', 'chat', 'administration', 'settings');

    expect($tools)->not->toBeNull()
        ->and(collect($tools['children'])->pluck('label')->all())
        ->toContain('file_manager', 'calendar', 'my_board', 'chat', 'pwa_settings')
        ->not->toContain('temperature'.'_logs');

    expect($humanResources)->not->toBeNull()
        ->and(collect($humanResources['children'])->pluck('label')->all())->toBe(['hr_employees']);

    expect(Route::has('admin.tools.'.'temperature'.'-logs.index'))->toBeFalse();

    $this->actingAs($user)
        ->get('/admin/tools/'.'temperature'.'-logs')
        ->assertNotFound();

    app()->setLocale('ar');

    $arabicMenu = app(MenuService::class)->getMenu($user);

    expect(collect($arabicMenu)->pluck('text')->take(2)->all())->toBe([
        'لوحة التحكم',
        'البيانات الأساسية',
    ]);
});

test('tools menu appears from child permissions and opens for moved tool routes', function (string $routeName, string $permission, string $label) {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Permission::findOrCreate($permission, 'web');

    $user = User::factory()->create();
    $user->givePermissionTo($permission);

    $request = Request::create('/_menu-test/'.Str::slug($label));
    $request->setRouteResolver(fn (): Illuminate\Routing\Route => new Illuminate\Routing\Route(
        ['GET'],
        '/_menu-test/'.Str::slug($label),
        ['as' => $routeName]
    ));
    app()->instance('request', $request);

    $menu = app(MenuService::class)->getMenu($user);
    $topLevelLabels = collect($menu)->pluck('label')->all();
    $tools = collect($menu)->firstWhere('label', 'tools');

    expect($topLevelLabels)->toContain('tools')
        ->and($topLevelLabels)->not->toContain($label)
        ->and($tools)->not->toBeNull()
        ->and($tools['active'])->toBeTrue()
        ->and($tools['open'])->toBeTrue()
        ->and(collect($tools['children'])->pluck('label')->all())->toBe([$label]);
})->with([
    'file manager' => ['admin.file-manager.index', 'file_manager.view', 'file_manager'],
    'calendar' => ['admin.calendar.index', 'calendar.view', 'calendar'],
    'my board' => ['admin.my-board.index', 'my_board.view', 'my_board'],
    'chat' => ['admin.chat.index', 'chat.view', 'chat'],
    'web app settings' => ['admin.settings.pwa', 'settings.pwa.view', 'pwa_settings'],
]);

test('former administration and system audit items open their owning domains', function (string $routeName, string $permission, string $label, string $expectedDomain) {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Permission::findOrCreate($permission, 'web');

    $user = User::factory()->create();
    $user->givePermissionTo($permission);

    $request = Request::create('/_menu-test/'.Str::slug($label));
    $request->setRouteResolver(fn (): Illuminate\Routing\Route => new Illuminate\Routing\Route(
        ['GET'],
        '/_menu-test/'.Str::slug($label),
        ['as' => $routeName]
    ));
    app()->instance('request', $request);

    $menu = app(MenuService::class)->getMenu($user);
    $topLevelLabels = collect($menu)->pluck('label')->all();
    $domain = collect($menu)->firstWhere('label', $expectedDomain);

    expect($topLevelLabels)->toContain($expectedDomain)
        ->and($topLevelLabels)->not->toContain('administration', $label)
        ->and($domain)->not->toBeNull()
        ->and($domain['active'])->toBeTrue()
        ->and($domain['open'])->toBeTrue()
        ->and(collect($domain['children'])->pluck('label')->all())->toBe([$label]);
})->with([
    'users' => ['admin.users.index', 'users.view', 'users', 'basic_data'],
    'roles' => ['admin.roles.index', 'roles.view', 'roles', 'basic_data'],
    'activity logs' => ['admin.activity-logs.index', 'activity.logs.view', 'activity_logs', 'tools'],
    'auth logs' => ['admin.auth-logs.index', 'auth.logs.view', 'auth_logs', 'tools'],
    'active sessions' => ['admin.auth-sessions.index', 'auth.sessions.view', 'auth_sessions', 'tools'],
]);

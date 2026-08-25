<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Services\PlasticsDashboardService;

test('authenticated layout uses one directional theme and one user stylesheet', function () {
    $response = $this->withSession(['locale' => 'en'])
        ->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('width=device-width, initial-scale=1, viewport-fit=cover', false)
        ->assertSee('assets/css/theme.min.css', false)
        ->assertDontSee('assets/css/theme-rtl.min.css', false)
        ->assertDontSee('fonts.googleapis.com', false)
        ->assertDontSee('fonts.gstatic.com', false);

    expect(substr_count($response->getContent(), 'assets/css/user.css'))->toBe(1);
});

test('arabic layout sends only the rtl theme stylesheet', function () {
    $response = $this->withSession(['locale' => 'ar'])
        ->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('assets/css/theme-rtl.min.css', false)
        ->assertDontSee('assets/css/theme.min.css', false);

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
    $navbarPreference = file_get_contents(public_path('assets/js/modules/Core/navbar-preference.js'));
    $pwaRuntime = file_get_contents(public_path('assets/js/modules/Core/pwa-runtime.js'));
    $notificationSound = file_get_contents(public_path('assets/js/modules/Core/notification-sound.js'));
    $notifications = file_get_contents(public_path('assets/js/modules/Core/notifications.js'));
    $pushNotifications = file_get_contents(public_path('assets/js/modules/Core/push-notifications.js'));
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
        ->toContain("document.addEventListener('submit'")
        ->toContain('event.preventDefault()')
        ->and($navbarPreference)
        ->toContain("cookieName = 'erp_navbar_position'")
        ->toContain('SameSite=Lax')
        ->toContain('[data-theme-control="navbarPosition"]')
        ->and($pwaRuntime)
        ->toContain("registration.addEventListener('updatefound'")
        ->toContain("registration.waiting.postMessage({ type: 'SKIP_WAITING' })")
        ->toContain('reloadRequested')
        ->and($notificationSound)
        ->toContain("return storedValue() === '1'")
        ->toContain('if (!isEnabled() || !unlocked)')
        ->toContain('now - lastPlayedAt < throttleMs')
        ->toContain("['click', 'keydown', 'touchstart']")
        ->toContain("icon.setAttribute('class'")
        ->and($notifications)
        ->toContain('baselineReady')
        ->toContain('AppNotificationSound.play()')
        ->toContain('hiddenIntervalMs')
        ->and($pushNotifications)
        ->toContain("icon.setAttribute('class'")
        ->not->toContain('icon.className =')
        ->and($salesIndex)
        ->toContain('id="sales-cycle-table"')
        ->not->toContain('data-datatables');
});

test('dashboard schema checks reuse one column listing per table', function () {
    Schema::shouldReceive('getTableListing')
        ->once()
        ->andReturn(['public.purchase_orders']);
    Schema::shouldReceive('getColumnListing')
        ->once()
        ->with('purchase_orders')
        ->andReturn(['id', 'company_id', 'branch_id', 'status']);

    $dashboard = app(PlasticsDashboardService::class);
    $hasColumn = new ReflectionMethod($dashboard, 'hasColumn');

    expect($hasColumn->invoke($dashboard, 'purchase_orders', 'company_id'))->toBeTrue()
        ->and($hasColumn->invoke($dashboard, 'purchase_orders', 'branch_id'))->toBeTrue()
        ->and($hasColumn->invoke($dashboard, 'purchase_orders', 'financial_period_id'))->toBeFalse();
});

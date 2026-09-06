<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Modules\Auth\Models\AuthLog;
use Modules\Core\Services\InactiveSessionService;
use Modules\Core\Services\IntendedUrlService;
use Modules\Core\Services\LocalePreferenceService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Cookie;

test('session status reports active session without refreshing activity', function () {
    config(['session.lifetime' => 120]);
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));

    $user = User::factory()->create();
    $lastActivityAt = now()->subMinute()->getTimestamp();

    $this->actingAs($user)
        ->withSession([InactiveSessionService::LastActivitySessionKey => $lastActivityAt])
        ->getJson('/session/status')
        ->assertOk()
        ->assertJson([
            'authenticated' => true,
            'expired' => false,
            'lifetime_seconds' => 7200,
            'server_time' => now()->getTimestamp(),
            'last_activity_at' => $lastActivityAt,
            'seconds_remaining' => 7140,
        ])
        ->assertJsonPath('session_identity', fn (mixed $identity): bool => is_string($identity) && strlen($identity) === 64);

    $this->assertAuthenticatedAs($user);
    expect(session(InactiveSessionService::LastActivitySessionKey))->toBe($lastActivityAt);
    expect(AuthLog::where('user_id', $user->id)->where('event', 'session_timeout_detected')->exists())->toBeFalse();

    Carbon::setTestNow();
});

test('session status reports expired without touching activity', function () {
    config(['session.lifetime' => 1]);
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));

    $user = User::factory()->create();
    $lastActivityAt = now()->subMinutes(2)->getTimestamp();

    $this->actingAs($user)
        ->withSession([InactiveSessionService::LastActivitySessionKey => $lastActivityAt])
        ->getJson('/session/status')
        ->assertOk()
        ->assertJson([
            'authenticated' => false,
            'expired' => true,
            'lifetime_seconds' => 60,
            'server_time' => now()->getTimestamp(),
            'seconds_remaining' => 0,
        ]);

    expect(session(InactiveSessionService::LastActivitySessionKey))->toBe($lastActivityAt);
    expect(AuthLog::where('user_id', $user->id)->where('event', 'session_timeout_detected')->exists())->toBeTrue();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'session_timeout_redirect_to_login')->exists())->toBeTrue();

    Carbon::setTestNow();
});

test('session touch extends authenticated activity', function () {
    config(['session.lifetime' => 1]);
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([InactiveSessionService::LastActivitySessionKey => now()->subSeconds(30)->getTimestamp()])
        ->postJson('/session/touch')
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'server_time' => now()->getTimestamp(),
            'lifetime_seconds' => 60,
            'expires_at' => now()->addMinute()->getTimestamp(),
        ]);

    expect(session(InactiveSessionService::LastActivitySessionKey))->toBe(now()->getTimestamp());

    Carbon::setTestNow();
});

test('inactive html requests redirect to clean login and preserve intended in session', function () {
    config(['session.lifetime' => 1]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([InactiveSessionService::LastActivitySessionKey => now()->subMinutes(2)->getTimestamp()])
        ->get('/dashboard?tab=overview')
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('auth_session_expired', true)
        ->assertSessionHas('url.intended', '/dashboard?tab=overview')
        ->assertCookie(IntendedUrlService::CookieName);

    $this->assertGuest();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'session_timeout_detected')->exists())->toBeTrue();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'logout_forced_by_timeout')->exists())->toBeTrue();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'session_timeout_redirect_to_login')->exists())->toBeTrue();
});

test('login shows expired flash message and returns to sanitized intended url', function () {
    $user = User::factory()->create();

    $this->withSession([
        'auth_session_expired' => true,
        'url.intended' => url('/admin/roles'),
    ])->get('/login')
        ->assertOk()
        ->assertSee(__('auth.session.expired'));

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/admin/roles');
});

test('external intended urls are rejected after login', function () {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => 'https://example.com/admin'])
        ->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
});

test('technical intended session urls are rejected after login', function (string $unsafeIntended) {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => $unsafeIntended])
        ->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
})->with([
    'legacy service worker' => ['/service-worker.js'],
    'service worker' => ['/pwa-service-worker.js'],
    'versioned pwa script' => ['/pwa-cache-v1.js'],
    'manifest' => ['/manifest.webmanifest'],
    'asset script' => ['/assets/js/modules/Core/pwa-settings.js'],
    'csrf endpoint' => ['/auth/csrf-token'],
    'notification poll' => ['/admin/notifications/poll'],
    'file preview' => ['/admin/file-manager/files/AF-0001/preview'],
]);

test('inactive json requests receive unauthorized json without login urls', function () {
    config(['session.lifetime' => 1]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([InactiveSessionService::LastActivitySessionKey => now()->subMinutes(2)->getTimestamp()])
        ->getJson('/dashboard?tab=overview')
        ->assertUnauthorized()
        ->assertJson([
            'authenticated' => false,
            'expired' => true,
            'message' => __('auth.session.expired'),
        ])
        ->assertJsonMissingPath('login_url');

    $this->assertGuest();
});

test('expired ajax flow reload can still show login message through auth redirect', function () {
    config(['session.lifetime' => 1]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([InactiveSessionService::LastActivitySessionKey => now()->subMinutes(2)->getTimestamp()])
        ->getJson('/dashboard')
        ->assertUnauthorized()
        ->assertSessionHas('auth_session_expired', true);

    $this->get('/dashboard')
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('url.intended', '/dashboard')
        ->assertCookie(IntendedUrlService::CookieName);

    $this->get('/login')
        ->assertOk()
        ->assertSee(__('auth.session.expired'));
});

test('locale survives inactive session expiry and login', function () {
    config(['session.lifetime' => 1]);

    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->get(route('lang.switch', ['locale' => 'en']))
        ->assertRedirect();

    expect($user->fresh()->locale)->toBe('en');

    $this->actingAs($user)
        ->withSession([
            'locale' => 'en',
            InactiveSessionService::LastActivitySessionKey => now()->subMinutes(2)->getTimestamp(),
        ])
        ->get('/dashboard')
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('locale', 'en')
        ->assertCookie(LocalePreferenceService::CookieName);

    $this->get('/login')
        ->assertOk()
        ->assertSee('dir="ltr"', false)
        ->assertSee(Lang::get('auth.login.title', [], 'en'));

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('dir="ltr"', false);
});

test('intended cookie restores protected page after login page session expires', function () {
    config(['session.lifetime' => 1]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('roles.view');

    $user = User::factory()->create();
    $user->givePermissionTo('roles.view');

    $expiredResponse = $this->actingAs($user)
        ->withSession([InactiveSessionService::LastActivitySessionKey => now()->subMinutes(2)->getTimestamp()])
        ->get('/admin/roles?page=2')
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('url.intended', '/admin/roles?page=2')
        ->assertCookie(IntendedUrlService::CookieName);

    $this->assertGuest();

    $intendedCookie = collect($expiredResponse->headers->getCookies())
        ->first(fn (Cookie $cookie): bool => $cookie->getName() === IntendedUrlService::CookieName);

    expect($intendedCookie)->not->toBeNull();

    $loginResponse = $this
        ->withCookie(IntendedUrlService::CookieName, $intendedCookie->getValue())
        ->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

    $loginResponse
        ->assertRedirect('/admin/roles?page=2')
        ->assertCookieExpired(IntendedUrlService::CookieName);
});

test('unsafe intended cookies are rejected after login', function (string $unsafeIntended) {
    $user = User::factory()->create();

    $this
        ->withCookie(IntendedUrlService::CookieName, $unsafeIntended)
        ->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])
        ->assertRedirect(route('dashboard', absolute: false));
})->with([
    'https url' => ['https://evil.test/admin'],
    'http url' => ['http://evil.test/admin'],
    'scheme relative url' => ['//evil.test/admin'],
    'javascript url' => ['javascript:alert(1)'],
    'legacy service worker' => ['/service-worker.js'],
    'service worker' => ['/pwa-service-worker.js'],
    'versioned pwa script' => ['/pwa-cache-v1.js'],
    'manifest' => ['/manifest.webmanifest'],
    'asset script' => ['/assets/js/modules/Core/pwa-settings.js'],
    'csrf endpoint' => ['/auth/csrf-token'],
    'notification poll' => ['/admin/notifications/poll'],
    'file preview' => ['/admin/file-manager/files/AF-0001/preview'],
]);

test('session timeout watcher loads only on authenticated layout with clean endpoints', function () {
    $user = User::factory()->create();
    $script = file_get_contents(public_path('assets/js/modules/Core/session-timeout.js'));

    expect($script)
        ->toContain('/session/status')
        ->toContain('/session/touch')
        ->toContain('isCheckingStatus')
        ->toContain('statusCheckDebounceMilliseconds')
        ->toContain("window.addEventListener('erp:bfcache-restore'")
        ->toContain('window.location.href = window.location.href')
        ->not->toContain('intended=')
        ->not->toContain('expired=')
        ->not->toContain('/session/check');

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('assets/js/modules/Core/session-timeout.js', false);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('assets/js/modules/Core/session-timeout.js', false)
        ->assertSee('\\/session\\/status', false)
        ->assertSee('\\/session\\/touch', false);
});

test('back forward cache guard clears password fields and revalidates without forcing a reload', function () {
    $script = file_get_contents(public_path('assets/js/modules/Core/page-cache-guard.js'));

    expect($script)
        ->toContain('clearPasswordFields()')
        ->toContain("new Event('erp:bfcache-restore')")
        ->not->toContain('window.location.reload()')
        ->not->toContain('sessionStorage');
});

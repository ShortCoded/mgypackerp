<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\AuthLog;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Auth\Services\LockScreenService;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Services\InactiveSessionService;
use Modules\Core\Services\SafeRedirectUrlService;
use Spatie\Activitylog\Models\Activity;

function validRememberCookieValueFor(User $user): string
{
    $user->setRememberToken(Str::random(60));
    $user->save();

    return $user->getKey().'|'.$user->getRememberToken().'|'.$user->password;
}

function authActivityLogIsEmptyForLockScreen(): bool
{
    return Activity::query()
        ->where('module', 'auth')
        ->doesntExist();
}

test('safe redirect url service only allows local relative urls', function (?string $url, ?string $expected) {
    $safeUrls = app(SafeRedirectUrlService::class);

    expect($safeUrls->sanitize($url))->toBe($expected)
        ->and($safeUrls->isSafe($url))->toBe($expected !== null);
})->with([
    'dashboard' => ['/dashboard', '/dashboard'],
    'query' => ['/admin/roles?page=2', '/admin/roles?page=2'],
    'fragment' => ['/admin/roles#section', '/admin/roles#section'],
    'https url' => ['https://evil.test/admin', null],
    'http url' => ['http://evil.test/admin', null],
    'scheme relative url' => ['//evil.test/admin', null],
    'javascript url' => ['javascript:alert(1)', null],
    'data url' => ['data:text/html,evil', null],
    'null url' => [null, null],
]);

test('safe intended url guard only allows authenticated document pages', function (?string $url, ?string $expected) {
    $safeUrls = app(SafeRedirectUrlService::class);

    expect($safeUrls->sanitizeIntended($url))->toBe($expected)
        ->and($safeUrls->isSafeIntendedUrl($url))->toBe($expected !== null)
        ->and($safeUrls->isSafePostUnlockUrl($url))->toBe($expected !== null);
})->with([
    'dashboard' => ['/dashboard', '/dashboard'],
    'dashboard query' => ['/dashboard?tab=overview', '/dashboard?tab=overview'],
    'admin page' => ['/admin/roles?page=2#section', '/admin/roles?page=2#section'],
    'profile page' => ['/profile', '/profile'],
    'service worker' => ['/pwa-service-worker.js', null],
    'versioned pwa script' => ['/pwa-cache-v1.js', null],
    'manifest' => ['/manifest.webmanifest', null],
    'csrf endpoint' => ['/auth/csrf-token', null],
    'session status' => ['/session/status', null],
    'notification poll' => ['/admin/notifications/poll', null],
    'data endpoint' => ['/admin/roles/data', null],
    'asset script' => ['/assets/js/modules/Core/pwa-settings.js', null],
    'vendor script' => ['/vendors/jquery/jquery.min.js', null],
    'public icon' => ['/storage/pwa/icons/icon.png', null],
    'file preview' => ['/admin/file-manager/files/AF-0001/preview', null],
    'public archive download' => ['/public/archive/files/0123456789012345678901234567890123456789/download', null],
    'login page' => ['/login', null],
    'lock screen' => ['/lock-screen', null],
    'external url' => ['https://evil.test/admin', null],
    'scheme relative url' => ['//evil.test/admin', null],
]);

test('guest lock screen browser request redirects to login without raw json', function () {
    $response = $this
        ->withHeader('Accept', 'application/json')
        ->get(route('lock-screen.show'));

    $response
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('auth_error', __('auth.session.expired_sign_in_again'));

    expect($response->headers->get('content-type') ?? '')->not->toContain('application/json')
        ->and($response->getContent())->not->toContain('Unauthenticated');
});

test('guest lock screen html request redirects to login with expired session message', function () {
    $this
        ->withHeader('Accept', 'text/html')
        ->get(route('lock-screen.show'))
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('auth_error', __('auth.session.expired_sign_in_again'));
});

test('guest lock screen ajax request returns json unauthenticated response', function () {
    $this
        ->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->get(route('lock-screen.show'))
        ->assertUnauthorized()
        ->assertJson([
            'success' => false,
            'message' => __('auth.session.expired_sign_in_again'),
            'redirect' => route('login', [], false),
        ]);
});

test('expired ajax lock screen unlock returns clean redirect json', function () {
    $response = $this
        ->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->post(route('lock-screen.unlock'), [
            'password' => 'password',
        ]);

    $response
        ->assertUnauthorized()
        ->assertJson([
            'success' => false,
            'message' => __('auth.session.expired_sign_in_again'),
            'redirect' => route('login', [], false),
            'redirect_url' => route('login', [], false),
        ]);

    expect($response->getContent())->not->toContain('Unauthenticated');
});

test('expired normal lock screen unlock redirects to login with message', function () {
    $response = $this
        ->withHeader('Accept', 'text/html')
        ->post(route('lock-screen.unlock'), [
            'password' => 'password',
        ]);

    $response
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('auth_error', __('auth.session.expired_sign_in_again'));

    expect($response->headers->get('content-type') ?? '')->not->toContain('application/json')
        ->and($response->getContent())->not->toContain('Unauthenticated');
});

test('authenticated locked users can open lock screen page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([LockScreenService::LockedSessionKey => true])
        ->get(route('lock-screen.show'))
        ->assertOk()
        ->assertViewIs('modules.auth.lock-screen')
        ->assertSee(__('auth.lock_screen.title'));
});

test('ajax protected requests still return json unauthenticated responses', function () {
    $this
        ->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->get('/dashboard')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

test('manual lock screen blocks protected pages until password unlock', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('lock-screen.store'), [
            'return_url' => '/dashboard',
        ])
        ->assertRedirect(route('lock-screen.show', absolute: false))
        ->assertSessionHas(LockScreenService::LockedSessionKey, true)
        ->assertSessionHas(LockScreenService::ReturnUrlSessionKey, '/dashboard')
        ->assertCookie(LockScreenService::ReturnUrlCookieName);

    expect(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_manual')->exists())->toBeTrue();

    $this->get('/dashboard')
        ->assertRedirect(route('lock-screen.show', absolute: false));

    $this->getJson('/dashboard')
        ->assertStatus(423)
        ->assertJson([
            'authenticated' => true,
            'locked' => true,
            'action' => 'lock',
            'lock_screen_url' => route('lock-screen.show', [], false),
        ]);

    $this->withHeader('X-Current-Path', '/admin/roles#table')
        ->getJson('/dashboard')
        ->assertStatus(423)
        ->assertSessionHas(LockScreenService::ReturnUrlSessionKey, '/admin/roles#table');

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'wrong-password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    $this->assertTrue((bool) session(LockScreenService::LockedSessionKey));
    expect(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_unlock_failed')->where('failure_reason', 'invalid_password')->exists())->toBeTrue();

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'password',
    ])->assertOk()
        ->assertJson([
            'success' => true,
            'redirect' => '/admin/roles#table',
        ])
        ->assertCookieExpired(LockScreenService::ReturnUrlCookieName);

    $this->assertFalse((bool) session(LockScreenService::LockedSessionKey));
    $this->assertTrue((bool) session(LockScreenService::UnlockedSessionKey));
    expect(session(InactiveSessionService::LastActivitySessionKey))->not->toBeNull();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_unlock_success')->exists())->toBeTrue();
    expect(authActivityLogIsEmptyForLockScreen())->toBeTrue();
});

test('manual lock preserves client return url field with fragment', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('lock-screen.store'), [
            'return_url' => '/admin/roles?page=2#roles-table',
        ])
        ->assertRedirect(route('lock-screen.show', absolute: false))
        ->assertSessionHas(LockScreenService::ReturnUrlSessionKey, '/admin/roles?page=2#roles-table')
        ->assertCookie(LockScreenService::ReturnUrlCookieName);
});

test('remembered users are locked before protected app access', function () {
    $user = User::factory()->create();
    $recallerName = Auth::guard('web')->getRecallerName();
    $rememberCookieValue = validRememberCookieValueFor($user);

    $this->flushSession();

    $this
        ->withCookie($recallerName, $rememberCookieValue)
        ->withCookie(config('session.cookie'), 'fresh-session')
        ->get('/dashboard')
        ->assertRedirect(route('lock-screen.show', absolute: false))
        ->assertSessionHas(LockScreenService::LockedSessionKey, true)
        ->assertSessionHas(LockScreenService::ReturnUrlSessionKey, '/dashboard')
        ->assertCookie(LockScreenService::ReturnUrlCookieName);

    expect(AuthLog::where('user_id', $user->id)->where('event', 'remember_me_restored')->exists())->toBeTrue();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'remembered_user_forced_to_lock_screen')->exists())->toBeTrue();

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'password',
    ])->assertOk()
        ->assertJson([
            'success' => true,
            'redirect' => '/dashboard',
        ]);
});

test('remembered users are blocked when another active presence session exists', function () {
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $user = User::factory()->create();
    $recallerName = Auth::guard('web')->getRecallerName();
    $rememberCookieValue = validRememberCookieValueFor($user);

    UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'remember-active-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'last_seen_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    $this->flushSession();

    $this
        ->withCookie($recallerName, $rememberCookieValue)
        ->withCookie(config('session.cookie'), 'fresh-session')
        ->get('/dashboard')
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('auth_error', __('auth.messages.already_logged_in'))
        ->assertCookieExpired($recallerName);

    $this->assertGuest();

    $authLog = AuthLog::where('user_id', $user->id)
        ->where('event', 'login_blocked_already_online')
        ->firstOrFail();

    expect($authLog->status)->toBe('blocked')
        ->and($authLog->remember_me)->toBeTrue()
        ->and($authLog->failure_reason)->toBe('already_online');
});

test('remembered users ignore stale presence sessions until scheduled cleanup', function () {
    config([
        'presence.duplicate_login_active_threshold_seconds' => 120,
        'presence.offline_threshold_seconds' => 180,
    ]);

    $user = User::factory()->create();
    $recallerName = Auth::guard('web')->getRecallerName();
    $rememberCookieValue = validRememberCookieValueFor($user);

    $stalePresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'remember-stale-fingerprint',
        'status' => UserPresenceService::StatusLocked,
        'last_seen_at' => now()->subSeconds(121),
        'last_activity_at' => now()->subSeconds(121),
        'expires_at' => now()->addHour(),
    ]);

    $this->flushSession();

    $this
        ->withCookie($recallerName, $rememberCookieValue)
        ->withCookie(config('session.cookie'), 'fresh-session')
        ->get('/dashboard')
        ->assertRedirect(route('lock-screen.show', absolute: false))
        ->assertSessionHas(LockScreenService::LockedSessionKey, true)
        ->assertSessionHas(LockScreenService::ReturnUrlSessionKey, '/dashboard');

    expect($stalePresence->fresh()->status)->toBe(UserPresenceService::StatusLocked)
        ->and($stalePresence->fresh()->offline_reason)->toBeNull()
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'login_blocked_already_online')->exists())->toBeFalse()
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'remember_me_restored')->exists())->toBeTrue()
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'remembered_user_forced_to_lock_screen')->exists())->toBeTrue();
});

test('lock screen unlock is blocked when another fresh presence session exists', function (string $status) {
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $plainPassword = 'unlock-secret-123';
    $user = User::factory()->create([
        'password' => Hash::make($plainPassword),
    ]);

    $otherPresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'fresh-unlock-'.$status.'-fingerprint',
        'status' => $status,
        'last_seen_at' => now()->subSeconds(30),
        'last_activity_at' => now()->subSeconds(30),
        'expires_at' => now()->addHour(),
    ]);

    $message = __('auth.messages.already_logged_in');

    $response = $this->actingAs($user)
        ->withSession([
            LockScreenService::LockedSessionKey => true,
            LockScreenService::ReturnUrlSessionKey => '/dashboard',
        ])
        ->postJson(route('lock-screen.unlock'), [
            'password' => $plainPassword,
        ]);

    $response->assertUnprocessable()
        ->assertJson([
            'success' => false,
            'message' => $message,
            'errors' => [
                'password' => [$message],
            ],
        ])
        ->assertJsonValidationErrors(['password'])
        ->assertCookieExpired(LockScreenService::ReturnUrlCookieName);

    $this->assertGuest();

    $followUp = $this->get(route('lock-screen.show'));
    $followUp->assertRedirect(route('login', [], false));

    expect($followUp->headers->get('content-type') ?? '')->not->toContain('application/json')
        ->and($followUp->getContent())->not->toContain('Unauthenticated');

    $authLog = AuthLog::where('user_id', $user->id)
        ->where('event', 'lock_screen_unlock_blocked_already_online')
        ->firstOrFail();
    $encodedLog = json_encode($authLog->toArray());

    expect($authLog->status)->toBe('blocked')
        ->and($authLog->failure_reason)->toBe('already_online')
        ->and($authLog->login)->toBe($user->email)
        ->and($authLog->context['active_sessions_count'])->toBe(1)
        ->and($authLog->payload_summary['fields'])->not->toContain('password')
        ->and($encodedLog)->not->toContain($plainPassword)
        ->and(session(LockScreenService::UnlockedSessionKey))->toBeNull()
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_unlock_success')->exists())->toBeFalse()
        ->and(UserPresenceSession::where('user_id', $user->id)
            ->where('session_fingerprint', '!=', $otherPresence->session_fingerprint)
            ->where('status', UserPresenceService::StatusOnline)
            ->exists())->toBeFalse()
        ->and(authActivityLogIsEmptyForLockScreen())->toBeTrue()
        ->and($response->getContent())->not->toContain($otherPresence->session_fingerprint)
        ->and($response->getContent())->not->toContain('"id":'.$otherPresence->getKey());
})->with([
    'online' => [UserPresenceService::StatusOnline],
    'idle' => [UserPresenceService::StatusIdle],
    'locked' => [UserPresenceService::StatusLocked],
]);

test('lock screen unlock ignores stale presence sessions until scheduled cleanup', function (string $status) {
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $user = User::factory()->create();

    $stalePresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'stale-unlock-'.$status.'-fingerprint',
        'status' => $status,
        'last_seen_at' => now()->subSeconds(121),
        'last_activity_at' => now()->subSeconds(121),
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->withSession([
            LockScreenService::LockedSessionKey => true,
            LockScreenService::ReturnUrlSessionKey => '/dashboard',
        ])
        ->postJson(route('lock-screen.unlock'), [
            'password' => 'password',
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'redirect' => '/dashboard',
        ]);

    $this->assertAuthenticatedAs($user);

    expect($stalePresence->fresh()->status)->toBe($status)
        ->and($stalePresence->fresh()->offline_reason)->toBeNull()
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_unlock_blocked_already_online')->exists())->toBeFalse()
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_unlock_success')->exists())->toBeTrue();
})->with([
    'online' => [UserPresenceService::StatusOnline],
    'locked' => [UserPresenceService::StatusLocked],
]);

test('lock screen unlock is allowed when another presence session is offline', function () {
    $user = User::factory()->create();

    $offlinePresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'offline-unlock-fingerprint',
        'status' => UserPresenceService::StatusOffline,
        'last_seen_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHour(),
        'offline_reason' => UserPresenceService::ReasonLogout,
    ]);

    $this->actingAs($user)
        ->withSession([
            LockScreenService::LockedSessionKey => true,
            LockScreenService::ReturnUrlSessionKey => '/dashboard',
        ])
        ->postJson(route('lock-screen.unlock'), [
            'password' => 'password',
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'redirect' => '/dashboard',
        ]);

    $this->assertAuthenticatedAs($user);

    expect($offlinePresence->fresh()->status)->toBe(UserPresenceService::StatusOffline)
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_unlock_blocked_already_online')->exists())->toBeFalse();
});

test('remembered inactive users are logged out before lock screen access', function () {
    $user = User::factory()->create();
    $recallerName = Auth::guard('web')->getRecallerName();
    $rememberCookieValue = validRememberCookieValueFor($user);

    $user->forceFill(['status' => 'blocked'])->save();

    $this->flushSession();

    $this
        ->withCookie($recallerName, $rememberCookieValue)
        ->withCookie(config('session.cookie'), 'fresh-session')
        ->get('/dashboard')
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('auth_error', __('auth.messages.account_blocked'))
        ->assertCookieExpired($recallerName);

    $this->assertGuest();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'remembered_user_rejected_blocked')->exists())->toBeTrue();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'logout_forced_by_account_status')->exists())->toBeTrue();
});

test('remembered soft deleted users are logged out before protected access', function () {
    $user = User::factory()->create();
    $recallerName = Auth::guard('web')->getRecallerName();
    $rememberCookieValue = validRememberCookieValueFor($user);

    $user->delete();

    $this->flushSession();

    $this
        ->withCookie($recallerName, $rememberCookieValue)
        ->withCookie(config('session.cookie'), 'fresh-session')
        ->get('/dashboard')
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('auth_error', __('auth.messages.account_deleted'))
        ->assertCookieExpired($recallerName);

    $this->assertGuest();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'remembered_user_rejected_deleted')->exists())->toBeTrue();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'logout_forced_by_account_status')->exists())->toBeTrue();
});

test('lock screen unlock logs out accounts that became unavailable while locked', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([LockScreenService::LockedSessionKey => true])
        ->get(route('lock-screen.show'))
        ->assertOk();

    $user->delete();

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'password',
    ])->assertUnauthorized()
        ->assertJson([
            'success' => false,
            'message' => __('auth.messages.account_deleted'),
            'redirect' => route('login', [], false),
        ]);

    $this->assertGuest();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_unlock_blocked_deleted_user')->where('status', 'blocked')->exists())->toBeTrue();
});

test('session status locks expired remembered sessions instead of allowing direct access', function () {
    config(['session.lifetime' => 1]);

    $user = User::factory()->create();
    $recallerName = Auth::guard('web')->getRecallerName();
    $rememberCookieValue = validRememberCookieValueFor($user);

    $this
        ->actingAs($user)
        ->withCookie($recallerName, $rememberCookieValue)
        ->withSession([InactiveSessionService::LastActivitySessionKey => now()->subMinutes(2)->getTimestamp()])
        ->withHeader('X-Current-Path', '/admin/roles?page=2#roles-table')
        ->withCredentials()
        ->getJson(route('session.status'))
        ->assertOk()
        ->assertJson([
            'authenticated' => true,
            'locked' => true,
            'expired' => true,
            'via_remember' => true,
            'action' => 'lock',
            'lock_screen_url' => route('lock-screen.show', [], false),
        ])
        ->assertSessionHas(LockScreenService::LockedSessionKey, true)
        ->assertSessionHas(LockScreenService::ReturnUrlSessionKey, '/admin/roles?page=2#roles-table')
        ->assertCookie(LockScreenService::ReturnUrlCookieName);

    expect(AuthLog::where('user_id', $user->id)->where('event', 'session_timeout_detected')->exists())->toBeTrue();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_timeout')->exists())->toBeTrue();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'session_timeout_redirect_to_lock_screen')->exists())->toBeTrue();
});

test('locked requests discard technical current paths and unlock uses a safe app page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([LockScreenService::LockedSessionKey => true])
        ->withHeader('X-Current-Path', '/pwa-service-worker.js')
        ->getJson('/dashboard')
        ->assertStatus(423)
        ->assertSessionHas(LockScreenService::LockedSessionKey, true)
        ->assertSessionHas(LockScreenService::ReturnUrlSessionKey, '/dashboard');

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'password',
    ])->assertOk()
        ->assertJson([
            'success' => true,
            'redirect' => route('dashboard', [], false),
        ])
        ->assertJsonStructure(['csrf_token'])
        ->assertCookieExpired(LockScreenService::ReturnUrlCookieName);
});

test('lock return cookie restores destination if lock screen session loses return url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([LockScreenService::LockedSessionKey => true])
        ->withCookie(LockScreenService::ReturnUrlCookieName, '/admin/roles?page=2#section')
        ->get(route('lock-screen.show'))
        ->assertOk()
        ->assertSessionHas(LockScreenService::ReturnUrlSessionKey, '/admin/roles?page=2#section');

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'password',
    ])->assertOk()
        ->assertJson([
            'redirect' => '/admin/roles?page=2#section',
        ])
        ->assertCookieExpired(LockScreenService::ReturnUrlCookieName);
});

test('unlocked users opening lock screen return to safe target and clear lock cookie', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([LockScreenService::ReturnUrlSessionKey => '/admin/roles'])
        ->get(route('lock-screen.show'))
        ->assertRedirect('/admin/roles')
        ->assertCookieExpired(LockScreenService::ReturnUrlCookieName)
        ->assertSessionMissing(LockScreenService::ReturnUrlSessionKey);
});

test('unsafe lock return urls are rejected and fall back to dashboard', function (string $unsafeUrl) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('lock-screen.store'), [
            'return_url' => $unsafeUrl,
        ])
        ->assertRedirect(route('lock-screen.show', absolute: false))
        ->assertSessionMissing(LockScreenService::ReturnUrlSessionKey);

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'password',
    ])->assertOk()
        ->assertJson([
            'redirect' => route('dashboard', [], false),
        ]);
})->with([
    'https url' => ['https://evil.test/admin'],
    'http url' => ['http://evil.test/admin'],
    'scheme relative url' => ['//evil.test/admin'],
    'javascript url' => ['javascript:alert(1)'],
    'data url' => ['data:text/html,evil'],
    'service worker' => ['/pwa-service-worker.js'],
    'versioned pwa script' => ['/pwa-cache-v1.js'],
    'manifest' => ['/manifest.webmanifest'],
    'asset script' => ['/assets/js/modules/Core/pwa-settings.js'],
    'csrf endpoint' => ['/auth/csrf-token'],
    'notification poll' => ['/admin/notifications/poll'],
    'file preview' => ['/admin/file-manager/files/AF-0001/preview'],
]);

test('normal login clears stale lock return cookie without using it as intended url', function () {
    $user = User::factory()->create();

    $this->withCookie(LockScreenService::ReturnUrlCookieName, '/admin/roles')
        ->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])
        ->assertRedirect(route('dashboard', [], false))
        ->assertCookieExpired(LockScreenService::ReturnUrlCookieName);
});

test('lock screen renders localized user card and javascript', function () {
    $user = User::factory()->create([
        'name' => 'Jane Admin',
        'email' => 'jane@example.com',
    ]);

    $this->actingAs($user)
        ->withSession([LockScreenService::LockedSessionKey => true])
        ->get(route('lock-screen.show'))
        ->assertOk()
        ->assertSee(__('auth.lock_screen.title'))
        ->assertSee(__('auth.lock_screen.instructions'))
        ->assertSee('Jane Admin')
        ->assertSee('jane@example.com')
        ->assertSee('assets/js/modules/Core/falcon-defaults.js', false)
        ->assertSee('window.ErpFalconDefaults.apply();', false)
        ->assertSee('assets/js/modules/Auth/lock-screen.js', false)
        ->assertDontSee('Emma');

    expect(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_opened')->exists())->toBeTrue();
});

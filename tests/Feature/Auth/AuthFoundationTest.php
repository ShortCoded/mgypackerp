<?php

use App\Models\User;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Modules\Auth\Models\AuthLog;
use Modules\Auth\Models\MailConfiguration;
use Modules\Auth\Notifications\QueuedResetPasswordNotification;
use Modules\Core\Services\LocalePreferenceService;
use Spatie\Activitylog\Models\Activity;

function createActiveMailConfiguration(): MailConfiguration
{
    return MailConfiguration::create([
        'name' => 'Testing SMTP',
        'mailer' => 'smtp',
        'host' => '127.0.0.1',
        'port' => 2525,
        'from_address' => 'noreply@example.com',
        'from_name' => 'ERP',
        'is_active' => true,
    ]);
}

function authActivityLogIsEmpty(): bool
{
    return Activity::query()
        ->where('module', 'auth')
        ->doesntExist();
}

test('login screen renders with localized direction', function () {
    $this->withSession(['locale' => 'en'])
        ->get('/login')
        ->assertOk()
        ->assertHeader('Pragma', 'no-cache')
        ->assertHeader('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT')
        ->assertSee('dir="ltr"', false)
        ->assertSee('assets/js/modules/Core/client-context.js', false)
        ->assertSee('assets/js/modules/Auth/helpers.js', false)
        ->assertSee('assets/js/modules/Auth/ajax.js', false)
        ->assertSee('assets/js/modules/Core/page-cache-guard.js', false)
        ->assertDontSee('assets/js/custom/auth-ajax.js', false)
        ->assertDontSee('assets/js/custom/auth.js', false);

    expect($this->withSession(['locale' => 'en'])->get('/login')->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('no-cache')
        ->toContain('must-revalidate')
        ->toContain('max-age=0');

    $this->withSession(['locale' => 'ar'])
        ->get('/login')
        ->assertOk()
        ->assertSee('dir="rtl"', false);
});

test('arabic is the default locale before and after login until the user changes it', function () {
    $user = User::factory()->create(['locale' => null]);

    $this->get('/login')
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(Lang::get('auth.login.title', [], 'ar'));

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(Lang::get('dashboard.title', [], 'ar'));

    expect($user->fresh()->locale)->toBeNull();
});

test('authenticated language switch persists preference and logs activity', function () {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->get(route('lang.switch', ['locale' => 'en']))
        ->assertRedirect()
        ->assertSessionHas('locale', 'en')
        ->assertCookie(LocalePreferenceService::CookieName);

    $activity = Activity::query()->where('action', 'language.changed')->firstOrFail();

    expect($user->fresh()->locale)->toBe('en')
        ->and($activity->module)->toBe('auth')
        ->and($activity->status)->toBe('success')
        ->and($activity->properties->get('user_doc_num'))->toBe($user->doc_num)
        ->and($activity->properties->get('username'))->toBe($user->username)
        ->and($activity->properties->get('old_locale'))->toBe('ar')
        ->and($activity->properties->get('new_locale'))->toBe('en')
        ->and($activity->properties->has('id'))->toBeFalse()
        ->and($activity->properties->has('user_id'))->toBeFalse();
});

test('auth ajax renders validation errors in alert and fields', function () {
    $helpersScript = file_get_contents(public_path('assets/js/modules/Auth/helpers.js'));
    $ajaxScript = file_get_contents(public_path('assets/js/modules/Auth/ajax.js'));

    expect($helpersScript)
        ->toContain('showValidationAlert')
        ->toContain('<ul class="mb-0 ps-3"></ul>')
        ->toContain('$list.append')
        ->not->toContain('payload.message || messages.validationSummary');

    expect($ajaxScript)
        ->toContain('helpers.validationMessages(errors)')
        ->toContain('helpers.showValidationAlert')
        ->toContain('$error.text($.isArray(errors[field]) ? (errors[field][0] || \'\') : (errors[field] || \'\'));')
        ->not->toContain('assets/js/custom/auth-ajax.js');
});

test('auth ajax refreshes csrf before submit and retries stale csrf once', function () {
    $helpersScript = file_get_contents(public_path('assets/js/modules/Auth/helpers.js'));
    $ajaxScript = file_get_contents(public_path('assets/js/modules/Auth/ajax.js'));
    $lockScreenScript = file_get_contents(public_path('assets/js/modules/Auth/lock-screen.js'));

    expect($helpersScript)
        ->toContain('function refreshCsrfToken()')
        ->toContain('X-Requested-With')
        ->toContain('function withFreshCsrf(requestFactory)')
        ->toContain('response.status === 419')
        ->toContain('messages.sessionExpiredTryAgain')
        ->not->toContain('window.location.reload();');

    expect($ajaxScript)
        ->toContain('helpers.withFreshCsrf(function ()')
        ->toContain("data('auth-submitting')")
        ->toContain('helpers.showAlert($form, \'error\', helpers.errorMessage(response))');

    expect($lockScreenScript)
        ->toContain('helpers.withFreshCsrf(function ()')
        ->toContain("data('auth-submitting')")
        ->toContain('handleFailure($form, response || { status: 419 })');
});

test('registration is inaccessible', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});

test('user can login with email phone and username', function (string $field) {
    $user = User::factory()->create();

    $this->postJson('/login', [
        'login' => $user->{$field},
        'password' => 'password',
        'remember' => true,
    ])->assertOk()
        ->assertJson([
            'success' => true,
        ]);

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
    $authLog = AuthLog::where('user_id', $user->id)->where('event', 'login_success')->where('status', 'success')->firstOrFail();

    expect($authLog->remember_me)->toBeTrue();
    expect($authLog->login)->toBe($user->{$field});
    expect(authActivityLogIsEmpty())->toBeTrue();
})->with(['email', 'phone', 'username']);

test('user can login with equivalent egyptian phone formats and unicode digits', function (string $identifier) {
    $user = User::factory()->create([
        'phone' => '+20 10 1234 5678',
    ]);

    $this->postJson('/login', [
        'login' => $identifier,
        'password' => 'password',
    ])->assertOk();

    $this->assertAuthenticatedAs($user);
})->with([
    'local' => '01012345678',
    'international with separators' => '+20-10-1234-5678',
    'international without plus' => '201012345678',
    'international access prefix' => '0020 10 1234 5678',
    'arabic indic local digits' => '٠١٠ ١٢٣٤ ٥٦٧٨',
    'persian local digits' => '۰۱۰-۱۲۳۴-۵۶۷۸',
]);

test('stored local and unicode phone formats resolve to the same egyptian mobile identity', function (string $storedPhone, string $identifier) {
    $user = User::factory()->create(['phone' => $storedPhone]);

    $this->postJson('/login', [
        'login' => $identifier,
        'password' => 'password',
    ])->assertOk();

    $this->assertAuthenticatedAs($user);
})->with([
    'stored local with spaces' => ['010 1234 5678', '+201012345678'],
    'stored arabic indic digits' => ['٠١٠-١٢٣٤-٥٦٧٨', '0020 10 1234 5678'],
]);

test('one account keeps the same identity across username email and phone login', function () {
    $user = User::factory()->create([
        'username' => 'same-account-user',
        'email' => 'same-account@example.com',
        'phone' => '+20 10 1234 5678',
    ]);

    foreach (['same-account-user', 'same-account@example.com', '01012345678'] as $identifier) {
        $this->postJson('/login', [
            'login' => $identifier,
            'password' => 'password',
            'remember' => true,
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
        expect(auth()->id())->toBe($user->getKey());

        $this->postJson('/logout')->assertOk();
        $this->assertGuest();
    }
});

test('email login ignores case', function () {
    $user = User::factory()->create(['email' => 'Login.User@Example.com']);

    $this->postJson('/login', [
        'login' => 'LOGIN.USER@EXAMPLE.COM',
        'password' => 'password',
    ])->assertOk();

    $this->assertAuthenticatedAs($user);
});

test('numeric username remains a valid username', function () {
    $user = User::factory()->create([
        'username' => '123456',
        'phone' => '+201012345678',
    ]);

    $this->postJson('/login', [
        'login' => '123456',
        'password' => 'password',
    ])->assertOk();

    $this->assertAuthenticatedAs($user);
});

test('phone login does not match a suffix or a malformed local number', function (string $identifier) {
    User::factory()->create(['phone' => '+201012345678']);

    $this->postJson('/login', [
        'login' => $identifier,
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.login.0', __('auth.messages.invalid_credentials'));

    $this->assertGuest();
})->with([
    'missing local zero' => '1012345678',
    'suffix only' => '12345678',
]);

test('ambiguous identifier across username and phone fails without selecting an account', function () {
    User::factory()->create([
        'username' => '01012345678',
        'phone' => '+201111111111',
    ]);
    User::factory()->create([
        'username' => 'different-user',
        'phone' => '+201012345678',
    ]);

    $this->postJson('/login', [
        'login' => '01012345678',
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.login.0', __('auth.messages.invalid_credentials'));

    $this->assertGuest();
});

test('duplicate normalized phone values fail safely', function () {
    User::factory()->create(['phone' => '01012345678']);
    User::factory()->create(['phone' => '+20 10 1234 5678']);

    $this->postJson('/login', [
        'login' => '00201012345678',
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.login.0', __('auth.messages.invalid_credentials'));

    $this->assertGuest();
});

test('switching identifiers cannot bypass the account login rate limit', function () {
    $user = User::factory()->create([
        'username' => 'rate-user',
        'email' => 'rate@example.com',
        'phone' => '+201012345678',
    ]);

    foreach (['rate@example.com', 'rate-user', '01012345678', '+201012345678', '00201012345678'] as $identifier) {
        $this->postJson('/login', [
            'login' => $identifier,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/login', [
        'login' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(429)
        ->assertJsonPath('error_code', 'rate_limited')
        ->assertJsonValidationErrors(['login']);
});

test('AuthLog successful login stores sanitized rich auth context', function () {
    $user = User::factory()->create();

    $this
        ->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/123.0.0.0 Safari/537.36')
        ->withHeader('X-Request-Id', 'request-123')
        ->postJson('/login', [
            'login' => $user->email,
            'password' => 'password',
            'client_context' => json_encode([
                'timezone' => 'Africa/Cairo',
                'locale' => 'ar-EG',
                'platform' => 'Linux x86_64',
                'csrf_token' => 'do-not-store',
            ]),
            'client_location' => json_encode([
                'source' => 'browser_geolocation_cached',
                'latitude' => 30.0444,
                'longitude' => 31.2357,
                'accuracy' => 25.5,
                'captured_at' => '2026-05-10T10:00:00.000Z',
                'permission_state' => 'granted',
                'cache_expires_at' => '2026-05-17T10:00:00.000Z',
                'cached' => true,
            ]),
        ])->assertOk();

    $authLog = AuthLog::where('user_id', $user->id)->where('event', 'login_success')->firstOrFail();
    $encodedLog = json_encode($authLog->toArray());

    expect($authLog->session_fingerprint)->not->toBeNull()
        ->and($authLog->session_fingerprint)->not->toBe(session()->getId())
        ->and($authLog->request_id)->toBe('request-123')
        ->and($authLog->browser_name)->toBe('Chrome')
        ->and($authLog->os_name)->toBe('Linux')
        ->and($authLog->device_type)->toBe('desktop')
        ->and($authLog->timezone)->toBe('Africa/Cairo')
        ->and($authLog->client_context['timezone'])->toBe('Africa/Cairo')
        ->and($authLog->location_context['source'])->toBe('browser_geolocation_cached')
        ->and($authLog->location_context['captured_at'])->toBe('2026-05-10T10:00:00.000Z')
        ->and($authLog->location_context['permission_state'])->toBe('granted')
        ->and($authLog->location_context['cache_expires_at'])->toBe('2026-05-17T10:00:00.000Z')
        ->and($authLog->location_context['cached'])->toBeTrue()
        ->and($authLog->latitude)->toBe(30.0444)
        ->and($authLog->longitude)->toBe(31.2357)
        ->and($encodedLog)->not->toContain('password')
        ->and($encodedLog)->not->toContain('do-not-store');
});

test('login validation failures are logged only in auth logs', function () {
    $this->postJson('/login', [
        'login' => '',
        'password' => '',
        'remember' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['login', 'password']);

    $authLog = AuthLog::where('event', 'login_failed_validation')->firstOrFail();

    expect($authLog->status)->toBe('failed');
    expect($authLog->remember_me)->toBeTrue();
    expect($authLog->failure_reason)->toBe('validation_failed');
    expect($authLog->payload_summary['fields'])->not->toContain('password');
    expect(authActivityLogIsEmpty())->toBeTrue();
});

test('username login ignores case but password does not', function () {
    $user = User::factory()->create([
        'username' => 'caseuser',
    ]);

    $this->postJson('/login', [
        'login' => 'CASEUSER',
        'password' => 'password',
    ])->assertOk();

    $this->assertAuthenticatedAs($user);

    auth()->logout();

    $this->postJson('/login', [
        'login' => 'CASEUSER',
        'password' => 'PASSWORD',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['login']);

    $this->assertGuest();
});

test('inactive and blocked users cannot login', function (string $status) {
    $user = User::factory()->create(['status' => $status]);

    $this->postJson('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['login'])
        ->assertJsonPath('errors.login.0', __('auth.messages.invalid_credentials'));

    $this->assertGuest();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'login_failed_'.$status.'_user')->where('failure_reason', $status.'_account')->exists())->toBeTrue();
    expect(authActivityLogIsEmpty())->toBeTrue();
})->with(['inactive', 'blocked']);

test('soft deleted users cannot login with email phone or username', function (string $field) {
    $user = User::factory()->create();
    $identifier = $user->{$field};

    $user->delete();

    $this->postJson('/login', [
        'login' => $identifier,
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['login'])
        ->assertJsonPath('errors.login.0', __('auth.messages.invalid_credentials'));

    $this->assertGuest();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'login_failed_deleted_user')->where('failure_reason', 'deleted_account')->exists())->toBeTrue();
})->with(['email', 'phone', 'username']);

test('inactive authenticated users are logged out from protected app requests', function () {
    $user = User::factory()->create(['status' => 'blocked']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('auth_error', __('auth.messages.account_blocked'));

    $this->assertGuest();
});

test('inactive authenticated ajax requests receive unauthorized response', function () {
    $user = User::factory()->create(['status' => 'inactive']);

    $this->actingAs($user)
        ->getJson('/dashboard')
        ->assertUnauthorized()
        ->assertJson([
            'success' => false,
            'message' => __('auth.messages.account_inactive'),
            'redirect' => route('login', [], false),
        ]);

    $this->assertGuest();
});

test('failed login is throttled and logged', function () {
    $user = User::factory()->create();

    $this->postJson('/login', [
        'login' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnprocessable();

    $this->assertGuest();
    $failedLogin = AuthLog::where('login', $user->email)->where('event', 'login_failed_invalid_credentials')->where('status', 'failed')->firstOrFail();

    expect($failedLogin->payload_summary['fields'])->not->toContain('password');
    expect(authActivityLogIsEmpty())->toBeTrue();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $this->postJson('/login', [
        'login' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(429);

    expect(AuthLog::where('login', $user->email)->where('event', 'login_throttled')->where('status', 'blocked')->exists())->toBeTrue();
});

test('AuthLog failed login stores denied geolocation safely without secrets', function () {
    $user = User::factory()->create();

    $this->postJson('/login', [
        'login' => $user->email,
        'password' => 'wrong-password',
        'client_context' => json_encode([
            'timezone' => 'Africa/Cairo',
            'secret' => 'hidden-client-secret',
        ]),
        'client_location' => json_encode([
            'source' => 'html5',
            'denied' => true,
        ]),
    ])->assertUnprocessable();

    $authLog = AuthLog::where('login', $user->email)->where('event', 'login_failed_invalid_credentials')->firstOrFail();
    $encodedLog = json_encode($authLog->toArray());

    expect($authLog->location_context['denied'])->toBeTrue()
        ->and($authLog->client_context['timezone'])->toBe('Africa/Cairo')
        ->and($encodedLog)->not->toContain('wrong-password')
        ->and($encodedLog)->not->toContain('hidden-client-secret');
});

test('logout logs activity', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/logout')
        ->assertOk()
        ->assertJson([
            'success' => true,
        ]);

    $this->assertGuest();
    expect(AuthLog::where('user_id', $user->id)->where('event', 'logout_success')->where('status', 'success')->exists())->toBeTrue();
    expect(authActivityLogIsEmpty())->toBeTrue();
});

test('forgot password returns ajax response and sends reset link', function () {
    Notification::fake();
    createActiveMailConfiguration();

    $user = User::factory()->create();

    $this->postJson('/forgot-password', [
        'email' => $user->email,
    ])->assertOk()
        ->assertJson([
            'success' => true,
        ]);

    Notification::assertSentTo($user, QueuedResetPasswordNotification::class);
    expect(AuthLog::where('user_id', $user->id)->where('event', 'forgot_password_requested_success')->exists())->toBeTrue();
    expect(authActivityLogIsEmpty())->toBeTrue();
});

test('forgot password requires active mail configuration', function () {
    $user = User::factory()->create();

    $this->postJson('/forgot-password', [
        'email' => $user->email,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect(AuthLog::where('email', $user->email)->where('failure_reason', 'mail_not_configured')->exists())->toBeTrue();
    expect(AuthLog::where('email', $user->email)->where('event', 'forgot_password_requested_failed')->exists())->toBeTrue();
    expect(authActivityLogIsEmpty())->toBeTrue();
});

test('forgot password unknown accounts are logged internally without user-facing disclosure', function () {
    Notification::fake();
    createActiveMailConfiguration();

    $this->postJson('/forgot-password', [
        'email' => 'missing@example.com',
    ])->assertOk()
        ->assertJson([
            'success' => true,
        ]);

    expect(AuthLog::where('email', 'missing@example.com')->where('event', 'forgot_password_requested_unknown_account')->where('failure_reason', 'unknown_account')->exists())->toBeTrue();
    expect(authActivityLogIsEmpty())->toBeTrue();
});

test('password can be reset through ajax', function () {
    Notification::fake();
    createActiveMailConfiguration();

    $user = User::factory()->create();

    $this->postJson('/forgot-password', [
        'email' => $user->email,
    ]);

    Notification::assertSentTo($user, QueuedResetPasswordNotification::class, function (QueuedResetPasswordNotification $notification) use ($user) {
        $this->postJson('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        return true;
    });

    $authLog = AuthLog::where('user_id', $user->id)->where('event', 'password_reset_success')->firstOrFail();

    expect($authLog->payload_summary['fields'])->not->toContain('password');
    expect($authLog->payload_summary['fields'])->not->toContain('password_confirmation');
    expect($authLog->payload_summary['fields'])->not->toContain('token');
    expect(authActivityLogIsEmpty())->toBeTrue();
});

test('invalid reset tokens are logged without storing token payload', function () {
    $user = User::factory()->create();

    $this->postJson('/reset-password', [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    $authLog = AuthLog::where('email', $user->email)->where('event', 'password_reset_token_invalid')->firstOrFail();

    expect($authLog->failure_reason)->toBe('invalid_token');
    expect($authLog->payload_summary['fields'])->not->toContain('token');
    expect($authLog->payload_summary['fields'])->not->toContain('password');
    expect(authActivityLogIsEmpty())->toBeTrue();
});

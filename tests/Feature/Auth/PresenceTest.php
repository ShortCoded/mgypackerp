<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Modules\Auth\Models\AuthLog;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Auth\Services\LockScreenService;
use Modules\Auth\Services\OnlineSeatLimitService;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Services\InactiveSessionService;
use Spatie\Activitylog\Models\Activity;

function presenceAuthActivityLogIsEmpty(): bool
{
    return Activity::query()
        ->where('module', 'auth')
        ->doesntExist();
}

function presenceValidRememberCookieValueFor(User $user): string
{
    $user->setRememberToken(Str::random(60));
    $user->save();

    return $user->getKey().'|'.$user->getRememberToken().'|'.$user->password;
}

function presenceCreateFreshSessionFor(
    User $user,
    string $fingerprint,
    ?Carbon $lastSeenAt = null,
    string $status = UserPresenceService::StatusOnline
): UserPresenceSession {
    $lastSeenAt ??= now();

    return UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => $fingerprint,
        'status' => $status,
        'last_seen_at' => $lastSeenAt,
        'last_activity_at' => $lastSeenAt,
        'expires_at' => now()->addHour(),
    ]);
}

function presenceCreateFreshOnlineUsers(int $count): void
{
    User::factory()
        ->count($count)
        ->create()
        ->each(fn (User $user, int $index) => presenceCreateFreshSessionFor($user, 'seat-user-'.$index));
}

test('login creates an online presence session without raw session id', function () {
    config(['session.lifetime' => 2]);
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));

    $user = User::factory()->create();

    $this->postJson('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $presence = UserPresenceSession::where('user_id', $user->id)->firstOrFail();

    expect($presence->status)->toBe(UserPresenceService::StatusOnline)
        ->and($presence->session_fingerprint)->not->toBeNull()
        ->and($presence->session_fingerprint)->not->toBe(session()->getId())
        ->and($presence->login_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($presence->expires_at?->toDateTimeString())->toBe(now()->addMinutes(2)->toDateTimeString());

    Carbon::setTestNow();
});

test('login succeeds when user has no active presence session', function () {
    $user = User::factory()->create();

    $this
        ->withSession(['locale' => 'en'])
        ->postJson('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertOk()
        ->assertJson([
            'success' => true,
        ]);

    $this->assertAuthenticatedAs($user);
    expect(AuthLog::where('user_id', $user->id)->where('event', 'login_blocked_already_online')->exists())->toBeFalse();
});

test('login succeeds when active online distinct users count is below max online users', function () {
    config(['erp_seats.max_online_users' => 2]);

    $alreadyOnlineUser = User::factory()->create();
    presenceCreateFreshSessionFor($alreadyOnlineUser, 'seat-distinct-one');
    presenceCreateFreshSessionFor($alreadyOnlineUser, 'seat-distinct-two');

    $loginUser = User::factory()->create();

    $this
        ->withSession(['locale' => 'en'])
        ->postJson('/login', [
            'login' => $loginUser->email,
            'password' => 'password',
        ])->assertOk();

    $this->assertAuthenticatedAs($loginUser);

    expect(UserPresenceSession::query()->where('user_id', $loginUser->id)->exists())->toBeTrue()
        ->and(AuthLog::where('user_id', $loginUser->id)->where('event', 'login_success')->exists())->toBeTrue();
});

test('login is rejected when active online distinct users count equals max online users', function () {
    config(['erp_seats.max_online_users' => 2]);

    presenceCreateFreshOnlineUsers(2);

    $loginUser = User::factory()->create();
    $message = Lang::get('auth.messages.seat_limit_reached', [], 'en');

    $this
        ->withSession(['locale' => 'en'])
        ->postJson('/login', [
            'login' => $loginUser->email,
            'password' => 'password',
        ])->assertUnprocessable()
        ->assertJson([
            'message' => $message,
            'errors' => [
                'login' => [$message],
            ],
        ])
        ->assertJsonValidationErrors(['login']);

    $this->assertGuest();

    expect(UserPresenceSession::query()->where('user_id', $loginUser->id)->exists())->toBeFalse()
        ->and(AuthLog::where('user_id', $loginUser->id)
            ->where('event', 'login_blocked_seat_limit_reached')
            ->where('status', 'blocked')
            ->where('failure_reason', 'seat_limit_reached')
            ->exists())->toBeTrue()
        ->and(AuthLog::where('user_id', $loginUser->id)->where('event', 'login_success')->exists())->toBeFalse();
});

test('login is rejected when active online distinct users count is above max online users', function () {
    config(['erp_seats.max_online_users' => 2]);

    presenceCreateFreshOnlineUsers(3);

    $loginUser = User::factory()->create();
    $message = Lang::get('auth.messages.seat_limit_reached', [], 'en');

    $this
        ->withSession(['locale' => 'en'])
        ->postJson('/login', [
            'login' => $loginUser->email,
            'password' => 'password',
        ])->assertUnprocessable()
        ->assertJsonPath('message', $message)
        ->assertJsonPath('errors.login.0', $message);

    $this->assertGuest();

    expect(UserPresenceSession::query()->where('user_id', $loginUser->id)->exists())->toBeFalse();
});

test('stale presence sessions are not counted against max online users', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));
    config([
        'erp_seats.max_online_users' => 1,
        'presence.duplicate_login_active_threshold_seconds' => 120,
    ]);

    $staleUser = User::factory()->create();
    $stalePresence = presenceCreateFreshSessionFor($staleUser, 'seat-stale-user', now()->subSeconds(121));

    $loginUser = User::factory()->create();

    $this
        ->withSession(['locale' => 'en'])
        ->postJson('/login', [
            'login' => $loginUser->email,
            'password' => 'password',
        ])->assertOk();

    $this->assertAuthenticatedAs($loginUser);

    expect($stalePresence->fresh()->status)->toBe(UserPresenceService::StatusOnline)
        ->and($stalePresence->fresh()->offline_reason)->toBeNull()
        ->and(UserPresenceSession::query()->where('user_id', $loginUser->id)->exists())->toBeTrue();

    Carbon::setTestNow();
});

test('online seat limit is read from developer config file', function () {
    config(['erp_seats.max_online_users' => 7]);

    expect(app(OnlineSeatLimitService::class)->maxOnlineUsers())->toBe(7);
});

test('locked fresh sessions count as occupied seats', function () {
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $user = User::factory()->create();

    presenceCreateFreshSessionFor(
        $user,
        'seat-locked-fresh-session',
        now()->subSeconds(10),
        UserPresenceService::StatusLocked
    );

    expect(app(OnlineSeatLimitService::class)->currentOnlineUsersCount())->toBe(1);
});

test('login ignores stale presence sessions until scheduled cleanup', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));
    config([
        'presence.duplicate_login_active_threshold_seconds' => 120,
        'presence.offline_threshold_seconds' => 180,
    ]);

    $user = User::factory()->create();

    $stalePresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'stale-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'last_seen_at' => now()->subSeconds(121),
        'last_activity_at' => now()->subSeconds(121),
        'expires_at' => now()->addHour(),
    ]);

    $staleLockedPresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'stale-locked-fingerprint',
        'status' => UserPresenceService::StatusLocked,
        'last_seen_at' => now()->subSeconds(121),
        'last_activity_at' => now()->subSeconds(121),
        'expires_at' => now()->addHour(),
    ]);

    $expiredPresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'expired-fingerprint',
        'status' => UserPresenceService::StatusLocked,
        'last_seen_at' => now()->subSeconds(30),
        'last_activity_at' => now()->subSeconds(30),
        'expires_at' => now()->subSecond(),
    ]);

    $this
        ->withSession(['locale' => 'en'])
        ->postJson('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertOk();

    $this->assertAuthenticatedAs($user);

    expect($stalePresence->fresh()->status)->toBe(UserPresenceService::StatusOnline)
        ->and($stalePresence->fresh()->offline_reason)->toBeNull()
        ->and($staleLockedPresence->fresh()->status)->toBe(UserPresenceService::StatusLocked)
        ->and($staleLockedPresence->fresh()->offline_reason)->toBeNull()
        ->and($expiredPresence->fresh()->status)->toBe(UserPresenceService::StatusLocked)
        ->and($expiredPresence->fresh()->offline_reason)->toBeNull()
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'login_blocked_already_online')->exists())->toBeFalse();

    Carbon::setTestNow();
});

test('login ignores null last seen presence session until scheduled cleanup', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $user = User::factory()->create();

    $nullLastSeenPresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'null-last-seen-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'last_seen_at' => null,
        'last_activity_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    $this
        ->withSession(['locale' => 'en'])
        ->postJson('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertOk();

    $this->assertAuthenticatedAs($user);

    expect($nullLastSeenPresence->fresh()->status)->toBe(UserPresenceService::StatusOnline)
        ->and($nullLastSeenPresence->fresh()->offline_reason)->toBeNull()
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'login_blocked_already_online')->exists())->toBeFalse();

    Carbon::setTestNow();
});

test('login is not blocked by offline presence session', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));

    $user = User::factory()->create();

    UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'offline-fingerprint',
        'status' => UserPresenceService::StatusOffline,
        'last_seen_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHour(),
        'offline_reason' => UserPresenceService::ReasonLogout,
    ]);

    $this
        ->withSession(['locale' => 'en'])
        ->postJson('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertOk();

    $this->assertAuthenticatedAs($user);

    Carbon::setTestNow();
});

test('login is blocked when same user has an active presence session', function (string $status) {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $plainPassword = 'correct-secret-123';
    $user = User::factory()->create([
        'password' => Hash::make($plainPassword),
    ]);

    $activePresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'active-'.$status.'-fingerprint',
        'status' => $status,
        'last_seen_at' => now()->subSeconds(30),
        'last_activity_at' => now()->subSeconds(30),
        'expires_at' => now()->addHour(),
    ]);

    $message = Lang::get('auth.messages.already_logged_in', [], 'en');

    $response = $this
        ->withSession(['locale' => 'en'])
        ->postJson('/login', [
            'login' => $user->email,
            'password' => $plainPassword,
            'remember' => true,
        ]);

    $response
        ->assertUnprocessable()
        ->assertJson([
            'message' => $message,
            'errors' => [
                'login' => [$message],
            ],
        ])
        ->assertJsonValidationErrors(['login']);

    $this->assertGuest();

    $authLog = AuthLog::where('user_id', $user->id)
        ->where('event', 'login_blocked_already_online')
        ->firstOrFail();
    $encodedLog = json_encode($authLog->toArray());

    expect($authLog->status)->toBe('blocked')
        ->and($authLog->failure_reason)->toBe('already_online')
        ->and($authLog->remember_me)->toBeTrue()
        ->and($authLog->login)->toBe($user->email)
        ->and($authLog->context['active_sessions_count'])->toBe(1)
        ->and($authLog->payload_summary['fields'])->not->toContain('password')
        ->and($encodedLog)->not->toContain($plainPassword)
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'login_success')->exists())->toBeFalse()
        ->and(presenceAuthActivityLogIsEmpty())->toBeTrue()
        ->and($response->getContent())->not->toContain($activePresence->session_fingerprint)
        ->and($response->getContent())->not->toContain((string) $activePresence->getKey());

    Carbon::setTestNow();
})->with([
    'online' => [UserPresenceService::StatusOnline],
    'idle' => [UserPresenceService::StatusIdle],
    'locked' => [UserPresenceService::StatusLocked],
]);

test('duplicate login message is translated to arabic', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));

    $user = User::factory()->create();

    UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'arabic-active-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'last_seen_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    $message = Lang::get('auth.messages.already_logged_in', [], 'ar');

    $this
        ->withSession(['locale' => 'ar'])
        ->postJson('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertUnprocessable()
        ->assertJsonPath('message', $message)
        ->assertJsonPath('errors.login.0', $message);

    $this->assertGuest();

    Carbon::setTestNow();
});

test('session touch updates presence activity without auth log spam', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([InactiveSessionService::LastActivitySessionKey => now()->subSeconds(30)->getTimestamp()])
        ->postJson('/session/touch')
        ->assertOk();

    $presence = UserPresenceSession::where('user_id', $user->id)->firstOrFail();

    expect($presence->status)->toBe(UserPresenceService::StatusOnline)
        ->and($presence->last_seen_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($presence->last_activity_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and(AuthLog::where('user_id', $user->id)->exists())->toBeFalse();

    Carbon::setTestNow();
});

test('lock unlock and logout update presence lifecycle', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('lock-screen.store'), [
            'return_url' => '/dashboard',
        ])
        ->assertOk();

    expect(UserPresenceSession::where('user_id', $user->id)->latest('id')->firstOrFail()->status)
        ->toBe(UserPresenceService::StatusLocked)
        ->and(app(OnlineSeatLimitService::class)->currentOnlineUsersCount())->toBe(1);

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'password',
    ])->assertOk();

    expect(UserPresenceSession::where('user_id', $user->id)->latest('id')->firstOrFail()->status)
        ->toBe(UserPresenceService::StatusOnline);

    $this->postJson('/logout')->assertOk();

    $presence = UserPresenceSession::where('user_id', $user->id)->latest('id')->firstOrFail();

    expect($presence->status)->toBe(UserPresenceService::StatusOffline)
        ->and($presence->offline_reason)->toBe(UserPresenceService::ReasonLogout)
        ->and(app(OnlineSeatLimitService::class)->currentOnlineUsersCount())->toBe(0);
});

test('locking the screen does not reduce active seat count', function () {
    config(['erp_seats.max_online_users' => 1]);

    $user = User::factory()->create();

    $this->withSession(['locale' => 'en'])
        ->postJson('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertOk();

    expect(app(OnlineSeatLimitService::class)->currentOnlineUsersCount())->toBe(1);

    $this->postJson(route('lock-screen.store'), [
        'return_url' => '/dashboard',
    ])->assertOk();

    expect(app(OnlineSeatLimitService::class)->currentOnlineUsersCount())->toBe(1)
        ->and(UserPresenceSession::where('user_id', $user->id)->latest('id')->firstOrFail()->status)
        ->toBe(UserPresenceService::StatusLocked);
});

test('user can unlock own fresh locked session when seats are full without extra presence row', function () {
    config([
        'erp_seats.max_online_users' => 1,
        'presence.duplicate_login_active_threshold_seconds' => 120,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('lock-screen.store'), [
            'return_url' => '/dashboard',
        ])
        ->assertOk();

    $lockedPresence = UserPresenceSession::where('user_id', $user->id)->firstOrFail();

    expect($lockedPresence->status)->toBe(UserPresenceService::StatusLocked)
        ->and(app(OnlineSeatLimitService::class)->currentOnlineUsersCount())->toBe(1)
        ->and(UserPresenceSession::where('user_id', $user->id)->count())->toBe(1);

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'password',
    ])->assertOk()
        ->assertJson([
            'success' => true,
            'redirect' => '/dashboard',
        ]);

    $this->assertAuthenticatedAs($user);

    expect(UserPresenceSession::where('user_id', $user->id)->count())->toBe(1)
        ->and($lockedPresence->fresh()->status)->toBe(UserPresenceService::StatusOnline)
        ->and($lockedPresence->fresh()->locked_at)->toBeNull()
        ->and($lockedPresence->fresh()->logout_at)->toBeNull()
        ->and(app(OnlineSeatLimitService::class)->currentOnlineUsersCount())->toBe(1);
});

test('stale or offline locked unlock is blocked when seats are full', function (string $status) {
    config([
        'erp_seats.max_online_users' => 1,
        'presence.duplicate_login_active_threshold_seconds' => 120,
    ]);

    $user = User::factory()->create();
    $fillerUser = User::factory()->create();
    $message = Lang::get('auth.messages.seat_limit_reached_on_unlock', [], 'en');

    $this->actingAs($user)
        ->withSession(['locale' => 'en'])
        ->postJson(route('lock-screen.store'), [
            'return_url' => '/dashboard',
        ])
        ->assertOk();

    $currentPresence = UserPresenceSession::where('user_id', $user->id)->firstOrFail();
    $currentPresence->forceFill([
        'status' => $status,
        'last_seen_at' => $status === UserPresenceService::StatusOffline ? now() : now()->subSeconds(121),
        'last_activity_at' => $status === UserPresenceService::StatusOffline ? now() : now()->subSeconds(121),
        'logout_at' => $status === UserPresenceService::StatusOffline ? now() : null,
        'offline_reason' => $status === UserPresenceService::StatusOffline ? UserPresenceService::ReasonLogout : null,
        'expires_at' => now()->addHour(),
    ])->save();
    presenceCreateFreshSessionFor($fillerUser, 'seat-filler-user');

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJson([
            'message' => $message,
            'errors' => [
                'password' => [$message],
            ],
        ])
        ->assertJsonValidationErrors(['password']);

    $this->assertAuthenticatedAs($user);
    $this->assertTrue((bool) session(LockScreenService::LockedSessionKey));

    expect(UserPresenceSession::where('user_id', $user->id)->where('status', UserPresenceService::StatusOnline)->exists())->toBeFalse()
        ->and($currentPresence->fresh()->status)->toBe($status)
        ->and(AuthLog::where('user_id', $user->id)
            ->where('event', 'lock_screen_unlock_blocked_seat_limit_reached')
            ->where('status', 'blocked')
            ->where('failure_reason', 'seat_limit_reached_on_unlock')
            ->exists())->toBeTrue()
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_unlock_success')->exists())->toBeFalse();
})->with([
    'stale locked' => [UserPresenceService::StatusLocked],
    'offline' => [UserPresenceService::StatusOffline],
]);

test('stale or offline locked unlock succeeds when a seat is available', function (string $status) {
    config([
        'erp_seats.max_online_users' => 1,
        'presence.duplicate_login_active_threshold_seconds' => 120,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('lock-screen.store'), [
            'return_url' => '/dashboard',
        ])
        ->assertOk();

    $currentPresence = UserPresenceSession::where('user_id', $user->id)->firstOrFail();
    $currentPresence->forceFill([
        'status' => $status,
        'last_seen_at' => $status === UserPresenceService::StatusOffline ? now() : now()->subSeconds(121),
        'last_activity_at' => $status === UserPresenceService::StatusOffline ? now() : now()->subSeconds(121),
        'logout_at' => $status === UserPresenceService::StatusOffline ? now() : null,
        'offline_reason' => $status === UserPresenceService::StatusOffline ? UserPresenceService::ReasonLogout : null,
        'expires_at' => now()->addHour(),
    ])->save();

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'password',
    ])->assertOk()
        ->assertJson([
            'success' => true,
            'redirect' => '/dashboard',
        ]);

    $this->assertAuthenticatedAs($user);

    expect(UserPresenceSession::where('user_id', $user->id)->where('status', UserPresenceService::StatusOnline)->count())->toBe(1)
        ->and(app(OnlineSeatLimitService::class)->currentOnlineUsersCount())->toBe(1)
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'lock_screen_unlock_success')->exists())->toBeTrue();
})->with([
    'stale locked' => [UserPresenceService::StatusLocked],
    'offline' => [UserPresenceService::StatusOffline],
]);

test('unlock is blocked when same user already has another fresh active session', function () {
    config([
        'erp_seats.max_online_users' => 5,
        'presence.duplicate_login_active_threshold_seconds' => 120,
    ]);

    $user = User::factory()->create();
    $message = Lang::get('auth.messages.already_logged_in', [], 'en');

    $this->actingAs($user)
        ->withSession(['locale' => 'en'])
        ->postJson(route('lock-screen.store'), [
            'return_url' => '/dashboard',
        ])
        ->assertOk();

    presenceCreateFreshSessionFor($user, 'same-user-other-active-seat');

    $this->postJson(route('lock-screen.unlock'), [
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJson([
            'success' => false,
            'message' => $message,
            'errors' => [
                'password' => [$message],
            ],
        ])
        ->assertJsonValidationErrors(['password']);

    $this->assertGuest();

    expect(AuthLog::where('user_id', $user->id)
        ->where('event', 'lock_screen_unlock_blocked_already_online')
        ->where('status', 'blocked')
        ->where('failure_reason', 'already_online')
        ->exists())->toBeTrue();
});

test('session status skips duplicate-session rejection without refreshing activity or creating auth log spam', function () {
    $user = User::factory()->create();
    $lastActivityAt = now()->subMinute()->getTimestamp();
    $otherPresence = presenceCreateFreshSessionFor($user, 'session-status-other-active-session');

    $this->actingAs($user)
        ->withSession([InactiveSessionService::LastActivitySessionKey => $lastActivityAt])
        ->getJson('/session/status')
        ->assertOk()
        ->assertJson([
            'authenticated' => true,
            'expired' => false,
        ]);

    expect(session(InactiveSessionService::LastActivitySessionKey))->toBe($lastActivityAt)
        ->and(AuthLog::where('user_id', $user->id)->exists())->toBeFalse()
        ->and(UserPresenceSession::where('user_id', $user->id)->count())->toBe(1)
        ->and($otherPresence->fresh()->status)->toBe(UserPresenceService::StatusOnline);
});

test('protected request succeeds when no other fresh presence session exists', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();

    $this->assertAuthenticatedAs($user);

    expect(AuthLog::where('user_id', $user->id)->where('event', 'session_blocked_already_online')->exists())
        ->toBeFalse();
});

test('presence conflict state aggregates forced logout and other active sessions in one query', function () {
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $request = Request::create('/dashboard');
    $session = app('session')->driver();
    $session->setId(Str::random(40));
    $session->start();
    $session->put('auth_presence_session_fingerprints', ['stored-session-fingerprint']);
    $session->put(LockScreenService::LockTokenSessionKey, 'current-lock-flow-token');
    $request->setLaravelSession($session);

    $presence = app(UserPresenceService::class);
    $currentSessionFingerprint = $presence->sessionFingerprint($request);
    $currentLockFlowFingerprint = $presence->lockFlowFingerprint($request);

    UserPresenceSession::create([
        'user_id' => $otherUser->id,
        'session_fingerprint' => $currentSessionFingerprint,
        'status' => UserPresenceService::StatusOffline,
        'last_seen_at' => now(),
        'last_activity_at' => now(),
        'logout_at' => now(),
        'expires_at' => now()->addHour(),
        'offline_reason' => UserPresenceService::ReasonForcedLogout,
    ]);

    presenceCreateFreshSessionFor($user, 'included-other-active-session');
    presenceCreateFreshSessionFor($user, 'stored-session-fingerprint');
    presenceCreateFreshSessionFor($user, 'same-lock-flow-session')->update([
        'context' => ['lock_flow_fingerprint' => $currentLockFlowFingerprint],
    ]);
    presenceCreateFreshSessionFor($user, 'stale-session', now()->subSeconds(121));
    presenceCreateFreshSessionFor($user, 'expired-session')->update([
        'expires_at' => now()->subSecond(),
    ]);
    presenceCreateFreshSessionFor($user, 'logged-out-session')->update([
        'logout_at' => now(),
    ]);
    presenceCreateFreshSessionFor($user, 'offline-session')->update([
        'status' => UserPresenceService::StatusOffline,
    ]);
    presenceCreateFreshSessionFor($user, 'missing-last-seen-session')->update([
        'last_seen_at' => null,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $sessionConflictState = $presence->sessionConflictStateForRequest($user, $request);
    $presenceQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'user_presence_sessions'))
        ->values();

    DB::disableQueryLog();

    expect($currentSessionFingerprint)->not->toBeNull()
        ->and($currentLockFlowFingerprint)->not->toBeNull()
        ->and($sessionConflictState)->toBe([
            'forced_logout' => true,
            'other_active_count' => 1,
        ])
        ->and($presenceQueries)->toHaveCount(1)
        ->and(strtolower($presenceQueries->sole()['query']))
        ->toContain('forced_logout')
        ->toContain('other_active_count');
});

test('session status still applies forced logout while duplicate-session checks are skipped', function () {
    $user = User::factory()->create();

    $this->mock(UserPresenceService::class, function ($presence) use ($user): void {
        $presence->shouldReceive('sessionConflictStateForRequest')
            ->once()
            ->withArgs(fn (User $candidate, Request $request, bool $includeOtherActiveSessions): bool => $candidate->is($user)
                && $request->routeIs('session.status')
                && ! $includeOtherActiveSessions)
            ->andReturn([
                'forced_logout' => true,
                'other_active_count' => 0,
            ]);
    });

    $this->actingAs($user)
        ->withSession(['locale' => 'en'])
        ->getJson('/session/status')
        ->assertUnauthorized()
        ->assertJson([
            'success' => false,
            'message' => __('auth_sessions.messages.forced_logout'),
            'redirect' => route('login', [], false),
            'redirect_url' => route('login', [], false),
        ]);

    $this->assertGuest();

    expect(AuthLog::query()
        ->where('user_id', $user->id)
        ->where('event', 'session_forced_logout_applied')
        ->where('failure_reason', UserPresenceService::ReasonForcedLogout)
        ->exists())->toBeTrue();
});

test('protected request is blocked when another fresh presence session exists', function (string $status) {
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $user = User::factory()->create();

    $otherPresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'protected-fresh-'.$status.'-fingerprint',
        'status' => $status,
        'last_seen_at' => now()->subSeconds(30),
        'last_activity_at' => now()->subSeconds(30),
        'expires_at' => now()->addHour(),
    ]);

    $recallerName = Auth::guard('web')->getRecallerName();
    $rememberCookieValue = presenceValidRememberCookieValueFor($user);
    $message = __('auth.messages.already_logged_in');

    $response = $this->actingAs($user)
        ->withCookie($recallerName, $rememberCookieValue)
        ->withSession([LockScreenService::UnlockedSessionKey => true])
        ->get('/dashboard');

    $response
        ->assertRedirect(route('login', [], false))
        ->assertSessionHas('auth_error', $message)
        ->assertCookieExpired($recallerName);

    $this->assertGuest();

    expect(session(LockScreenService::UnlockedSessionKey))->toBeNull();

    $authLog = AuthLog::where('user_id', $user->id)
        ->where('event', 'session_blocked_already_online')
        ->firstOrFail();
    $encodedLog = json_encode($authLog->toArray());

    expect($authLog->status)->toBe('blocked')
        ->and($authLog->failure_reason)->toBe('already_online')
        ->and($authLog->remember_me)->toBeTrue()
        ->and($authLog->login)->toBe($user->email)
        ->and($authLog->context['active_sessions_count'])->toBe(1)
        ->and($authLog->payload_summary['fields'])->not->toContain('password')
        ->and($encodedLog)->not->toContain($rememberCookieValue)
        ->and($response->getContent())->not->toContain($otherPresence->session_fingerprint)
        ->and($response->getContent())->not->toContain('"id":'.$otherPresence->getKey())
        ->and(presenceAuthActivityLogIsEmpty())->toBeTrue();

    $this->get('/dashboard')
        ->assertRedirect(route('login', [], false));
})->with([
    'online' => [UserPresenceService::StatusOnline],
    'idle' => [UserPresenceService::StatusIdle],
    'locked' => [UserPresenceService::StatusLocked],
]);

test('protected ajax request blocked by another fresh presence session returns clean json', function () {
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $user = User::factory()->create();

    $otherPresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'protected-json-fresh-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'last_seen_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    $message = __('auth.messages.already_logged_in');

    $response = $this->actingAs($user)
        ->getJson('/dashboard');

    $response
        ->assertUnauthorized()
        ->assertJson([
            'success' => false,
            'message' => $message,
            'redirect' => route('login', [], false),
            'redirect_url' => route('login', [], false),
        ]);

    $this->assertGuest();

    expect($response->getContent())->not->toContain($otherPresence->session_fingerprint)
        ->and($response->getContent())->not->toContain('"id":'.$otherPresence->getKey())
        ->and(AuthLog::where('user_id', $user->id)
            ->where('event', 'session_blocked_already_online')
            ->where('failure_reason', 'already_online')
            ->exists())->toBeTrue();
});

test('protected request ignores stale presence sessions until scheduled cleanup', function (string $status) {
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $user = User::factory()->create();

    $stalePresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'protected-stale-'.$status.'-fingerprint',
        'status' => $status,
        'last_seen_at' => now()->subSeconds(121),
        'last_activity_at' => now()->subSeconds(121),
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();

    $this->assertAuthenticatedAs($user);

    expect($stalePresence->fresh()->status)->toBe($status)
        ->and($stalePresence->fresh()->offline_reason)->toBeNull()
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'session_blocked_already_online')->exists())->toBeFalse();
})->with([
    'online' => [UserPresenceService::StatusOnline],
    'idle' => [UserPresenceService::StatusIdle],
    'locked' => [UserPresenceService::StatusLocked],
]);

test('protected request is allowed when another presence session is offline', function () {
    $user = User::factory()->create();

    $offlinePresence = UserPresenceSession::create([
        'user_id' => $user->id,
        'session_fingerprint' => 'protected-offline-fingerprint',
        'status' => UserPresenceService::StatusOffline,
        'last_seen_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHour(),
        'offline_reason' => UserPresenceService::ReasonLogout,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();

    $this->assertAuthenticatedAs($user);

    expect($offlinePresence->fresh()->status)->toBe(UserPresenceService::StatusOffline)
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'session_blocked_already_online')->exists())->toBeFalse();
});

test('current fresh session is not blocked by its own presence row', function () {
    $user = User::factory()->create();

    $this->withSession(['locale' => 'en'])
        ->postJson('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertOk();

    $this->assertAuthenticatedAs($user);

    $presence = UserPresenceSession::where('user_id', $user->id)->firstOrFail();

    $this->get('/dashboard')
        ->assertOk();

    $this->assertAuthenticatedAs($user);

    expect($presence->fresh()->status)->toBe(UserPresenceService::StatusOnline)
        ->and(AuthLog::where('user_id', $user->id)->where('event', 'session_blocked_already_online')->exists())->toBeFalse();
});

test('stale presence command marks idle and offline sessions', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));
    config([
        'presence.idle_threshold_seconds' => 300,
        'presence.offline_threshold_seconds' => 180,
        'presence.duplicate_login_active_threshold_seconds' => 120,
    ]);

    $idleUser = User::factory()->create();
    $offlineUser = User::factory()->create();

    $idlePresence = UserPresenceSession::create([
        'user_id' => $idleUser->id,
        'session_fingerprint' => 'idle-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'last_seen_at' => now()->subSeconds(60),
        'last_activity_at' => now()->subSeconds(301),
        'expires_at' => now()->addHour(),
    ]);

    $offlinePresence = UserPresenceSession::create([
        'user_id' => $offlineUser->id,
        'session_fingerprint' => 'offline-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'last_seen_at' => now()->subSeconds(121),
        'last_activity_at' => now()->subSeconds(121),
        'expires_at' => now()->addHour(),
    ]);

    $this->artisan('presence:mark-stale-offline')
        ->assertExitCode(0);

    expect($idlePresence->fresh()->status)->toBe(UserPresenceService::StatusIdle)
        ->and($offlinePresence->fresh()->status)->toBe(UserPresenceService::StatusOffline)
        ->and($offlinePresence->fresh()->offline_reason)->toBe(UserPresenceService::ReasonHeartbeatTimeout);

    Carbon::setTestNow();
});

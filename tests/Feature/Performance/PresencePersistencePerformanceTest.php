<?php

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\AuthLog;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Auth\Services\AuthLogService;
use Modules\Core\Services\InactiveSessionService;

test('session presence writes do not introspect the database schema', function () {
    $user = User::factory()->create();
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $this->actingAs($user)
        ->postJson('/session/touch')
        ->assertOk();

    expect(UserPresenceSession::query()->where('user_id', $user->getKey())->first())
        ->not->toBeNull()
        ->public_id->not->toBeNull();

    expect(collect($queries)->filter(
        fn (string $sql): bool => str_contains($sql, 'pragma_table')
            || str_contains($sql, 'sqlite_master')
            || str_contains($sql, 'information_schema')
            || str_contains($sql, 'pg_catalog'),
    ))->toBeEmpty();
});

test('rapid session touches extend inactivity without repeating presence persistence', function (): void {
    config(['presence.activity_touch_throttle_seconds' => 30]);
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/session/touch')
        ->assertOk();

    $firstPresenceTouch = UserPresenceSession::query()
        ->where('user_id', $user->getKey())
        ->firstOrFail()
        ->last_activity_at
        ?->toDateTimeString();

    Carbon::setTestNow(now()->addSeconds(5));

    $this->postJson('/session/touch')
        ->assertOk();

    expect(UserPresenceSession::query()->where('user_id', $user->getKey())->firstOrFail()->last_activity_at?->toDateTimeString())
        ->toBe($firstPresenceTouch)
        ->and(session(InactiveSessionService::LastActivitySessionKey))->toBe(now()->getTimestamp());

    Carbon::setTestNow();
});

test('account status middleware reuses the authenticated user loaded for the request', function (): void {
    $user = User::factory()->create();
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $this->actingAs($user)
        ->getJson('/session/status')
        ->assertOk();

    expect(collect($queries)->filter(
        fn (string $sql): bool => str_contains($sql, 'from "users"')
            && str_contains($sql, 'where "users"."id" ='),
    ))->toBeEmpty();
});

test('auth log writes do not introspect the database schema', function () {
    $request = Request::create('/login', 'POST', ['login' => 'performance@example.test']);
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $log = app(AuthLogService::class)->log($request, 'performance_probe', 'success');

    expect($log)
        ->toBeInstanceOf(AuthLog::class)
        ->public_id->not->toBeNull()
        ->and(collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'pragma_table')
                || str_contains($sql, 'sqlite_master')
                || str_contains($sql, 'information_schema')
                || str_contains($sql, 'pg_catalog'),
        ))->toBeEmpty();
});

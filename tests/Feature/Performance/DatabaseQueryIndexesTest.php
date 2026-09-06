<?php

use App\Models\User;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function databaseIndexDefinition(string $table, string $name): array
{
    $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);

    expect($index)->toBeArray();

    return $index;
}

function sqliteQueryPlan(string $sql, array $bindings = []): string
{
    expect(DB::getDriverName())->toBe('sqlite');

    return collect(DB::select("EXPLAIN QUERY PLAN {$sql}", $bindings))
        ->pluck('detail')
        ->implode(' ');
}

test('permission role lookups have a role-leading reverse index', function (): void {
    $index = databaseIndexDefinition(
        config('permission.table_names.role_has_permissions'),
        'role_has_permissions_role_id_permission_id_index',
    );

    expect($index['columns'])->toBe(['role_id', 'permission_id'])
        ->and($index['unique'])->toBeFalse();

    $plan = sqliteQueryPlan(
        'SELECT permission_id FROM role_has_permissions WHERE role_id = ?',
        [1],
    );

    expect($plan)->toContain('role_has_permissions_role_id_permission_id_index');
});

test('presence lookups have indexes matching active-session and expiry predicates', function (): void {
    $activeIndex = databaseIndexDefinition(
        'user_presence_sessions',
        'user_presence_sessions_user_status_last_seen_index',
    );
    $expiryIndex = databaseIndexDefinition(
        'user_presence_sessions',
        'user_presence_sessions_status_expires_at_index',
    );

    expect($activeIndex['columns'])->toBe(['user_id', 'status', 'last_seen_at'])
        ->and($activeIndex['unique'])->toBeFalse()
        ->and($expiryIndex['columns'])->toBe(['status', 'expires_at'])
        ->and($expiryIndex['unique'])->toBeFalse();

    $activePlan = sqliteQueryPlan(
        'SELECT COUNT(*) FROM user_presence_sessions WHERE user_id = ? AND status IN (?, ?, ?) AND logout_at IS NULL AND last_seen_at > ? AND (expires_at IS NULL OR expires_at > ?)',
        [1, 'online', 'idle', 'locked', '2026-09-06 07:58:00', '2026-09-06 08:00:00'],
    );
    $expiryPlan = sqliteQueryPlan(
        'SELECT id FROM user_presence_sessions WHERE status IN (?, ?, ?) AND expires_at IS NOT NULL AND expires_at <= ?',
        ['online', 'idle', 'locked', '2026-09-06 08:00:00'],
    );

    expect($activePlan)->toContain('user_presence_sessions_user_status_last_seen_index')
        ->and($expiryPlan)->toContain('user_presence_sessions_status_expires_at_index');
});

test('presence active-session predicate keeps only fresh non-logged-out sessions', function (): void {
    $now = now()->startOfSecond();
    $activeSince = $now->copy()->subMinutes(2);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    DB::table('user_presence_sessions')->insert([
        [
            'user_id' => $user->getKey(),
            'session_fingerprint' => 'fresh-session',
            'status' => 'online',
            'last_seen_at' => $now->copy()->subMinute(),
            'logout_at' => null,
            'expires_at' => $now->copy()->addHour(),
        ],
        [
            'user_id' => $user->getKey(),
            'session_fingerprint' => 'logged-out-session',
            'status' => 'online',
            'last_seen_at' => $now->copy()->subMinute(),
            'logout_at' => $now,
            'expires_at' => $now->copy()->addHour(),
        ],
        [
            'user_id' => $user->getKey(),
            'session_fingerprint' => 'stale-session',
            'status' => 'idle',
            'last_seen_at' => $activeSince,
            'logout_at' => null,
            'expires_at' => $now->copy()->addHour(),
        ],
        [
            'user_id' => $user->getKey(),
            'session_fingerprint' => 'expired-session',
            'status' => 'locked',
            'last_seen_at' => $now->copy()->subMinute(),
            'logout_at' => null,
            'expires_at' => $now,
        ],
        [
            'user_id' => $otherUser->getKey(),
            'session_fingerprint' => 'other-user-session',
            'status' => 'online',
            'last_seen_at' => $now->copy()->subMinute(),
            'logout_at' => null,
            'expires_at' => $now->copy()->addHour(),
        ],
    ]);

    $activeFingerprints = DB::table('user_presence_sessions')
        ->where('user_id', $user->getKey())
        ->whereIn('status', ['online', 'idle', 'locked'])
        ->whereNull('logout_at')
        ->whereNotNull('last_seen_at')
        ->where('last_seen_at', '>', $activeSince)
        ->where(function ($query) use ($now): void {
            $query->whereNull('expires_at')
                ->orWhere('expires_at', '>', $now);
        })
        ->pluck('session_fingerprint')
        ->all();

    expect($activeFingerprints)->toBe(['fresh-session']);
});

test('performance index migrations roll back and reapply cleanly', function (): void {
    $roleIndexMigration = require base_path('modules/Auth/Database/Migrations/2026_09_06_080744_add_role_permission_reverse_lookup_index.php');
    $presenceIndexMigration = require base_path('modules/Auth/Database/Migrations/2026_09_06_080746_add_user_presence_query_indexes.php');

    $presenceIndexMigration->down();
    $roleIndexMigration->down();

    expect(Schema::hasIndex('role_has_permissions', 'role_has_permissions_role_id_permission_id_index'))->toBeFalse()
        ->and(Schema::hasIndex('user_presence_sessions', 'user_presence_sessions_user_status_last_seen_index'))->toBeFalse()
        ->and(Schema::hasIndex('user_presence_sessions', 'user_presence_sessions_status_expires_at_index'))->toBeFalse();

    $roleIndexMigration->up();
    $presenceIndexMigration->up();

    expect(Schema::hasIndex('role_has_permissions', 'role_has_permissions_role_id_permission_id_index'))->toBeTrue()
        ->and(Schema::hasIndex('user_presence_sessions', 'user_presence_sessions_user_status_last_seen_index'))->toBeTrue()
        ->and(Schema::hasIndex('user_presence_sessions', 'user_presence_sessions_status_expires_at_index'))->toBeTrue();
});

test('postgres performance indexes are built concurrently outside transactions', function (): void {
    $roleIndexMigration = require base_path('modules/Auth/Database/Migrations/2026_09_06_080744_add_role_permission_reverse_lookup_index.php');
    $presenceIndexMigration = require base_path('modules/Auth/Database/Migrations/2026_09_06_080746_add_user_presence_query_indexes.php');
    $roleTable = 'erp_auth.role_has_permissions';
    $grammar = new PostgresGrammar(DB::connection());
    config()->set('permission.table_names.role_has_permissions', $roleTable);

    expect($roleIndexMigration->withinTransaction)->toBeFalse()
        ->and($presenceIndexMigration->withinTransaction)->toBeFalse();

    Schema::shouldReceive('hasTable')->with($roleTable)->twice()->andReturnTrue();
    Schema::shouldReceive('hasTable')->with('user_presence_sessions')->twice()->andReturnTrue();

    DB::shouldReceive('getDriverName')->times(5)->andReturn('pgsql');
    DB::shouldReceive('getQueryGrammar')->times(7)->andReturn($grammar);
    DB::shouldReceive('selectOne')->times(6)->andReturn(
        (object) ['is_valid' => 0],
        null,
        null,
        null,
        null,
        null,
    );
    DB::shouldReceive('statement')
        ->once()
        ->with('DROP INDEX CONCURRENTLY IF EXISTS "erp_auth"."role_has_permissions_role_id_permission_id_index"')
        ->ordered()
        ->andReturnTrue();
    DB::shouldReceive('statement')
        ->once()
        ->with('CREATE INDEX CONCURRENTLY "role_has_permissions_role_id_permission_id_index" ON "erp_auth"."role_has_permissions" ("role_id", "permission_id")')
        ->ordered()
        ->andReturnTrue();
    DB::shouldReceive('statement')
        ->once()
        ->with('CREATE INDEX CONCURRENTLY "user_presence_sessions_user_status_last_seen_index" ON "user_presence_sessions" ("user_id", "status", "last_seen_at")')
        ->ordered()
        ->andReturnTrue();
    DB::shouldReceive('statement')
        ->once()
        ->with('CREATE INDEX CONCURRENTLY "user_presence_sessions_status_expires_at_index" ON "user_presence_sessions" ("status", "expires_at")')
        ->ordered()
        ->andReturnTrue();
    DB::shouldReceive('statement')
        ->once()
        ->with('DROP INDEX CONCURRENTLY IF EXISTS "erp_auth"."role_has_permissions_role_id_permission_id_index"')
        ->ordered()
        ->andReturnTrue();
    DB::shouldReceive('statement')
        ->once()
        ->with('DROP INDEX CONCURRENTLY IF EXISTS "user_presence_sessions_user_status_last_seen_index"')
        ->ordered()
        ->andReturnTrue();
    DB::shouldReceive('statement')
        ->once()
        ->with('DROP INDEX CONCURRENTLY IF EXISTS "user_presence_sessions_status_expires_at_index"')
        ->ordered()
        ->andReturnTrue();

    $roleIndexMigration->up();
    $presenceIndexMigration->up();
    $roleIndexMigration->down();
    $presenceIndexMigration->down();
});

test('postgres performance index migration skips an existing valid definition', function (): void {
    $migration = require base_path('modules/Auth/Database/Migrations/2026_09_06_080744_add_role_permission_reverse_lookup_index.php');
    $roleTable = config('permission.table_names.role_has_permissions');

    Schema::shouldReceive('hasTable')->with($roleTable)->once()->andReturnTrue();
    DB::shouldReceive('getDriverName')->once()->andReturn('pgsql');
    DB::shouldReceive('selectOne')->twice()->andReturn(
        (object) ['is_valid' => 1],
        (object) ['index_exists' => 1],
    );
    DB::shouldNotReceive('statement');

    $migration->up();
});

test('postgres migration rejects a wrong target definition even when an equivalent index exists', function (): void {
    $migration = require base_path('modules/Auth/Database/Migrations/2026_09_06_080744_add_role_permission_reverse_lookup_index.php');
    $roleTable = config('permission.table_names.role_has_permissions');
    $targetIndex = 'role_has_permissions_role_id_permission_id_index';
    $equivalentIndexLookupReached = false;

    Schema::shouldReceive('hasTable')->with($roleTable)->once()->andReturnTrue();
    DB::shouldReceive('getDriverName')->once()->andReturn('pgsql');
    DB::shouldReceive('selectOne')->andReturnUsing(
        function (string $query, array $bindings) use ($targetIndex, &$equivalentIndexLookupReached): ?object {
            if (str_contains($query, 'AS is_valid')) {
                return (object) ['is_valid' => 1];
            }

            if (in_array($targetIndex, $bindings, true)) {
                return null;
            }

            $equivalentIndexLookupReached = true;

            return (object) ['index_exists' => 1];
        },
    );
    DB::shouldNotReceive('statement');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, "Index [{$targetIndex}] already exists with an unexpected definition.")
        ->and($equivalentIndexLookupReached)->toBeFalse();
});

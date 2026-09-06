<?php

use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('permission checks use lightweight memoized existence queries without model hydration', function () {
    $directPermission = Permission::findOrCreate('performance.direct', 'web');
    $rolePermission = Permission::findOrCreate('performance.role', 'web');
    Permission::findOrCreate('performance.denied', 'web');

    $role = Role::query()->create([
        'name' => 'performance-role',
        'guard_name' => 'web',
        'doc_number' => 990001,
        'doc_num' => 'Role-990001',
    ]);
    $role->givePermissionTo($rolePermission);

    $user = User::factory()->create();
    $user->givePermissionTo($directPermission);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $queries = [];
    $retrievedPermissionModels = 0;
    $retrievedRoleModels = 0;

    Permission::retrieved(function () use (&$retrievedPermissionModels): void {
        $retrievedPermissionModels++;
    });
    Role::retrieved(function () use (&$retrievedRoleModels): void {
        $retrievedRoleModels++;
    });
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect($user->can('performance.direct'))->toBeTrue()
        ->and($user->can('performance.direct'))->toBeTrue()
        ->and($user->can('performance.role'))->toBeTrue()
        ->and($user->can('performance.denied'))->toBeFalse()
        ->and($user->can('performance.missing'))->toBeFalse()
        ->and($retrievedPermissionModels)->toBe(0)
        ->and($retrievedRoleModels)->toBe(0);

    $permissionQueries = collect($queries)
        ->filter(fn (string $sql): bool => str_contains($sql, 'model_has_permissions') || str_contains($sql, 'role_has_permissions'))
        ->values();

    expect($permissionQueries)->toHaveCount(4);

    $permissionQueries->each(function (string $sql): void {
        expect(strtolower($sql))->toContain('select exists');
    });
});

test('loading the permission set for navigation uses one scalar union query', function () {
    $directPermission = Permission::findOrCreate('performance.menu-direct', 'web');
    $rolePermission = Permission::findOrCreate('performance.menu-role', 'web');
    $role = Role::query()->create([
        'name' => 'performance-menu-role',
        'guard_name' => 'web',
        'doc_number' => 990003,
        'doc_num' => 'Role-990003',
    ]);
    $role->givePermissionTo($rolePermission);

    $user = User::factory()->create();
    $user->givePermissionTo($directPermission);
    $user->assignRole($role);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $permissionNames = app(EffectivePermissionResolver::class)->namesFor($user);

    expect($permissionNames)
        ->toHaveKeys(['performance.menu-direct', 'performance.menu-role'])
        ->and($user->can('performance.menu-direct'))->toBeTrue()
        ->and($user->can('performance.menu-role'))->toBeTrue()
        ->and($user->can('performance.menu-denied'))->toBeFalse();

    $permissionQueries = collect($queries)
        ->filter(fn (string $sql): bool => str_contains($sql, 'model_has_permissions') || str_contains($sql, 'role_has_permissions'))
        ->values();

    expect($permissionQueries)->toHaveCount(1)
        ->and(strtolower($permissionQueries->first()))->toContain(' union ');
});

test('permissions inherited from a deleted role are not granted', function () {
    $permission = Permission::findOrCreate('performance.deleted-role', 'web');
    $role = Role::query()->create([
        'name' => 'performance-deleted-role',
        'guard_name' => 'web',
        'doc_number' => 990002,
        'doc_num' => 'Role-990002',
    ]);
    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);
    $role->delete();

    expect($user->can('performance.deleted-role'))->toBeFalse();
});

test('html requests load one scalar permission set for repeated blade authorization checks', function () {
    $firstPermission = Permission::findOrCreate('performance.html-first', 'web');
    $secondPermission = Permission::findOrCreate('performance.html-second', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo([$firstPermission, $secondPermission]);

    Route::middleware(['web', 'auth'])->get('/_performance/html-permission-set', function () {
        /** @var User $user */
        $user = request()->user();

        return response()->json([
            $user->can('performance.html-first'),
            $user->can('performance.html-second'),
            $user->can('performance.html-denied'),
        ]);
    });

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)
        ->withHeader('Accept', 'text/html')
        ->get('/_performance/html-permission-set')
        ->assertOk()
        ->assertExactJson([true, true, false]);

    $permissionQueries = collect($queries)
        ->filter(fn (string $sql): bool => str_contains($sql, 'model_has_permissions') || str_contains($sql, 'role_has_permissions'))
        ->values();

    expect($permissionQueries)->toHaveCount(1)
        ->and(strtolower($permissionQueries->first()))->toContain(' union ');
});

test('ajax requests keep single permission checks on an indexed existence query', function () {
    $permission = Permission::findOrCreate('performance.ajax-check', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo($permission);

    Route::middleware(['web', 'auth'])->get('/_performance/ajax-permission-check', function () {
        return response()->json([
            'allowed' => request()->user()?->can('performance.ajax-check'),
        ]);
    });

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)
        ->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->get('/_performance/ajax-permission-check')
        ->assertOk()
        ->assertJsonPath('allowed', true);

    $permissionQueries = collect($queries)
        ->filter(fn (string $sql): bool => str_contains($sql, 'model_has_permissions') || str_contains($sql, 'role_has_permissions'))
        ->values();

    expect($permissionQueries)->toHaveCount(1)
        ->and(strtolower($permissionQueries->first()))->toContain('select exists')
        ->and(strtolower($permissionQueries->first()))->not->toContain(' union ');
});

test('permission decisions do not leak between requests', function () {
    Route::middleware(['web', 'auth', 'can:performance.request-isolation'])
        ->get('/_performance/permission-isolation', fn () => response('allowed'));

    $permission = Permission::findOrCreate('performance.request-isolation', 'web');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/_performance/permission-isolation')
        ->assertForbidden();

    $user->givePermissionTo($permission);

    $this->actingAs($user)
        ->get('/_performance/permission-isolation')
        ->assertOk();

    $user->revokePermissionTo($permission);

    $this->actingAs($user)
        ->get('/_performance/permission-isolation')
        ->assertForbidden();
});

test('permission mutations invalidate effective decisions inside the current request', function () {
    $directPermission = Permission::findOrCreate('performance.same-request-direct', 'web');
    $rolePermission = Permission::findOrCreate('performance.same-request-role', 'web');
    $role = Role::query()->create([
        'name' => 'performance-same-request-role',
        'guard_name' => 'web',
        'doc_number' => 990004,
        'doc_num' => 'Role-990004',
    ]);
    $role->givePermissionTo($rolePermission);

    $user = User::factory()->create();
    $user->givePermissionTo($directPermission);
    $user->assignRole($role);

    expect($user->can($directPermission->name))->toBeTrue()
        ->and($user->can($rolePermission->name))->toBeTrue();

    $user->revokePermissionTo($directPermission);
    $user->removeRole($role);

    expect($user->can($directPermission->name))->toBeFalse()
        ->and($user->can($rolePermission->name))->toBeFalse();

    $user->givePermissionTo($directPermission);
    $user->assignRole($role);

    expect($user->can($directPermission->name))->toBeTrue()
        ->and($user->can($rolePermission->name))->toBeTrue();

    $role->revokePermissionTo($rolePermission);

    expect($user->can($rolePermission->name))->toBeFalse();
});

test('wildcard mode keeps the package permission semantics and safe missing checks', function () {
    config()->set('permission.enable_wildcard_permission', true);

    $permission = Permission::findOrCreate('performance.wildcard.*', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo($permission);

    expect($user->checkPermissionTo('performance.wildcard.read'))->toBeTrue()
        ->and($user->checkPermissionTo('performance.missing'))->toBeFalse();
});

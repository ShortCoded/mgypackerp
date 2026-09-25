<?php

use App\Models\User;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('ERP permission sync dry run previews changes without writing to the database', function (): void {
    Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);

    $this->artisan('erp:permissions:sync', ['--dry-run' => true])
        ->expectsOutputToContain('ERP permission sync summary')
        ->expectsOutputToContain('Mode: dry-run')
        ->expectsOutputToContain('Scanned config/menu files:')
        ->expectsOutputToContain('Collected permissions count:')
        ->expectsOutputToContain('Created permissions count: 0 (dry-run; would create')
        ->expectsOutputToContain('Admin assigned permissions count: 0 (dry-run; would assign')
        ->assertSuccessful();

    expect(Permission::query()->count())->toBe(0);
});

test('ERP permission sync creates discovered permissions and assigns them to admin role', function (): void {
    $adminRole = Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);

    $this->artisan('erp:permissions:sync')
        ->expectsOutputToContain('Mode: real execution')
        ->expectsOutputToContain('Admin role used: admin')
        ->assertSuccessful();

    $registryPermissions = app(PermissionRegistryService::class)->all();

    expect(Permission::query()->where('guard_name', 'web')->whereIn('name', $registryPermissions)->count())
        ->toBe(count($registryPermissions));

    expect($adminRole->refresh()->permissions()->pluck('name')->sort()->values()->all())
        ->toBe($registryPermissions);
});

test('ERP permission sync prunes stale permissions and clears stale direct and role grants', function (): void {
    $adminRole = Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $stalePermission = Permission::query()->create([
        'name' => 'obsolete.permission',
        'guard_name' => 'web',
    ]);

    $adminRole->givePermissionTo($stalePermission);
    $user->givePermissionTo($stalePermission);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('erp:permissions:sync', [
        '--prune' => true,
        '--admin-role' => (string) $adminRole->getKey(),
    ])
        ->expectsOutputToContain('Prune requested: yes')
        ->expectsOutputToContain('Deleted stale permissions count: 1')
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'obsolete.permission')->exists())->toBeFalse()
        ->and($adminRole->refresh()->permissions()->where('name', 'obsolete.permission')->exists())->toBeFalse()
        ->and($user->refresh()->permissions()->where('name', 'obsolete.permission')->exists())->toBeFalse();
});

test('ERP permission sync refuses suspiciously low discovered permission counts without force', function (): void {
    $this->mock(PermissionRegistryService::class, function ($mock): void {
        $mock->shouldReceive('all')->once()->andReturn(['dashboard.view']);
    });

    foreach (range(1, 5) as $index) {
        Permission::query()->create([
            'name' => "existing.permission_{$index}",
            'guard_name' => 'web',
        ]);
    }

    $this->artisan('erp:permissions:sync', ['--prune' => true])
        ->expectsOutputToContain('suspiciously low')
        ->assertFailed();

    expect(Permission::query()->where('name', 'existing.permission_1')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'dashboard.view')->exists())->toBeFalse();
});

test('ERP permission sync aborts when no permissions are discovered', function (): void {
    $this->mock(PermissionRegistryService::class, function ($mock): void {
        $mock->shouldReceive('all')->once()->andReturn([]);
    });

    $this->artisan('erp:permissions:sync')
        ->expectsOutputToContain('No permissions were discovered')
        ->assertFailed();

    expect(Permission::query()->count())->toBe(0);
});

test('--show-stale lists stale permissions grouped by prefix during dry run', function (): void {
    $this->mock(PermissionRegistryService::class, function ($mock): void {
        $mock->shouldReceive('all')->once()->andReturn(['dashboard.view', 'users.view']);
    });

    Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);

    Permission::query()->create(['name' => 'obsolete.hr.test', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'obsolete.core.test', 'guard_name' => 'web']);

    $this->artisan('erp:permissions:sync', [
        '--dry-run' => true,
        '--show-stale' => true,
    ])
        ->expectsOutputToContain('Stale permissions (2)')
        ->expectsOutputToContain('obsolete.hr.test')
        ->expectsOutputToContain('obsolete.core.test')
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'obsolete.hr.test')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'obsolete.core.test')->exists())->toBeTrue();
});

test('--show-admin-diff displays permissions to add and remove from admin', function (): void {
    $this->mock(PermissionRegistryService::class, function ($mock): void {
        $mock->shouldReceive('all')->once()->andReturn(['dashboard.view', 'users.view']);
    });

    $adminRole = Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);

    Permission::query()->create(['name' => 'obsolete.old', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'dashboard.view', 'guard_name' => 'web']);

    $adminRole->givePermissionTo('obsolete.old');
    $adminRole->givePermissionTo('dashboard.view');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('erp:permissions:sync', [
        '--dry-run' => true,
        '--prune' => true,
        '--show-admin-diff' => true,
    ])
        ->expectsOutputToContain('Admin role permission diff')
        ->expectsOutputToContain('Permissions to REMOVE from admin (1)')
        ->expectsOutputToContain('obsolete.old')
        ->expectsOutputToContain('Permissions to ADD to admin (1)')
        ->expectsOutputToContain('users.view')
        ->expectsOutputToContain('Permissions remaining on admin: 1')
        ->assertSuccessful();
});

test('--export-audit writes JSON file with permission audit data', function (): void {
    $this->mock(PermissionRegistryService::class, function ($mock): void {
        $mock->shouldReceive('all')->once()->andReturn(['dashboard.view', 'users.view']);
    });

    Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);

    $auditPath = 'app/tests/permissions-audit-test.json';

    $this->artisan('erp:permissions:sync', [
        '--dry-run' => true,
        '--export-audit' => $auditPath,
    ])->assertSuccessful();

    $fullPath = base_path($auditPath);

    expect(file_exists($fullPath))->toBeTrue();

    $audit = json_decode(file_get_contents($fullPath), true);

    expect($audit)
        ->toHaveKey('collected_permissions')
        ->toHaveKey('current_db_permissions')
        ->toHaveKey('stale_permissions')
        ->toHaveKey('admin_role')
        ->toHaveKey('admin_counts')
        ->toHaveKey('admin_current_permissions')
        ->toHaveKey('admin_permissions_to_add')
        ->toHaveKey('admin_permissions_to_remove');

    expect($audit['collected_permissions'])->toBe(['dashboard.view', 'users.view'])
        ->and($audit['guard'])->toBe('web')
        ->and($audit['admin_role']['name'] ?? null)->toBe('admin');

    unlink($fullPath);
    rmdir(dirname($fullPath));
});

test('--skip-admin-sync creates permissions but does not assign them to admin', function (): void {
    $adminRole = Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);

    $this->artisan('erp:permissions:sync', [
        '--skip-admin-sync' => true,
    ])
        ->expectsOutputToContain('Admin sync skipped (--skip-admin-sync)')
        ->assertSuccessful();

    $registryPermissions = app(PermissionRegistryService::class)->all();

    expect(Permission::query()->where('guard_name', 'web')->whereIn('name', $registryPermissions)->count())
        ->toBe(count($registryPermissions));

    expect($adminRole->refresh()->permissions()->count())->toBe(0);
});

test('stale permissions are not deleted unless --prune is passed', function (): void {
    $this->mock(PermissionRegistryService::class, function ($mock): void {
        $mock->shouldReceive('all')->andReturn(['dashboard.view', 'users.view']);
    });

    $adminRole = Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);

    $stalePermission = Permission::query()->create(['name' => 'obsolete.permission', 'guard_name' => 'web']);
    $adminRole->givePermissionTo($stalePermission);

    $this->artisan('erp:permissions:sync')
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'obsolete.permission')->exists())->toBeTrue()
        ->and($adminRole->refresh()->permissions()->where('name', 'obsolete.permission')->exists())->toBeTrue()
        ->and(Permission::query()->whereIn('name', ['dashboard.view', 'users.view'])->count())->toBe(2);

    $this->artisan('erp:permissions:sync', [
        '--prune' => true,
    ])
        ->expectsOutputToContain('Deleted stale permissions count: 1')
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'obsolete.permission')->exists())->toBeFalse();
});

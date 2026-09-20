<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\RoleService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function privilegeCeilingPermission(string $name): Permission
{
    return Permission::findOrCreate($name, 'web');
}

function privilegeCeilingActor(array $permissions, ?string $roleName = null): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        privilegeCeilingPermission($permission);
    }

    $actor = User::factory()->create();

    if ($roleName === null) {
        $actor->givePermissionTo($permissions);

        return $actor;
    }

    $role = Role::query()->create([
        'name' => $roleName,
        'guard_name' => 'web',
        'doc_number' => 900,
        'doc_num' => 'Role-00900',
    ]);
    $role->givePermissionTo($permissions);
    $actor->assignRole($role);

    return $actor;
}

function privilegeCeilingRole(string $name, int $number, array $permissions = []): Role
{
    foreach ($permissions as $permission) {
        privilegeCeilingPermission($permission);
    }

    $role = Role::query()->create([
        'name' => $name,
        'guard_name' => 'web',
        'doc_number' => $number,
        'doc_num' => 'Role-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
    ]);

    if ($permissions !== []) {
        $role->givePermissionTo($permissions);
    }

    return $role;
}

/**
 * @return array{company: Company, branch: Branch, period: FinancialPeriod, role: Role}
 */
function privilegeCeilingRestrictedScope(int $number): array
{
    $company = Company::factory()->create([
        'doc_number' => $number,
        'doc_num' => 'Company-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Branch-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->id,
        'name' => "Ceiling Branch {$number}",
        'type' => 'warehouse',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Period-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->id,
        'name' => "Ceiling Period {$number}",
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);
    $role = privilegeCeilingRole("restricted-scope-{$number}", $number);
    $role->forceFill([
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
    ])->save();
    $role->companyAccessCompanies()->sync([$company->id]);
    $role->branchAccessBranches()->sync([$branch->id]);
    $role->financialPeriodAccessPeriods()->sync([$period->id]);

    return compact('company', 'branch', 'period', 'role');
}

beforeEach(function (): void {
    privilegeCeilingRole('protected-system-role', 0);
});

test('role create and update still require their route permissions', function (): void {
    $target = privilegeCeilingRole('ceiling-route-target', 1);

    $this->actingAs(privilegeCeilingActor(['users.view']))
        ->postJson(route('admin.roles.store'), [
            'name' => 'forbidden-created-role',
            'permissions' => ['users.view'],
        ])
        ->assertForbidden();

    $this->actingAs(privilegeCeilingActor(['users.view']))
        ->putJson(route('admin.roles.update', $target->doc_num), [
            'name' => 'forbidden-updated-role',
            'permissions' => [],
        ])
        ->assertForbidden();

    expect(Role::query()->where('name', 'forbidden-created-role')->exists())->toBeFalse()
        ->and($target->refresh()->name)->toBe('ceiling-route-target');
});

test('editor can grant A and B but cannot grant C', function (): void {
    $actor = privilegeCeilingActor(['roles.create', 'users.view', 'users.create']);
    privilegeCeilingPermission('users.edit');

    $this->actingAs($actor)
        ->postJson(route('admin.roles.store'), [
            'name' => 'allowed-a-b-role',
            'permissions' => ['users.view', 'users.create'],
        ])
        ->assertOk();

    $allowedRole = Role::query()->where('name', 'allowed-a-b-role')->firstOrFail();

    expect($allowedRole->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['users.create', 'users.view']);

    $this->postJson(route('admin.roles.store'), [
        'name' => 'forbidden-c-role',
        'permissions' => ['users.view', 'users.edit'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions']);

    expect(Role::query()->where('name', 'forbidden-c-role')->exists())->toBeFalse();
});

test('mixed allowed and forbidden update is atomic and writes no successful audit', function (): void {
    $actor = privilegeCeilingActor(['roles.edit', 'users.view']);
    $target = privilegeCeilingRole('atomic-ceiling-target', 2, ['users.view']);
    privilegeCeilingPermission('users.create');

    Activity::query()->delete();

    $this->actingAs($actor)
        ->putJson(route('admin.roles.update', $target->doc_num), [
            'name' => 'tampered-atomic-target',
            'notes' => 'must not persist',
            'permissions' => ['users.view', 'users.create'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions']);

    $target->refresh();

    expect($target->name)->toBe('atomic-ceiling-target')
        ->and($target->notes)->toBeNull()
        ->and($target->permissions()->pluck('name')->values()->all())->toBe(['users.view'])
        ->and(Activity::query()->whereIn('action', ['roles.update', 'roles.permissions.sync'])->exists())->toBeFalse();
});

test('editing an own role cannot add a permission outside the actor ceiling', function (): void {
    $ownRole = privilegeCeilingRole('own-editable-role', 3, ['roles.edit', 'users.view']);
    privilegeCeilingPermission('users.create');
    $actor = User::factory()->create();
    $actor->assignRole($ownRole);

    $this->actingAs($actor)
        ->putJson(route('admin.roles.update', $ownRole->doc_num), [
            'name' => $ownRole->name,
            'permissions' => ['roles.edit', 'users.view', 'users.create'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions']);

    expect($ownRole->refresh()->hasPermissionTo('users.create'))->toBeFalse();
});

test('metadata edits preserve existing higher permissions without allowing their regrant', function (): void {
    $actor = privilegeCeilingActor(['roles.edit', 'users.view']);
    $target = privilegeCeilingRole('higher-permission-target', 4, ['users.view', 'users.create']);

    $this->actingAs($actor)
        ->putJson(route('admin.roles.update', $target->doc_num), [
            'name' => 'renamed-higher-permission-target',
            'notes' => 'metadata changed',
            'permissions' => ['users.view'],
        ])
        ->assertOk();

    $target->refresh();

    expect($target->name)->toBe('renamed-higher-permission-target')
        ->and($target->notes)->toBe('metadata changed')
        ->and($target->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['users.create', 'users.view']);

    $this->putJson(route('admin.roles.update', $target->doc_num), [
        'name' => $target->name,
        'notes' => $target->notes,
        'permissions' => ['users.view', 'users.create'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions']);
});

test('a role named Super Admin receives no delegation bypass', function (): void {
    privilegeCeilingPermission('users.create');
    $actor = privilegeCeilingActor(['roles.create'], 'Super Admin');

    $this->actingAs($actor)
        ->postJson(route('admin.roles.store'), [
            'name' => 'fake-super-admin-created-role',
            'permissions' => ['users.create'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions']);

    expect(Role::query()->where('name', 'fake-super-admin-created-role')->exists())->toBeFalse();
});

test('canonical aliases are evaluated against the same actor ceiling', function (): void {
    privilegeCeilingPermission('roles.company_access.manage');
    privilegeCeilingPermission('roles.operating_scope.manage');
    $actor = privilegeCeilingActor(['roles.create', 'roles.company_access.manage']);

    $this->actingAs($actor)
        ->postJson(route('admin.roles.store'), [
            'name' => 'canonical-alias-role',
            'permissions' => ['roles.company_access.manage'],
        ])
        ->assertOk();

    $role = Role::query()->where('name', 'canonical-alias-role')->firstOrFail();

    expect($role->permissions()->pluck('name')->values()->all())
        ->toBe(['roles.operating_scope.manage']);
});

test('unknown registered-looking permissions and duplicate inputs are rejected', function (): void {
    $actor = privilegeCeilingActor(['roles.create', 'users.view', 'forged.permission']);

    $this->actingAs($actor)
        ->postJson(route('admin.roles.store'), [
            'name' => 'unknown-permission-role',
            'permissions' => ['forged.permission'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions']);

    $this->postJson(route('admin.roles.store'), [
        'name' => 'duplicate-permission-role',
        'permissions' => ['users.view', 'users.view'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions.0', 'permissions.1']);

    expect(Role::query()->whereIn('name', ['unknown-permission-role', 'duplicate-permission-role'])->exists())
        ->toBeFalse();
});

test('role assignment rejects a role above the actor ceiling including self assignment', function (): void {
    $actor = privilegeCeilingActor(['users.edit', 'users.roles.manage', 'users.view']);
    $higherRole = privilegeCeilingRole('higher-assignment-role', 5, ['users.create']);

    Activity::query()->delete();

    $this->actingAs($actor)
        ->putJson(route('admin.users.update', $actor->doc_num), [
            'name' => 'tampered actor name',
            'username' => $actor->username,
            'email' => $actor->email,
            'phone' => $actor->phone,
            'status' => $actor->status,
            'notes' => $actor->notes,
            '_roles_present' => '1',
            'roles' => [$higherRole->doc_num],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles']);

    $actor->refresh();

    expect($actor->name)->not->toBe('tampered actor name')
        ->and($actor->roles()->whereKey($higherRole->getKey())->exists())->toBeFalse()
        ->and(Activity::query()->where('action', 'users.update')->exists())->toBeFalse();
});

test('role and user assignment screens expose only delegable new authority', function (): void {
    privilegeCeilingPermission('users.create');
    $actor = privilegeCeilingActor(['roles.create', 'users.edit', 'users.roles.manage', 'users.view']);
    $allowedRole = privilegeCeilingRole('ui-allowed-role', 6, ['users.view']);
    $forbiddenRole = privilegeCeilingRole('ui-forbidden-role', 7, ['users.create']);

    $this->actingAs($actor)
        ->get(route('admin.roles.create'))
        ->assertOk()
        ->assertSee('value="users.view"', false)
        ->assertDontSee('value="users.create"', false);

    $this->getJson(route('admin.select2.roles.assignable', [
        'q' => 'ui-',
    ]))
        ->assertOk()
        ->assertJsonFragment(['id' => $allowedRole->doc_num])
        ->assertJsonMissing(['id' => $forbiddenRole->doc_num]);
});

test('restricted actors cannot create clone update or assign broader operating scope', function (): void {
    $scope = privilegeCeilingRestrictedScope(40);
    $actor = privilegeCeilingActor([
        'roles.create',
        'roles.clone',
        'roles.edit',
        'users.edit',
        'users.roles.manage',
    ]);
    $actor->assignRole($scope['role']);
    $unrestrictedRole = privilegeCeilingRole('unrestricted-scope-role', 41);
    $targetUser = User::factory()->create();

    $this->actingAs($actor)
        ->postJson(route('admin.roles.store'), [
            'name' => 'forbidden-unrestricted-create',
            'permissions' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_company_doc_nums']);

    $this->withSession(['roles.clone_sources.scope-clone-token' => $unrestrictedRole->doc_num])
        ->postJson(route('admin.roles.store'), [
            'clone_source_token' => 'scope-clone-token',
            'name' => 'forbidden-unrestricted-clone',
            'permissions' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_company_doc_nums']);

    $this->putJson(route('admin.roles.update', $unrestrictedRole->doc_num), [
        'name' => 'forbidden-unrestricted-update',
        'permissions' => [],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_company_doc_nums']);

    $this->putJson(route('admin.users.update', $targetUser->doc_num), [
        'name' => $targetUser->name,
        'username' => $targetUser->username,
        'email' => $targetUser->email,
        'phone' => $targetUser->phone,
        'status' => $targetUser->status,
        '_roles_present' => '1',
        'roles' => [$unrestrictedRole->doc_num],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles']);

    $this->getJson(route('admin.select2.roles.assignable', ['q' => 'unrestricted-scope-role']))
        ->assertOk()
        ->assertJsonMissing(['id' => $unrestrictedRole->doc_num]);

    expect(Role::query()->whereIn('name', ['forbidden-unrestricted-create', 'forbidden-unrestricted-clone'])->exists())->toBeFalse()
        ->and($unrestrictedRole->refresh()->name)->toBe('unrestricted-scope-role')
        ->and($targetUser->roles()->exists())->toBeFalse();
});

test('restricted actors can create roles within their existing operating scope', function (): void {
    $scope = privilegeCeilingRestrictedScope(50);
    $actor = privilegeCeilingActor(['roles.create', 'roles.operating_scope.manage']);
    $actor->assignRole($scope['role']);

    $this->actingAs($actor)
        ->postJson(route('admin.roles.store'), [
            'name' => 'allowed-restricted-scope-role',
            'permissions' => [],
            'accessible_company_doc_nums' => [$scope['company']->doc_num],
            'accessible_branch_doc_nums' => [$scope['branch']->doc_num],
            'accessible_financial_period_doc_nums' => [$scope['period']->doc_num],
        ])
        ->assertOk();

    $role = Role::query()->where('name', 'allowed-restricted-scope-role')->firstOrFail();

    expect($role->company_access_restricted)->toBeTrue()
        ->and($role->branch_access_restricted)->toBeTrue()
        ->and($role->financial_period_access_restricted)->toBeTrue();
});

test('manipulated user updates cannot remove above ceiling or protected roles', function (): void {
    $actor = privilegeCeilingActor(['users.edit', 'users.roles.manage', 'users.view']);
    $higherRole = privilegeCeilingRole('existing-higher-user-role', 60, ['users.create']);
    $targetUser = User::factory()->create();
    $targetUser->assignRole($higherRole);

    $payload = [
        'name' => 'must not change',
        'username' => $targetUser->username,
        'email' => $targetUser->email,
        'phone' => $targetUser->phone,
        'status' => $targetUser->status,
        '_roles_present' => '1',
        'roles' => [],
    ];

    $this->actingAs($actor)
        ->putJson(route('admin.users.update', $targetUser->doc_num), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles']);

    expect($targetUser->refresh()->name)->not->toBe('must not change')
        ->and($targetUser->roles()->whereKey($higherRole->getKey())->exists())->toBeTrue();

    $adminRole = privilegeCeilingRole('admin', 61, $actor->getAllPermissions()->pluck('name')->all());
    $adminUser = User::factory()->create();
    $adminUser->assignRole($adminRole);

    $this->putJson(route('admin.users.update', $adminUser->doc_num), [
        ...$payload,
        'name' => $adminUser->name,
        'username' => $adminUser->username,
        'email' => $adminUser->email,
        'phone' => $adminUser->phone,
        'status' => $adminUser->status,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles']);

    expect($adminUser->roles()->whereKey($adminRole->getKey())->exists())->toBeTrue();
});

test('non form permissions remain preserved even when the actor owns them', function (): void {
    $actor = privilegeCeilingActor(['roles.edit', 'users.view', 'companies.files.view']);
    $target = privilegeCeilingRole('actor-owned-stale-permission', 70, ['users.view', 'companies.files.view']);

    $this->actingAs($actor)
        ->putJson(route('admin.roles.update', $target->doc_num), [
            'name' => 'actor-owned-stale-permission-renamed',
            'permissions' => ['users.view'],
        ])
        ->assertOk();

    expect($target->refresh()->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['companies.files.view', 'users.view']);
});

test('assignable role lookup is always filtered regardless of context parameters', function (): void {
    privilegeCeilingPermission('users.create');
    $actor = privilegeCeilingActor(['users.roles.manage', 'users.view']);
    $allowedRole = privilegeCeilingRole('lookup-allowed-role', 80, ['users.view']);
    $forbiddenRole = privilegeCeilingRole('lookup-forbidden-role', 81, ['users.create']);

    foreach ([null, 'invalid', 'user_role_assignment'] as $context) {
        $parameters = ['q' => 'lookup-'];

        if ($context !== null) {
            $parameters['context'] = $context;
        }

        $response = $this->actingAs($actor)
            ->getJson(route('admin.select2.roles.assignable', $parameters))
            ->assertOk();

        expect(collect($response->json('results'))->pluck('id')->all())
            ->toContain($allowedRole->doc_num)
            ->not->toContain($forbiddenRole->doc_num);
    }
});

test('user role assignment evaluates dormant company branch and period pivots', function (): void {
    DB::statement('DROP INDEX IF EXISTS companies_one_active_main_unique');
    $scope = privilegeCeilingRestrictedScope(90);
    $actor = privilegeCeilingActor(['users.edit', 'users.roles.manage']);
    $actor->assignRole($scope['role']);
    $targetUser = User::factory()->create();

    $dormantCompany = Company::factory()->create([
        'doc_number' => 91,
        'doc_num' => 'Company-00091',
    ]);
    $dormantCompany->delete();
    $dormantBranch = Branch::query()->create([
        'doc_number' => 91,
        'doc_num' => 'Branch-00091',
        'company_id' => $scope['company']->id,
        'name' => 'Dormant Ceiling Branch',
        'type' => 'warehouse',
        'status' => 'inactive',
    ]);
    $dormantPeriod = FinancialPeriod::query()->create([
        'doc_number' => 91,
        'doc_num' => 'Period-00091',
        'company_id' => $scope['company']->id,
        'name' => 'Dormant Ceiling Period',
        'from_date' => '2027-01-01',
        'to_date' => '2027-12-31',
        'is_closed' => false,
    ]);
    $dormantPeriod->delete();

    $makeScopedRole = function (string $name, int $number): Role {
        $role = privilegeCeilingRole($name, $number);
        $role->forceFill([
            'company_access_restricted' => true,
            'branch_access_restricted' => true,
            'financial_period_access_restricted' => true,
        ])->save();

        return $role;
    };

    $companyRole = $makeScopedRole('dormant-company-role', 92);
    $companyRole->companyAccessCompanies()->sync([$scope['company']->id, $dormantCompany->id]);
    $companyRole->branchAccessBranches()->sync([$scope['branch']->id]);
    $companyRole->financialPeriodAccessPeriods()->sync([$scope['period']->id]);

    $branchRole = $makeScopedRole('dormant-branch-role', 93);
    $branchRole->companyAccessCompanies()->sync([$scope['company']->id]);
    $branchRole->branchAccessBranches()->sync([$scope['branch']->id, $dormantBranch->id]);
    $branchRole->financialPeriodAccessPeriods()->sync([$scope['period']->id]);

    $periodRole = $makeScopedRole('dormant-period-role', 94);
    $periodRole->companyAccessCompanies()->sync([$scope['company']->id]);
    $periodRole->branchAccessBranches()->sync([$scope['branch']->id]);
    $periodRole->financialPeriodAccessPeriods()->sync([$scope['period']->id, $dormantPeriod->id]);

    $assign = function (Role $role) use ($actor, $targetUser): void {
        $this->actingAs($actor)
            ->putJson(route('admin.users.update', $targetUser->doc_num), [
                'name' => $targetUser->name,
                'username' => $targetUser->username,
                'email' => $targetUser->email,
                'phone' => $targetUser->phone,
                'status' => $targetUser->status,
                '_roles_present' => '1',
                'roles' => [$role->doc_num],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles']);
    };

    $assign($companyRole);
    $assign($branchRole);
    $assign($periodRole);

    $dormantCompany->restore();
    $dormantBranch->forceFill(['status' => 'active'])->save();
    $dormantPeriod->restore();

    $assign($companyRole);
    $assign($branchRole);
    $assign($periodRole);

    expect($targetUser->roles()->exists())->toBeFalse();
});

test('locked role refresh rechecks protected role immutability', function (): void {
    $actor = privilegeCeilingActor(['roles.edit']);
    $target = privilegeCeilingRole('stale-normal-role', 95);
    $staleRole = $target->fresh();

    DB::table('roles')->where('id', $target->id)->update(['name' => 'admin']);

    $this->actingAs($actor);

    expect(fn () => app(RoleService::class)->update($staleRole, [
        'name' => 'stale-bypass-attempt',
        'permissions' => [],
    ]))->toThrow(DomainException::class);

    expect($target->refresh()->name)->toBe('admin');
});

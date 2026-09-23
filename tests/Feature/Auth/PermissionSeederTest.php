<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\MenuService;
use Spatie\Permission\Models\Permission;

function permissionRegistryResourceNodes(array $nodes): array
{
    $resources = [];

    foreach ($nodes as $node) {
        if (($node['permissions'] ?? []) !== []) {
            $resources[] = $node;
        }

        $resources = array_merge($resources, permissionRegistryResourceNodes($node['children'] ?? []));
    }

    return $resources;
}

function permissionRegistryPermissionNames(array $nodes): array
{
    return collect(permissionRegistryResourceNodes($nodes))
        ->flatMap(fn (array $node): array => collect($node['permissions'])->pluck('name')->all())
        ->values()
        ->all();
}

function permissionRegistryFindNodeByLabel(array $nodes, string $label): ?array
{
    foreach ($nodes as $node) {
        if (($node['label'] ?? null) === $label) {
            return $node;
        }

        $match = permissionRegistryFindNodeByLabel($node['children'] ?? [], $label);

        if ($match !== null) {
            return $match;
        }
    }

    return null;
}

function permissionRegistryFindDirectChildByLabel(array $node, string $label): ?array
{
    return collect($node['children'] ?? [])
        ->first(fn (array $child): bool => ($child['label'] ?? null) === $label);
}

function permissionRegistryRestoredHrPrefixes(): array
{
    return [
        'hr.allowances',
        'hr.areas',
        'hr.cities',
        'hr.countries',
        'hr.faculties',
        'hr.governorates',
        'hr.grades',
        'hr.hiring_statuses',
        'hr.identifications',
        'hr.insurance_offices',
        'hr.military_services',
        'hr.nationalities',
        'hr.qualifications',
        'hr.religions',
        'hr.specializations',
        'hr.universities',
    ];
}

function permissionRegistryStandardCrudPermissions(string $prefix): array
{
    return [
        "{$prefix}.view",
        "{$prefix}.create",
        "{$prefix}.clone",
        "{$prefix}.edit",
        "{$prefix}.delete",
        "{$prefix}.view_trashed",
        "{$prefix}.restore",
        "{$prefix}.document_number.control",
        "{$prefix}.document_number_settings.update",
    ];
}

test('permission registry collects permissions from menu configs', function () {
    $registry = app(PermissionRegistryService::class);
    $permissions = $registry->all();
    $formPermissions = $registry->formAssignablePermissions();

    expect($permissions)
        ->toContain('dashboard.view')
        ->toContain('profile.view')
        ->toContain('profile.edit')
        ->toContain('profile.password.update')
        ->toContain('profile.sessions.view')
        ->toContain('profile.auth_logs.view')
        ->toContain('profile.delete')
        ->toContain('users.view')
        ->toContain('users.create')
        ->toContain('users.edit')
        ->toContain('users.delete')
        ->toContain('users.roles.manage')
        ->toContain('file_manager.upload')
        ->toContain('file_manager.view')
        ->toContain('file_manager.download')
        ->toContain('file_manager.delete')
        ->toContain('file_manager.document_number_settings.update')
        ->toContain('file_manager.folders.create')
        ->toContain('file_manager.folders.rename')
        ->toContain('file_manager.folders.delete')
        ->toContain('roles.view')
        ->toContain('roles.create')
        ->toContain('roles.clone')
        ->toContain('roles.edit')
        ->toContain('roles.delete')
        ->toContain('roles.document_number.control')
        ->toContain('roles.document_number_settings.update')
        ->not->toContain('permissions.view')
        ->not->toContain('permissions.create')
        ->not->toContain('permissions.edit')
        ->not->toContain('permissions.delete')
        ->toContain('auth.logs.view')
        ->toContain('auth.logs.export')
        ->toContain('branches.view')
        ->toContain('branches.create')
        ->toContain('branches.clone')
        ->toContain('branches.edit')
        ->toContain('branches.delete')
        ->toContain('branches.view_trashed')
        ->toContain('branches.restore')
        ->toContain('branches.document_number.control')
        ->toContain('branches.document_number_settings.update')
        ->toContain('financial_periods.view')
        ->toContain('financial_periods.create')
        ->toContain('financial_periods.clone')
        ->toContain('financial_periods.edit')
        ->toContain('financial_periods.delete')
        ->toContain('financial_periods.view_trashed')
        ->toContain('financial_periods.restore')
        ->toContain('financial_periods.document_number.control')
        ->toContain('financial_periods.document_number_settings.update')
        ->toContain('my_board.view')
        ->toContain('my_board.lists.create')
        ->toContain('settings.pwa.view')
        ->toContain('settings.pwa.update')
        ->not->toContain('tools.'.'temperature'.'_logs.view');

    expect(config('permissions'))->toBe([])
        ->and($permissions)->toBe(array_values(array_unique($permissions)))
        ->and($permissions)->not->toContain('users.index')
        ->and($permissions)->not->toContain('users.bulk_delete')
        ->and($permissions)->not->toContain('roles.bulk_delete')
        ->and($permissions)->not->toContain('companies.index')
        ->and($permissions)->not->toContain('companies.bulk_delete')
        ->and($permissions)->not->toContain('tasks.view')
        ->and($permissions)->toContain('hr.employees.view')
        ->and($permissions)->toContain('hr.employees.create')
        ->and($permissions)->toContain('hr.employees.clone')
        ->and($permissions)->toContain('hr.employees.edit')
        ->and($permissions)->toContain('hr.employees.delete')
        ->and($permissions)->toContain('hr.employees.view_trashed')
        ->and($permissions)->toContain('hr.employees.restore')
        ->and($permissions)->toContain('hr.employees.document_number.control')
        ->and($permissions)->toContain('hr.employees.document_number_settings.update')
        ->and($permissions)->toContain('hr.departments.view')
        ->and($permissions)->toContain('hr.sections.view')
        ->and($permissions)->toContain('hr.jobs.view')
        ->and($permissions)->toContain('hr.employment_types.view')
        ->and($permissions)->toContain('hr.biometric_devices.view')
        ->and($permissions)->toContain('hr.shifts.view')
        ->and($permissions)->toContain('hr.document_types.view')
        ->and($permissions)->toContain('hr.insurance_offices.view')
        ->and($permissions)->toContain('hr.insurance_offices.document_number.control')
        ->and($permissions)->toContain('hr.hiring_statuses.view')
        ->and($permissions)->toContain('hr.countries.view')
        ->and($permissions)->not->toContain('hr.regulations.view')
        ->and($permissions)->not->toContain('hr.attendance_rules.view')
        ->and($permissions)->not->toContain('hr.insurances.view')
        ->and($permissions)->not->toContain('hr.work_permissions.view')
        ->and($permissions)->not->toContain('hr.org_units.view')
        ->and($permissions)->not->toContain('hr.positions.view')
        ->and($permissions)->not->toContain('hr.cost_centers.view')
        ->and($permissions)->not->toContain('companies.files.view')
        ->and($permissions)->not->toContain('companies.files.upload')
        ->and($permissions)->not->toContain('companies.files.download')
        ->and($permissions)->not->toContain('companies.files.delete')
        ->and($permissions)->not->toContain('file_manager.index')
        ->and($permissions)->not->toContain('file_manager.bulk_delete')
        ->and($permissions)->not->toContain('file_manager.bulk_download')
        ->and($formPermissions)->toContain('profile.view')
        ->and($formPermissions)->toContain('profile.password.update')
        ->and($formPermissions)->toContain('profile.sessions.view')
        ->and($formPermissions)->toContain('profile.auth_logs.view')
        ->and($formPermissions)->toContain('profile.delete')
        ->and($formPermissions)->toContain('auth.logs.view')
        ->and($formPermissions)->not->toContain('permissions.view')
        ->and($formPermissions)->not->toContain('users.index')
        ->and($formPermissions)->not->toContain('roles.bulk_delete');
});

test('permission registry resolves localized permission labels and readable fallbacks', function () {
    app()->setLocale('en');

    $registry = app(PermissionRegistryService::class);

    expect($registry->labelForPermission('users.roles.manage'))->toBe('Manage User Groups')
        ->and($registry->labelForPermission('profile.password.update'))->toBe('Update Password')
        ->and($registry->labelForPermission('profile.sessions.view'))->toBe('View Profile Sessions')
        ->and($registry->labelForPermission('profile.auth_logs.view'))->toBe('View Profile Login Activity')
        ->and($registry->labelForPermission('profile.delete'))->toBe('Delete Account')
        ->and($registry->labelForPermission('profile.password.update', 'password_update'))->toBe('Update password')
        ->and($registry->labelForPermission('profile.sessions.view', 'sessions_view'))->toBe('View sessions')
        ->and($registry->labelForPermission('profile.auth_logs.view', 'auth_logs_view'))->toBe('View login activity')
        ->and($registry->labelForPermission('profile.delete', 'delete_account'))->toBe('Delete account')
        ->and($registry->labelForPermission('companies.document_number.control'))->toBe('Control Company Document Number')
        ->and($registry->labelForPermission('warehouse.stock.adjust'))->toBe('Adjust Warehouse Stock');

    app()->setLocale('ar');

    expect($registry->labelForPermission('users.roles.manage'))->toBe('إدارة مجموعات المستخدمين');

    app()->setLocale('en');

    $groups = $registry->groupedForForm(['users.roles.manage']);
    $permission = permissionRegistryResourceNodes($groups)[0]['permissions'][0];

    expect($permission['name'])->toBe('users.roles.manage')
        ->and($permission['label'])->toBe('Manage user groups')
        ->and($permission['label'])->not->toBe($permission['name']);
});

test('permission registry groups form assignable menu permissions without stale fallback rows', function () {
    app()->setLocale('en');

    $groups = app(PermissionRegistryService::class)->groupedForForm([
        'dashboard.view',
        'file_manager.index',
        'profile.view',
        'profile.edit',
        'profile.password.update',
        'profile.sessions.view',
        'profile.auth_logs.view',
        'profile.delete',
        'permissions.view',
        'auth.logs.view',
        'roles.view',
        'companies.files.view',
        'settings.pwa.view',
        'settings.pwa.update',
        'unmapped.permission',
    ]);

    $generalGroups = collect($groups)->where('key', 'general')->values();
    $basicDataGroups = collect($groups)->where('key', 'basic_data')->values();
    $toolsGroups = collect($groups)->where('key', 'tools')->values();
    $otherGroups = collect($groups)->where('key', 'other_permissions')->values();
    $generalPermissions = permissionRegistryPermissionNames($generalGroups->all());
    $generalResourceLabels = collect(permissionRegistryResourceNodes($generalGroups->all()))->pluck('label')->all();
    $basicDataPermissions = permissionRegistryPermissionNames($basicDataGroups->all());
    $toolsPermissions = permissionRegistryPermissionNames($toolsGroups->all());
    $allPermissions = permissionRegistryPermissionNames($groups);
    $fileManagerNode = permissionRegistryFindNodeByLabel($toolsGroups->all(), __('menu.file_manager'));
    $pwaSettingsNode = permissionRegistryFindNodeByLabel($toolsGroups->all(), __('menu.pwa_settings'));
    $fileManagerPermissions = $fileManagerNode === null
        ? []
        : collect($fileManagerNode['permissions'])->pluck('name')->all();
    $pwaSettingsPermissions = $pwaSettingsNode === null
        ? []
        : collect($pwaSettingsNode['permissions'])->pluck('name')->all();
    $profileNode = permissionRegistryFindNodeByLabel($basicDataGroups->all(), __('menu.profile'));
    $profilePermissions = $profileNode === null
        ? []
        : collect($profileNode['permissions'])->pluck('name')->all();
    $profilePermissionLabels = $profileNode === null
        ? []
        : collect($profileNode['permissions'])->pluck('label')->all();

    expect($generalGroups)->toHaveCount(1)
        ->and($basicDataGroups)->toHaveCount(1)
        ->and($toolsGroups)->toHaveCount(1)
        ->and($otherGroups)->toHaveCount(0)
        ->and($generalResourceLabels)->toContain(__('menu.dashboard'))
        ->and($generalPermissions)->toContain('dashboard.view')
        ->and($generalPermissions)->not->toContain('file_manager.index')
        ->and($toolsGroups->first()['label'])->toBe(__('menu.tools'))
        ->and($toolsPermissions)->toContain('file_manager.view')
        ->and($toolsPermissions)->toContain('settings.pwa.view')
        ->and($toolsPermissions)->toContain('settings.pwa.update')
        ->and($toolsPermissions)->toContain('auth.logs.view')
        ->and($toolsPermissions)->not->toContain('tools.'.'temperature'.'_logs.view')
        ->and($fileManagerNode)->not->toBeNull()
        ->and($fileManagerPermissions)->toContain('file_manager.view')
        ->and($fileManagerPermissions)->not->toContain('file_manager.index')
        ->and($pwaSettingsNode)->not->toBeNull()
        ->and($pwaSettingsPermissions)->toContain('settings.pwa.view')
        ->and($pwaSettingsPermissions)->toContain('settings.pwa.update')
        ->and($basicDataPermissions)->toContain('profile.view')
        ->and($basicDataPermissions)->toContain('profile.edit')
        ->and($basicDataPermissions)->toContain('profile.password.update')
        ->and($basicDataPermissions)->toContain('profile.sessions.view')
        ->and($basicDataPermissions)->toContain('profile.auth_logs.view')
        ->and($basicDataPermissions)->toContain('profile.delete')
        ->and($basicDataPermissions)->not->toContain('auth.logs.view')
        ->and($basicDataPermissions)->toContain('roles.view')
        ->and($profilePermissions)->toContain('profile.view')
        ->and($profilePermissions)->toContain('profile.edit')
        ->and($profilePermissions)->toContain('profile.password.update')
        ->and($profilePermissions)->toContain('profile.sessions.view')
        ->and($profilePermissions)->toContain('profile.auth_logs.view')
        ->and($profilePermissions)->toContain('profile.delete')
        ->and($profilePermissionLabels)->toContain('View profile')
        ->and($profilePermissionLabels)->toContain('Edit profile')
        ->and($profilePermissionLabels)->toContain('Update password')
        ->and($profilePermissionLabels)->toContain('View sessions')
        ->and($profilePermissionLabels)->toContain('View login activity')
        ->and($profilePermissionLabels)->toContain('Delete account')
        ->and($basicDataPermissions)->not->toContain('permissions.view')
        ->and($allPermissions)->not->toContain('companies.files.view')
        ->and($allPermissions)->not->toContain('unmapped.permission')
        ->and($allPermissions)->toHaveCount(count(array_unique($allPermissions)));
});

test('permission registry preserves nested menu hierarchy for role forms', function () {
    $groups = app(PermissionRegistryService::class)->groupedForForm([
        'companies.index',
        'branches.view',
        'financial_periods.view',
        'hr.employees.view',
        'hr.departments.view',
        'hr.countries.view',
        'my_board.view',
        'my_board.lists.create',
        'settings.pwa.view',
    ]);

    $basicData = permissionRegistryFindNodeByLabel($groups, __('menu.basic_data'));
    $accountingCosting = permissionRegistryFindNodeByLabel($groups, __('menu.accounting_costing'));
    $tools = permissionRegistryFindNodeByLabel($groups, __('menu.tools'));
    $humanResources = permissionRegistryFindNodeByLabel($groups, __('menu.human_resources'));
    $myBoard = permissionRegistryFindNodeByLabel($groups, __('menu.my_board'));

    expect($basicData)->not->toBeNull();
    expect($accountingCosting)->not->toBeNull();
    expect($tools)->not->toBeNull();
    expect($humanResources)->not->toBeNull();
    expect($myBoard)->not->toBeNull();

    $basicDataChildLabels = collect($basicData['children'])->pluck('label')->all();
    $toolsChildLabels = collect($tools['children'])->pluck('label')->all();
    $organizationSetup = collect($basicData['children'])->firstWhere('label', __('menu.organization_setup'));
    $generalAccounting = collect($accountingCosting['children'])->firstWhere('label', __('menu.general_accounting'));
    $humanResourcesChildLabels = collect($humanResources['children'])->pluck('label')->all();
    $humanResourcesResourceLabels = collect(permissionRegistryResourceNodes($humanResources['children']))
        ->pluck('label')
        ->all();
    $workManagement = collect($tools['children'])->firstWhere('label', __('menu.work_management'));
    $applicationTools = collect($tools['children'])->firstWhere('label', __('menu.application_tools'));

    expect($basicDataChildLabels)
        ->toContain(__('menu.organization_setup'))
        ->not->toContain(__('menu.human_resources'))
        ->not->toContain(__('menu.hr_countries'));
    expect($toolsChildLabels)
        ->toContain(__('menu.work_management'))
        ->toContain(__('menu.application_tools'));
    expect(collect($organizationSetup['children'])->pluck('label')->all())
        ->toContain(__('menu.companies'), __('menu.branches'))
        ->not->toContain(__('menu.financial_periods'));
    expect(collect($generalAccounting['children'])->pluck('label')->all())
        ->toContain(__('menu.financial_periods'));
    expect($humanResourcesResourceLabels)
        ->toContain(__('menu.hr_employees'), __('menu.hr_departments'), __('menu.hr_countries'));
    expect($humanResourcesChildLabels)->not->toContain(__('menu.employee_data'), __('menu.hr_setup'));
    expect(collect($workManagement['children'])->pluck('label')->all())->toContain(__('menu.my_board'));
    expect(collect($applicationTools['children'])->pluck('label')->all())->toContain(__('menu.pwa_settings'));

    $allPermissions = permissionRegistryPermissionNames($groups);

    expect($allPermissions)
        ->toBe(array_values(array_unique($allPermissions)))
        ->toContain('hr.employees.view')
        ->toContain('hr.departments.view')
        ->toContain('hr.countries.view')
        ->toContain('my_board.view')
        ->not->toContain('permissions.view');
});

test('branches and financial periods appear in their canonical menus only for permitted users', function () {
    Permission::findOrCreate('companies.view', 'web');
    Permission::findOrCreate('branches.view', 'web');
    Permission::findOrCreate('financial_periods.view', 'web');

    $permitted = User::factory()->create();
    $permitted->givePermissionTo('companies.view', 'branches.view', 'financial_periods.view');

    $basicData = permissionRegistryFindNodeByLabel(
        app(MenuService::class)->getMenu($permitted),
        'basic_data'
    );
    $accountingCosting = permissionRegistryFindNodeByLabel(
        app(MenuService::class)->getMenu($permitted),
        'accounting_costing'
    );

    expect($basicData)->not->toBeNull();
    expect($accountingCosting)->not->toBeNull();

    $organizationSetup = collect($basicData['children'])->firstWhere('label', 'organization_setup');
    $children = collect($organizationSetup['children'])->pluck('label')->all();
    $generalAccounting = collect($accountingCosting['children'])->firstWhere('label', 'general_accounting');
    $generalAccountingChildren = collect($generalAccounting['children'])->pluck('label')->all();

    expect($children)
        ->toContain('companies')
        ->toContain('branches')
        ->not->toContain('financial_periods');
    expect($generalAccountingChildren)->toContain('financial_periods');

    $blockedMenu = app(MenuService::class)->getMenu(User::factory()->create());

    expect(permissionRegistryFindNodeByLabel($blockedMenu, 'branches'))->toBeNull()
        ->and(permissionRegistryFindNodeByLabel($blockedMenu, 'financial_periods'))->toBeNull();
});

test('permission seeder creates permissions and syncs all to admin role', function () {
    $this->seed(PermissionSeeder::class);

    $registryPermissions = app(PermissionRegistryService::class)->all();
    $adminRole = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
    $adminPermissionNames = $adminRole->permissions()->pluck('name')->all();

    expect(Permission::query()->whereIn('name', $registryPermissions)->where('guard_name', 'web')->count())->toBe(count($registryPermissions));
    expect($adminRole->permissions()->pluck('name')->sort()->values()->all())->toBe($registryPermissions);
    expect($adminRole->hasPermissionTo('profile.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('profile.edit'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('profile.password.update'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('profile.sessions.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('profile.auth_logs.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('profile.delete'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('users.delete'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('roles.delete'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.upload'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.download'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.delete'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.document_number_settings.update'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.folders.create'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.folders.rename'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.folders.delete'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('branches.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('branches.document_number_settings.update'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('financial_periods.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('financial_periods.document_number_settings.update'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.employees.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.employees.create'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.employees.edit'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.employees.delete'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.employees.view_trashed'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.employees.restore'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.employees.document_number.control'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.employees.document_number_settings.update'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.departments.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.sections.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.jobs.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.employment_types.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.biometric_devices.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.shifts.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.document_types.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.insurance_offices.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('hr.insurance_offices.document_number_settings.update'))->toBeTrue()
        ->and($adminPermissionNames)->toContain('hr.hiring_statuses.view')
        ->and($adminPermissionNames)->toContain('hr.countries.view')
        ->and($adminPermissionNames)->not->toContain('hr.cost_centers.view')
        ->and($adminPermissionNames)->not->toContain('hr.regulations.view')
        ->and($adminPermissionNames)->not->toContain('hr.attendance_rules.view');

    foreach (permissionRegistryRestoredHrPrefixes() as $prefix) {
        foreach (permissionRegistryStandardCrudPermissions($prefix) as $permission) {
            expect($adminPermissionNames)->toContain($permission);
        }
    }

    expect($adminPermissionNames)
        ->not->toContain('hr.org_units.tree.view')
        ->not->toContain('hr.org_units.tree.manage')
        ->not->toContain('hr.org_units.move')
        ->not->toContain('hr.positions.hierarchy.view')
        ->not->toContain('hr.positions.occupancy.view');
});

test('permission seeder copies legacy duplicate grants to canonical permissions', function () {
    $legacyRole = Role::query()->create([
        'name' => 'legacy manager',
        'guard_name' => 'web',
    ]);
    $legacyUser = User::factory()->create();

    Permission::findOrCreate('users.index', 'web');
    Permission::findOrCreate('roles.bulk_delete', 'web');
    Permission::findOrCreate('file_manager.bulk_download', 'web');

    $legacyRole->givePermissionTo('users.index', 'roles.bulk_delete');
    $legacyUser->givePermissionTo('file_manager.bulk_download');

    $this->seed(PermissionSeeder::class);

    $legacyRole->refresh();
    $legacyUser->refresh();

    expect($legacyRole->hasPermissionTo('users.view'))->toBeTrue()
        ->and($legacyRole->hasPermissionTo('roles.delete'))->toBeTrue()
        ->and($legacyUser->hasPermissionTo('file_manager.download'))->toBeTrue()
        ->and(app(PermissionRegistryService::class)->all())->not->toContain('users.index')
        ->and(app(PermissionRegistryService::class)->all())->not->toContain('roles.bulk_delete')
        ->and(app(PermissionRegistryService::class)->all())->not->toContain('file_manager.bulk_download');
});

test('database seeder is repeatable and assigns admin role to default admin user', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $registryPermissions = app(PermissionRegistryService::class)->all();
    $adminRole = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
    $adminUser = User::query()->where('email', 'info@shortcoded.com')->firstOrFail();

    expect(Permission::query()->whereIn('name', $registryPermissions)->where('guard_name', 'web')->count())->toBe(count($registryPermissions));
    expect(Permission::query()->select('name', 'guard_name')->groupBy('name', 'guard_name')->havingRaw('COUNT(*) > 1')->count())->toBe(0);
    expect(Role::query()->where('name', 'admin')->where('guard_name', 'web')->count())->toBe(1);
    expect($adminRole->permissions()->count())->toBe(count($registryPermissions));
    expect($adminUser->hasRole('admin'))->toBeTrue();
});

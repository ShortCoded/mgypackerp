<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\DocumentNumberService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function __construct(
        private readonly PermissionRegistryService $permissionRegistry,
        private readonly DocumentNumberService $documentNumberService,
    ) {}

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionNames = collect($this->permissionRegistry->all())->values();
        $existingPermissionNames = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->pluck('name');
        $createdPermissionNames = $permissionNames->diff($existingPermissionNames)->values();

        $permissions = $permissionNames
            ->map(fn (string $permission): Permission => Permission::query()->updateOrCreate(
                [
                    'name' => $permission,
                    'guard_name' => 'web',
                ],
                [
                    'name' => $permission,
                    'guard_name' => 'web',
                ],
            ));

        $compatibilityResult = $this->copyLegacyGrantsToCanonical();

        $stalePermissionNames = Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', $permissionNames)
            ->orderBy('name')
            ->pluck('name')
            ->values();

        $adminRole = Role::withTrashed()->updateOrCreate(
            [
                'name' => 'admin',
                'guard_name' => 'web',
            ],
            [
                'name' => 'admin',
                'guard_name' => 'web',
            ],
        );

        if ($adminRole->trashed()) {
            $adminRole->restore();
        }

        if ($adminRole->doc_number === null || $adminRole->doc_num === null) {
            DB::transaction(function () use ($adminRole): void {
                $adminRole->forceFill($this->documentNumberService->next('roles', Role::class))->save();
            });
        }

        $adminRole->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Permissions discovered from menu: %d (%d created, %d existing).',
            $permissionNames->count(),
            $createdPermissionNames->count(),
            $existingPermissionNames->count(),
        ));

        if ($compatibilityResult['mapped_permissions'] !== []) {
            $this->command?->info(sprintf(
                'Legacy permission grants copied to canonical permissions: %s (%d role grants, %d direct user grants).',
                implode(', ', $compatibilityResult['mapped_permissions']),
                $compatibilityResult['role_grants'],
                $compatibilityResult['user_grants'],
            ));
        }

        if ($stalePermissionNames->isNotEmpty()) {
            $this->command?->warn(sprintf(
                'Stale DB permissions not found in menu config: %s',
                $stalePermissionNames->implode(', '),
            ));
        }
    }

    /**
     * @return array{mapped_permissions: list<string>, role_grants: int, user_grants: int}
     */
    private function copyLegacyGrantsToCanonical(): array
    {
        $legacyMap = $this->permissionRegistry->legacyPermissionMap();

        if ($legacyMap === []) {
            return [
                'mapped_permissions' => [],
                'role_grants' => 0,
                'user_grants' => 0,
            ];
        }

        /** @var Collection<string, Permission> $legacyPermissions */
        $legacyPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', array_keys($legacyMap))
            ->with(['roles', 'users'])
            ->get()
            ->keyBy('name');

        /** @var Collection<string, Permission> $canonicalPermissions */
        $canonicalPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', array_values($legacyMap))
            ->get()
            ->keyBy('name');

        $mappedPermissions = [];
        $roleGrants = 0;
        $userGrants = 0;

        foreach ($legacyMap as $legacyPermissionName => $canonicalPermissionName) {
            $legacyPermission = $legacyPermissions->get($legacyPermissionName);
            $canonicalPermission = $canonicalPermissions->get($canonicalPermissionName);

            if (! $legacyPermission instanceof Permission || ! $canonicalPermission instanceof Permission) {
                continue;
            }

            $mapped = false;

            foreach ($legacyPermission->roles as $role) {
                if ($role->hasPermissionTo($canonicalPermission)) {
                    continue;
                }

                $role->givePermissionTo($canonicalPermission);
                $roleGrants++;
                $mapped = true;
            }

            foreach ($legacyPermission->users as $user) {
                if ($user->hasPermissionTo($canonicalPermission)) {
                    continue;
                }

                $user->givePermissionTo($canonicalPermission);
                $userGrants++;
                $mapped = true;
            }

            if ($mapped) {
                $mappedPermissions[] = "{$legacyPermissionName} => {$canonicalPermissionName}";
            }
        }

        return [
            'mapped_permissions' => $mappedPermissions,
            'role_grants' => $roleGrants,
            'user_grants' => $userGrants,
        ];
    }
}

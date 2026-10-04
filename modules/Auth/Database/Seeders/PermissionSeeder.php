<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\LegacyPermissionGrantMigrationService;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\DocumentNumberService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function __construct(
        private readonly PermissionRegistryService $permissionRegistry,
        private readonly DocumentNumberService $documentNumberService,
        private readonly LegacyPermissionGrantMigrationService $legacyGrants,
    ) {}

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            DB::transaction(fn () => $this->seedPermissions());
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedPermissions(): void
    {
        $permissionNames = collect($this->permissionRegistry->all())->values();
        $existingPermissionCount = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->count();
        $now = now();

        $permissionNames
            ->map(fn (string $permission): array => [
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->chunk(500)
            ->each(fn (Collection $permissions): int => Permission::query()->insertOrIgnore($permissions->all()));

        $migratedLegacyGrants = $this->legacyGrants->migrate($permissionNames->all());
        $migratedProductionControlGrants = $this->legacyGrants->migrateProductionControlGrants();

        $stalePermissionNames = Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', $permissionNames)
            ->orderBy('name')
            ->pluck('name')
            ->values();

        $adminRole = Role::withTrashed()->where('name', 'admin')->where('guard_name', 'web')->first();
        if (! $adminRole?->trashed()) {
            $createdAdminRole = $adminRole === null;
            $adminRole ??= Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);

            if ($createdAdminRole && ($adminRole->doc_number === null || $adminRole->doc_num === null)) {
                $adminRole->forceFill($this->documentNumberService->next('roles', Role::class))->save();
            }

            if ($createdAdminRole) {
                $permissionIds = Permission::query()
                    ->where('guard_name', 'web')
                    ->whereIn('name', $permissionNames)
                    ->pluck((new Permission)->getKeyName());

                $adminRole->permissions()->sync($permissionIds);
            }
        }

        $this->command?->info(sprintf(
            'Permissions discovered from menu: %d (%d created, %d existing).',
            $permissionNames->count(),
            $permissionNames->count() - $existingPermissionCount,
            $existingPermissionCount,
        ));

        if ($migratedLegacyGrants > 0) {
            $this->command?->info(sprintf(
                'Legacy role and direct user grants moved to current screens: %d.',
                $migratedLegacyGrants,
            ));
        }

        if ($migratedProductionControlGrants > 0) {
            $this->command?->info(sprintf(
                'Existing production run report grants copied to production monitoring: %d.',
                $migratedProductionControlGrants,
            ));
        }

        if ($stalePermissionNames->isNotEmpty()) {
            $this->command?->warn(sprintf(
                'Stale DB permissions not found in menu config: %s',
                $stalePermissionNames->implode(', '),
            ));
        }
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\MenuConfigFileOrder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class SyncErpPermissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:permissions:sync
        {--dry-run : Preview the permission sync without writing to the database}
        {--prune : Delete stale permissions that are no longer discovered from ERP menu/config definitions}
        {--force : Allow destructive pruning in production or when the discovered permission count looks suspicious}
        {--admin-role= : Role id or role name that should receive all valid permissions}
        {--show-stale : List all stale permission names grouped by prefix}
        {--show-created : List all missing permission names that would be created}
        {--show-admin-diff : Show admin role permission diff (adds/removes/remaining)}
        {--skip-admin-sync : During real execution, skip assigning permissions to admin role}
        {--export-audit= : Export audit data as JSON to the given path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely synchronize ERP menu/config permissions into Spatie permissions.';

    /**
     * @var string
     */
    protected $help = <<<'HELP'
Examples:
  php artisan erp:permissions:sync --dry-run
  php artisan erp:permissions:sync
  php artisan erp:permissions:sync --prune
  php artisan erp:permissions:sync --prune --admin-role=1
  php artisan erp:permissions:sync --prune --force
  php artisan erp:permissions:sync --dry-run --show-stale
  php artisan erp:permissions:sync --dry-run --show-admin-diff
  php artisan erp:permissions:sync --dry-run --export-audit=storage/app/permissions-audit.json
HELP;

    /**
     * Execute the console command.
     */
    public function handle(
        PermissionRegistryService $permissionRegistry,
        MenuConfigFileOrder $menuFiles,
        PermissionRegistrar $permissionRegistrar,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $prune = (bool) $this->option('prune');
        $force = (bool) $this->option('force');
        $guardName = $this->guardName();
        $scannedFiles = $menuFiles->files();

        $showStale = (bool) $this->option('show-stale');
        $showCreated = (bool) $this->option('show-created');
        $showAdminDiff = (bool) $this->option('show-admin-diff');
        $skipAdminSync = (bool) $this->option('skip-admin-sync');
        $exportAudit = $this->option('export-audit');

        try {
            $permissionNames = $permissionRegistry->all();
        } catch (Throwable $exception) {
            $this->error('Permission discovery failed; no database changes were made.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        if ($permissionNames === []) {
            $this->error('No permissions were discovered from ERP menu/config definitions. No database changes were made.');

            return self::FAILURE;
        }

        /** @var Collection<int, Permission> $currentPermissions */
        $currentPermissions = Permission::query()
            ->where('guard_name', $guardName)
            ->orderBy('name')
            ->get(['id', 'name', 'guard_name']);

        $currentPermissionNames = $currentPermissions->pluck('name')->values();
        $permissionNameCollection = collect($permissionNames)->values();
        $missingPermissionNames = $permissionNameCollection->diff($currentPermissionNames)->values();
        $existingPermissionNames = $permissionNameCollection->intersect($currentPermissionNames)->values();
        $stalePermissions = $currentPermissions
            ->reject(fn (Permission $permission): bool => in_array($permission->name, $permissionNames, true))
            ->values();
        $adminRole = $this->resolveAdminRole($guardName);

        $adminCurrentPermissions = $adminRole instanceof Role
            ? $adminRole->permissions()->pluck('name')->sort()->values()->all()
            : [];

        $adminPermissionsToRemove = $prune
            ? array_values(array_diff($adminCurrentPermissions, $permissionNames))
            : [];
        $adminPermissionsToAdd = array_values(array_diff($permissionNames, $adminCurrentPermissions));
        $adminPermissionsRemaining = $prune
            ? array_values(array_intersect($adminCurrentPermissions, $permissionNames))
            : $adminCurrentPermissions;

        $suspiciousCount = $this->collectedCountLooksSuspicious($permissionNameCollection->count(), $currentPermissions->count());

        if (! $dryRun && $prune && $suspiciousCount && ! $force) {
            $this->error(sprintf(
                'Discovered permission count [%d] is suspiciously low compared to current DB count [%d]. Re-run with --force after verifying menu/config discovery.',
                $permissionNameCollection->count(),
                $currentPermissions->count(),
            ));

            return self::FAILURE;
        }

        if (! $dryRun && $prune && $stalePermissions->isNotEmpty() && app()->isProduction() && ! $force) {
            $this->error('Refusing to prune stale permissions in production without --force.');

            return self::FAILURE;
        }

        // ── Visibility: --show-stale ─────────────────────────────────────
        if ($showStale && $stalePermissions->isNotEmpty()) {
            $this->newLine();
            $this->warn(sprintf('Stale permissions (%d):', $stalePermissions->count()));

            $grouped = [];
            foreach ($stalePermissions as $perm) {
                $prefix = Str::before($perm->name, '.');
                $grouped[$prefix][] = $perm->name;
            }
            ksort($grouped);

            foreach ($grouped as $prefix => $names) {
                $this->line(sprintf('  %s (%d):', $prefix, count($names)));
                foreach ($names as $name) {
                    $this->line('    - '.$name);
                }
            }
        }

        // ── Visibility: --show-created ───────────────────────────────────
        if ($showCreated && $missingPermissionNames->isNotEmpty()) {
            $this->newLine();
            $this->info(sprintf('New permissions to create (%d):', $missingPermissionNames->count()));
            foreach ($missingPermissionNames as $name) {
                $this->line('  - '.$name);
            }
        }

        // ── Visibility: --show-admin-diff ────────────────────────────────
        if ($showAdminDiff) {
            $this->newLine();
            $this->info('Admin role permission diff');

            if (! $adminRole instanceof Role) {
                $this->warn('No admin role found — cannot compute admin diff.');
            } else {
                $this->line(sprintf('Admin role: %s', $this->adminRoleLabel($adminRole)));
                $this->line(sprintf('Admin current permissions: %d', count($adminCurrentPermissions)));

                if ($adminPermissionsToRemove !== []) {
                    $this->warn(sprintf('Permissions to REMOVE from admin (%d):', count($adminPermissionsToRemove)));
                    foreach ($adminPermissionsToRemove as $p) {
                        $this->line('  - '.$p);
                    }
                }

                if ($adminPermissionsToAdd !== []) {
                    $this->info(sprintf('Permissions to ADD to admin (%d):', count($adminPermissionsToAdd)));
                    foreach ($adminPermissionsToAdd as $p) {
                        $this->line('  - '.$p);
                    }
                }

                $this->line(sprintf('Permissions remaining on admin: %d', count($adminPermissionsRemaining)));
            }
        }

        // ── Warning about stale permissions in admin ─────────────────────
        if ($stalePermissions->isNotEmpty() && $adminPermissionsToRemove !== []) {
            $this->newLine();
            $this->warn(sprintf(
                'WARNING: Real execution without --skip-admin-sync will remove %d permissions from admin.',
                count($adminPermissionsToRemove)
            ));
        }

        if ($dryRun) {
            $wouldAssignAdminPermissionsCount = ($adminRole instanceof Role && ! $skipAdminSync)
                ? count(array_unique(array_merge($adminPermissionsRemaining, $adminPermissionsToAdd)))
                : 0;

            $this->printSummary(
                scannedFiles: $scannedFiles,
                guardName: $guardName,
                collectedCount: $permissionNameCollection->count(),
                createdCount: 0,
                wouldCreateCount: $missingPermissionNames->count(),
                existingCount: $existingPermissionNames->count(),
                staleCount: $stalePermissions->count(),
                deletedStaleCount: 0,
                wouldDeleteStaleCount: $prune ? $stalePermissions->count() : 0,
                adminRole: $adminRole,
                adminAssignedPermissionsCount: 0,
                wouldAssignAdminPermissionsCount: $wouldAssignAdminPermissionsCount,
                dryRun: true,
                prune: $prune,
                suspiciousCount: $suspiciousCount,
                skipAdminSync: $skipAdminSync,
            );

            if (! $adminRole instanceof Role) {
                $this->warn('Permissions would be synced, but no admin role was found for assignment.');
            }

            $this->exportAuditIfRequested(
                exportAudit: $exportAudit,
                scannedFiles: $scannedFiles,
                guardName: $guardName,
                permissionNames: $permissionNames,
                currentPermissionNames: $currentPermissionNames,
                missingPermissionNames: $missingPermissionNames,
                existingPermissionNames: $existingPermissionNames,
                stalePermissions: $stalePermissions,
                adminRole: $adminRole,
                adminCurrentPermissions: $adminCurrentPermissions,
                adminPermissionsToAdd: $adminPermissionsToAdd,
                adminPermissionsToRemove: $adminPermissionsToRemove,
                adminPermissionsRemaining: $adminPermissionsRemaining,
            );

            return self::SUCCESS;
        }

        $deletedStaleCount = 0;
        $adminAssignedPermissionsCount = 0;

        DB::transaction(function () use (
            $permissionRegistrar,
            $missingPermissionNames,
            $permissionNames,
            $guardName,
            $prune,
            $stalePermissions,
            $adminRole,
            $skipAdminSync,
            &$deletedStaleCount,
            &$adminAssignedPermissionsCount,
        ): void {
            $permissionRegistrar->forgetCachedPermissions();

            foreach ($missingPermissionNames as $permissionName) {
                Permission::query()->firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => $guardName,
                ]);
            }

            if ($prune && $stalePermissions->isNotEmpty()) {
                $deletedStaleCount = $this->deleteStalePermissions($stalePermissions);
            }

            /** @var Collection<int, Permission> $validPermissions */
            $validPermissions = Permission::query()
                ->where('guard_name', $guardName)
                ->whereIn('name', $permissionNames)
                ->orderBy('name')
                ->get();

            if ($adminRole instanceof Role && ! $skipAdminSync) {
                if ($adminRole->trashed()) {
                    $adminRole->restore();
                }

                $permissionsToAssign = $prune
                    ? $validPermissions
                    : $adminRole->permissions()->get()->concat($validPermissions)->unique('id');
                $adminRole->syncPermissions($permissionsToAssign);
                $adminAssignedPermissionsCount = $permissionsToAssign->count();
            }

            $permissionRegistrar->forgetCachedPermissions();
        });

        $this->printSummary(
            scannedFiles: $scannedFiles,
            guardName: $guardName,
            collectedCount: $permissionNameCollection->count(),
            createdCount: $missingPermissionNames->count(),
            wouldCreateCount: 0,
            existingCount: $existingPermissionNames->count(),
            staleCount: $stalePermissions->count(),
            deletedStaleCount: $deletedStaleCount,
            wouldDeleteStaleCount: 0,
            adminRole: $adminRole,
            adminAssignedPermissionsCount: $adminAssignedPermissionsCount,
            wouldAssignAdminPermissionsCount: 0,
            dryRun: false,
            prune: $prune,
            suspiciousCount: false,
            skipAdminSync: $skipAdminSync,
        );

        if ($skipAdminSync) {
            $this->line('Admin sync skipped (--skip-admin-sync).');
        }

        if (! $adminRole instanceof Role) {
            $this->warn('Permissions were synced, but no admin role was found. Admin role permissions were not updated.');
        }

        $this->exportAuditIfRequested(
            exportAudit: $exportAudit,
            scannedFiles: $scannedFiles,
            guardName: $guardName,
            permissionNames: $permissionNames,
            currentPermissionNames: $currentPermissionNames,
            missingPermissionNames: $missingPermissionNames,
            existingPermissionNames: $existingPermissionNames,
            stalePermissions: $stalePermissions,
            adminRole: $adminRole,
            adminCurrentPermissions: $adminCurrentPermissions,
            adminPermissionsToAdd: $adminPermissionsToAdd,
            adminPermissionsToRemove: $adminPermissionsToRemove,
            adminPermissionsRemaining: $adminPermissionsRemaining,
        );

        return self::SUCCESS;
    }

    public function collectedCountLooksSuspicious(int $collectedCount, int $currentCount): bool
    {
        return $currentCount >= 3 && $collectedCount < (int) ceil($currentCount * 0.5);
    }

    /**
     * @param  Collection<int, Permission>  $stalePermissions
     */
    private function deleteStalePermissions(Collection $stalePermissions): int
    {
        $permissionIds = $stalePermissions
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if ($permissionIds->isEmpty()) {
            return 0;
        }

        $permissionPivotKey = $this->permissionPivotKey();

        DB::table($this->permissionTableName('role_has_permissions'))
            ->whereIn($permissionPivotKey, $permissionIds)
            ->delete();

        DB::table($this->permissionTableName('model_has_permissions'))
            ->whereIn($permissionPivotKey, $permissionIds)
            ->delete();

        return Permission::query()
            ->whereIn('id', $permissionIds)
            ->delete();
    }

    private function resolveAdminRole(string $guardName): ?Role
    {
        $adminRoleOption = $this->option('admin-role');

        if (is_string($adminRoleOption) && trim($adminRoleOption) !== '') {
            return $this->findRoleByReference($adminRoleOption, $guardName);
        }

        foreach ($this->configuredAdminRoleReferences() as $reference) {
            $role = $this->findRoleByReference($reference, $guardName);

            if ($role instanceof Role) {
                return $role;
            }
        }

        foreach ([1, 'admin', 'Administrator'] as $reference) {
            $role = $this->findRoleByReference($reference, $guardName);

            if ($role instanceof Role) {
                return $role;
            }
        }

        return null;
    }

    private function findRoleByReference(int|string $reference, string $guardName): ?Role
    {
        $reference = trim((string) $reference);

        if ($reference === '') {
            return null;
        }

        if (ctype_digit($reference)) {
            $role = Role::withTrashed()
                ->whereKey((int) $reference)
                ->where('guard_name', $guardName)
                ->first();

            if ($role instanceof Role) {
                return $role;
            }
        }

        return Role::withTrashed()
            ->where('name', $reference)
            ->where('guard_name', $guardName)
            ->first();
    }

    /**
     * @return list<int|string>
     */
    private function configuredAdminRoleReferences(): array
    {
        $references = [];

        foreach ([
            'erp.admin_role_id',
            'erp.admin_role_name',
            'erp.admin_role',
            'auth.admin_role_id',
            'auth.admin_role_name',
            'auth.admin_role',
            'permissions.admin_role_id',
            'permissions.admin_role_name',
            'permissions.admin_role',
            'permission.admin_role_id',
            'permission.admin_role_name',
            'permission.admin_role',
        ] as $configKey) {
            $value = config($configKey);

            if (is_int($value) || is_string($value)) {
                $references[] = $value;
            }

            if (is_array($value)) {
                foreach (['id', 'name'] as $nestedKey) {
                    $nestedValue = $value[$nestedKey] ?? null;

                    if (is_int($nestedValue) || is_string($nestedValue)) {
                        $references[] = $nestedValue;
                    }
                }
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * @param  list<string>  $scannedFiles
     */
    private function printSummary(
        array $scannedFiles,
        string $guardName,
        int $collectedCount,
        int $createdCount,
        int $wouldCreateCount,
        int $existingCount,
        int $staleCount,
        int $deletedStaleCount,
        int $wouldDeleteStaleCount,
        ?Role $adminRole,
        int $adminAssignedPermissionsCount,
        int $wouldAssignAdminPermissionsCount,
        bool $dryRun,
        bool $prune,
        bool $suspiciousCount,
        bool $skipAdminSync = false,
    ): void {
        $this->newLine();
        $this->info('ERP permission sync summary');
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'real execution'));
        $this->line('Guard: '.$guardName);
        $this->line('Prune requested: '.($prune ? 'yes' : 'no'));

        if ($suspiciousCount) {
            $this->warn('Discovered permission count looks suspiciously low compared to the current permissions table.');
        }

        $this->line(sprintf('Scanned config/menu files: %d', count($scannedFiles)));

        foreach ($scannedFiles as $file) {
            $this->line('  - '.$this->relativePath($file));
        }

        $this->line('Collected permissions count: '.$collectedCount);
        $this->line('Created permissions count: '.$this->countWithDryRunPreview($createdCount, $wouldCreateCount, $dryRun, 'create'));
        $this->line('Existing permissions count: '.$existingCount);
        $this->line('Stale permissions count: '.$staleCount);
        $this->line('Deleted stale permissions count: '.$this->countWithDryRunPreview($deletedStaleCount, $wouldDeleteStaleCount, $dryRun, 'delete'));
        $this->line('Admin role used: '.$this->adminRoleLabel($adminRole));

        if ($dryRun && $skipAdminSync) {
            $this->line('Admin assigned permissions count: 0 (dry-run; skip requested via --skip-admin-sync)');
        } elseif (! $dryRun && $skipAdminSync) {
            $this->line('Admin assigned permissions count: skipped (--skip-admin-sync)');
        } else {
            $this->line('Admin assigned permissions count: '.$this->countWithDryRunPreview($adminAssignedPermissionsCount, $wouldAssignAdminPermissionsCount, $dryRun, 'assign'));
        }
    }

    private function countWithDryRunPreview(int $actualCount, int $wouldCount, bool $dryRun, string $verb): string
    {
        if (! $dryRun) {
            return (string) $actualCount;
        }

        return sprintf('%d (dry-run; would %s %d)', $actualCount, $verb, $wouldCount);
    }

    private function adminRoleLabel(?Role $adminRole): string
    {
        if (! $adminRole instanceof Role) {
            return 'not found';
        }

        $label = sprintf('%s [id: %s, guard: %s]', $adminRole->name, $adminRole->getKey(), $adminRole->guard_name);

        if ($adminRole->trashed()) {
            return $label.' (trashed; will be restored on real execution)';
        }

        return $label;
    }

    private function relativePath(string $file): string
    {
        $basePath = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($file, $basePath) ? substr($file, strlen($basePath)) : $file;
    }

    private function guardName(): string
    {
        $guard = config('auth.defaults.guard');

        return is_string($guard) && trim($guard) !== '' ? trim($guard) : 'web';
    }

    private function permissionTableName(string $key): string
    {
        $table = config("permission.table_names.{$key}");

        return is_string($table) && trim($table) !== '' ? $table : $key;
    }

    private function permissionPivotKey(): string
    {
        $permissionPivotKey = config('permission.column_names.permission_pivot_key');

        return is_string($permissionPivotKey) && trim($permissionPivotKey) !== ''
            ? $permissionPivotKey
            : 'permission_id';
    }

    /**
     * @param  list<string>  $scannedFiles
     * @param  Collection<int, string>  $currentPermissionNames
     * @param  Collection<int, string>  $missingPermissionNames
     * @param  Collection<int, string>  $existingPermissionNames
     * @param  Collection<int, Permission>  $stalePermissions
     * @param  list<string>  $adminCurrentPermissions
     * @param  list<string>  $adminPermissionsToAdd
     * @param  list<string>  $adminPermissionsToRemove
     * @param  list<string>  $adminPermissionsRemaining
     */
    private function exportAuditIfRequested(
        mixed $exportAudit,
        array $scannedFiles,
        string $guardName,
        array $permissionNames,
        Collection $currentPermissionNames,
        Collection $missingPermissionNames,
        Collection $existingPermissionNames,
        Collection $stalePermissions,
        ?Role $adminRole,
        array $adminCurrentPermissions,
        array $adminPermissionsToAdd,
        array $adminPermissionsToRemove,
        array $adminPermissionsRemaining,
    ): void {
        if ($exportAudit === false || $exportAudit === null) {
            return;
        }

        $exportPath = is_string($exportAudit) && trim($exportAudit) !== '' ? trim($exportAudit) : '';

        if ($exportPath === '') {
            $this->error('--export-audit requires a file path.');

            return;
        }

        $audit = [
            'generated_at' => now()->toIso8601String(),
            'guard' => $guardName,
            'scanned_files' => array_map(fn (string $f): string => $this->relativePath($f), $scannedFiles),
            'collected_permissions' => $permissionNames,
            'current_db_permissions' => $currentPermissionNames->values()->all(),
            'missing_permissions' => $missingPermissionNames->values()->all(),
            'existing_permissions' => $existingPermissionNames->values()->all(),
            'stale_permissions' => $stalePermissions->pluck('name')->values()->all(),
            'counts' => [
                'collected' => count($permissionNames),
                'current_db' => $currentPermissionNames->count(),
                'missing' => $missingPermissionNames->count(),
                'existing' => $existingPermissionNames->count(),
                'stale' => $stalePermissions->count(),
            ],
            'admin_role' => $adminRole instanceof Role
                ? [
                    'id' => $adminRole->getKey(),
                    'name' => $adminRole->name,
                    'guard_name' => $adminRole->guard_name,
                ]
                : null,
            'admin_current_permissions' => $adminCurrentPermissions,
            'admin_permissions_to_add' => $adminPermissionsToAdd,
            'admin_permissions_to_remove' => $adminPermissionsToRemove,
            'admin_permissions_remaining' => $adminPermissionsRemaining,
            'admin_counts' => [
                'current' => count($adminCurrentPermissions),
                'to_add' => count($adminPermissionsToAdd),
                'to_remove' => count($adminPermissionsToRemove),
                'remaining' => count($adminPermissionsRemaining),
            ],
        ];

        $storagePath = str_starts_with($exportPath, '/') ? $exportPath : base_path($exportPath);
        $dir = dirname($storagePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($storagePath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line(sprintf('Audit exported to: %s', $this->relativePath($storagePath)));
    }
}

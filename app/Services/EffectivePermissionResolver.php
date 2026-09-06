<?php

namespace App\Services;

use App\Models\User;
use BackedEnum;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;

final class EffectivePermissionResolver
{
    private ?Request $activeRequest = null;

    /**
     * @var array<string, array<string, true>>
     */
    private array $permissionNames = [];

    /**
     * @var array<string, bool>
     */
    private array $decisions = [];

    /**
     * @var array<string, array{name: string, guard_name: string}|null>
     */
    private array $permissionIdentities = [];

    public function allows(User $user, mixed $permission, ?string $guardName = null): bool
    {
        $this->synchronizeRequestScope();

        if (config('permission.teams') || config('permission.enable_wildcard_permission')) {
            try {
                return $user->hasPermissionTo($permission, $guardName);
            } catch (PermissionDoesNotExist) {
                return false;
            }
        }

        $identity = $this->permissionIdentity($user, $permission, $guardName);

        if ($identity === null || ! $user->exists || $user->getKey() === null) {
            return false;
        }

        $userKey = $this->userCacheKey($user, $identity['guard_name']);

        if ($this->shouldLoadPermissionSet()) {
            return isset($this->namesFor($user, $identity['guard_name'])[$identity['name']]);
        }

        if (array_key_exists($userKey, $this->permissionNames)) {
            return isset($this->permissionNames[$userKey][$identity['name']]);
        }

        $decisionKey = $userKey.':'.$identity['name'];

        return $this->decisions[$decisionKey] ??= $this->queryAllows(
            $user,
            $identity['name'],
            $identity['guard_name'],
        );
    }

    /**
     * @return array<string, true>
     */
    public function namesFor(User $user, ?string $guardName = null): array
    {
        $this->synchronizeRequestScope();

        if (! $user->exists || $user->getKey() === null) {
            return [];
        }

        $guardName ??= Guard::getDefaultName($user);
        $cacheKey = $this->userCacheKey($user, $guardName);

        return $this->permissionNames[$cacheKey] ??= $this->queryPermissionNames($user, $guardName);
    }

    public function flush(): void
    {
        $this->permissionNames = [];
        $this->decisions = [];
        $this->permissionIdentities = [];
    }

    private function queryAllows(User $user, string $permissionName, string $guardName): bool
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $permissionPivotKey = $columns['permission_pivot_key'] ?? 'permission_id';
        $rolePivotKey = $columns['role_pivot_key'] ?? 'role_id';
        $modelKey = $columns['model_morph_key'] ?? 'model_id';

        return DB::table($tables['permissions'].' as requested_permission')
            ->where('requested_permission.name', $permissionName)
            ->where('requested_permission.guard_name', $guardName)
            ->where(function (Builder $grants) use (
                $guardName,
                $modelKey,
                $permissionPivotKey,
                $rolePivotKey,
                $tables,
                $user,
            ): void {
                $grants->whereExists(function (Builder $directGrant) use (
                    $modelKey,
                    $permissionPivotKey,
                    $tables,
                    $user,
                ): void {
                    $directGrant
                        ->selectRaw('1')
                        ->from($tables['model_has_permissions'].' as direct_assignment')
                        ->whereColumn(
                            'direct_assignment.'.$permissionPivotKey,
                            'requested_permission.id',
                        )
                        ->where('direct_assignment.'.$modelKey, $user->getKey())
                        ->where('direct_assignment.model_type', $user->getMorphClass());
                })->orWhereExists(function (Builder $roleGrant) use (
                    $guardName,
                    $modelKey,
                    $permissionPivotKey,
                    $rolePivotKey,
                    $tables,
                    $user,
                ): void {
                    $roleGrant
                        ->selectRaw('1')
                        ->from($tables['role_has_permissions'].' as role_permission')
                        ->join(
                            $tables['roles'].' as effective_role',
                            'effective_role.id',
                            '=',
                            'role_permission.'.$rolePivotKey,
                        )
                        ->join(
                            $tables['model_has_roles'].' as assigned_role',
                            'assigned_role.'.$rolePivotKey,
                            '=',
                            'effective_role.id',
                        )
                        ->whereColumn(
                            'role_permission.'.$permissionPivotKey,
                            'requested_permission.id',
                        )
                        ->where('assigned_role.'.$modelKey, $user->getKey())
                        ->where('assigned_role.model_type', $user->getMorphClass())
                        ->whereNull('effective_role.deleted_at')
                        ->where('effective_role.guard_name', $guardName);
                });
            })
            ->exists();
    }

    /**
     * @return array<string, true>
     */
    private function queryPermissionNames(User $user, string $guardName): array
    {
        if (config('permission.teams')) {
            return $user->getAllPermissions()
                ->pluck('name')
                ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
                ->mapWithKeys(fn (string $name): array => [$name => true])
                ->all();
        }

        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $permissionPivotKey = $columns['permission_pivot_key'] ?? 'permission_id';
        $rolePivotKey = $columns['role_pivot_key'] ?? 'role_id';
        $modelKey = $columns['model_morph_key'] ?? 'model_id';

        $directPermissions = DB::table($tables['model_has_permissions'].' as direct_assignments')
            ->join(
                $tables['permissions'].' as direct_permissions',
                'direct_permissions.id',
                '=',
                'direct_assignments.'.$permissionPivotKey,
            )
            ->where('direct_assignments.'.$modelKey, $user->getKey())
            ->where('direct_assignments.model_type', $user->getMorphClass())
            ->where('direct_permissions.guard_name', $guardName)
            ->select('direct_permissions.name');

        $rolePermissions = DB::table($tables['model_has_roles'].' as assigned_roles')
            ->join(
                $tables['roles'].' as effective_roles',
                'effective_roles.id',
                '=',
                'assigned_roles.'.$rolePivotKey,
            )
            ->join(
                $tables['role_has_permissions'].' as role_permissions',
                'role_permissions.'.$rolePivotKey,
                '=',
                'effective_roles.id',
            )
            ->join(
                $tables['permissions'].' as inherited_permissions',
                'inherited_permissions.id',
                '=',
                'role_permissions.'.$permissionPivotKey,
            )
            ->where('assigned_roles.'.$modelKey, $user->getKey())
            ->where('assigned_roles.model_type', $user->getMorphClass())
            ->whereNull('effective_roles.deleted_at')
            ->where('effective_roles.guard_name', $guardName)
            ->where('inherited_permissions.guard_name', $guardName)
            ->select('inherited_permissions.name');

        return $directPermissions
            ->union($rolePermissions)
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->mapWithKeys(fn (string $name): array => [$name => true])
            ->all();
    }

    /**
     * @return array{name: string, guard_name: string}|null
     */
    private function permissionIdentity(User $user, mixed $permission, ?string $guardName): ?array
    {
        if ($permission instanceof BackedEnum) {
            $permission = $permission->value;
        }

        if ($permission instanceof Permission) {
            return [
                'name' => $permission->name,
                'guard_name' => $permission->guard_name ?? $guardName ?? Guard::getDefaultName($user),
            ];
        }

        $guardName ??= Guard::getDefaultName($user);

        if (is_int($permission) || PermissionRegistrar::isUid($permission)) {
            return $this->permissionIdentityByKey($permission, $guardName);
        }

        if (! is_string($permission)) {
            return null;
        }

        return ['name' => $permission, 'guard_name' => $guardName];
    }

    /**
     * @return array{name: string, guard_name: string}|null
     */
    private function permissionIdentityByKey(int|string $permissionKey, string $guardName): ?array
    {
        $cacheKey = $guardName.':'.(string) $permissionKey;

        if (array_key_exists($cacheKey, $this->permissionIdentities)) {
            return $this->permissionIdentities[$cacheKey];
        }

        $permissionClass = config('permission.models.permission');
        $permissionModel = new $permissionClass;
        $permission = DB::table($permissionModel->getTable())
            ->where($permissionModel->getKeyName(), $permissionKey)
            ->where('guard_name', $guardName)
            ->first(['name', 'guard_name']);

        if (! $permission || ! is_string($permission->name) || ! is_string($permission->guard_name)) {
            return $this->permissionIdentities[$cacheKey] = null;
        }

        return $this->permissionIdentities[$cacheKey] = [
            'name' => $permission->name,
            'guard_name' => $permission->guard_name,
        ];
    }

    private function userCacheKey(User $user, string $guardName): string
    {
        return implode(':', [$user->getMorphClass(), (string) $user->getKey(), $guardName]);
    }

    private function synchronizeRequestScope(): void
    {
        if (! app()->bound('request')) {
            return;
        }

        $request = app('request');

        if (! $request instanceof Request || $request === $this->activeRequest) {
            return;
        }

        $this->activeRequest = $request;
        $this->flush();
    }

    private function shouldLoadPermissionSet(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        $request = app('request');

        return $request instanceof Request
            && $request->route() !== null
            && $request->isMethod('GET')
            && ! $request->ajax()
            && ! $request->expectsJson()
            && $request->acceptsHtml();
    }
}

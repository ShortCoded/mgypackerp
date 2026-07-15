<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\TaskBoard;

class TaskBoardAccessService
{
    private const AdminUserGroupRoleId = 1;

    public function isAdminUserGroup(User $user): bool
    {
        if ($user->relationLoaded('roles')) {
            return $user->roles->contains(
                fn ($role): bool => (int) $role->getKey() === self::AdminUserGroupRoleId,
            );
        }

        return $user->roles()->whereKey(self::AdminUserGroupRoleId)->exists();
    }

    public function canAccess(User $user, TaskBoard $board, string $permission): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        if ($this->isAdminUserGroup($user)) {
            return true;
        }

        $userId = (int) $user->getKey();

        if ($board->relationLoaded('users') && $board->users->contains(fn (User $assignedUser): bool => (int) $assignedUser->getKey() === $userId)) {
            return true;
        }

        if (! $board->relationLoaded('users') && $board->users()->whereKey($userId)->exists()) {
            return true;
        }

        $roleIds = $this->roleIds($user);

        if ($roleIds === []) {
            return false;
        }

        if ($board->relationLoaded('roles')) {
            return $board->roles->contains(fn ($role): bool => in_array((int) $role->getKey(), $roleIds, true));
        }

        return $board->roles()->whereIn('roles.id', $roleIds)->exists();
    }

    /**
     * @param  Builder<TaskBoard>  $query
     * @return Builder<TaskBoard>
     */
    public function scopeVisibleBoards(Builder $query, User $user, string $permission): Builder
    {
        if (! $user->can($permission)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isAdminUserGroup($user)) {
            return $query;
        }

        $roleIds = $this->roleIds($user);

        return $query->where(function (Builder $visibleQuery) use ($user, $roleIds): void {
            $visibleQuery->whereHas('users', fn (Builder $userQuery): Builder => $userQuery->whereKey($user->getKey()));

            if ($roleIds !== []) {
                $visibleQuery->orWhereHas('roles', fn (Builder $roleQuery): Builder => $roleQuery->whereIn('roles.id', $roleIds));
            }
        });
    }

    /**
     * @return list<int>
     */
    private function roleIds(User $user): array
    {
        if ($user->relationLoaded('roles')) {
            return $user->roles
                ->map(fn ($role): int => (int) $role->getKey())
                ->filter(fn (int $roleId): bool => $roleId > 0)
                ->values()
                ->all();
        }

        return $user->roles()
            ->pluck('roles.id')
            ->map(fn (mixed $roleId): int => (int) $roleId)
            ->filter(fn (int $roleId): bool => $roleId > 0)
            ->values()
            ->all();
    }
}

<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\UserTask;

class UserTaskAccessService
{
    private const AdminUserGroupRoleId = 1;

    /**
     * @return Builder<UserTask>
     */
    public function boardQuery(User $boardOwner, string $recordFilter = 'active'): Builder
    {
        $query = match ($recordFilter) {
            'trashed' => UserTask::onlyTrashed(),
            'all' => UserTask::withTrashed(),
            default => UserTask::query(),
        };

        return $query
            ->with(['assignees:id,name,doc_num,email', 'assignedTo:id,name,doc_num,email', 'assignedBy:id,name,doc_num', 'createdBy:id,name,doc_num', 'boardList:id,doc_num,type,name,slug,status,color,position'])
            ->where(function (Builder $query) use ($boardOwner): void {
                $query
                    ->where('assigned_to', $boardOwner->getKey())
                    ->orWhereHas('assignees', fn (Builder $assigneeQuery) => $assigneeQuery->whereKey($boardOwner->getKey()));
            })
            ->when($recordFilter === 'active', fn (Builder $query): Builder => $query->where('user_tasks.is_active', true))
            ->when($recordFilter === 'inactive', fn (Builder $query): Builder => $query->where('user_tasks.is_active', false))
            ->orderBy('type')
            ->orderBy('board_list_id')
            ->orderBy('position')
            ->orderByDesc('due_at')
            ->orderByDesc('created_at');
    }

    public function canViewBoard(User $user, User $boardOwner): bool
    {
        return (int) $user->getKey() === (int) $boardOwner->getKey()
            || $this->canSwitchBoardUser($user);
    }

    public function canSwitchBoardUser(User $user): bool
    {
        return $this->isAdminUserGroup($user);
    }

    public function isAdminUserGroup(User $user): bool
    {
        if ($user->relationLoaded('roles')) {
            return $user->roles->contains(
                fn ($role): bool => (int) $role->getKey() === self::AdminUserGroupRoleId,
            );
        }

        return $user->roles()->whereKey(self::AdminUserGroupRoleId)->exists();
    }

    public function resolveBoardUser(User $actor, mixed $docNum = null, mixed $id = null): User
    {
        if (! $this->canSwitchBoardUser($actor)) {
            return $actor;
        }

        $docNum = trim((string) $docNum);
        $id = trim((string) $id);

        if (($docNum === '' && $id === '')
            || ($docNum !== '' && $docNum === (string) $actor->doc_num)
            || ($id !== '' && ctype_digit($id) && (int) $id === (int) $actor->getKey())
        ) {
            return $actor;
        }

        $query = User::query()
            ->where('status', 'active')
            ->whereNull('deleted_at');

        if ($docNum !== '') {
            return $query->where('doc_num', $docNum)->firstOrFail();
        }

        abort_unless(ctype_digit($id), 404);

        return $query->whereKey((int) $id)->firstOrFail();
    }

    public function canView(User $user, UserTask $task): bool
    {
        if ($this->isAdminUserGroup($user) && (
            $user->can('my_board.view')
            || $user->can('my_board.view_any')
            || $user->can('my_board.manage_any')
            || ($task->type === UserTask::TypeTask && $user->can('my_board.tasks.view_all'))
            || ($task->type === UserTask::TypeNote && $user->can('my_board.notes.view_all'))
        )) {
            return true;
        }

        if (! $user->can('my_board.view')) {
            return false;
        }

        return $this->isVisibleTo($user, $task);
    }

    public function canEdit(User $user, UserTask $task): bool
    {
        if ($this->isAdminUserGroup($user) && $user->can('my_board.manage_any')) {
            return true;
        }

        if (! $user->can('my_board.edit')) {
            return false;
        }

        return (int) $task->created_by === (int) $user->getKey()
            || $this->isAssignedTo($user, $task);
    }

    public function canDelete(User $user, UserTask $task): bool
    {
        if ($this->isAdminUserGroup($user) && $user->can('my_board.manage_any')) {
            return true;
        }

        if (! $user->can('my_board.delete')) {
            return false;
        }

        return (int) $task->created_by === (int) $user->getKey()
            || $this->isAssignedTo($user, $task);
    }

    public function canClone(User $user, UserTask $task): bool
    {
        if (! $user->can('my_board.clone')) {
            return false;
        }

        return $this->canView($user, $task);
    }

    public function canRestore(User $user, UserTask $task): bool
    {
        if ($this->isAdminUserGroup($user) && $user->can('my_board.manage_any')) {
            return true;
        }

        if (! $user->can('my_board.restore')) {
            return false;
        }

        return $this->isVisibleTo($user, $task);
    }

    public function canMove(User $user, UserTask $task): bool
    {
        if ($this->isAdminUserGroup($user) && $user->can('my_board.manage_any')) {
            return true;
        }

        if (! $user->can('my_board.reorder')) {
            return false;
        }

        return $this->isVisibleTo($user, $task);
    }

    public function isVisibleTo(User $user, UserTask $task): bool
    {
        $userId = (int) $user->getKey();

        return (int) $task->created_by === $userId
            || (int) $task->assigned_to === $userId
            || (int) $task->assigned_by === $userId
            || $this->isAssignedTo($user, $task);
    }

    public function canAssign(User $user): bool
    {
        return $this->isAdminUserGroup($user)
            && ($user->can('my_board.assign') || $user->can('my_board.manage_any'));
    }

    private function isAssignedTo(User $user, UserTask $task): bool
    {
        $userId = (int) $user->getKey();

        if ((int) $task->assigned_to === $userId) {
            return true;
        }

        if ($task->relationLoaded('assignees')) {
            return $task->assignees->contains(fn (User $assignee): bool => (int) $assignee->getKey() === $userId);
        }

        return $task->assignees()->whereKey($userId)->exists();
    }
}

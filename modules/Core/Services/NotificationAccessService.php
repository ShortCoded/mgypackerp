<?php

namespace Modules\Core\Services;

use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\UserNotification;

class NotificationAccessService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly OperatingScopeAccessService $operatingScope,
    ) {}

    /**
     * @return Builder<UserNotification>
     */
    public function queryFor(User $user): Builder
    {
        $query = UserNotification::query()
            ->forUser($user)
            ->where(function (Builder $query): void {
                $query->whereNull('conversation_id')
                    ->orWhereExists(function ($participants): void {
                        $participants
                            ->selectRaw('1')
                            ->from('chat_conversation_user')
                            ->whereColumn('chat_conversation_user.conversation_id', 'user_notifications.conversation_id')
                            ->whereColumn('chat_conversation_user.user_id', 'user_notifications.user_id')
                            ->whereNull('chat_conversation_user.deleted_at');
                    });
            });

        $permissionNames = array_keys($this->permissions->namesFor($user));

        $query->where(function (Builder $query) use ($permissionNames): void {
            $query->whereNull('required_permission');

            if ($permissionNames !== []) {
                $query->orWhereIn('required_permission', $permissionNames);
            }
        });

        if (! $this->operatingScope->hasUnrestrictedCompanyAccess($user)) {
            $companyIds = $this->operatingScope->allowedCompanyQuery($user)->pluck('companies.id');
            $query->where(function (Builder $query) use ($companyIds): void {
                $query->whereNull('user_notifications.company_id')
                    ->orWhereIn('user_notifications.company_id', $companyIds->all() ?: [0]);
            });
        }

        if (! $this->operatingScope->hasUnrestrictedBranchAccess($user)) {
            $branchIds = $this->operatingScope->allowedBranchQuery($user)->pluck('branches.id');
            $query->where(function (Builder $query) use ($branchIds): void {
                $query->whereNull('user_notifications.branch_id')
                    ->orWhereIn('user_notifications.branch_id', $branchIds->all() ?: [0]);
            });
        }

        return $query;
    }

    public function allows(User $user, UserNotification $notification): bool
    {
        if ($user->status !== 'active' || $user->trashed()) {
            return false;
        }

        return $this->queryFor($user)
            ->whereKey($notification->getKey())
            ->exists();
    }
}

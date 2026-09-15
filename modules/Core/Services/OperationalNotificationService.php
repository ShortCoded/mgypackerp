<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class OperationalNotificationService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly OperatingScopeAccessService $operatingScope,
    ) {}

    /**
     * @param  list<int|null>  $explicitUserIds
     * @param  array<string, mixed>  $metadata
     */
    public function send(
        Model $subject,
        string $type,
        string $title,
        string $body,
        ?string $url,
        ?string $recipientPermission,
        array $explicitUserIds = [],
        array $metadata = [],
        string $severity = 'action',
        bool $requiresAction = true,
        ?string $soundKey = 'action',
        ?string $externalBody = null,
        ?string $viewPermission = null,
    ): void {
        $actorId = $this->actorId($subject);
        $companyId = $this->integerAttribute($subject, 'company_id');
        $branchId = $this->integerAttribute($subject, 'branch_id');
        $eventUuid = (string) Str::uuid();
        $recipientResolution = $this->recipients($recipientPermission, $viewPermission, $explicitUserIds, $companyId, $branchId, $actorId);
        $recipients = $recipientResolution['users']
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->values();

        foreach ($recipients as $recipient) {
            $fallbackToAdministrators = $recipientResolution['fallback_user_ids']->contains((int) $recipient->getKey());
            $requiredPermission = is_string($recipientPermission) && $recipient->can($recipientPermission)
                ? $recipientPermission
                : $viewPermission;

            $this->notifications->createImmediate(
                $recipient,
                $type,
                $fallbackToAdministrators ? __('notifications.operational.unassigned_title') : $title,
                $fallbackToAdministrators ? __('notifications.operational.unassigned_body') : $body,
                $url,
                [
                    ...$metadata,
                    'actor_id' => $actorId > 0 ? $actorId : null,
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->getKey(),
                ],
                "{$type}:{$eventUuid}:{$recipient->getKey()}",
                [
                    'event_uuid' => $eventUuid,
                    'module' => Str::before($type, '.'),
                    'severity' => $severity,
                    'requires_action' => $requiresAction,
                    'sound_key' => $soundKey,
                    'external_title' => $fallbackToAdministrators ? __('notifications.operational.unassigned_title') : $title,
                    'external_body' => $fallbackToAdministrators ? __('notifications.operational.unassigned_external_body') : ($externalBody ?? $body),
                    'required_permission' => $requiredPermission,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                ],
            );
        }
    }

    /**
     * @param  list<int|null>  $explicitUserIds
     * @return array{users: Collection<int, User>, fallback_user_ids: Collection<int, int>}
     */
    private function recipients(?string $permission, ?string $viewPermission, array $explicitUserIds, ?int $companyId, ?int $branchId, int $actorId): array
    {
        $ids = collect($explicitUserIds)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        $candidateIds = $ids;

        $permissionExists = is_string($permission)
            && $permission !== ''
            && Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists();

        if ($permissionExists) {
            $candidateIds = $candidateIds
                ->merge(User::permission($permission)->pluck('users.id')->map(fn (mixed $id): int => (int) $id))
                ->unique();
        }

        $users = User::query()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereKey($candidateIds)
            ->with('roles:id,name,guard_name,company_access_restricted,branch_access_restricted,financial_period_access_restricted')
            ->get()
            ->filter(function (User $user) use ($ids, $permission, $permissionExists, $viewPermission): bool {
                return ($ids->contains((int) $user->getKey()) && is_string($viewPermission) && $user->can($viewPermission))
                    || ($permissionExists && is_string($permission) && $user->can($permission));
            })
            ->reject(fn (User $user): bool => (int) $user->getKey() === $actorId)
            ->filter(fn (User $user): bool => $this->withinScope($user, $companyId, $branchId));

        $fallbackUserIds = collect();
        $hasActionRecipient = $permissionExists
            && $users->contains(fn (User $user): bool => $user->can($permission));

        if (! $hasActionRecipient && is_string($permission) && $permission !== '') {
            $fallbackUsers = User::query()
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->whereHas('roles', fn ($query) => $query->where('roles.name', 'admin')->where('roles.guard_name', 'web'))
                ->get()
                ->reject(fn (User $user): bool => (int) $user->getKey() === $actorId)
                ->filter(fn (User $user): bool => $this->withinScope($user, $companyId, $branchId));
            $fallbackUserIds = $fallbackUsers->map(fn (User $user): int => (int) $user->getKey())->values();
            $users = $users->concat($fallbackUsers)->unique(fn (User $user): int => (int) $user->getKey());
        }

        return ['users' => $users->values(), 'fallback_user_ids' => $fallbackUserIds];
    }

    private function withinScope(User $user, ?int $companyId, ?int $branchId): bool
    {
        if ($companyId !== null && ! $this->operatingScope->hasUnrestrictedCompanyAccess($user)) {
            if (! $this->operatingScope->allowedCompanyQuery($user)->whereKey($companyId)->exists()) {
                return false;
            }
        }

        if ($branchId !== null && ! $this->operatingScope->hasUnrestrictedBranchAccess($user)) {
            if (! $this->operatingScope->allowedBranchQuery($user)->whereKey($branchId)->exists()) {
                return false;
            }
        }

        return true;
    }

    private function actorId(Model $subject): int
    {
        $authenticatedUserId = auth()->id();

        if (is_numeric($authenticatedUserId)) {
            return (int) $authenticatedUserId;
        }

        foreach (['updated_by', 'created_by', 'submitted_by'] as $attribute) {
            $userId = $this->integerAttribute($subject, $attribute);

            if ($userId !== null) {
                return $userId;
            }
        }

        return 0;
    }

    private function integerAttribute(Model $subject, string $attribute): ?int
    {
        $value = $subject->getAttribute($attribute);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}

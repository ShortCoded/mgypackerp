<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Modules\Core\Services\RequestMemo;

class UserAccountStatusService
{
    public function __construct(
        private readonly RequestMemo $memo,
        private readonly LoginIdentifierResolver $loginIdentifiers,
    ) {}

    public function resolveForLogin(string $identifier): LoginIdentifierResolution
    {
        return $this->loginIdentifiers->resolve($identifier);
    }

    public function freshUser(User $user): ?User
    {
        return $this->freshUserById($user->getKey());
    }

    public function freshUserById(mixed $userId): ?User
    {
        if (! is_numeric($userId) && ! is_string($userId)) {
            return null;
        }

        $userId = (string) $userId;

        return $this->memo->remember("auth.fresh_user.{$userId}", fn (): ?User => User::withTrashed()->find($userId));
    }

    public function isActive(?User $user): bool
    {
        return $this->inactiveReason($user) === null;
    }

    public function inactiveReason(?User $user): ?string
    {
        if (! $user instanceof User) {
            return 'missing_account';
        }

        if (method_exists($user, 'trashed') && $user->trashed()) {
            return 'deleted_account';
        }

        return match ($user->status) {
            'active' => null,
            'blocked' => 'blocked_account',
            'inactive' => 'inactive_account',
            default => 'inactive_account',
        };
    }

    public function messageKey(?string $reason): string
    {
        return match ($reason) {
            'deleted_account', 'missing_account' => 'auth.messages.account_deleted',
            'blocked_account' => 'auth.messages.account_blocked',
            'inactive_account' => 'auth.messages.account_inactive',
            default => 'auth.messages.invalid_credentials',
        };
    }

    public function message(?string $reason): string
    {
        return __($this->messageKey($reason));
    }
}

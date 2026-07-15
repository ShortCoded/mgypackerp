<?php

namespace Modules\Auth\Services;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\UserPresenceSession;

class OnlineSeatLimitService
{
    private const DefaultMaxOnlineUsers = 10;

    private const LockName = 'auth:online-seat-limit';

    public function __construct(
        private readonly UserPresenceService $presence,
    ) {}

    public function maxOnlineUsers(): int
    {
        $value = config('erp_seats.max_online_users');

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return self::DefaultMaxOnlineUsers;
    }

    public function currentOnlineUsersCount(): int
    {
        $query = UserPresenceSession::query()
            ->whereNotNull('user_presence_sessions.user_id')
            ->distinct('user_presence_sessions.user_id');

        $this->presence->applyFreshActiveSessionConstraints($query);

        return $query->count('user_presence_sessions.user_id');
    }

    public function seatsAreFull(): bool
    {
        return $this->currentOnlineUsersCount() >= $this->maxOnlineUsers();
    }

    public function assertSeatAvailable(): void
    {
        if (! $this->seatsAreFull()) {
            return;
        }

        throw ValidationException::withMessages([
            'login' => __('auth.messages.seat_limit_reached'),
        ]);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withinSeatLimitLock(
        Closure $callback,
        string $validationField = 'login',
        ?string $validationMessage = null
    ): mixed {
        try {
            return Cache::lock(self::LockName, 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                $validationField => $validationMessage ?? __('auth.messages.seat_limit_reached'),
            ]);
        }
    }
}

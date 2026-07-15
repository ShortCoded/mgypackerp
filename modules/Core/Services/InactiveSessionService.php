<?php

namespace Modules\Core\Services;

use Illuminate\Http\Request;

class InactiveSessionService
{
    public const LastActivitySessionKey = 'auth_last_activity_at';

    public function lifetimeSeconds(): int
    {
        return max(1, (int) config('session.lifetime', 120)) * 60;
    }

    public function lastActivityAt(Request $request): ?int
    {
        $lastActivityAt = $request->session()->get(self::LastActivitySessionKey);

        return is_numeric($lastActivityAt) ? (int) $lastActivityAt : null;
    }

    public function secondsRemaining(Request $request): int
    {
        $lastActivityAt = $this->lastActivityAt($request);

        if ($lastActivityAt === null) {
            return $this->lifetimeSeconds();
        }

        return max(0, $this->lifetimeSeconds() - (now()->getTimestamp() - $lastActivityAt));
    }

    public function expiresAt(?int $lastActivityAt = null): int
    {
        return ($lastActivityAt ?? now()->getTimestamp()) + $this->lifetimeSeconds();
    }

    public function isExpired(Request $request): bool
    {
        $lastActivityAt = $this->lastActivityAt($request);

        return $lastActivityAt !== null
            && now()->getTimestamp() - $lastActivityAt >= $this->lifetimeSeconds();
    }

    public function touch(Request $request): int
    {
        $timestamp = now()->getTimestamp();

        $request->session()->put(self::LastActivitySessionKey, $timestamp);

        return $timestamp;
    }
}

<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\RequestMemo;
use Throwable;

class UserPresenceService
{
    private const SessionFingerprintsSessionKey = 'auth_presence_session_fingerprints';

    public const StatusOnline = 'online';

    public const StatusIdle = 'idle';

    public const StatusLocked = 'locked';

    public const StatusOffline = 'offline';

    public const ReasonLogout = 'logout';

    public const ReasonSessionExpired = 'session_expired';

    public const ReasonHeartbeatTimeout = 'heartbeat_timeout';

    public const ReasonAccountBlocked = 'account_blocked';

    public const ReasonAccountDeleted = 'account_deleted';

    public const ReasonAccountInactive = 'account_inactive';

    public const ReasonForcedLogout = 'forced_logout';

    public const ReasonUnknown = 'unknown';

    public function __construct(
        private readonly AuthLogService $authLogs,
        private readonly OperatingContextService $operatingContext,
        private readonly RequestMemo $memo,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function markOnline(Request $request, ?User $user = null, array $context = []): ?UserPresenceSession
    {
        return $this->write($request, $user, self::StatusOnline, $context + [
            'login_at' => now(),
            'last_seen_at' => now(),
            'last_activity_at' => now(),
            'locked_at' => null,
            'logout_at' => null,
            'offline_reason' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function touch(Request $request, ?User $user = null, bool $activity = true, array $context = []): ?UserPresenceSession
    {
        if ($this->shouldThrottleTrackedActivityTouch($request, $context)) {
            return null;
        }

        $status = $this->isLocked($request) ? self::StatusLocked : self::StatusOnline;
        $timestamps = [
            'last_seen_at' => now(),
            'offline_reason' => null,
            'logout_at' => null,
        ];

        if ($activity) {
            $timestamps['last_activity_at'] = now();
        }

        if ($status === self::StatusLocked) {
            $timestamps['locked_at'] = now();
        } else {
            $timestamps['locked_at'] = null;
        }

        $presenceSession = $this->write($request, $user, $status, $context + $timestamps);

        if ($presenceSession instanceof UserPresenceSession) {
            $this->rememberTrackedActivityTouch($request, $context);
        }

        return $presenceSession;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function markLocked(Request $request, ?User $user = null, array $context = []): ?UserPresenceSession
    {
        return $this->write($request, $user, self::StatusLocked, $context + [
            'last_seen_at' => now(),
            'locked_at' => now(),
            'logout_at' => null,
            'offline_reason' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function markOffline(Request $request, ?User $user = null, string $reason = self::ReasonUnknown, array $context = []): ?UserPresenceSession
    {
        return $this->write($request, $user, self::StatusOffline, $context + [
            'last_seen_at' => now(),
            'logout_at' => now(),
            'offline_reason' => $reason,
        ]);
    }

    public function markFingerprintOffline(?string $sessionFingerprint, string $reason = self::ReasonForcedLogout): void
    {
        if ($sessionFingerprint === null) {
            return;
        }

        $this->markFingerprintsOffline([$sessionFingerprint], $reason);
    }

    public function currentSessionWasForcedLogout(Request $request): bool
    {
        $sessionFingerprint = $this->sessionFingerprint($request);

        if ($sessionFingerprint === null) {
            return false;
        }

        return (bool) $this->memo->remember("presence.forced_logout.{$sessionFingerprint}", fn (): bool => UserPresenceSession::query()
            ->where('session_fingerprint', $sessionFingerprint)
            ->where('status', self::StatusOffline)
            ->where('offline_reason', self::ReasonForcedLogout)
            ->exists());
    }

    public function deleteDatabaseSessionForFingerprint(?string $sessionFingerprint): bool
    {
        if ($sessionFingerprint === null || trim($sessionFingerprint) === '') {
            return false;
        }

        $table = (string) config('session.table', 'sessions');

        if (config('session.driver') !== 'database' || ! Schema::hasTable($table)) {
            return false;
        }

        try {
            foreach (DB::table($table)->select('id')->orderBy('last_activity')->cursor() as $session) {
                $sessionId = is_string($session->id ?? null) ? $session->id : null;

                if ($sessionId === null || $this->fingerprintForSessionId($sessionId) !== $sessionFingerprint) {
                    continue;
                }

                return DB::table($table)->where('id', $sessionId)->delete() > 0;
            }
        } catch (Throwable $exception) {
            $this->reportFailure('Database session deletion by fingerprint failed.', $exception);
        }

        return false;
    }

    /**
     * @param  list<string>  $sessionFingerprints
     */
    public function markFingerprintsOffline(array $sessionFingerprints, string $reason = self::ReasonForcedLogout): void
    {
        $sessionFingerprints = $this->normalizeFingerprints($sessionFingerprints);

        if ($sessionFingerprints === []) {
            return;
        }

        try {
            UserPresenceSession::query()
                ->whereIn('session_fingerprint', $sessionFingerprints)
                ->whereIn('status', [self::StatusOnline, self::StatusIdle, self::StatusLocked])
                ->update([
                    'status' => self::StatusOffline,
                    'logout_at' => now(),
                    'offline_reason' => $reason,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            $this->reportFailure('Presence fingerprint offline update failed.', $exception);
        }
    }

    public function hasActiveSession(User $user, ?string $currentSessionFingerprint = null): bool
    {
        return $this->activeSessionsQuery($user, $currentSessionFingerprint, Carbon::now())->exists();
    }

    public function hasAnotherFreshActiveSession(
        User $user,
        string|array|null $currentSessionFingerprints = null,
        ?string $currentLockFlowFingerprint = null
    ): bool {
        return $this->anotherFreshActiveSessionCount($user, $currentSessionFingerprints, $currentLockFlowFingerprint) > 0;
    }

    public function anotherFreshActiveSessionCount(
        User $user,
        string|array|null $currentSessionFingerprints = null,
        ?string $currentLockFlowFingerprint = null
    ): int {
        return $this->activeSessionCountForUser($user, $currentSessionFingerprints, $currentLockFlowFingerprint);
    }

    public function anotherFreshActiveSessionCountForRequest(User $user, Request $request): int
    {
        return $this->anotherFreshActiveSessionCount(
            $user,
            $this->sessionFingerprints($request),
            $this->lockFlowFingerprint($request)
        );
    }

    public function activeSessionCountForUser(
        User $user,
        string|array|null $exceptSessionFingerprints = null,
        ?string $exceptLockFlowFingerprint = null
    ): int {
        return $this->activeSessionsQuery($user, $exceptSessionFingerprints, Carbon::now(), $exceptLockFlowFingerprint)->count();
    }

    /**
     * @return Collection<int, UserPresenceSession>
     */
    public function activeSessionsForUser(
        User $user,
        string|array|null $exceptSessionFingerprints = null,
        ?string $exceptLockFlowFingerprint = null
    ): Collection {
        return $this->activeSessionsQuery($user, $exceptSessionFingerprints, Carbon::now(), $exceptLockFlowFingerprint)->get();
    }

    public function isFreshActiveSession(UserPresenceSession $session, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();
        $status = trim((string) $session->status);
        $lastSeenAt = $session->last_seen_at;
        $expiresAt = $session->expires_at;

        if (! in_array($status, $this->activeStatuses(), true)) {
            return false;
        }

        if ($session->logout_at !== null) {
            return false;
        }

        if (! $lastSeenAt instanceof Carbon) {
            return false;
        }

        if ($lastSeenAt->lte($now->copy()->subSeconds($this->duplicateLoginActiveThresholdSeconds()))) {
            return false;
        }

        return ! ($expiresAt instanceof Carbon && $expiresAt->lte($now));
    }

    public function effectivePresenceStatus(UserPresenceSession $session, ?Carbon $now = null): string
    {
        return $this->isFreshActiveSession($session, $now)
            ? trim((string) $session->status)
            : self::StatusOffline;
    }

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function effectivePresenceStatusRankSql(?Carbon $now = null, string $table = 'user_presence_sessions'): array
    {
        $now ??= Carbon::now();
        $activeSince = $now->copy()->subSeconds($this->duplicateLoginActiveThresholdSeconds());
        $freshActiveCondition = "{$table}.logout_at IS NULL
            AND {$table}.last_seen_at IS NOT NULL
            AND {$table}.last_seen_at > ?
            AND ({$table}.expires_at IS NULL OR {$table}.expires_at > ?)";

        return [
            'sql' => "CASE
                WHEN {$table}.status = ? AND {$freshActiveCondition} THEN 1
                WHEN {$table}.status = ? AND {$freshActiveCondition} THEN 2
                WHEN {$table}.status = ? AND {$freshActiveCondition} THEN 3
                WHEN {$table}.status = ? THEN 4
                WHEN {$table}.status IN (?, ?, ?) THEN 4
                ELSE 99
            END",
            'bindings' => [
                self::StatusOnline,
                $activeSince,
                $now,
                self::StatusIdle,
                $activeSince,
                $now,
                self::StatusLocked,
                $activeSince,
                $now,
                self::StatusOffline,
                self::StatusOnline,
                self::StatusIdle,
                self::StatusLocked,
            ],
        ];
    }

    /**
     * @param  Builder<UserPresenceSession>  $query
     * @return Builder<UserPresenceSession>
     */
    public function applyFreshActiveSessionConstraints(Builder $query, ?Carbon $now = null, string $table = 'user_presence_sessions'): Builder
    {
        $now ??= Carbon::now();
        $activeSince = $now->copy()->subSeconds($this->duplicateLoginActiveThresholdSeconds());

        return $query
            ->whereIn("{$table}.status", $this->activeStatuses())
            ->whereNull("{$table}.logout_at")
            ->whereNotNull("{$table}.last_seen_at")
            ->where("{$table}.last_seen_at", '>', $activeSince)
            ->where(function ($query) use ($now, $table): void {
                $query->whereNull("{$table}.expires_at")
                    ->orWhere("{$table}.expires_at", '>', $now);
            });
    }

    /**
     * @param  Builder<UserPresenceSession>  $query
     * @return Builder<UserPresenceSession>
     */
    public function applyEffectivePresenceStatusConstraint(Builder $query, string $status, ?Carbon $now = null, string $table = 'user_presence_sessions'): Builder
    {
        $now ??= Carbon::now();
        $status = trim($status);

        if (in_array($status, $this->activeStatuses(), true)) {
            return $this->applyFreshActiveSessionConstraints(
                $query->where("{$table}.status", $status),
                $now,
                $table
            );
        }

        if ($status !== self::StatusOffline) {
            return $query->whereRaw('1 = 0');
        }

        $activeSince = $now->copy()->subSeconds($this->duplicateLoginActiveThresholdSeconds());

        return $query->where(function ($query) use ($activeSince, $now, $table): void {
            $query->where("{$table}.status", self::StatusOffline)
                ->orWhere(function ($query) use ($activeSince, $now, $table): void {
                    $query
                        ->whereIn("{$table}.status", $this->activeStatuses())
                        ->where(function ($query) use ($activeSince, $now, $table): void {
                            $query->whereNotNull("{$table}.logout_at")
                                ->orWhereNull("{$table}.last_seen_at")
                                ->orWhere("{$table}.last_seen_at", '<=', $activeSince)
                                ->orWhere(function ($query) use ($now, $table): void {
                                    $query->whereNotNull("{$table}.expires_at")
                                        ->where("{$table}.expires_at", '<=', $now);
                                });
                        });
                });
        });
    }

    public function markStaleSessionsOfflineForUser(User $user): int
    {
        try {
            $now = now();
            $duplicateLoginActiveBefore = $now->copy()->subSeconds($this->duplicateLoginActiveThresholdSeconds());

            $expired = UserPresenceSession::query()
                ->where('user_id', $user->id)
                ->whereIn('status', $this->activeStatuses())
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->update([
                    'status' => self::StatusOffline,
                    'logout_at' => $now,
                    'offline_reason' => self::ReasonSessionExpired,
                    'updated_at' => $now,
                ]);

            $timedOut = UserPresenceSession::query()
                ->where('user_id', $user->id)
                ->whereIn('status', $this->activeStatuses())
                ->where(function ($query) use ($duplicateLoginActiveBefore): void {
                    $query->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<=', $duplicateLoginActiveBefore);
                })
                ->update([
                    'status' => self::StatusOffline,
                    'logout_at' => $now,
                    'offline_reason' => self::ReasonHeartbeatTimeout,
                    'updated_at' => $now,
                ]);

            return $expired + $timedOut;
        } catch (Throwable $exception) {
            $this->reportFailure('User stale presence cleanup failed.', $exception);

            return 0;
        }
    }

    public function sessionFingerprint(Request $request): ?string
    {
        return $this->authLogs->sessionFingerprint($request);
    }

    public function fingerprintForSessionId(string $sessionId): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if (is_string($decoded) && $decoded !== '') {
                $key = $decoded;
            }
        }

        return hash_hmac('sha256', $sessionId, $key !== '' ? $key : (string) config('app.name', 'laravel'));
    }

    /**
     * @return list<string>
     */
    public function sessionFingerprints(Request $request): array
    {
        return $this->normalizeFingerprints([
            $this->sessionFingerprint($request),
            ...$this->storedSessionFingerprints($request),
        ]);
    }

    public function currentSessionForRequest(Request $request, User $user): ?UserPresenceSession
    {
        $sessionFingerprints = $this->sessionFingerprints($request);

        if ($sessionFingerprints === []) {
            return null;
        }

        return UserPresenceSession::query()
            ->where('user_id', $user->id)
            ->whereIn('session_fingerprint', $sessionFingerprints)
            ->orderByRaw(
                'CASE WHEN status = ? THEN 0 ELSE 1 END',
                [self::StatusLocked]
            )
            ->latest('last_seen_at')
            ->first();
    }

    public function isFreshLockedCurrentSession(?UserPresenceSession $session, ?Carbon $now = null): bool
    {
        return $session instanceof UserPresenceSession
            && trim((string) $session->status) === self::StatusLocked
            && $this->isFreshActiveSession($session, $now);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function markCurrentSessionOnline(
        Request $request,
        User $user,
        UserPresenceSession $presenceSession,
        array $context = []
    ): ?UserPresenceSession {
        try {
            $sessionFingerprints = $this->sessionFingerprints($request);

            if (! in_array((string) $presenceSession->session_fingerprint, $sessionFingerprints, true)) {
                return null;
            }

            if ((int) $presenceSession->user_id !== (int) $user->id) {
                return null;
            }

            $device = $this->authLogs->deviceSummary($request);
            $now = Carbon::now();
            $values = array_merge([
                'user_id' => $user->id,
                'status' => self::StatusOnline,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'browser_name' => $device['browser']['name'] ?? null,
                'os_name' => $device['os']['name'] ?? null,
                'device_type' => $device['device']['type'] ?? null,
                'last_seen_at' => $now,
                'last_activity_at' => $now,
                'locked_at' => null,
                'logout_at' => null,
                'offline_reason' => null,
                'expires_at' => $this->expiresAt($now),
                'context' => $this->context($request, $context),
            ], $this->operatingContextColumns($request));

            $presenceSession->forceFill($values)->save();
            $this->rememberSessionFingerprint($request, (string) $presenceSession->session_fingerprint);

            return $presenceSession->refresh();
        } catch (Throwable $exception) {
            $this->reportFailure('Current presence unlock update failed.', $exception);

            return null;
        }
    }

    public function lockFlowFingerprint(Request $request): ?string
    {
        try {
            if (! $request->hasSession()) {
                return null;
            }

            $lockToken = $request->session()->get(LockScreenService::LockTokenSessionKey);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($lockToken) || trim($lockToken) === '') {
            return null;
        }

        return hash_hmac('sha256', $lockToken, (string) config('app.key'));
    }

    public function offlineReasonForAccountStatus(string $reason): string
    {
        return match ($reason) {
            'deleted_account', 'missing_account' => self::ReasonAccountDeleted,
            'blocked_account' => self::ReasonAccountBlocked,
            'inactive_account' => self::ReasonAccountInactive,
            default => self::ReasonForcedLogout,
        };
    }

    /**
     * @return array{offline: int, idle: int}
     */
    public function markStaleSessionsOffline(): array
    {
        try {
            $now = now();
            $idleBefore = $now->copy()->subSeconds($this->idleAfterSeconds());
            $offlineBefore = $now->copy()->subSeconds($this->duplicateLoginActiveThresholdSeconds());

            $idle = UserPresenceSession::query()
                ->where('status', self::StatusOnline)
                ->whereNotNull('last_activity_at')
                ->where('last_activity_at', '<=', $idleBefore)
                ->where(function ($query) use ($offlineBefore): void {
                    $query->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '>', $offlineBefore);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                })
                ->update([
                    'status' => self::StatusIdle,
                    'updated_at' => $now,
                ]);

            $expired = UserPresenceSession::query()
                ->whereIn('status', $this->activeStatuses())
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->update([
                    'status' => self::StatusOffline,
                    'logout_at' => $now,
                    'offline_reason' => self::ReasonSessionExpired,
                    'updated_at' => $now,
                ]);

            $timedOut = UserPresenceSession::query()
                ->whereIn('status', $this->activeStatuses())
                ->where(function ($query) use ($offlineBefore): void {
                    $query->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<=', $offlineBefore);
                })
                ->update([
                    'status' => self::StatusOffline,
                    'logout_at' => $now,
                    'offline_reason' => self::ReasonHeartbeatTimeout,
                    'updated_at' => $now,
                ]);

            return [
                'offline' => $expired + $timedOut,
                'idle' => $idle,
            ];
        } catch (Throwable $exception) {
            $this->reportFailure('Stale presence cleanup failed.', $exception);

            return [
                'offline' => 0,
                'idle' => 0,
            ];
        }
    }

    private function activeSessionsQuery(
        User $user,
        string|array|null $exceptSessionFingerprints,
        Carbon $now,
        ?string $exceptLockFlowFingerprint = null
    ): Builder {
        $exceptSessionFingerprints = $this->normalizeFingerprints($exceptSessionFingerprints);

        $query = UserPresenceSession::query()
            ->where('user_id', $user->id);

        $this->applyFreshActiveSessionConstraints($query, $now);

        if ($exceptSessionFingerprints !== []) {
            $query->where(function ($query) use ($exceptSessionFingerprints): void {
                $query->whereNull('session_fingerprint')
                    ->orWhereNotIn('session_fingerprint', $exceptSessionFingerprints);
            });
        }

        if ($exceptLockFlowFingerprint !== null && trim($exceptLockFlowFingerprint) !== '') {
            $query->where(function ($query) use ($exceptLockFlowFingerprint): void {
                $query->whereNull('context->lock_flow_fingerprint')
                    ->orWhere('context->lock_flow_fingerprint', '!=', $exceptLockFlowFingerprint);
            });
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    public function activeStatuses(): array
    {
        return [
            self::StatusOnline,
            self::StatusIdle,
            self::StatusLocked,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(Request $request, ?User $user, string $status, array $attributes): ?UserPresenceSession
    {
        try {
            $user ??= $request->user();

            if (! $user instanceof User) {
                return null;
            }

            $sessionFingerprint = $this->sessionFingerprint($request);

            if ($sessionFingerprint === null) {
                return null;
            }

            $device = $this->authLogs->deviceSummary($request);
            $now = Carbon::now();
            $values = array_merge([
                'user_id' => $user->id,
                'status' => $status,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'browser_name' => $device['browser']['name'] ?? null,
                'os_name' => $device['os']['name'] ?? null,
                'device_type' => $device['device']['type'] ?? null,
                'expires_at' => $this->expiresAt($now),
                'context' => $this->context($request, $attributes),
            ], $this->operatingContextColumns($request), $this->onlyPresenceAttributes($attributes));

            $presenceSession = UserPresenceSession::query()->updateOrCreate(
                ['session_fingerprint' => $sessionFingerprint],
                $values
            );

            $this->rememberSessionFingerprint($request, $sessionFingerprint);

            return $presenceSession;
        } catch (Throwable $exception) {
            $this->reportFailure('Presence write failed.', $exception);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function onlyPresenceAttributes(array $attributes): array
    {
        return collect($attributes)
            ->only([
                'login_at',
                'last_seen_at',
                'last_activity_at',
                'locked_at',
                'logout_at',
                'offline_reason',
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function operatingContextColumns(Request $request): array
    {
        if (! Schema::hasTable('user_presence_sessions')) {
            return [];
        }

        $columns = $this->memo->remember('schema.columns.user_presence_sessions', fn (): array => Schema::getColumnListing('user_presence_sessions'));

        return collect($this->operatingContext->snapshot($request))
            ->only($columns)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    private function context(Request $request, array $attributes): ?array
    {
        $context = collect($attributes)
            ->except([
                'login_at',
                'last_seen_at',
                'last_activity_at',
                'locked_at',
                'logout_at',
                'offline_reason',
            ])
            ->all();

        $context['path'] = $request->path();
        $context['route_name'] = $request->route()?->getName();

        $lockFlowFingerprint = $this->lockFlowFingerprint($request);

        if ($lockFlowFingerprint !== null) {
            $context['lock_flow_fingerprint'] = $lockFlowFingerprint;
        }

        $safe = $this->authLogs->safeArray($context);

        return $safe === [] ? null : $safe;
    }

    private function rememberSessionFingerprint(Request $request, string $sessionFingerprint): void
    {
        try {
            if (! $request->hasSession()) {
                return;
            }

            $request->session()->put(
                self::SessionFingerprintsSessionKey,
                array_slice($this->normalizeFingerprints([
                    $sessionFingerprint,
                    ...$this->storedSessionFingerprints($request),
                ]), 0, 3)
            );
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @return list<string>
     */
    private function storedSessionFingerprints(Request $request): array
    {
        try {
            if (! $request->hasSession()) {
                return [];
            }

            $value = $request->session()->get(self::SessionFingerprintsSessionKey, []);
        } catch (Throwable) {
            return [];
        }

        if (is_string($value)) {
            return $this->normalizeFingerprints([$value]);
        }

        if (! is_array($value)) {
            return [];
        }

        return $this->normalizeFingerprints($value);
    }

    /**
     * @param  string|array<int, mixed>|null  $sessionFingerprints
     * @return list<string>
     */
    private function normalizeFingerprints(string|array|null $sessionFingerprints): array
    {
        if (is_string($sessionFingerprints) || $sessionFingerprints === null) {
            $sessionFingerprints = [$sessionFingerprints];
        }

        return collect($sessionFingerprints)
            ->filter(fn (mixed $sessionFingerprint): bool => is_string($sessionFingerprint) && trim($sessionFingerprint) !== '')
            ->map(fn (string $sessionFingerprint): string => trim($sessionFingerprint))
            ->unique()
            ->values()
            ->all();
    }

    private function expiresAt(Carbon $now): Carbon
    {
        return $now->copy()->addSeconds(max(1, (int) config('session.lifetime', 120)) * 60);
    }

    private function idleThresholdSeconds(): int
    {
        return max(1, (int) config('presence.idle_threshold_seconds', 300));
    }

    private function onlineThresholdSeconds(): int
    {
        return max(1, (int) config('presence.online_threshold_seconds', 90));
    }

    private function idleAfterSeconds(): int
    {
        return min($this->idleThresholdSeconds(), $this->onlineThresholdSeconds());
    }

    private function offlineThresholdSeconds(): int
    {
        return max(1, (int) config('presence.offline_threshold_seconds', 180));
    }

    private function duplicateLoginActiveThresholdSeconds(): int
    {
        return max(1, (int) config('presence.duplicate_login_active_threshold_seconds', 120));
    }

    private function isLocked(Request $request): bool
    {
        try {
            return (bool) $request->session()->get(LockScreenService::LockedSessionKey, false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function shouldThrottleTrackedActivityTouch(Request $request, array $context): bool
    {
        if (($context['event'] ?? null) !== 'tracked_activity') {
            return false;
        }

        $seconds = $this->trackedActivityTouchThrottleSeconds();

        if ($seconds <= 0) {
            return false;
        }

        try {
            if (! $request->hasSession()) {
                return false;
            }

            $lastTouchAt = (int) $request->session()->get('auth_presence_last_tracked_activity_touch_at', 0);
        } catch (Throwable) {
            return false;
        }

        return $lastTouchAt > 0 && now()->getTimestamp() - $lastTouchAt < $seconds;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function rememberTrackedActivityTouch(Request $request, array $context): void
    {
        if (($context['event'] ?? null) !== 'tracked_activity') {
            return;
        }

        try {
            if ($request->hasSession()) {
                $request->session()->put('auth_presence_last_tracked_activity_touch_at', now()->getTimestamp());
            }
        } catch (Throwable) {
            return;
        }
    }

    private function trackedActivityTouchThrottleSeconds(): int
    {
        return max(0, (int) config('presence.tracked_activity_touch_throttle_seconds', 30));
    }

    private function reportFailure(string $message, Throwable $exception): void
    {
        Log::warning($message, [
            'exception' => $exception->getMessage(),
        ]);

        report($exception);
    }
}

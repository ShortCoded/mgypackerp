<?php

namespace Modules\Auth\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Services\AuthLogService;
use Modules\Auth\Services\LockScreenService;
use Modules\Auth\Services\UserAccountStatusService;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Services\LocalePreferenceService;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserAccountIsActive
{
    public function __construct(
        private readonly UserAccountStatusService $accounts,
        private readonly LocalePreferenceService $locales,
        private readonly LockScreenService $lockScreen,
        private readonly AuthLogService $authLogs,
        private readonly UserPresenceService $presence,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $freshUser = $this->freshAuthenticatedUser($request);

        if ($freshUser === null && $this->candidateUserId($request) === null) {
            return $next($request);
        }

        $reason = $this->accounts->inactiveReason($freshUser);

        if ($reason !== null) {
            return $this->logoutAndReject($request, $reason, $freshUser);
        }

        if ($freshUser instanceof User && $this->presence->currentSessionWasForcedLogout($request)) {
            return $this->logoutAndRejectForcedLogout($request, $freshUser);
        }

        if ($freshUser instanceof User && ! $this->shouldSkipDuplicateSessionCheck($request)) {
            $activeSessionsCount = $this->presence->anotherFreshActiveSessionCountForRequest($freshUser, $request);

            if ($activeSessionsCount > 0) {
                return $this->logoutAndRejectAlreadyOnline($request, $freshUser, $activeSessionsCount);
            }
        }

        return $next($request);
    }

    private function logoutAndReject(Request $request, string $reason, ?User $user): Response
    {
        $locale = $this->locales->resolve($request);
        $message = $this->accounts->message($reason);
        $recallerCookieName = $this->recallerCookieName();
        $remembered = $this->hasRememberCookie($request) || Auth::viaRemember();

        if ($remembered) {
            $this->authLogs->log($request, $this->rememberedRejectedEvent($reason), 'blocked', [
                'user' => $user,
                'remember_me' => true,
                'failure_reason' => $reason,
            ]);
        }

        if ($request->routeIs('lock-screen.unlock') || $request->is('lock-screen/unlock')) {
            $this->authLogs->log($request, $this->blockedUnlockEvent($reason), 'blocked', [
                'user' => $user,
                'failure_reason' => $reason,
            ]);
        }

        $this->authLogs->log($request, 'logout_forced_by_account_status', 'blocked', [
            'user' => $user,
            'remember_me' => $remembered,
            'failure_reason' => $reason,
        ]);
        $this->presence->markOffline($request, $user, $this->presence->offlineReasonForAccountStatus($reason), [
            'event' => 'logout_forced_by_account_status',
            'failure_reason' => $reason,
        ]);

        Auth::guard('web')->logout();

        $request->session()->forget([
            LockScreenService::LockedSessionKey,
            LockScreenService::UnlockedSessionKey,
            LockScreenService::ReturnUrlSessionKey,
            LockScreenService::LockTokenSessionKey,
        ]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->locales->persist($request, $locale, saveAuthenticatedUser: false);

        if ($request->expectsJson() || $request->ajax()) {
            $response = response()->json([
                'success' => false,
                'message' => $message,
                'redirect' => route('login', [], false),
            ], 401);
        } else {
            $response = redirect(route('login', [], false))
                ->with('auth_error', $message);
        }

        $response->withCookie($this->lockScreen->forgetReturnUrlCookie());

        if ($recallerCookieName !== null) {
            $response->withCookie(cookie()->forget($recallerCookieName));
        }

        return $response;
    }

    private function logoutAndRejectForcedLogout(Request $request, User $user): Response
    {
        $locale = $this->locales->resolve($request);
        $message = __('auth_sessions.messages.forced_logout');

        $this->authLogs->log($request, 'session_forced_logout_applied', 'success', [
            'user' => $user,
            'failure_reason' => UserPresenceService::ReasonForcedLogout,
        ]);

        Auth::guard('web')->logout();

        $request->session()->forget([
            LockScreenService::LockedSessionKey,
            LockScreenService::UnlockedSessionKey,
            LockScreenService::ReturnUrlSessionKey,
            LockScreenService::LockTokenSessionKey,
        ]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->locales->persist($request, $locale, saveAuthenticatedUser: false);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'redirect' => route('login', [], false),
                'redirect_url' => route('login', [], false),
            ], 401);
        }

        return redirect(route('login', [], false))
            ->with('auth_error', $message);
    }

    private function logoutAndRejectAlreadyOnline(Request $request, User $user, int $activeSessionsCount): Response
    {
        $locale = $this->locales->resolve($request);
        $message = __('auth.messages.already_logged_in');
        $recallerCookieName = $this->recallerCookieName();

        $this->authLogs->log($request, 'session_blocked_already_online', 'blocked', [
            'user' => $user,
            'login' => $user->email ?? $user->username,
            'remember_me' => $this->hasRememberCookie($request) || Auth::viaRemember(),
            'failure_reason' => 'already_online',
            'active_sessions_count' => $activeSessionsCount,
        ]);

        $this->presence->markFingerprintsOffline($this->presence->sessionFingerprints($request));

        $guard = Auth::guard('web');

        if (method_exists($guard, 'logoutCurrentDevice')) {
            $guard->logoutCurrentDevice();
        } else {
            $guard->logout();
        }

        $request->session()->forget([
            LockScreenService::LockedSessionKey,
            LockScreenService::UnlockedSessionKey,
            LockScreenService::ReturnUrlSessionKey,
            LockScreenService::LockTokenSessionKey,
        ]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->locales->persist($request, $locale, saveAuthenticatedUser: false);

        if ($request->expectsJson() || $request->ajax()) {
            $response = response()->json([
                'success' => false,
                'message' => $message,
                'redirect' => route('login', [], false),
                'redirect_url' => route('login', [], false),
            ], 401);
        } else {
            $response = redirect(route('login', [], false))
                ->with('auth_error', $message);
        }

        $response->withCookie($this->lockScreen->forgetReturnUrlCookie());

        if ($recallerCookieName !== null) {
            $response->withCookie(cookie()->forget($recallerCookieName));
        }

        return $response;
    }

    private function hasRememberCookie(Request $request): bool
    {
        $recallerCookieName = $this->recallerCookieName();

        return $recallerCookieName !== null && $request->cookies->has($recallerCookieName);
    }

    private function rememberedRejectedEvent(string $reason): string
    {
        return match ($reason) {
            'deleted_account', 'missing_account' => 'remembered_user_rejected_deleted',
            'inactive_account' => 'remembered_user_rejected_inactive',
            'blocked_account' => 'remembered_user_rejected_blocked',
            default => 'remembered_user_rejected_inactive',
        };
    }

    private function blockedUnlockEvent(string $reason): string
    {
        return match ($reason) {
            'deleted_account', 'missing_account' => 'lock_screen_unlock_blocked_deleted_user',
            'inactive_account' => 'lock_screen_unlock_blocked_inactive_user',
            'blocked_account' => 'lock_screen_unlock_blocked_blocked_user',
            default => 'lock_screen_unlock_failed',
        };
    }

    private function recallerCookieName(): ?string
    {
        $guard = Auth::guard('web');

        return method_exists($guard, 'getRecallerName')
            ? $guard->getRecallerName()
            : null;
    }

    private function shouldSkip(Request $request): bool
    {
        return $request->routeIs(
            'login',
            'login.store',
            'password.*',
            'lang.switch'
        );
    }

    private function shouldSkipDuplicateSessionCheck(Request $request): bool
    {
        if ($this->lockScreen->isRememberedButNotUnlocked($request)) {
            return true;
        }

        if ($request->routeIs(
            'lock-screen.*',
            'logout',
            'auth.csrf-token',
            'login',
            'login.store',
            'password.*',
            'lang.switch',
            'session.status',
            'session.touch',
        )) {
            return true;
        }

        return $request->is(
            'lock-screen',
            'lock-screen/*',
            'logout',
            'auth/csrf-token',
            'login',
            'forgot-password',
            'reset-password',
            'reset-password/*',
            'session/status',
            'session/touch',
            'assets/*',
            'vendors/*',
            'build/*',
            'favicon.ico',
        );
    }

    private function freshAuthenticatedUser(Request $request): ?User
    {
        if ($request->user() !== null) {
            return $this->accounts->freshUser($request->user());
        }

        return $this->accounts->freshUserById($this->candidateUserId($request));
    }

    private function candidateUserId(Request $request): mixed
    {
        $guard = Auth::guard('web');

        if (method_exists($guard, 'getName')) {
            $sessionUserId = $request->session()->get($guard->getName());

            if ($sessionUserId !== null) {
                return $sessionUserId;
            }
        }

        $recallerCookieName = $this->recallerCookieName();

        if ($recallerCookieName === null) {
            return null;
        }

        $recaller = $request->cookies->get($recallerCookieName);

        if (! is_string($recaller) || $recaller === '') {
            return null;
        }

        return explode('|', $recaller, 2)[0] ?? null;
    }
}

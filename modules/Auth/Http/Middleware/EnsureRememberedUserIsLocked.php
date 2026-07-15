<?php

namespace Modules\Auth\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Services\AuthLogService;
use Modules\Auth\Services\LockScreenService;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Services\LocalePreferenceService;
use Symfony\Component\HttpFoundation\Response;

class EnsureRememberedUserIsLocked
{
    public function __construct(
        protected LockScreenService $lockScreen,
        protected AuthLogService $authLogs,
        protected UserPresenceService $presence,
        protected LocalePreferenceService $locales
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request) || ! $this->lockScreen->isRememberedButNotUnlocked($request)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User) {
            $currentSessionFingerprints = $this->presence->sessionFingerprints($request);
            $activeSessionsCount = $this->presence->anotherFreshActiveSessionCountForRequest($user, $request);

            if ($activeSessionsCount > 0) {
                return $this->rejectAlreadyOnline($request, $user, $currentSessionFingerprints, $activeSessionsCount);
            }
        }

        if (! (bool) $request->session()->get('auth_logged_remember_me_restored', false)) {
            $this->authLogs->log($request, 'remember_me_restored', 'success', [
                'user' => $request->user(),
                'remember_me' => true,
            ]);
            $request->session()->put('auth_logged_remember_me_restored', true);
        }

        $this->lockScreen->lock($request, $this->lockScreen->requestReturnUrl($request));
        $this->presence->markLocked($request, $request->user(), ['event' => 'remembered_user_forced_to_lock_screen']);

        if (! (bool) $request->session()->get('auth_logged_remembered_lock', false)) {
            $this->authLogs->log($request, 'remembered_user_forced_to_lock_screen', 'success', [
                'user' => $request->user(),
                'remember_me' => Auth::viaRemember(),
            ]);
            $request->session()->put('auth_logged_remembered_lock', true);
        }

        return $this->lockScreen->redirectOrJson($request);
    }

    protected function rejectAlreadyOnline(
        Request $request,
        User $user,
        array $currentSessionFingerprints,
        int $activeSessionsCount
    ): Response {
        $locale = $this->locales->resolve($request);
        $message = __('auth.messages.already_logged_in');

        $this->authLogs->log($request, 'login_blocked_already_online', 'blocked', [
            'user' => $user,
            'login' => $user->email ?? $user->username,
            'remember_me' => true,
            'failure_reason' => 'already_online',
            'active_sessions_count' => $activeSessionsCount,
        ]);
        $this->presence->markFingerprintsOffline($currentSessionFingerprints);

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
            ], 401);
        } else {
            $response = redirect(route('login', [], false))
                ->with('auth_error', $message);
        }

        $response->withCookie($this->lockScreen->forgetReturnUrlCookie());

        $recallerCookieName = $this->recallerCookieName();

        if ($recallerCookieName !== null) {
            $response->withCookie(cookie()->forget($recallerCookieName));
        }

        return $response;
    }

    protected function shouldSkip(Request $request): bool
    {
        return $request->routeIs(
            'lock-screen.*',
            'logout',
            'auth.csrf-token',
            'login',
            'login.store',
            'password.*',
            'pwa.*',
            'lang.switch',
            'session.status'
        );
    }

    protected function recallerCookieName(): ?string
    {
        $guard = Auth::guard('web');

        return method_exists($guard, 'getRecallerName')
            ? $guard->getRecallerName()
            : null;
    }
}

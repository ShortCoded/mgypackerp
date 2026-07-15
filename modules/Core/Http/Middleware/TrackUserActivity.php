<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Services\AuthLogService;
use Modules\Auth\Services\LockScreenService;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Services\InactiveSessionService;
use Modules\Core\Services\IntendedUrlService;
use Modules\Core\Services\LocalePreferenceService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class TrackUserActivity
{
    public function __construct(
        protected InactiveSessionService $inactiveSession,
        protected IntendedUrlService $intendedUrls,
        protected LocalePreferenceService $locales,
        protected LockScreenService $lockScreen,
        protected AuthLogService $authLogs,
        protected UserPresenceService $presence,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request) || $this->shouldSkipActivityTracking($request)) {
            return $next($request);
        }

        if (! Auth::check()) {
            return $this->persistGuestIntended($request, $next($request));
        }

        if ($this->inactiveSession->isExpired($request)) {
            return $this->expireSession($request);
        }

        $response = $next($request);

        if (! $this->shouldSkipTrackedActivityTouch($request)) {
            $this->inactiveSession->touch($request);
            $this->presence->touch($request, $request->user(), true, ['event' => 'tracked_activity']);
        }

        return $response;
    }

    protected function expireSession(Request $request): Response
    {
        $user = $request->user();

        $this->authLogs->log($request, 'session_timeout_detected', 'success', [
            'user' => $user,
            'remember_me' => $this->lockScreen->hasRememberCookie($request),
        ]);

        if ($this->lockScreen->hasRememberCookie($request)) {
            $this->lockScreen->lock($request, $this->lockScreen->requestReturnUrl($request));
            $request->session()->put('auth_session_expired', true);
            $this->authLogs->log($request, 'lock_screen_timeout', 'success', [
                'user' => $user,
                'remember_me' => true,
            ]);
            $this->authLogs->log($request, 'session_timeout_redirect_to_lock_screen', 'success', [
                'user' => $user,
                'remember_me' => true,
            ]);
            $this->presence->markLocked($request, $user, ['event' => 'lock_screen_timeout']);

            return $this->lockScreen->redirectOrJson($request);
        }

        $locale = $this->locales->resolve($request);
        $intended = $this->intendedUrls->requestUri($request);

        $this->authLogs->log($request, 'logout_forced_by_timeout', 'success', [
            'user' => $user,
            'logged_out_at' => now(),
        ]);
        $this->authLogs->log($request, 'session_timeout_redirect_to_login', 'success', [
            'user' => $user,
        ]);
        $this->presence->markOffline($request, $user, UserPresenceService::ReasonSessionExpired, [
            'event' => 'logout_forced_by_timeout',
        ]);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->locales->persist($request, $locale, saveAuthenticatedUser: false);
        $request->session()->put('auth_session_expired', true);

        if ($intended !== null) {
            $request->session()->put('url.intended', $intended);
            $this->intendedUrls->queueCookie($intended);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'authenticated' => false,
                'expired' => true,
                'message' => __('auth.session.expired'),
            ], 401);
        }

        return redirect(route('login', [], false));
    }

    protected function persistGuestIntended(Request $request, Response $response): Response
    {
        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        $targetPath = parse_url($response->getTargetUrl(), PHP_URL_PATH);

        if ($targetPath !== route('login', [], false)) {
            return $response;
        }

        $this->intendedUrls->rememberRequest($request);

        return $response;
    }

    protected function shouldSkip(Request $request): bool
    {
        if ($request->routeIs(
            'session.status',
            'lock-screen.*',
            'auth.csrf-token',
            'login',
            'login.store',
            'logout',
            'password.*',
            'pwa.*',
            'lang.switch',
        )) {
            return true;
        }

        return $request->is(
            'session/status',
            'auth/csrf-token',
            'lock-screen',
            'lock-screen/*',
            'login',
            'logout',
            'forgot-password',
            'manifest.webmanifest',
            'offline',
            'pwa-service-worker.js',
            'reset-password',
            'reset-password/*',
            'assets/*',
            'vendors/*',
            'build/*',
            'favicon.ico',
        );
    }

    protected function shouldSkipActivityTracking(Request $request): bool
    {
        return $this->lockScreen->isLocked($request)
            || $this->lockScreen->isRememberedButNotUnlocked($request);
    }

    protected function shouldSkipTrackedActivityTouch(Request $request): bool
    {
        return $request->routeIs(
            'admin.notifications.poll',
            'admin.chat.conversations',
            'admin.chat.messages.poll',
        );
    }
}

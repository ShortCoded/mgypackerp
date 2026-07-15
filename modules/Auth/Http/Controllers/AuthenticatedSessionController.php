<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Services\AuthLogService;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Auth\Services\LockScreenService;
use Modules\Auth\Services\OnlineSeatLimitService;
use Modules\Auth\Services\UserAccountStatusService;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Services\InactiveSessionService;
use Modules\Core\Services\IntendedUrlService;
use Modules\Core\Services\LocalePreferenceService;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request, IntendedUrlService $intendedUrls): View
    {
        $intendedUrls->restoreFromCookie($request);

        return view('modules.auth.login', [
            'authSessionExpired' => (bool) $request->session()->pull('auth_session_expired', false),
        ]);
    }

    public function store(
        LoginRequest $request,
        AuthLogService $authLogService,
        InactiveSessionService $inactiveSession,
        IntendedUrlService $intendedUrls,
        LocalePreferenceService $locales,
        LockScreenService $lockScreen,
        OnlineSeatLimitService $seatLimit,
        DefaultLoginContextService $defaultLoginContexts,
        UserAccountStatusService $accounts,
        UserPresenceService $presence,
    ): JsonResponse|RedirectResponse {
        $preLoginSessionFingerprint = $presence->sessionFingerprint($request);
        $user = $request->authenticate($authLogService, $accounts, $presence);
        $intendedUrls->restoreFromCookie($request);
        $intendedUrls->sanitizeSessionIntended($request);
        $defaultContextApplied = false;

        $seatLimit->withinSeatLimitLock(function () use (
            $request,
            $authLogService,
            $defaultLoginContexts,
            &$defaultContextApplied,
            $inactiveSession,
            $locales,
            $presence,
            $seatLimit,
            $preLoginSessionFingerprint,
            $user
        ): void {
            if ($seatLimit->seatsAreFull()) {
                $authLogService->log($request, 'login_blocked_seat_limit_reached', 'blocked', [
                    'user' => $user,
                    'login' => $request->string('login')->trim()->toString(),
                    'remember_me' => $request->boolean('remember'),
                    'failure_reason' => 'seat_limit_reached',
                    'current_online_users_count' => $seatLimit->currentOnlineUsersCount(),
                    'max_online_users' => $seatLimit->maxOnlineUsers(),
                ]);

                throw ValidationException::withMessages([
                    'login' => __('auth.messages.seat_limit_reached'),
                ]);
            }

            Auth::login($user, $request->boolean('remember'));
            $request->clearRateLimit();

            $request->session()->regenerate();
            $presence->markFingerprintOffline($preLoginSessionFingerprint);
            $request->session()->forget(LockScreenService::LockedSessionKey);
            $request->session()->forget(LockScreenService::ReturnUrlSessionKey);
            $request->session()->put(LockScreenService::UnlockedSessionKey, true);
            $defaultContextApplied = $defaultLoginContexts->applyForLogin($request, $user);
            $inactiveSession->touch($request);
            $locales->persist($request, $locales->resolve($request), saveAuthenticatedUser: false);
            $presence->markOnline($request, $user, [
                'event' => 'login_success',
                'default_login_context_applied' => $defaultContextApplied,
            ]);

            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ])->save();

            $authLogService->log($request, 'login_success', 'success', [
                'user' => $user,
                'login' => $request->string('login')->trim()->toString(),
                'remember_me' => $request->boolean('remember'),
                'logged_in_at' => now(),
                'default_login_context_applied' => $defaultContextApplied,
            ]);
        });

        if ($request->expectsJson() || $request->ajax()) {
            $redirect = $this->redirectPath($request, $intendedUrls);

            return response()->json([
                'success' => true,
                'message' => __('auth.login_success'),
                'redirect' => $redirect,
            ])->withCookie($intendedUrls->forgetCookie())
                ->withCookie($lockScreen->forgetReturnUrlCookie());
        }

        return redirect()
            ->intended(route('dashboard', absolute: false))
            ->withCookie($intendedUrls->forgetCookie())
            ->withCookie($lockScreen->forgetReturnUrlCookie());
    }

    public function destroy(
        Request $request,
        AuthLogService $authLogService,
        LocalePreferenceService $locales,
        LockScreenService $lockScreen,
        UserPresenceService $presence
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        $locale = $locales->resolve($request);

        if ($user !== null) {
            $authLogService->log($request, 'logout_success', 'success', [
                'user' => $user,
                'logged_out_at' => now(),
            ]);
            $presence->markOffline($request, $user, UserPresenceService::ReasonLogout, ['event' => 'logout_success']);
            $presence->markFingerprintsOffline($presence->sessionFingerprints($request), UserPresenceService::ReasonLogout);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $locales->persist($request, $locale, saveAuthenticatedUser: false);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => __('auth.logout_success'),
                'redirect' => route('login'),
            ])->withCookie($lockScreen->forgetReturnUrlCookie());
        }

        return redirect()
            ->route('login')
            ->withCookie($lockScreen->forgetReturnUrlCookie());
    }

    protected function redirectPath(Request $request, IntendedUrlService $intendedUrls): string
    {
        $intended = $intendedUrls->pullSanitized($request);

        if (is_string($intended)) {
            return $intended;
        }

        return route('dashboard', absolute: false);
    }
}

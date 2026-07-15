<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Auth\Services\AuthLogService;
use Modules\Auth\Services\LockScreenService;
use Modules\Auth\Services\OnlineSeatLimitService;
use Modules\Auth\Services\UserAccountStatusService;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Services\InactiveSessionService;

class LockScreenController extends Controller
{
    public function show(
        Request $request,
        LockScreenService $lockScreen,
        AuthLogService $authLogService,
        UserPresenceService $presence
    ): View|JsonResponse|RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->guestLockScreenResponse($request);
        }

        $lockScreen->restoreReturnUrlFromCookie($request);

        if ($lockScreen->isRememberedButNotUnlocked($request)) {
            $lockScreen->lock($request, $lockScreen->redirectTarget($request));
            $presence->markLocked($request, $user, ['event' => 'lock_screen_remembered_user']);
            $authLogService->log($request, 'lock_screen_remembered_user', 'success', [
                'user' => $user,
                'remember_me' => true,
            ]);
        }

        if (! $lockScreen->isLocked($request)) {
            $redirectTarget = $lockScreen->redirectTarget($request);
            $lockScreen->clearReturnUrl($request);

            return redirect($redirectTarget)
                ->withCookie($lockScreen->forgetReturnUrlCookie());
        }

        $authLogService->log($request, 'lock_screen_opened', 'success', [
            'user' => $user,
        ]);
        $presence->markLocked($request, $user, ['event' => 'lock_screen_opened']);

        return view('modules.auth.lock-screen');
    }

    public function store(
        Request $request,
        LockScreenService $lockScreen,
        AuthLogService $authLogService,
        UserPresenceService $presence
    ): JsonResponse|RedirectResponse {
        $lockScreen->lock($request, $lockScreen->requestReturnUrl($request));
        $presence->markLocked($request, $request->user(), ['event' => 'lock_screen_manual']);
        $authLogService->log($request, 'lock_screen_manual', 'success', [
            'user' => $request->user(),
        ]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'locked' => true,
                'redirect' => route('lock-screen.show', [], false),
            ]);
        }

        return redirect(route('lock-screen.show', [], false));
    }

    public function unlock(
        Request $request,
        LockScreenService $lockScreen,
        InactiveSessionService $inactiveSession,
        AuthLogService $authLogService,
        UserAccountStatusService $accounts,
        OnlineSeatLimitService $seatLimit,
        UserPresenceService $presence
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ], [], [
            'password' => __('auth.password'),
        ]);

        $user = $request->user();
        $freshUser = $this->freshUserForUnlock($request, $accounts);
        $inactiveReason = $accounts->inactiveReason($freshUser);

        if ($inactiveReason !== null && $inactiveReason !== 'missing_account') {
            $authLogService->log($request, $this->blockedUnlockEvent($inactiveReason), 'blocked', [
                'user' => $freshUser ?? $user,
                'failure_reason' => $inactiveReason,
            ]);
            $presence->markOffline(
                $request,
                $freshUser ?? $user,
                $presence->offlineReasonForAccountStatus($inactiveReason),
                ['event' => $this->blockedUnlockEvent($inactiveReason)]
            );

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $accounts->message($inactiveReason),
                    'redirect' => route('login', [], false),
                ], 401)->withCookie($lockScreen->forgetReturnUrlCookie());
            }

            return redirect(route('login', [], false))
                ->with('auth_error', $accounts->message($inactiveReason))
                ->withCookie($lockScreen->forgetReturnUrlCookie());
        }

        if ($freshUser === null || ! Hash::check((string) $validated['password'], $freshUser->password)) {
            $authLogService->log($request, 'lock_screen_unlock_failed', 'failed', [
                'user' => $freshUser ?? $user,
                'failure_reason' => 'invalid_password',
            ]);

            throw ValidationException::withMessages([
                'password' => __('auth.lock_screen.invalid_password'),
            ]);
        }

        $oldSessionFingerprints = $presence->sessionFingerprints($request);

        return $seatLimit->withinSeatLimitLock(function () use (
            $request,
            $lockScreen,
            $inactiveSession,
            $authLogService,
            $presence,
            $seatLimit,
            $freshUser,
            $oldSessionFingerprints
        ): JsonResponse|RedirectResponse {
            $activeSessionsCount = $presence->anotherFreshActiveSessionCount(
                $freshUser,
                $oldSessionFingerprints,
                $presence->lockFlowFingerprint($request)
            );

            if ($activeSessionsCount > 0) {
                return $this->rejectUnlockAlreadyOnline(
                    $request,
                    $lockScreen,
                    $authLogService,
                    $presence,
                    $freshUser,
                    $oldSessionFingerprints,
                    $activeSessionsCount
                );
            }

            $currentPresenceSession = $presence->currentSessionForRequest($request, $freshUser);

            if ($presence->isFreshLockedCurrentSession($currentPresenceSession)) {
                $updatedPresenceSession = $presence->markCurrentSessionOnline($request, $freshUser, $currentPresenceSession, [
                    'event' => 'lock_screen_unlock_success',
                ]);

                if ($updatedPresenceSession !== null) {
                    $presence->markFingerprintsOffline(array_values(array_diff(
                        $oldSessionFingerprints,
                        [(string) $updatedPresenceSession->session_fingerprint]
                    )));
                }

                $returnUrl = $lockScreen->unlock($request);
                $inactiveSession->touch($request);
                $authLogService->log($request, 'lock_screen_unlock_success', 'success', [
                    'user' => $freshUser,
                ]);

                return $this->successfulUnlockResponse($request, $lockScreen, $returnUrl);
            }

            if ($seatLimit->seatsAreFull()) {
                $authLogService->log($request, 'lock_screen_unlock_blocked_seat_limit_reached', 'blocked', [
                    'user' => $freshUser,
                    'login' => $freshUser->email ?? $freshUser->username,
                    'remember_me' => Auth::viaRemember(),
                    'failure_reason' => 'seat_limit_reached_on_unlock',
                    'current_online_users_count' => $seatLimit->currentOnlineUsersCount(),
                    'max_online_users' => $seatLimit->maxOnlineUsers(),
                ]);

                throw ValidationException::withMessages([
                    'password' => __('auth.messages.seat_limit_reached_on_unlock'),
                ]);
            }

            $returnUrl = $lockScreen->unlock($request);

            $request->session()->regenerate();
            $presence->markFingerprintsOffline($oldSessionFingerprints);
            $inactiveSession->touch($request);
            $presence->markOnline($request, $freshUser, ['event' => 'lock_screen_unlock_success']);
            $authLogService->log($request, 'lock_screen_unlock_success', 'success', [
                'user' => $freshUser,
            ]);

            return $this->successfulUnlockResponse($request, $lockScreen, $returnUrl);
        }, 'password', __('auth.messages.seat_limit_reached_on_unlock'));
    }

    protected function guestLockScreenResponse(Request $request): JsonResponse|RedirectResponse
    {
        $message = __('auth.session.expired_sign_in_again');

        if ($request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'redirect' => route('login', [], false),
            ], 401);
        }

        return redirect(route('login', [], false))
            ->with('auth_error', $message);
    }

    protected function successfulUnlockResponse(
        Request $request,
        LockScreenService $lockScreen,
        string $returnUrl
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => __('auth.lock_screen.unlocked'),
                'csrf_token' => csrf_token(),
                'redirect' => $returnUrl,
            ])->withCookie($lockScreen->forgetReturnUrlCookie());
        }

        return redirect($returnUrl)
            ->withCookie($lockScreen->forgetReturnUrlCookie());
    }

    protected function rejectUnlockAlreadyOnline(
        Request $request,
        LockScreenService $lockScreen,
        AuthLogService $authLogService,
        UserPresenceService $presence,
        User $user,
        array $currentSessionFingerprints,
        int $activeSessionsCount
    ): JsonResponse|RedirectResponse {
        $message = __('auth.messages.already_logged_in');

        $authLogService->log($request, 'lock_screen_unlock_blocked_already_online', 'blocked', [
            'user' => $user,
            'login' => $user->email ?? $user->username,
            'remember_me' => Auth::viaRemember(),
            'failure_reason' => 'already_online',
            'active_sessions_count' => $activeSessionsCount,
        ]);
        $presence->markFingerprintsOffline($currentSessionFingerprints);

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

        if ($request->expectsJson() || $request->ajax()) {
            $response = response()->json([
                'success' => false,
                'message' => $message,
                'errors' => [
                    'password' => [$message],
                ],
            ], 422);
        } else {
            $response = redirect(route('login', [], false))
                ->with('auth_error', $message);
        }

        $response->withCookie($lockScreen->forgetReturnUrlCookie());

        $recallerCookieName = $this->recallerCookieName();

        if ($recallerCookieName !== null) {
            $response->withCookie(cookie()->forget($recallerCookieName));
        }

        return $response;
    }

    protected function blockedUnlockEvent(string $reason): string
    {
        return match ($reason) {
            'deleted_account' => 'lock_screen_unlock_blocked_deleted_user',
            'inactive_account' => 'lock_screen_unlock_blocked_inactive_user',
            'blocked_account' => 'lock_screen_unlock_blocked_blocked_user',
            default => 'lock_screen_unlock_failed',
        };
    }

    protected function freshUserForUnlock(Request $request, UserAccountStatusService $accounts): ?User
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $accounts->freshUser($user);
        }

        $guard = Auth::guard('web');

        if (! method_exists($guard, 'getName')) {
            return null;
        }

        $userId = $request->session()->get($guard->getName());

        return $accounts->freshUserById($userId);
    }

    protected function recallerCookieName(): ?string
    {
        $guard = Auth::guard('web');

        return method_exists($guard, 'getRecallerName')
            ? $guard->getRecallerName()
            : null;
    }
}

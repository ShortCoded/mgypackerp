<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Services\AuthLogService;
use Modules\Auth\Services\LockScreenService;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Services\InactiveSessionService;

class SessionController extends Controller
{
    public function status(
        Request $request,
        InactiveSessionService $inactiveSession,
        LockScreenService $lockScreen,
        AuthLogService $authLogs,
        UserPresenceService $presence
    ): JsonResponse {
        $serverTime = now()->getTimestamp();
        $lifetimeSeconds = $inactiveSession->lifetimeSeconds();
        $isAuthenticated = Auth::check();
        $isExpired = $inactiveSession->isExpired($request);
        $hasRememberCookie = $lockScreen->hasRememberCookie($request);

        if ($isAuthenticated && ($lockScreen->isLocked($request) || $lockScreen->isRememberedButNotUnlocked($request) || ($isExpired && $hasRememberCookie))) {
            if ($isExpired) {
                $this->logTimeoutOnce($request, $authLogs, 'session_timeout_detected', [
                    'user' => $request->user(),
                    'remember_me' => true,
                ]);
                $this->logTimeoutOnce($request, $authLogs, 'lock_screen_timeout', [
                    'user' => $request->user(),
                    'remember_me' => true,
                ]);
                $this->logTimeoutOnce($request, $authLogs, 'session_timeout_redirect_to_lock_screen', [
                    'user' => $request->user(),
                    'remember_me' => true,
                ]);
            }

            $lockScreen->lock($request, $lockScreen->currentPathFromHeader($request));
            $presence->markLocked($request, $request->user(), ['event' => $isExpired ? 'lock_screen_timeout' : 'session_status_locked']);

            return response()->json([
                'authenticated' => true,
                'locked' => true,
                'expired' => $isExpired,
                'via_remember' => Auth::viaRemember() || $hasRememberCookie,
                'action' => 'lock',
                'lock_screen_url' => route('lock-screen.show', [], false),
                'lifetime_seconds' => $lifetimeSeconds,
                'server_time' => $serverTime,
                'seconds_remaining' => 0,
            ]);
        }

        if (! $isAuthenticated || $isExpired) {
            if ($isAuthenticated && $isExpired) {
                $this->logTimeoutOnce($request, $authLogs, 'session_timeout_detected', [
                    'user' => $request->user(),
                    'remember_me' => false,
                ]);
                $this->logTimeoutOnce($request, $authLogs, 'session_timeout_redirect_to_login', [
                    'user' => $request->user(),
                ]);
                $presence->markOffline($request, $request->user(), UserPresenceService::ReasonSessionExpired, [
                    'event' => 'session_timeout_redirect_to_login',
                ]);
            }

            return response()->json([
                'authenticated' => false,
                'expired' => true,
                'action' => 'login',
                'lifetime_seconds' => $lifetimeSeconds,
                'server_time' => $serverTime,
                'seconds_remaining' => 0,
            ]);
        }

        return response()->json([
            'authenticated' => true,
            'expired' => false,
            'lifetime_seconds' => $lifetimeSeconds,
            'server_time' => $serverTime,
            'last_activity_at' => $inactiveSession->lastActivityAt($request) ?? $serverTime,
            'seconds_remaining' => $inactiveSession->secondsRemaining($request),
        ]);
    }

    public function touch(
        Request $request,
        InactiveSessionService $inactiveSession,
        UserPresenceService $presence
    ): JsonResponse {
        $serverTime = $inactiveSession->touch($request);
        $presence->touch($request, $request->user(), true, ['event' => 'session_touch']);

        return response()->json([
            'ok' => true,
            'server_time' => $serverTime,
            'lifetime_seconds' => $inactiveSession->lifetimeSeconds(),
            'expires_at' => $inactiveSession->expiresAt($serverTime),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logTimeoutOnce(Request $request, AuthLogService $authLogs, string $event, array $context): void
    {
        $key = 'auth_logged_'.$event;

        if ((bool) $request->session()->get($key, false)) {
            return;
        }

        $authLogs->log($request, $event, 'success', $context);
        $request->session()->put($key, true);
    }
}

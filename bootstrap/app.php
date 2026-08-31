<?php

use App\Http\Middleware\NormalizeJsonErrorResponse;
use App\Http\Middleware\SetLocale;
use App\Support\Http\JsonErrorResponse;
use App\Support\Http\JsonExceptionRenderer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Http\Middleware\EnsureRememberedUserIsLocked;
use Modules\Auth\Http\Middleware\EnsureUserAccountIsActive;
use Modules\Auth\Http\Middleware\EnsureUserIsNotLocked;
use Modules\Auth\Services\AuthLogService;
use Modules\Auth\Services\UserAccountStatusService;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Http\Middleware\EnsureExpandedErpPhase;
use Modules\Core\Http\Middleware\PreventDynamicPageCache;
use Modules\Core\Http\Middleware\TrackUserActivity;
use Modules\Core\Services\IntendedUrlService;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
        $middleware->encryptCookies(except: ['erp_navbar_position']);

        $middleware->web(prepend: [
            PreventDynamicPageCache::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
            NormalizeJsonErrorResponse::class,
            EnsureUserAccountIsActive::class,
            TrackUserActivity::class,
            EnsureRememberedUserIsLocked::class,
            EnsureUserIsNotLocked::class,
        ]);

        $middleware->alias([
            'erp.expanded' => EnsureExpandedErpPhase::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isLockScreenWrite = function (Request $request): bool {
            return $request->routeIs('lock-screen.store', 'lock-screen.unlock')
                || $request->is('lock-screen', 'lock-screen/unlock');
        };

        $lockScreenExpiredResponse = function (Request $request, int $status = 401) {
            $message = __('auth.session.expired_sign_in_again');
            $loginUrl = route('login', [], false);

            if ($request->expectsJson() || $request->ajax()) {
                return app(JsonErrorResponse::class)->make(
                    $message,
                    'session_expired',
                    $status,
                    extra: [
                        'redirect' => $loginUrl,
                        'redirect_url' => $loginUrl,
                    ],
                );
            }

            return redirect($loginUrl)
                ->with('auth_error', $message);
        };

        $exceptions->render(function (TokenMismatchException $exception, Request $request) use ($isLockScreenWrite, $lockScreenExpiredResponse) {
            if (! $isLockScreenWrite($request)) {
                return null;
            }

            return $lockScreenExpiredResponse($request, 419);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($isLockScreenWrite, $lockScreenExpiredResponse) {
            $guard = Auth::guard('web');
            $candidateUserId = method_exists($guard, 'getName')
                ? $request->session()->get($guard->getName())
                : null;

            if ($candidateUserId === null && method_exists($guard, 'getRecallerName')) {
                $recaller = $request->cookies->get($guard->getRecallerName());
                $candidateUserId = is_string($recaller) && $recaller !== ''
                    ? (explode('|', $recaller, 2)[0] ?? null)
                    : null;
            }

            $accounts = app(UserAccountStatusService::class);
            $candidateUser = $candidateUserId !== null
                ? $accounts->freshUserById($candidateUserId)
                : null;
            $reason = $candidateUserId !== null
                ? $accounts->inactiveReason($candidateUser)
                : null;

            if ($reason !== null) {
                $message = $accounts->message($reason);
                $recallerCookie = method_exists($guard, 'getRecallerName')
                    ? cookie()->forget($guard->getRecallerName())
                    : null;
                $hasRememberCookie = method_exists($guard, 'getRecallerName')
                    && $request->cookies->has($guard->getRecallerName());
                $authLogs = app(AuthLogService::class);
                $presence = app(UserPresenceService::class);

                if ($hasRememberCookie) {
                    $authLogs->log($request, match ($reason) {
                        'deleted_account', 'missing_account' => 'remembered_user_rejected_deleted',
                        'inactive_account' => 'remembered_user_rejected_inactive',
                        'blocked_account' => 'remembered_user_rejected_blocked',
                        default => 'remembered_user_rejected_inactive',
                    }, 'blocked', [
                        'user' => $candidateUser,
                        'remember_me' => true,
                        'failure_reason' => $reason,
                    ]);
                }

                if ($request->routeIs('lock-screen.unlock')) {
                    $authLogs->log($request, match ($reason) {
                        'deleted_account', 'missing_account' => 'lock_screen_unlock_blocked_deleted_user',
                        'inactive_account' => 'lock_screen_unlock_blocked_inactive_user',
                        'blocked_account' => 'lock_screen_unlock_blocked_blocked_user',
                        default => 'lock_screen_unlock_failed',
                    }, 'blocked', [
                        'user' => $candidateUser,
                        'failure_reason' => $reason,
                    ]);
                }

                $authLogs->log($request, 'logout_forced_by_account_status', 'blocked', [
                    'user' => $candidateUser,
                    'remember_me' => $hasRememberCookie,
                    'failure_reason' => $reason,
                ]);
                $presence->markOffline($request, $candidateUser, $presence->offlineReasonForAccountStatus($reason), [
                    'event' => 'logout_forced_by_account_status',
                    'failure_reason' => $reason,
                ]);

                if ($request->expectsJson() || $request->ajax()) {
                    $response = app(JsonErrorResponse::class)->make(
                        $message,
                        'authentication_required',
                        401,
                        extra: ['redirect' => route('login', [], false)],
                    );
                } else {
                    $response = redirect(route('login', [], false))
                        ->with('auth_error', $message);
                }

                if ($recallerCookie !== null) {
                    $response->withCookie($recallerCookie);
                }

                return $response;
            }

            if ($isLockScreenWrite($request)) {
                return $lockScreenExpiredResponse($request);
            }

            if ($request->expectsJson() || $request->ajax()) {
                return null;
            }

            app(IntendedUrlService::class)->rememberRequest($request);

            return redirect(route('login', [], false));
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            return app(JsonExceptionRenderer::class)->render($exception, $request);
        });

        $exceptions->report(function (Throwable $exception) {
            if (! app()->bound('request')) {
                return null;
            }

            $request = request();

            if (! $request instanceof Request || $request->route() === null) {
                return null;
            }

            app(JsonExceptionRenderer::class)->report($exception, $request);

            return false;
        });

        $exceptions->dontReportDuplicates();
    })->create();

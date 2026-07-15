<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Services\LockScreenService;
use Modules\Auth\Services\UserPresenceService;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotLocked
{
    public function __construct(
        protected LockScreenService $lockScreen,
        protected UserPresenceService $presence
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request) || ! $this->lockScreen->isLocked($request)) {
            return $next($request);
        }

        $this->lockScreen->lock($request, $this->lockScreen->requestReturnUrl($request));
        $this->presence->markLocked($request, $request->user(), ['event' => 'locked_request_blocked']);

        return $this->lockScreen->redirectOrJson($request);
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
}

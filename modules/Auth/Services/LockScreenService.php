<?php

namespace Modules\Auth\Services;

use Illuminate\Contracts\Cookie\QueueingFactory as CookieFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Core\Services\SafeRedirectUrlService;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class LockScreenService
{
    public const ReturnUrlCookieName = 'erp_lock_return_url';

    public const LockedSessionKey = 'auth_locked';

    public const UnlockedSessionKey = 'auth_unlocked';

    public const ReturnUrlSessionKey = 'lock_return_url';

    public const LockTokenSessionKey = 'auth_lock_token';

    public function __construct(
        protected SafeRedirectUrlService $urls,
        protected CookieFactory $cookies
    ) {}

    public function isLocked(Request $request): bool
    {
        return (bool) $request->session()->get(self::LockedSessionKey, false);
    }

    public function lock(Request $request, ?string $returnUrl = null): ?string
    {
        $safeReturnUrl = $this->safeReturnUrl($request, $returnUrl);

        if ($safeReturnUrl !== null && $safeReturnUrl !== route('lock-screen.show', [], false)) {
            $request->session()->put(self::ReturnUrlSessionKey, $safeReturnUrl);
            $this->queueReturnUrlCookie($safeReturnUrl);
        }

        $request->session()->put(self::LockedSessionKey, true);
        $request->session()->forget(self::UnlockedSessionKey);

        if (! is_string($request->session()->get(self::LockTokenSessionKey))) {
            $request->session()->put(self::LockTokenSessionKey, Str::random(40));
        }

        return $safeReturnUrl;
    }

    public function unlock(Request $request): string
    {
        $returnUrl = $this->pullReturnUrl($request)
            ?? $this->returnUrlFromCookie($request)
            ?? $this->urls->fallback();

        $request->session()->forget(self::LockedSessionKey);
        $request->session()->forget(self::LockTokenSessionKey);
        $request->session()->put(self::UnlockedSessionKey, true);

        return $returnUrl;
    }

    public function isRememberedButNotUnlocked(Request $request): bool
    {
        return Auth::check()
        && Auth::viaRemember()
        && ! (bool) $request->session()->get(self::UnlockedSessionKey, false);
    }

    public function hasRememberCookie(Request $request): bool
    {
        $guard = Auth::guard('web');

        if (! method_exists($guard, 'getRecallerName')) {
            return false;
        }

        return $request->cookies->has($guard->getRecallerName());
    }

    public function currentPathFromHeader(Request $request): ?string
    {
        $currentPath = $request->headers->get('X-Current-Path');

        if (! is_string($currentPath)) {
            return null;
        }

        return $this->urls->sanitizeIntended($currentPath);
    }

    public function requestReturnUrl(Request $request): ?string
    {
        $currentPath = $request->input('return_url');

        if (is_string($currentPath)) {
            return $this->urls->sanitizeIntended($currentPath);
        }

        $currentPath = $this->currentPathFromHeader($request);

        if ($currentPath !== null) {
            return $currentPath;
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return $this->urls->sanitizeIntended($request->getRequestUri());
        }

        return $this->refererPath($request);
    }

    public function redirectOrJson(Request $request): Response
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'authenticated' => true,
                'locked' => true,
                'action' => 'lock',
                'lock_screen_url' => route('lock-screen.show', [], false),
            ], 423);
        }

        return redirect(route('lock-screen.show', [], false));
    }

    protected function safeReturnUrl(Request $request, ?string $returnUrl): ?string
    {
        if (is_string($returnUrl)) {
            return $this->urls->sanitizeIntended($returnUrl);
        }

        return $this->requestReturnUrl($request);
    }

    protected function pullReturnUrl(Request $request): ?string
    {
        $returnUrl = $request->session()->pull(self::ReturnUrlSessionKey);

        if (! is_string($returnUrl)) {
            return null;
        }

        return $this->urls->sanitizeIntended($returnUrl);
    }

    public function restoreReturnUrlFromCookie(Request $request): ?string
    {
        if ($request->session()->has(self::ReturnUrlSessionKey)) {
            return $this->sanitizeSessionReturnUrl($request);
        }

        $returnUrl = $this->returnUrlFromCookie($request);

        if ($returnUrl === null) {
            return null;
        }

        $request->session()->put(self::ReturnUrlSessionKey, $returnUrl);

        return $returnUrl;
    }

    public function redirectTarget(Request $request): string
    {
        return $this->sanitizeSessionReturnUrl($request)
            ?? $this->returnUrlFromCookie($request)
            ?? $this->urls->fallback();
    }

    public function clearReturnUrl(Request $request): void
    {
        $request->session()->forget(self::ReturnUrlSessionKey);
    }

    public function forgetReturnUrlCookie(): Cookie
    {
        $this->cookies->unqueue(self::ReturnUrlCookieName);

        return cookie()->forget(self::ReturnUrlCookieName);
    }

    protected function sanitizeSessionReturnUrl(Request $request): ?string
    {
        $returnUrl = $request->session()->get(self::ReturnUrlSessionKey);

        if (! is_string($returnUrl)) {
            $request->session()->forget(self::ReturnUrlSessionKey);

            return null;
        }

        $returnUrl = $this->urls->sanitizeIntended($returnUrl);

        if ($returnUrl === null) {
            $request->session()->forget(self::ReturnUrlSessionKey);

            return null;
        }

        $request->session()->put(self::ReturnUrlSessionKey, $returnUrl);

        return $returnUrl;
    }

    protected function returnUrlFromCookie(Request $request): ?string
    {
        $returnUrl = $request->cookie(self::ReturnUrlCookieName);

        if (! is_string($returnUrl)) {
            return null;
        }

        return $this->urls->sanitizeIntended($returnUrl);
    }

    protected function queueReturnUrlCookie(string $returnUrl): void
    {
        $returnUrl = $this->urls->sanitizeIntended($returnUrl);

        if ($returnUrl === null) {
            return;
        }

        $this->cookies->queue(cookie(
            name: self::ReturnUrlCookieName,
            value: $returnUrl,
            minutes: 60,
            httpOnly: true,
            secure: $this->secureCookie(),
            sameSite: 'lax'
        ));
    }

    protected function refererPath(Request $request): ?string
    {
        $referer = $request->headers->get('referer');

        if (! is_string($referer)) {
            return null;
        }

        $parts = parse_url($referer);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['host']) && ! hash_equals($request->getHost(), $parts['host'])) {
            return null;
        }

        if (isset($parts['scheme']) && ! hash_equals($request->getScheme(), $parts['scheme'])) {
            return null;
        }

        return $this->urls->sanitizeIntended(
            ($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '')
        );
    }

    private function secureCookie(): bool
    {
        return (bool) (config('session.secure') ?? app()->isProduction());
    }
}

<?php

namespace Modules\Core\Services;

use Illuminate\Contracts\Cookie\QueueingFactory as CookieFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class IntendedUrlService
{
    public const CookieName = 'erp_intended_url';

    public const AfterOperatingContextSessionKey = 'url.intended_after_operating_context';

    public function __construct(
        private readonly CookieFactory $cookies,
        private readonly SafeRedirectUrlService $urls
    ) {}

    public function sanitizeForRequest(Request $request, string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048) {
            return null;
        }

        if (str_contains($url, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        if (Str::startsWith($url, '/') && ! Str::startsWith($url, '//')) {
            return $this->sanitizeRelativeUrl($url);
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        if (! $this->isAllowedAbsoluteUrl($request, $parts)) {
            return null;
        }

        $relativeUrl = ($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

        return $this->sanitizeRelativeUrl($relativeUrl);
    }

    public function sanitizeRelativeUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048) {
            return null;
        }

        if (str_contains($url, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        if (! Str::startsWith($url, '/') || Str::startsWith($url, '//')) {
            return null;
        }

        return $this->urls->sanitizeIntended($url);
    }

    public function requestUri(Request $request): ?string
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;
        }

        return $this->sanitizeRelativeUrl($request->getRequestUri());
    }

    public function rememberRequest(Request $request): ?string
    {
        $intended = $this->requestUri($request);

        if ($intended === null) {
            return null;
        }

        $request->session()->put('url.intended', $intended);
        $this->queueCookie($intended);

        return $intended;
    }

    public function restoreFromCookie(Request $request): ?string
    {
        if ($request->session()->has('url.intended')) {
            return $this->sanitizeSessionIntended($request);
        }

        $intended = $request->cookie(self::CookieName);

        if (! is_string($intended)) {
            return null;
        }

        $intended = $this->sanitizeRelativeUrl($intended);

        if ($intended === null) {
            return null;
        }

        $request->session()->put('url.intended', $intended);

        return $intended;
    }

    public function sanitizeSessionIntended(Request $request): ?string
    {
        $intended = $request->session()->get('url.intended');

        if (! is_string($intended)) {
            $request->session()->forget('url.intended');

            return null;
        }

        $intended = $this->sanitizeForRequest($request, $intended);

        if ($intended === null) {
            $request->session()->forget('url.intended');

            return null;
        }

        $request->session()->put('url.intended', $intended);

        return $intended;
    }

    public function pullSanitized(Request $request): ?string
    {
        $intended = $this->sanitizeSessionIntended($request);

        $request->session()->forget('url.intended');

        return $intended;
    }

    public function queueCookie(string $intended): void
    {
        $intended = $this->sanitizeRelativeUrl($intended);

        if ($intended === null) {
            return;
        }

        $this->cookies->queue(cookie(
            name: self::CookieName,
            value: $intended,
            minutes: 60,
            httpOnly: true,
            secure: $this->secureCookie(),
            sameSite: 'lax'
        ));
    }

    public function forgetCookie(): Cookie
    {
        $this->cookies->unqueue(self::CookieName);

        return cookie()->forget(self::CookieName);
    }

    /**
     * @param  array{scheme?: string, host?: string, port?: int}  $parts
     */
    protected function isAllowedAbsoluteUrl(Request $request, array $parts): bool
    {
        return $this->matchesOrigin(
            $parts,
            $request->getScheme(),
            $request->getHost(),
            $request->getPort()
        ) || $this->matchesConfiguredAppUrl($parts);
    }

    /**
     * @param  array{scheme?: string, host?: string, port?: int}  $parts
     */
    protected function matchesConfiguredAppUrl(array $parts): bool
    {
        $appUrlParts = parse_url((string) config('app.url'));

        if ($appUrlParts === false || ! isset($appUrlParts['host'])) {
            return false;
        }

        return $this->matchesOrigin(
            $parts,
            $appUrlParts['scheme'] ?? 'http',
            $appUrlParts['host'],
            $appUrlParts['port'] ?? null
        );
    }

    /**
     * @param  array{scheme?: string, host?: string, port?: int}  $parts
     */
    protected function matchesOrigin(array $parts, string $scheme, string $host, ?int $port): bool
    {
        if (! hash_equals($host, $parts['host'] ?? '')) {
            return false;
        }

        if (isset($parts['scheme']) && ! hash_equals($scheme, $parts['scheme'])) {
            return false;
        }

        $actualPort = $parts['port'] ?? $this->defaultPort($parts['scheme'] ?? $scheme);
        $expectedPort = $port ?? $this->defaultPort($scheme);

        return $actualPort === $expectedPort;
    }

    protected function defaultPort(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    private function secureCookie(): bool
    {
        return (bool) (config('session.secure') ?? app()->isProduction());
    }
}

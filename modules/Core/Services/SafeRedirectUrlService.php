<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\ImplicitRouteBinding;
use Illuminate\Routing\Route as MatchedRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

class SafeRedirectUrlService
{
    /**
     * @var list<string>
     */
    private const StaticExtensions = [
        'avif',
        'bmp',
        'css',
        'csv',
        'doc',
        'docx',
        'eot',
        'gif',
        'ico',
        'jpeg',
        'jpg',
        'js',
        'json',
        'map',
        'mp3',
        'mp4',
        'otf',
        'pdf',
        'png',
        'ppt',
        'pptx',
        'svg',
        'ttf',
        'txt',
        'wasm',
        'webmanifest',
        'webp',
        'woff',
        'woff2',
        'xls',
        'xlsx',
        'xml',
        'zip',
    ];

    public function sanitize(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

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

        return $url;
    }

    public function sanitizeIntended(?string $url): ?string
    {
        $url = $this->sanitize($url);

        if ($url === null || ! $this->isSafeIntendedUrl($url)) {
            return null;
        }

        return $url;
    }

    public function sanitizeIntendedForUser(?string $url, User $user): ?string
    {
        $url = $this->sanitizeIntended($url);

        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $route = is_string($path) ? $this->matchGetRoute('/'.ltrim($path, '/')) : null;

        if (! $route instanceof MatchedRoute || ! $this->userCanAccessRoute($route, $user)) {
            return null;
        }

        try {
            app('router')->substituteBindings($route);
            ImplicitRouteBinding::resolveForRoute(app(), $route);
        } catch (Throwable) {
            return null;
        }

        return $url;
    }

    public function isSafe(?string $url): bool
    {
        return $this->sanitize($url) !== null;
    }

    public function isSafeIntendedUrl(?string $url): bool
    {
        $url = $this->sanitize($url);

        if ($url === null) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return false;
        }

        $path = '/'.ltrim($path, '/');

        if ($this->isTechnicalPath($path)) {
            return false;
        }

        $route = $this->matchGetRoute($path);

        if (! $route instanceof MatchedRoute) {
            return false;
        }

        if (! in_array('auth', $route->gatherMiddleware(), true)) {
            return false;
        }

        return ! $this->isTechnicalRoute($route);
    }

    public function isSafePostUnlockUrl(?string $url): bool
    {
        return $this->isSafeIntendedUrl($url);
    }

    public function fallback(): string
    {
        return route('dashboard', [], false);
    }

    private function isTechnicalPath(string $path): bool
    {
        $path = Str::lower(rawurldecode($path));

        if (in_array($path, [
            '/auth/csrf-token',
            '/favicon.ico',
            '/manifest.json',
            '/manifest.webmanifest',
            '/offline',
            '/pwa-service-worker.js',
            '/session/status',
            '/session/touch',
        ], true)) {
            return true;
        }

        if (preg_match('#^/pwa-[^/]*\.js$#', $path) === 1) {
            return true;
        }

        if (Str::startsWith($path, [
            '/assets/',
            '/build/',
            '/forgot-password',
            '/lock-screen',
            '/login',
            '/logout',
            '/password/',
            '/public/archive/',
            '/reset-password',
            '/storage/',
            '/vendors/',
        ])) {
            return true;
        }

        if (preg_match('#\.('.implode('|', self::StaticExtensions).')$#i', $path) === 1) {
            return true;
        }

        return preg_match('#/(data|download|export|preview)(/|$)#', $path) === 1
            || str_contains($path, '/filter-options/')
            || str_contains($path, '/notifications/')
            || str_contains($path, '/operating-context/')
            || str_contains($path, '/picker/')
            || str_contains($path, '/public-link')
            || str_contains($path, '/select2/');
    }

    private function isTechnicalRoute(MatchedRoute $route): bool
    {
        $name = $route->getName();

        if (! is_string($name)) {
            return false;
        }

        if (Str::is([
            'auth.csrf-token',
            'lang.switch',
            'lock-screen.*',
            'login',
            'login.store',
            'logout',
            'password.*',
            'pwa.*',
            'session.*',
        ], $name)) {
            return true;
        }

        if (Str::is([
            '*.data',
            '*.download',
            '*.export.*',
            '*.filter-options.*',
            '*.image',
            '*.preview',
            '*.public-link.*',
            '*.select2.*',
            'admin.calendar.events*',
            'admin.chat.attachments.*',
            'admin.chat.messages.*',
            'admin.file-manager.picker.*',
            'admin.navigation-search*',
            'admin.notifications.*',
            'admin.operating-context.*',
            'admin.select2.*',
        ], $name)) {
            return true;
        }

        return false;
    }

    private function matchGetRoute(string $path): ?MatchedRoute
    {
        try {
            return Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return null;
        }
    }

    private function userCanAccessRoute(MatchedRoute $route, User $user): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! Str::startsWith($middleware, 'can:')) {
                continue;
            }

            $ability = Str::before(Str::after($middleware, 'can:'), ',');

            if ($ability === '' || ! $user->can($ability)) {
                return false;
            }
        }

        return true;
    }
}

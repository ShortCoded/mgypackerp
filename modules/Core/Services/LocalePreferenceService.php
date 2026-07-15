<?php

namespace Modules\Core\Services;

use Illuminate\Contracts\Cookie\QueueingFactory as CookieFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class LocalePreferenceService
{
    public const CookieName = 'app_locale';

    public function __construct(
        private readonly CookieFactory $cookies
    ) {}

    public function apply(Request $request): string
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);
        $request->session()->put('locale', $locale);
        $this->queueCookie($locale);

        return $locale;
    }

    public function resolve(Request $request): string
    {
        $userLocale = $request->user()?->locale;

        if ($this->isAvailable($userLocale)) {
            return (string) $userLocale;
        }

        $sessionLocale = $request->session()->get('locale');

        if ($this->isAvailable($sessionLocale)) {
            return (string) $sessionLocale;
        }

        $cookieLocale = $request->cookie(self::CookieName);

        if ($this->isAvailable($cookieLocale)) {
            return (string) $cookieLocale;
        }

        return $this->defaultLocale();
    }

    public function persist(Request $request, string $locale, bool $saveAuthenticatedUser = true): string
    {
        $locale = $this->normalize($locale);

        $request->session()->put('locale', $locale);
        App::setLocale($locale);
        $this->queueCookie($locale);

        if ($saveAuthenticatedUser && $request->user()?->locale !== $locale) {
            $request->user()?->forceFill(['locale' => $locale])->save();
        }

        return $locale;
    }

    public function queueCookie(string $locale): void
    {
        $this->cookies->queue(cookie(
            name: self::CookieName,
            value: $this->normalize($locale),
            minutes: 60 * 24 * 365,
            httpOnly: true,
            secure: $this->secureCookie(),
            sameSite: 'lax'
        ));
    }

    public function normalize(?string $locale): string
    {
        return $this->isAvailable($locale) ? (string) $locale : $this->defaultLocale();
    }

    private function isAvailable(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, config('languages.available', []));
    }

    private function defaultLocale(): string
    {
        $default = (string) config('languages.default', config('app.locale', 'ar'));

        return $this->isAvailable($default) ? $default : 'ar';
    }

    private function secureCookie(): bool
    {
        return (bool) (config('session.secure') ?? app()->isProduction());
    }
}

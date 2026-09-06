<?php

use App\Models\User;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Modules\Core\Services\LocalePreferenceService;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

use function Pest\Laravel\mock;

uses(TestCase::class);

test('applying an unchanged locale does not rewrite the session or cookie', function (): void {
    $cookies = mock(CookieFactory::class);
    $cookies->shouldNotReceive('queue');

    $session = mock(Session::class);
    $session->shouldReceive('get')
        ->with('locale')
        ->twice()
        ->andReturn('en');
    $session->shouldNotReceive('put');

    $request = Request::create('/', 'GET', cookies: [LocalePreferenceService::CookieName => 'en']);
    $request->setLaravelSession($session);

    $locale = (new LocalePreferenceService($cookies))->apply($request);

    expect($locale)->toBe('en')
        ->and(app()->getLocale())->toBe('en');
});

test('persisting an unchanged locale does not rewrite the session or cookie', function (): void {
    $cookies = mock(CookieFactory::class);
    $cookies->shouldNotReceive('queue');

    $session = mock(Session::class);
    $session->shouldReceive('get')
        ->once()
        ->with('locale')
        ->andReturn('ar');
    $session->shouldNotReceive('put');

    $request = Request::create('/', 'GET', cookies: [LocalePreferenceService::CookieName => 'ar']);
    $request->setLaravelSession($session);

    $locale = (new LocalePreferenceService($cookies))->persist($request, 'ar', saveAuthenticatedUser: false);

    expect($locale)->toBe('ar')
        ->and(app()->getLocale())->toBe('ar');
});

test('applying a user locale synchronizes stale request state once', function (): void {
    $cookies = mock(CookieFactory::class);
    $cookies->shouldReceive('queue')
        ->once()
        ->with(Mockery::on(fn (Cookie $cookie): bool => $cookie->getName() === LocalePreferenceService::CookieName
            && $cookie->getValue() === 'en'));

    $session = mock(Session::class);
    $session->shouldReceive('get')
        ->once()
        ->with('locale')
        ->andReturn('ar');
    $session->shouldReceive('put')
        ->once()
        ->with('locale', 'en');

    $request = Request::create('/', 'GET', cookies: [LocalePreferenceService::CookieName => 'ar']);
    $request->setLaravelSession($session);
    $request->setUserResolver(fn (): User => new User(['locale' => 'en']));

    $locale = (new LocalePreferenceService($cookies))->apply($request);

    expect($locale)->toBe('en')
        ->and(app()->getLocale())->toBe('en');
});

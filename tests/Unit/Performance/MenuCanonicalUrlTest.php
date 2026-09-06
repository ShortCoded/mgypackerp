<?php

use Illuminate\Http\Request;
use Modules\Core\Services\MenuService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $request = Request::create('https://untrusted.example.test/dashboard');
    app()->instance('request', $request);
    app('url')->setRequest($request);
});

test('menu URLs remain hostless while preserving the configured application base path', function (): void {
    config()->set('app.url', 'https://ERP.Example.Test:8443/erp/');

    $url = app(MenuService::class)->urlFor(['route' => 'dashboard']);

    expect($url)
        ->toBe('/erp/dashboard')
        ->not->toContain('erp.example.test')
        ->not->toContain('untrusted.example.test');
});

test('an invalid configured application URL falls back to a hostless relative URL', function (): void {
    config()->set('app.url', 'https://trusted.example.test@untrusted.example.test');

    $url = app(MenuService::class)->urlFor(['route' => 'dashboard']);

    expect($url)
        ->toBe('/dashboard')
        ->not->toContain('untrusted.example.test');
});

test('an application URL without a path generates a hostless relative URL', function (): void {
    config()->set('app.url', 'http://127.0.0.1:8000');

    expect(app(MenuService::class)->urlFor(['route' => 'dashboard']))->toBe('/dashboard');
});

test('runtime menu payload omits permission actions while permission structure keeps them', function (): void {
    $flatten = function (array $items) use (&$flatten): array {
        $flattened = [];

        foreach ($items as $item) {
            $flattened[] = $item;
            $flattened = array_merge($flattened, $flatten($item['children'] ?? []));
        }

        return $flattened;
    };

    $menu = app(MenuService::class);
    $runtimeItems = $flatten($menu->structure());
    $permissionItems = $flatten($menu->permissionStructure());

    expect(collect($runtimeItems)->contains(fn (array $item): bool => array_key_exists('actions', $item)))
        ->toBeFalse()
        ->and(collect($permissionItems)->contains(fn (array $item): bool => ($item['actions'] ?? []) !== []))
        ->toBeTrue();
});

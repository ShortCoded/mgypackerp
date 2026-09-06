<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Application;
use Modules\Core\Services\NormalizedMenuCache;

function normalizedMenuCacheApplication(
    bool $production = true,
    bool $configurationCached = true,
    bool $routesCached = true,
): Application {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('isProduction')->andReturn($production);
    $application->shouldReceive('configurationIsCached')->andReturn($configurationCached);
    $application->shouldReceive('routesAreCached')->andReturn($routesCached);

    return $application;
}

function normalizedMenuCacheConfig(
    ?string $releaseIdentifier = 'release-1',
    string $applicationUrl = 'https://erp.example.test',
): ConfigRepository {
    return new ConfigRepository([
        'app' => [
            'asset_version' => $releaseIdentifier,
            'url' => $applicationUrl,
        ],
    ]);
}

test('it shares only matching release locale and phase contexts', function (): void {
    $store = new ArrayStore(true);
    $service = new NormalizedMenuCache(
        normalizedMenuCacheApplication(),
        new Repository($store),
        normalizedMenuCacheConfig(),
    );
    $resolutions = 0;
    $resolver = function () use (&$resolutions): array {
        return [['resolution' => ++$resolutions]];
    };

    $first = $service->remember('en', 'legacy', $resolver);
    $sameContext = $service->remember('en', 'legacy', $resolver);
    $otherLocale = $service->remember('ar', 'legacy', $resolver);
    $caseSensitiveLocale = $service->remember('EN', 'legacy', $resolver);
    $otherPhase = $service->remember('en', 'expanded', $resolver);

    expect($first)->toBe([['resolution' => 1]])
        ->and($sameContext)->toBe($first)
        ->and($otherLocale)->toBe([['resolution' => 2]])
        ->and($caseSensitiveLocale)->toBe([['resolution' => 3]])
        ->and($otherPhase)->toBe([['resolution' => 4]])
        ->and($resolutions)->toBe(4)
        ->and($store->all())->toHaveCount(4);
});

test('release changes replace the same bounded context key without reading cache artifact metadata', function (): void {
    $store = new ArrayStore(true);
    $cache = new Repository($store);
    $config = normalizedMenuCacheConfig();
    $application = normalizedMenuCacheApplication();
    $application->shouldNotReceive('getCachedConfigPath');
    $application->shouldNotReceive('getCachedRoutesPath');
    $service = new NormalizedMenuCache($application, $cache, $config);
    $resolutions = 0;
    $resolver = function () use (&$resolutions): array {
        return [['resolution' => ++$resolutions]];
    };

    $first = $service->remember('en', 'legacy', $resolver);
    $cached = $service->remember('en', 'legacy', $resolver);
    $firstEnvelope = array_values($store->all())[0]['value'];

    $config->set('app.asset_version', 'release-2');

    $refreshed = $service->remember('en', 'legacy', $resolver);
    $refreshedEnvelope = array_values($store->all())[0]['value'];

    expect($first)->toBe([['resolution' => 1]])
        ->and($cached)->toBe($first)
        ->and($refreshed)->toBe([['resolution' => 2]])
        ->and($firstEnvelope['deployment'])->not->toBe($refreshedEnvelope['deployment'])
        ->and($store->all())->toHaveCount(1);
});

test('only the configured base path participates in the menu deployment fingerprint', function (): void {
    $store = new ArrayStore(true);
    $config = normalizedMenuCacheConfig(applicationUrl: 'https://first.example.test/erp');
    $service = new NormalizedMenuCache(
        normalizedMenuCacheApplication(),
        new Repository($store),
        $config,
    );
    $resolutions = 0;
    $resolver = function () use (&$resolutions): array {
        return [['resolution' => ++$resolutions]];
    };

    $first = $service->remember('en', 'legacy', $resolver);

    $config->set('app.url', 'https://second.example.test/erp');
    $sameBasePath = $service->remember('en', 'legacy', $resolver);

    $config->set('app.url', 'https://second.example.test/back-office');
    $differentBasePath = $service->remember('en', 'legacy', $resolver);

    expect($first)->toBe([['resolution' => 1]])
        ->and($sameBasePath)->toBe($first)
        ->and($differentBasePath)->toBe([['resolution' => 2]])
        ->and($store->all())->toHaveCount(1);
});

test('it bypasses shared cache unless production config routes and release identifier are all ready', function (
    bool $production,
    bool $configurationCached,
    bool $routesCached,
    ?string $releaseIdentifier,
): void {
    $store = new ArrayStore(true);
    $service = new NormalizedMenuCache(
        normalizedMenuCacheApplication($production, $configurationCached, $routesCached),
        new Repository($store),
        normalizedMenuCacheConfig($releaseIdentifier),
    );
    $resolutions = 0;
    $resolver = function () use (&$resolutions): array {
        return [['resolution' => ++$resolutions]];
    };

    $first = $service->remember('en', 'legacy', $resolver);
    $second = $service->remember('en', 'legacy', $resolver);

    expect($first)->toBe([['resolution' => 1]])
        ->and($second)->toBe([['resolution' => 2]])
        ->and($store->all())->toBeEmpty();
})->with([
    'non-production' => [false, true, true, 'release-1'],
    'uncached config' => [true, false, true, 'release-1'],
    'uncached routes' => [true, true, false, 'release-1'],
    'missing release identifier' => [true, true, true, null],
    'blank release identifier' => [true, true, true, '  '],
]);

test('it falls back to direct resolution when a cache read fails', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->once()->andThrow(new RuntimeException('cache unavailable'));
    $cache->shouldNotReceive('forever');
    $service = new NormalizedMenuCache(
        normalizedMenuCacheApplication(),
        $cache,
        normalizedMenuCacheConfig(),
    );

    expect($service->remember('en', 'legacy', fn (): array => [['fresh' => true]]))
        ->toBe([['fresh' => true]]);
});

test('it returns one direct resolution when a cache write fails', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->once()->andReturnNull();
    $cache->shouldReceive('forever')->once()->andThrow(new RuntimeException('cache unavailable'));
    $service = new NormalizedMenuCache(
        normalizedMenuCacheApplication(),
        $cache,
        normalizedMenuCacheConfig(),
    );
    $resolutions = 0;

    $result = $service->remember('en', 'legacy', function () use (&$resolutions): array {
        $resolutions++;

        return [['fresh' => true]];
    });

    expect($result)->toBe([['fresh' => true]])
        ->and($resolutions)->toBe(1);
});

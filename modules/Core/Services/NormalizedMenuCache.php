<?php

namespace Modules\Core\Services;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Throwable;

class NormalizedMenuCache
{
    private const CacheKeyPrefix = 'erp.menu.normalized-base.v1.';

    private const PayloadVersion = 2;

    public function __construct(
        private readonly Application $application,
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @param  Closure(): array<int, array<string, mixed>>  $resolver
     * @return array<int, array<string, mixed>>
     */
    public function remember(
        string $locale,
        string $phase,
        Closure $resolver,
    ): array {
        $releaseIdentifier = $this->releaseIdentifier();

        if (! $this->shouldUseSharedCache() || $releaseIdentifier === null) {
            return $resolver();
        }

        $deploymentFingerprint = $this->deploymentFingerprint($releaseIdentifier);

        $key = $this->cacheKey($locale, $phase);

        try {
            $cached = $this->cache->get($key);
        } catch (Throwable) {
            return $resolver();
        }

        $cachedItems = $this->itemsFromCurrentDeployment($cached, $deploymentFingerprint);

        if ($cachedItems !== null) {
            return $cachedItems;
        }

        $items = $resolver();

        try {
            $this->cache->forever($key, [
                'version' => self::PayloadVersion,
                'deployment' => $deploymentFingerprint,
                'items' => $items,
            ]);
        } catch (Throwable) {
        }

        return $items;
    }

    private function shouldUseSharedCache(): bool
    {
        return $this->config->get('app.env') === 'production'
            && $this->application->configurationIsCached()
            && $this->application->routesAreCached();
    }

    private function deploymentFingerprint(string $releaseIdentifier): string
    {
        return hash('sha256', implode("\0", [
            (string) self::PayloadVersion,
            'release',
            $releaseIdentifier,
            'base-path',
            $this->configuredBasePath(),
        ]));
    }

    private function releaseIdentifier(): ?string
    {
        $releaseIdentifier = $this->config->get('app.asset_version');

        if (! is_string($releaseIdentifier) || trim($releaseIdentifier) === '') {
            return null;
        }

        return trim($releaseIdentifier);
    }

    private function configuredBasePath(): string
    {
        $configuredUrl = $this->config->get('app.url');

        if (! is_string($configuredUrl)) {
            return '';
        }

        $configuredUrl = trim($configuredUrl);
        $parts = parse_url($configuredUrl);
        $configuredPath = is_array($parts) ? ($parts['path'] ?? '') : '';

        if ($configuredUrl === ''
            || filter_var($configuredUrl, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! is_string($configuredPath)) {
            return '';
        }

        $basePath = trim($configuredPath, '/');

        return $basePath === '' ? '' : '/'.$basePath;
    }

    private function cacheKey(string $locale, string $phase): string
    {
        $context = implode("\0", [
            trim($locale),
            trim($phase),
        ]);

        return self::CacheKeyPrefix.hash('sha256', $context);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function itemsFromCurrentDeployment(mixed $cached, string $deploymentFingerprint): ?array
    {
        if (! is_array($cached)
            || ($cached['version'] ?? null) !== self::PayloadVersion
            || ! is_string($cached['deployment'] ?? null)
            || ! hash_equals($deploymentFingerprint, $cached['deployment'])
            || ! is_array($cached['items'] ?? null)) {
            return null;
        }

        return $cached['items'];
    }
}

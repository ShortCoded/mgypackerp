<?php

namespace Modules\Core\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

class AssetVersionService
{
    public function __construct(
        private readonly RequestMemo $memo,
        private readonly ConfigRepository $config,
    ) {}

    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        $url = asset($path);
        $version = $this->version($path);

        if ($version === null) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'v='.rawurlencode($version);
    }

    private function version(string $path): ?string
    {
        return $this->memo->remember('asset.version.'.sha1($path), function () use ($path): ?string {
            $deploymentVersion = $this->deploymentVersion();

            if ($deploymentVersion !== null) {
                return $deploymentVersion;
            }

            $absolutePath = public_path($path);

            $stat = @stat($absolutePath);

            if (! is_array($stat) || ! is_file($absolutePath)) {
                return null;
            }

            return implode('-', [
                (string) ($stat['mtime'] ?? 0),
                (string) ($stat['ctime'] ?? 0),
                (string) ($stat['size'] ?? 0),
                (string) ($stat['ino'] ?? 0),
            ]);
        });
    }

    private function deploymentVersion(): ?string
    {
        return $this->memo->remember('asset.deployment_version', function (): ?string {
            $configuredVersion = $this->config->get('app.asset_version');

            if (is_string($configuredVersion) && trim($configuredVersion) !== '') {
                return trim($configuredVersion);
            }

            return null;
        });
    }
}

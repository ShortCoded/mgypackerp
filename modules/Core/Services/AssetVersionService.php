<?php

namespace Modules\Core\Services;

class AssetVersionService
{
    public function __construct(
        private readonly RequestMemo $memo,
    ) {}

    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        $url = asset($path);
        $version = $this->version($path);

        if ($version === null) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'v='.$version;
    }

    private function version(string $path): ?string
    {
        return $this->memo->remember('asset.version.'.sha1($path), function () use ($path): ?string {
            $absolutePath = public_path($path);

            if (! is_file($absolutePath)) {
                return null;
            }

            $modifiedAt = filemtime($absolutePath);

            return $modifiedAt === false ? null : (string) $modifiedAt;
        });
    }
}

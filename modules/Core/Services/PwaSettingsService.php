<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\ArchiveFile;

class PwaSettingsService
{
    public const EnabledKey = 'pwa.enabled';

    public const AppNameKey = 'pwa.app_name';

    public const ShortNameKey = 'pwa.short_name';

    public const DescriptionKey = 'pwa.description';

    public const StartUrlKey = 'pwa.start_url';

    public const ScopeKey = 'pwa.scope';

    public const DisplayKey = 'pwa.display';

    public const OrientationKey = 'pwa.orientation';

    public const ThemeColorKey = 'pwa.theme_color';

    public const BackgroundColorKey = 'pwa.background_color';

    public const LocaleKey = 'pwa.locale';

    public const DirectionKey = 'pwa.direction';

    public const ServiceWorkerEnabledKey = 'pwa.service_worker_enabled';

    public const OfflineEnabledKey = 'pwa.offline_enabled';

    public const OfflineTitleKey = 'pwa.offline_title';

    public const OfflineMessageKey = 'pwa.offline_message';

    public const CacheNameKey = 'pwa.cache_name';

    public const Icon192PathKey = 'pwa.icon_192_path';

    public const Icon512PathKey = 'pwa.icon_512_path';

    public const IconMaskablePathKey = 'pwa.icon_maskable_path';

    public const AppleTouchIconPathKey = 'pwa.apple_touch_icon_path';

    private const IconDirectory = 'pwa/icons';

    private const DefaultCacheName = 'erp-pwa-cache-v1';

    /** @var array<string, string> */
    private const DefaultIconPaths = [
        'icon_192' => 'assets/img/favicon/web-app-manifest-192x192.png',
        'icon_512' => 'assets/img/favicon/web-app-manifest-512x512.png',
        'icon_maskable' => 'assets/img/favicon/web-app-manifest-512x512.png',
        'apple_touch_icon' => 'assets/img/favicon/apple-touch-icon.png',
    ];

    /**
     * @var array<string, array{key: string, sizes: string, purpose: string}>
     */
    private const IconFields = [
        'icon_192' => ['key' => self::Icon192PathKey, 'sizes' => '192x192', 'purpose' => 'any'],
        'icon_512' => ['key' => self::Icon512PathKey, 'sizes' => '512x512', 'purpose' => 'any'],
        'icon_maskable' => ['key' => self::IconMaskablePathKey, 'sizes' => '512x512', 'purpose' => 'maskable'],
        'apple_touch_icon' => ['key' => self::AppleTouchIconPathKey, 'sizes' => '180x180', 'purpose' => 'any'],
    ];

    public function __construct(
        private readonly SettingService $settings,
        private readonly FilePickerService $filePicker,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $defaults = $this->defaults();
        $values = $this->settings->many(array_keys($defaults), $defaults);

        return [
            'enabled' => $this->boolValue($values[self::EnabledKey] ?? false),
            'app_name' => (string) ($values[self::AppNameKey] ?? $defaults[self::AppNameKey]),
            'short_name' => (string) ($values[self::ShortNameKey] ?? $defaults[self::ShortNameKey]),
            'description' => (string) ($values[self::DescriptionKey] ?? ''),
            'start_url' => (string) ($values[self::StartUrlKey] ?? $defaults[self::StartUrlKey]),
            'scope' => (string) ($values[self::ScopeKey] ?? $defaults[self::ScopeKey]),
            'display' => (string) ($values[self::DisplayKey] ?? $defaults[self::DisplayKey]),
            'orientation' => (string) ($values[self::OrientationKey] ?? $defaults[self::OrientationKey]),
            'theme_color' => (string) ($values[self::ThemeColorKey] ?? $defaults[self::ThemeColorKey]),
            'background_color' => (string) ($values[self::BackgroundColorKey] ?? $defaults[self::BackgroundColorKey]),
            'locale' => (string) ($values[self::LocaleKey] ?? $defaults[self::LocaleKey]),
            'direction' => (string) ($values[self::DirectionKey] ?? $defaults[self::DirectionKey]),
            'service_worker_enabled' => $this->boolValue($values[self::ServiceWorkerEnabledKey] ?? true),
            'offline_enabled' => $this->boolValue($values[self::OfflineEnabledKey] ?? true),
            'offline_title' => (string) ($values[self::OfflineTitleKey] ?? __('pwa.offline.default_title')),
            'offline_message' => (string) ($values[self::OfflineMessageKey] ?? __('pwa.offline.default_message')),
            'cache_name' => (string) ($values[self::CacheNameKey] ?? self::DefaultCacheName),
            'icon_192_path' => $this->nullableString($values[self::Icon192PathKey] ?? null),
            'icon_512_path' => $this->nullableString($values[self::Icon512PathKey] ?? null),
            'icon_maskable_path' => $this->nullableString($values[self::IconMaskablePathKey] ?? null),
            'apple_touch_icon_path' => $this->nullableString($values[self::AppleTouchIconPathKey] ?? null),
            'icon_192_url' => $this->urlFor($values[self::Icon192PathKey] ?? null),
            'icon_512_url' => $this->urlFor($values[self::Icon512PathKey] ?? null),
            'icon_maskable_url' => $this->urlFor($values[self::IconMaskablePathKey] ?? null),
            'apple_touch_icon_url' => $this->urlFor($values[self::AppleTouchIconPathKey] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(array $data): array
    {
        $defaults = $this->defaults();
        $current = $this->settings->many([
            self::OrientationKey,
            self::DirectionKey,
            self::OfflineTitleKey,
            self::OfflineMessageKey,
        ], $defaults);

        $values = [
            'enabled' => ! empty($data['enabled']) ? '1' : '0',
            'app_name' => trim((string) ($data['app_name'] ?? '')),
            'short_name' => trim((string) ($data['short_name'] ?? '')),
            'description' => $this->blankToNull($data['description'] ?? null),
            'display' => trim((string) ($data['display'] ?? 'standalone')),
            'orientation' => $this->safeChoice($data['orientation'] ?? $current[self::OrientationKey] ?? null, ['any', 'portrait', 'landscape'], (string) $defaults[self::OrientationKey]),
            'direction' => $this->safeChoice($data['direction'] ?? $current[self::DirectionKey] ?? null, ['auto', 'rtl', 'ltr'], (string) $defaults[self::DirectionKey]),
            'offline_title' => $this->blankToNull($data['offline_title'] ?? $current[self::OfflineTitleKey] ?? null) ?? (string) $defaults[self::OfflineTitleKey],
            'offline_message' => $this->blankToNull($data['offline_message'] ?? $current[self::OfflineMessageKey] ?? null) ?? (string) $defaults[self::OfflineMessageKey],
            ...$this->systemControlledValues(),
        ];

        foreach ($this->scalarKeys() as $input => $key) {
            $this->settings->set($key, $values[$input] ?? null);
        }

        foreach (self::IconFields as $input => $definition) {
            $currentPath = $this->settings->get($definition['key']);
            $selectedFileDocNum = $this->blankToNull($data["{$input}_archive_file_doc_num"] ?? null);

            if ($selectedFileDocNum !== null) {
                $path = $this->syncArchiveIconToPublicStorage($selectedFileDocNum, $input);
                $this->deleteIfOwned($this->nullableString($currentPath));
                $this->settings->set($definition['key'], $path);
            }
        }

        return $this->settings();
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $settings = $this->settings();
        $manifest = [
            'name' => $settings['app_name'],
            'short_name' => $settings['short_name'],
            'description' => $settings['description'],
            'start_url' => $settings['start_url'],
            'scope' => $settings['scope'],
            'display' => $settings['display'],
            'orientation' => $settings['orientation'],
            'theme_color' => $settings['theme_color'],
            'background_color' => $settings['background_color'],
            'lang' => $settings['locale'],
            'dir' => $settings['direction'],
            'icons' => [],
        ];

        foreach (self::IconFields as $input => $definition) {
            $configuredPath = $settings["{$input}_path"] ?? null;
            $configuredUrl = $settings["{$input}_url"] ?? null;
            $path = is_string($configuredPath) && $configuredPath !== ''
                ? $configuredPath
                : self::DefaultIconPaths[$input];
            $url = is_string($configuredUrl) && $configuredUrl !== ''
                ? $configuredUrl
                : asset(self::DefaultIconPaths[$input]);

            $manifest['icons'][] = [
                'src' => $url,
                'sizes' => $definition['sizes'],
                'type' => $this->mimeTypeForPath($path),
                'purpose' => $definition['purpose'],
            ];
        }

        return $manifest;
    }

    /**
     * @return array<string, string>
     */
    public function defaultRows(): array
    {
        return collect($this->defaults())
            ->map(fn (mixed $value): string => is_bool($value) ? ($value ? '1' : '0') : (string) $value)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        $appName = (string) config('app.name', 'ERP');

        return [
            self::EnabledKey => false,
            self::AppNameKey => $appName,
            self::ShortNameKey => Str::limit($appName, 20, ''),
            self::DescriptionKey => __('pwa.defaults.description'),
            self::StartUrlKey => '/dashboard',
            self::ScopeKey => '/',
            self::DisplayKey => 'standalone',
            self::OrientationKey => 'any',
            self::ThemeColorKey => '#ffffff',
            self::BackgroundColorKey => '#ffffff',
            self::LocaleKey => app()->getLocale(),
            self::DirectionKey => $this->localeDirection(),
            self::ServiceWorkerEnabledKey => true,
            self::OfflineEnabledKey => true,
            self::OfflineTitleKey => __('pwa.offline.default_title'),
            self::OfflineMessageKey => __('pwa.offline.default_message'),
            self::CacheNameKey => self::DefaultCacheName,
            self::Icon192PathKey => '',
            self::Icon512PathKey => '',
            self::IconMaskablePathKey => '',
            self::AppleTouchIconPathKey => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function scalarKeys(): array
    {
        return [
            'enabled' => self::EnabledKey,
            'app_name' => self::AppNameKey,
            'short_name' => self::ShortNameKey,
            'description' => self::DescriptionKey,
            'display' => self::DisplayKey,
            'start_url' => self::StartUrlKey,
            'scope' => self::ScopeKey,
            'orientation' => self::OrientationKey,
            'theme_color' => self::ThemeColorKey,
            'background_color' => self::BackgroundColorKey,
            'locale' => self::LocaleKey,
            'direction' => self::DirectionKey,
            'service_worker_enabled' => self::ServiceWorkerEnabledKey,
            'offline_enabled' => self::OfflineEnabledKey,
            'offline_title' => self::OfflineTitleKey,
            'offline_message' => self::OfflineMessageKey,
            'cache_name' => self::CacheNameKey,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function systemControlledValues(): array
    {
        $defaults = $this->defaults();
        $current = $this->settings->many([
            self::ThemeColorKey,
            self::BackgroundColorKey,
        ], $defaults);

        return [
            'start_url' => route('dashboard', [], false),
            'scope' => '/',
            'theme_color' => $this->safeHexColor($current[self::ThemeColorKey] ?? null, (string) $defaults[self::ThemeColorKey]),
            'background_color' => $this->safeHexColor($current[self::BackgroundColorKey] ?? null, (string) $defaults[self::BackgroundColorKey]),
            'locale' => app()->getLocale(),
            'service_worker_enabled' => '1',
            'offline_enabled' => '1',
            'cache_name' => self::DefaultCacheName,
        ];
    }

    private function syncArchiveIconToPublicStorage(string $publicId, string $input): string
    {
        $file = $this->selectedArchiveImageFile($publicId, $input);
        $extension = mb_strtolower((string) ($file->extension ?: pathinfo((string) $file->path, PATHINFO_EXTENSION))) ?: 'png';
        $filename = sprintf('%s-%s.%s', str_replace('_', '-', $input), Str::uuid(), $extension);
        $path = self::IconDirectory.'/'.$filename;
        $stream = Storage::disk($file->disk)->readStream($file->path);

        if (! is_resource($stream)) {
            throw ValidationException::withMessages([
                "{$input}_archive_file_doc_num" => __('pwa.validation.selected_file_unavailable'),
            ]);
        }

        try {
            Storage::disk('public')->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    private function selectedArchiveImageFile(string $publicId, string $input): ArchiveFile
    {
        $companyId = $this->companyContext->currentCompanyId();
        $file = $companyId === null ? null : $this->filePicker->selectableFileByPublicId($publicId, $companyId, FilePickerService::AcceptImage);

        if (! $file instanceof ArchiveFile) {
            throw ValidationException::withMessages([
                "{$input}_archive_file_doc_num" => __('pwa.validation.selected_file_unavailable'),
            ]);
        }

        return $file;
    }

    private function urlFor(mixed $path): ?string
    {
        $path = $this->nullableString($path);

        return $path === null ? null : Storage::disk('public')->url($path);
    }

    private function deleteIfOwned(?string $path): void
    {
        if ($path !== null && Str::startsWith($path, self::IconDirectory.'/')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function mimeTypeForPath(string $path): string
    {
        return match (Str::lower(pathinfo($path, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            default => 'image/png',
        };
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function safeHexColor(mixed $value, string $default): string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : $default;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function safeChoice(mixed $value, array $allowed, string $default): string
    {
        $value = trim((string) $value);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function localeDirection(): string
    {
        $direction = (string) config('languages.available.'.app()->getLocale().'.dir', 'ltr');

        return in_array($direction, ['rtl', 'ltr'], true) ? $direction : 'ltr';
    }
}

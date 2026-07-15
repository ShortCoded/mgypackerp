<?php

namespace Modules\Core\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\Setting;
use Throwable;

class SettingService
{
    public const DateFormatKey = 'date_format';

    public const DateTimeFormatKey = 'date_time_format';

    public const DefaultDateFormat = 'd/m/Y';

    public const DefaultDateTimeFormat = 'd/m/Y h:i A';

    public function __construct(
        private readonly RequestMemo $memo,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->cacheKey($key);

        if (! $this->memo->has($cacheKey)) {
            $this->memo->put($cacheKey, $this->cachedValue($key)['value']);
        }

        return $this->memo->get($cacheKey) ?? $default;
    }

    /**
     * @param  list<string>|array<int, string>  $keys
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public function many(array $keys, array $defaults = []): array
    {
        $keys = collect($keys)
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->map(fn (string $key): string => trim($key))
            ->unique()
            ->values()
            ->all();

        $missingKeys = array_values(array_filter(
            $keys,
            fn (string $key): bool => ! $this->memo->has($this->cacheKey($key))
        ));

        if ($missingKeys !== []) {
            $cachedValues = $this->cachedValues($missingKeys);

            foreach ($missingKeys as $key) {
                $this->memo->put($this->cacheKey($key), $cachedValues[$key]['value'] ?? null);
            }
        }

        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->memo->get($this->cacheKey($key)) ?? ($defaults[$key] ?? null);
        }

        return $values;
    }

    public function set(string $key, mixed $value): Setting
    {
        $setting = Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value === null ? null : (string) $value],
        );

        self::forgetPersistentCacheFor($key);
        $this->memo->put($this->cacheKey($key), $setting->value);

        return $setting;
    }

    /**
     * @return array{date_format: string, date_time_format: string}
     */
    public function dateFormats(): array
    {
        $values = $this->many([
            self::DateFormatKey,
            self::DateTimeFormatKey,
        ], [
            self::DateFormatKey => self::DefaultDateFormat,
            self::DateTimeFormatKey => self::DefaultDateTimeFormat,
        ]);

        return [
            self::DateFormatKey => (string) ($values[self::DateFormatKey] ?? self::DefaultDateFormat),
            self::DateTimeFormatKey => (string) ($values[self::DateTimeFormatKey] ?? self::DefaultDateTimeFormat),
        ];
    }

    public function dateFormat(): string
    {
        return $this->dateFormats()[self::DateFormatKey];
    }

    public function dateTimeFormat(): string
    {
        return $this->dateFormats()[self::DateTimeFormatKey];
    }

    public function formatDate(mixed $date, ?string $fallback = null): string
    {
        return $this->formatTemporalValue($date, $this->dateFormat(), $fallback);
    }

    public function formatDateTime(mixed $date, ?string $fallback = null): string
    {
        return $this->formatTemporalValue($date, $this->dateTimeFormat(), $fallback);
    }

    private function formatTemporalValue(mixed $date, string $format, ?string $fallback): string
    {
        $fallback ??= __('common.messages.not_available');

        if ($date === null || $date === '') {
            return $fallback;
        }

        try {
            if ($date instanceof DateTimeInterface) {
                return Carbon::instance($date)->format($format);
            }

            if (is_string($date)) {
                $date = trim($date);

                $parsedDate = $this->parseStoredTemporalValue($date);

                if (! $parsedDate instanceof Carbon) {
                    return $fallback;
                }

                return $parsedDate->format($format);
            }

            return Carbon::parse($date)->format($format);
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function parseStoredTemporalValue(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        foreach ([
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d\TH:i:s.uP',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
            'Y-m-d',
        ] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value, config('app.timezone'));
            } catch (Throwable) {
                continue;
            }

            $errors = Carbon::getLastErrors();

            if (
                $date instanceof Carbon
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format($format) === $value
            ) {
                return strpbrk($format, 'HhGgisAa') === false ? $date->startOfDay() : $date;
            }
        }

        return null;
    }

    private function cacheKey(string $key): string
    {
        return 'settings.value.'.sha1($key);
    }

    /**
     * @return array{exists: bool, value: string|null}
     */
    private function cachedValue(string $key): array
    {
        return Cache::rememberForever(self::persistentCacheKey($key), function () use ($key): array {
            $setting = Setting::query()
                ->where('key', $key)
                ->first();

            return [
                'exists' => $setting instanceof Setting,
                'value' => $setting?->value,
            ];
        });
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{exists: bool, value: string|null}>
     */
    private function cachedValues(array $keys): array
    {
        $cacheKeysBySetting = collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => self::persistentCacheKey($key)])
            ->all();
        $cachedPayloads = Cache::many(array_values($cacheKeysBySetting));
        $missingKeys = [];
        $values = [];

        foreach ($cacheKeysBySetting as $settingKey => $cacheKey) {
            $payload = $cachedPayloads[$cacheKey] ?? null;

            if (is_array($payload) && array_key_exists('value', $payload)) {
                $values[$settingKey] = [
                    'exists' => (bool) ($payload['exists'] ?? false),
                    'value' => is_string($payload['value'] ?? null) ? $payload['value'] : null,
                ];

                continue;
            }

            $missingKeys[] = $settingKey;
        }

        if ($missingKeys === []) {
            return $values;
        }

        $settings = Setting::query()
            ->whereIn('key', $missingKeys)
            ->pluck('value', 'key');

        foreach ($missingKeys as $key) {
            $value = $settings->get($key);
            $payload = [
                'exists' => $settings->has($key),
                'value' => $settings->has($key) && $value !== null ? (string) $value : null,
            ];

            Cache::forever(self::persistentCacheKey($key), $payload);
            $values[$key] = $payload;
        }

        return $values;
    }

    public static function forgetPersistentCacheFor(string $key): void
    {
        Cache::forget(self::persistentCacheKey($key));

        try {
            app(RequestMemo::class)->forget('settings.value.'.sha1($key));
        } catch (Throwable) {
        }
    }

    public static function persistentCacheKey(string $key): string
    {
        return 'erp.settings.value.'.sha1($key);
    }
}

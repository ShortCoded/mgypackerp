<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Setting;
use Modules\Core\Services\PwaSettingsService;
use Modules\Core\Services\SettingService;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            SettingService::DateFormatKey => SettingService::DefaultDateFormat,
            SettingService::DateTimeFormatKey => SettingService::DefaultDateTimeFormat,
            ...$this->documentNumberDefaults(),
        ])->each(function (string $value, string $key): void {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => $value],
            );
        });

        collect(app(PwaSettingsService::class)->defaultRows())
            ->each(function (string $value, string $key): void {
                Setting::query()->firstOrCreate(
                    ['key' => $key],
                    ['value' => $value],
                );
            });
    }

    /**
     * @return array<string, string>
     */
    private function documentNumberDefaults(): array
    {
        return collect(config('document_numbers', []))
            ->flatMap(function (mixed $definition, string $key): array {
                if (! is_array($definition)) {
                    return [];
                }

                return [
                    "document_numbers.{$key}.prefix" => (string) ($definition['prefix'] ?? ''),
                    "document_numbers.{$key}.padding" => (string) ($definition['padding'] ?? 0),
                ];
            })
            ->all();
    }
}

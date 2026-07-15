<?php

namespace Modules\HR\Services;

use Modules\Core\Services\SettingService;

class HrLookupDocumentNumberSettingsService
{
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * @return array{prefix: string, padding: int}
     */
    public function current(HrLookupDefinition $definition): array
    {
        $prefixKey = $this->prefixKey($definition);
        $paddingKey = $this->paddingKey($definition);
        $values = $this->settings->many([
            $prefixKey,
            $paddingKey,
            SettingService::DateFormatKey,
            SettingService::DateTimeFormatKey,
        ], [
            $prefixKey => config("document_numbers.{$definition->documentKey}.prefix", ''),
            $paddingKey => config("document_numbers.{$definition->documentKey}.padding", 0),
            SettingService::DateFormatKey => SettingService::DefaultDateFormat,
            SettingService::DateTimeFormatKey => SettingService::DefaultDateTimeFormat,
        ]);

        return [
            'prefix' => (string) $values[$prefixKey],
            'padding' => max(0, (int) $values[$paddingKey]),
        ];
    }

    /**
     * @return array{old: array{prefix: string, padding: int}, new: array{prefix: string, padding: int}}
     */
    public function update(HrLookupDefinition $definition, ?string $prefix, int $padding): array
    {
        $old = $this->current($definition);
        $new = [
            'prefix' => trim((string) $prefix),
            'padding' => max(0, $padding),
        ];

        $this->settings->set($this->prefixKey($definition), $new['prefix']);
        $this->settings->set($this->paddingKey($definition), $new['padding']);

        return [
            'old' => $old,
            'new' => $new,
        ];
    }

    private function prefixKey(HrLookupDefinition $definition): string
    {
        return "document_numbers.{$definition->documentKey}.prefix";
    }

    private function paddingKey(HrLookupDefinition $definition): string
    {
        return "document_numbers.{$definition->documentKey}.padding";
    }
}

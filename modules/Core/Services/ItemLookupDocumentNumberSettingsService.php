<?php

namespace Modules\Core\Services;

class ItemLookupDocumentNumberSettingsService
{
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * @return array{prefix: string, padding: int}
     */
    public function current(ItemLookupDefinition $definition): array
    {
        $prefixKey = $this->prefixKey($definition);
        $paddingKey = $this->paddingKey($definition);
        $values = $this->settings->many([
            $prefixKey,
            $paddingKey,
        ], [
            $prefixKey => config("document_numbers.{$definition->documentKey}.prefix", ''),
            $paddingKey => config("document_numbers.{$definition->documentKey}.padding", 0),
        ]);

        return [
            'prefix' => (string) $values[$prefixKey],
            'padding' => max(0, (int) $values[$paddingKey]),
        ];
    }

    /**
     * @return array{old: array{prefix: string, padding: int}, new: array{prefix: string, padding: int}}
     */
    public function update(ItemLookupDefinition $definition, ?string $prefix, int $padding): array
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

    private function prefixKey(ItemLookupDefinition $definition): string
    {
        return "document_numbers.{$definition->documentKey}.prefix";
    }

    private function paddingKey(ItemLookupDefinition $definition): string
    {
        return "document_numbers.{$definition->documentKey}.padding";
    }
}

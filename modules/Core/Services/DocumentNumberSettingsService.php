<?php

namespace Modules\Core\Services;

class DocumentNumberSettingsService
{
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * @return array{prefix: string, padding: int}
     */
    public function current(string $key): array
    {
        $config = config("document_numbers.{$key}", []);

        return [
            'prefix' => (string) $this->settings->get("document_numbers.{$key}.prefix", $config['prefix'] ?? ''),
            'padding' => (int) $this->settings->get("document_numbers.{$key}.padding", $config['padding'] ?? 5),
        ];
    }

    /**
     * @return array{old: array{prefix: string, padding: int}, new: array{prefix: string, padding: int}}
     */
    public function update(string $key, ?string $prefix, int $padding): array
    {
        $old = $this->current($key);
        $new = ['prefix' => trim((string) $prefix), 'padding' => $padding];

        $this->settings->set("document_numbers.{$key}.prefix", $new['prefix']);
        $this->settings->set("document_numbers.{$key}.padding", $new['padding']);

        return ['old' => $old, 'new' => $new];
    }
}

<?php

namespace Modules\Core\Services;

class CurrencyDocumentNumberSettingsService
{
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    public function current(): array
    {
        $config = config('document_numbers.currencies', []);

        return [
            'prefix' => (string) $this->settings->get('document_numbers.currencies.prefix', $config['prefix'] ?? 'CUR-'),
            'padding' => (int) $this->settings->get('document_numbers.currencies.padding', $config['padding'] ?? 5),
        ];
    }

    public function update(?string $prefix, int $padding): array
    {
        $old = $this->current();
        $new = ['prefix' => trim((string) $prefix), 'padding' => $padding];

        $this->settings->set('document_numbers.currencies.prefix', $new['prefix']);
        $this->settings->set('document_numbers.currencies.padding', $new['padding']);

        return ['old' => $old, 'new' => $new];
    }
}

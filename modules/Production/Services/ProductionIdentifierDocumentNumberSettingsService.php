<?php

namespace Modules\Production\Services;

use Modules\Core\Services\SettingService;

class ProductionIdentifierDocumentNumberSettingsService
{
    public function __construct(private readonly SettingService $settings) {}

    public function current(): array
    {
        return [
            'prefix' => $this->settings->get('document_numbers.production_identifiers.prefix', config('document_numbers.production_identifiers.prefix', 'ID-')),
            'padding' => (int) $this->settings->get('document_numbers.production_identifiers.padding', config('document_numbers.production_identifiers.padding', 5)),
        ];
    }

    public function update(string $prefix, int $padding): array
    {
        $old = $this->current();
        $this->settings->set('document_numbers.production_identifiers.prefix', $prefix);
        $this->settings->set('document_numbers.production_identifiers.padding', $padding);

        return ['old' => $old, 'new' => $this->current()];
    }
}

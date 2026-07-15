<?php

namespace Modules\Accounting\Services;

use Modules\Core\Services\SettingService;

class AccountDocumentNumberSettingsService
{
    public function __construct(private readonly SettingService $settings) {}

    public function current(): array
    {
        return [
            'prefix' => $this->settings->get('document_numbers.accounts.prefix', config('document_numbers.accounts.prefix', 'ACC-')),
            'padding' => (int) $this->settings->get('document_numbers.accounts.padding', config('document_numbers.accounts.padding', 5)),
        ];
    }

    public function update(string $prefix, int $padding): array
    {
        $old = $this->current();
        $this->settings->set('document_numbers.accounts.prefix', $prefix);
        $this->settings->set('document_numbers.accounts.padding', $padding);

        return ['old' => $old, 'new' => $this->current()];
    }
}

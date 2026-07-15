<?php

namespace Modules\Accounting\Services;

use Modules\Core\Services\SettingService;

class CostCenterDocumentNumberSettingsService
{
    public function __construct(private readonly SettingService $settings) {}

    public function current(): array
    {
        return [
            'prefix' => $this->settings->get('document_numbers.cost_centers.prefix', config('document_numbers.cost_centers.prefix', 'CC-')),
            'padding' => (int) $this->settings->get('document_numbers.cost_centers.padding', config('document_numbers.cost_centers.padding', 5)),
        ];
    }

    public function update(string $prefix, int $padding): array
    {
        $old = $this->current();
        $this->settings->set('document_numbers.cost_centers.prefix', $prefix);
        $this->settings->set('document_numbers.cost_centers.padding', $padding);

        return ['old' => $old, 'new' => $this->current()];
    }
}

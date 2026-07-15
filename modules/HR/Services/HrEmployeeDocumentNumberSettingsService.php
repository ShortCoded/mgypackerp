<?php

namespace Modules\HR\Services;

use Modules\Core\Services\SettingService;

class HrEmployeeDocumentNumberSettingsService
{
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * @return array{prefix: string, padding: int}
     */
    public function current(): array
    {
        $prefixKey = 'document_numbers.hr_employees.prefix';
        $paddingKey = 'document_numbers.hr_employees.padding';
        $values = $this->settings->many([
            $prefixKey,
            $paddingKey,
        ], [
            $prefixKey => config('document_numbers.hr_employees.prefix', ''),
            $paddingKey => config('document_numbers.hr_employees.padding', 0),
        ]);

        return [
            'prefix' => (string) $values[$prefixKey],
            'padding' => max(0, (int) $values[$paddingKey]),
        ];
    }

    /**
     * @return array{old: array{prefix: string, padding: int}, new: array{prefix: string, padding: int}}
     */
    public function update(?string $prefix, int $padding): array
    {
        $old = $this->current();
        $new = [
            'prefix' => trim((string) $prefix),
            'padding' => max(0, $padding),
        ];

        $this->settings->set('document_numbers.hr_employees.prefix', $new['prefix']);
        $this->settings->set('document_numbers.hr_employees.padding', $new['padding']);

        return [
            'old' => $old,
            'new' => $new,
        ];
    }
}

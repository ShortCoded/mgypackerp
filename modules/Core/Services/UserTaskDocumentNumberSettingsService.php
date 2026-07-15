<?php

namespace Modules\Core\Services;

class UserTaskDocumentNumberSettingsService
{
    public const PrefixKey = 'document_numbers.user_tasks.prefix';

    public const PaddingKey = 'document_numbers.user_tasks.padding';

    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * @return array{prefix: string, padding: int}
     */
    public function current(): array
    {
        $values = $this->settings->many([
            self::PrefixKey,
            self::PaddingKey,
        ], [
            self::PrefixKey => config('document_numbers.user_tasks.prefix', ''),
            self::PaddingKey => config('document_numbers.user_tasks.padding', 0),
        ]);

        return [
            'prefix' => (string) $values[self::PrefixKey],
            'padding' => max(0, (int) $values[self::PaddingKey]),
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

        $this->settings->set(self::PrefixKey, $new['prefix']);
        $this->settings->set(self::PaddingKey, $new['padding']);

        return [
            'old' => $old,
            'new' => $new,
        ];
    }
}

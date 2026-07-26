<?php

namespace Modules\Core\Services;

use InvalidArgumentException;

class ProductDocumentNumberSettingsService
{
    public const ProductsKey = 'products';

    public const RawMaterialsKey = 'raw_materials';

    public const PackagingMaterialsKey = 'packaging_materials';

    /**
     * @var list<string>
     */
    private const SupportedKeys = [
        self::ProductsKey,
        self::RawMaterialsKey,
        self::PackagingMaterialsKey,
    ];

    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * @return array{prefix: string, padding: int}
     */
    public function current(string $key = self::ProductsKey): array
    {
        $config = $this->config($key);
        $prefixKey = $this->prefixKey($key);
        $paddingKey = $this->paddingKey($key);
        $values = $this->settings->many([
            $prefixKey,
            $paddingKey,
        ], [
            $prefixKey => $config['prefix'] ?? '',
            $paddingKey => $config['padding'] ?? 0,
        ]);

        return [
            'prefix' => (string) $values[$prefixKey],
            'padding' => max(0, (int) $values[$paddingKey]),
        ];
    }

    /**
     * @return array{old: array{prefix: string, padding: int}, new: array{prefix: string, padding: int}}
     */
    public function update(string $key, ?string $prefix, int $padding): array
    {
        $old = $this->current($key);
        $new = [
            'prefix' => trim((string) $prefix),
            'padding' => max(0, $padding),
        ];

        $this->settings->set($this->prefixKey($key), $new['prefix']);
        $this->settings->set($this->paddingKey($key), $new['padding']);

        return ['old' => $old, 'new' => $new];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $key): array
    {
        if (! in_array($key, self::SupportedKeys, true)) {
            throw new InvalidArgumentException("Unsupported product document number key [{$key}].");
        }

        $config = config("document_numbers.{$key}", []);

        return is_array($config) ? $config : [];
    }

    private function prefixKey(string $key): string
    {
        return "document_numbers.{$key}.prefix";
    }

    private function paddingKey(string $key): string
    {
        return "document_numbers.{$key}.padding";
    }
}

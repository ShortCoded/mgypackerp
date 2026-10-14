<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\NumericFormatService;

/** @implements CastsAttributes<string, string|int|float> */
class PurchaseUnitPriceCast implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        $numbers = app(NumericFormatService::class);
        $normalized = $numbers->normalize($value ?? 0) ?? '0';
        $fraction = explode('.', $normalized, 2)[1] ?? '';

        return $numbers->normalizeToScale($normalized, max(8, strlen($fraction))) ?? '0.00000000';
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return app(NumericFormatService::class)->normalizeToScale($value ?? 0, 15) ?? '0.000000000000000';
    }
}

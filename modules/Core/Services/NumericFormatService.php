<?php

namespace Modules\Core\Services;

use InvalidArgumentException;
use Stringable;

final class NumericFormatService
{
    private const DECIMAL_PATTERN = '/^-?(?:(?:\d+(?:\.\d*)?)|(?:\.\d+)|(?:[1-9]\d{0,2}(?:,\d{3})+(?:\.\d*)?))$/D';

    public function format(mixed $value): string
    {
        $normalized = $this->normalize($value);

        if ($normalized === null) {
            return '';
        }

        $negative = str_starts_with($normalized, '-');
        $unsigned = $negative ? substr($normalized, 1) : $normalized;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, null);
        $groupedInteger = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer) ?? $integer;
        $formatted = $fraction === null ? $groupedInteger : $groupedInteger.'.'.$fraction;

        return $negative ? '-'.$formatted : $formatted;
    }

    public function formatForInput(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) && trim($value) === '') {
            return '';
        }

        try {
            return $this->format($value);
        } catch (InvalidArgumentException) {
            return trim((string) $value);
        }
    }

    public function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $decimal = trim($this->decimalString($value));

        if ($decimal === '') {
            return null;
        }

        if (preg_match(self::DECIMAL_PATTERN, $decimal) !== 1) {
            throw new InvalidArgumentException("Invalid decimal value [{$decimal}].");
        }

        $negative = str_starts_with($decimal, '-');
        $unsigned = $negative ? substr($decimal, 1) : $decimal;
        $unsigned = str_replace(',', '', $unsigned);
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, null);

        if ($integer === '') {
            $integer = '0';
        }

        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $fraction === null ? null : rtrim($fraction, '0');
        $isZero = $integer === '0' && ($fraction === null || $fraction === '');
        $normalized = $fraction === null || $fraction === ''
            ? $integer
            : $integer.'.'.$fraction;

        return $negative && ! $isZero ? '-'.$normalized : $normalized;
    }

    public function normalizeForValidation(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return '';
        }

        try {
            return $this->normalize($value);
        } catch (InvalidArgumentException) {
            return $value;
        }
    }

    public function normalizeToScale(mixed $value, int $scale): ?string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Decimal scale cannot be negative.');
        }

        $normalized = $this->normalize($value);

        if ($normalized === null) {
            return null;
        }

        if ($this->decimalPlaces($normalized) > $scale) {
            throw new InvalidArgumentException("The decimal value exceeds the allowed scale of {$scale}.");
        }

        $negative = str_starts_with($normalized, '-');
        $unsigned = $negative ? substr($normalized, 1) : $normalized;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $scaled = $scale === 0
            ? $integer
            : $integer.'.'.str_pad($fraction, $scale, '0');

        return $negative ? '-'.$scaled : $scaled;
    }

    public function isValid(mixed $value): bool
    {
        try {
            $this->normalize($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function equivalent(mixed $left, mixed $right): bool
    {
        try {
            return $this->normalize($left) === $this->normalize($right);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function decimalPlaces(mixed $value): int
    {
        $normalized = $this->normalize($value);

        if ($normalized === null || ! str_contains($normalized, '.')) {
            return 0;
        }

        return strlen(substr(strrchr($normalized, '.'), 1));
    }

    public function excelNumberFormat(int $scale): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Excel number format scale cannot be negative.');
        }

        return $scale === 0 ? '#,##0' : '#,##0.'.str_repeat('#', $scale);
    }

    private function decimalString(mixed $value): string
    {
        if (is_string($value)) {
            return strtr($value, [
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٫' => '.', '٬' => ',',
            ]);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Non-finite numbers cannot be formatted.');
            }

            return $this->expandScientificNotation((string) $value);
        }

        throw new InvalidArgumentException('The value is not a supported decimal representation.');
    }

    private function expandScientificNotation(string $value): string
    {
        if (stripos($value, 'e') === false) {
            return $value;
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid scientific numeric value [{$value}].");
        }

        $sign = $matches[1];
        $integer = $matches[2];
        $fraction = $matches[3] ?? '';
        $exponent = (int) $matches[4];
        $digits = $integer.$fraction;
        $decimalPosition = strlen($integer) + $exponent;

        if ($decimalPosition <= 0) {
            return $sign.'0.'.str_repeat('0', -$decimalPosition).$digits;
        }

        if ($decimalPosition >= strlen($digits)) {
            return $sign.$digits.str_repeat('0', $decimalPosition - strlen($digits));
        }

        return $sign.substr($digits, 0, $decimalPosition).'.'.substr($digits, $decimalPosition);
    }
}

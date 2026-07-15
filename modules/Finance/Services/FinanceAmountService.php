<?php

namespace Modules\Finance\Services;

class FinanceAmountService
{
    public function normalize(mixed $value, int $scale = 4): string
    {
        $numeric = is_numeric($value) ? (float) $value : 0.0;

        return number_format($numeric, $scale, '.', '');
    }

    public function multiply(mixed $left, mixed $right, int $scale = 4): string
    {
        if (function_exists('bcmul')) {
            return bcmul((string) $left, (string) $right, $scale);
        }

        return number_format(((float) $left) * ((float) $right), $scale, '.', '');
    }

    public function divide(mixed $left, mixed $right, int $scale = 6): string
    {
        $right = (float) $right;

        if ($right == 0.0) {
            return $this->normalize(0, $scale);
        }

        if (function_exists('bcdiv')) {
            return bcdiv((string) $left, (string) $right, $scale);
        }

        return number_format(((float) $left) / $right, $scale, '.', '');
    }

    public function toUnits(mixed $value, int $scale = 4): int
    {
        $value = trim(str_replace(',', '', (string) $value));
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = preg_replace('/\D/', '', $whole) ?: '0';
        $fraction = substr(str_pad(preg_replace('/\D/', '', $fraction) ?: '', $scale, '0'), 0, $scale);
        $units = ((int) $whole * (10 ** $scale)) + (int) $fraction;

        return $negative ? -$units : $units;
    }

    public function fromUnits(int $units, int $scale = 4): string
    {
        $negative = $units < 0;
        $units = abs($units);
        $factor = 10 ** $scale;
        $whole = intdiv($units, $factor);
        $fraction = $units % $factor;
        $formatted = $whole.'.'.str_pad((string) $fraction, $scale, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').(rtrim(rtrim($formatted, '0'), '.') ?: '0');
    }
}

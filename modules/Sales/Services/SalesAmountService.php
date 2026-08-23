<?php

namespace Modules\Sales\Services;

use DomainException;

class SalesAmountService
{
    public function add(string|int|float $left, string|int|float $right, int $scale = 4): string
    {
        return bcadd((string) $left, (string) $right, $scale);
    }

    public function subtract(string|int|float $left, string|int|float $right, int $scale = 4): string
    {
        return bcsub((string) $left, (string) $right, $scale);
    }

    public function multiply(string|int|float $left, string|int|float $right, int $scale = 4): string
    {
        return bcmul((string) $left, (string) $right, $scale);
    }

    public function round(string|int|float $value, int $scale = 4): string
    {
        $increment = '0.'.str_repeat('0', $scale).'5';
        $adjusted = bccomp((string) $value, '0', $scale + 1) < 0
            ? bcsub((string) $value, $increment, $scale + 1)
            : bcadd((string) $value, $increment, $scale + 1);

        return bcadd($adjusted, '0', $scale);
    }

    public function compare(string|int|float $left, string|int|float $right, int $scale = 4): int
    {
        return bccomp((string) $left, (string) $right, $scale);
    }

    /** @param iterable<string|int|float> $values */
    public function sum(iterable $values, int $scale = 4): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = $this->add($total, $value, $scale);
        }

        return $total;
    }

    public function assertPositive(string|int|float $value, string $message, int $scale = 8): void
    {
        if ($this->compare($value, '0', $scale) <= 0) {
            throw new DomainException($message);
        }
    }

    public function assertNotGreaterThan(string|int|float $value, string|int|float $maximum, string $message, int $scale = 8): void
    {
        if ($this->compare($value, $maximum, $scale) > 0) {
            throw new DomainException($message);
        }
    }
}

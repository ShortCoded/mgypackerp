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

    public function unitPriceTotal(string|int|float $quantity, string|int|float $unitPrice, int $amountScale = 4): string
    {
        return $this->round($this->multiply($quantity, $unitPrice, 16), $amountScale);
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

    /** @param iterable<string|int|float|null> $values */
    public function sum(iterable $values, int $scale = 4): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = $this->add($total, $value ?? 0, $scale);
        }

        return $total;
    }

    public function assertPositive(string|int|float $value, string $message, int $scale = 8): void
    {
        if ($this->compare($value, '0', $scale) <= 0) {
            throw new DomainException($message);
        }
    }

    /** @param list<string> $weights @return list<string> */
    public function splitQuantityByWeights(string $quantity, array $weights, int $scale = 8): array
    {
        $total = $this->sum($weights, 8);
        $positiveKeys = array_keys(array_filter($weights, fn (string $weight): bool => bccomp($weight, '0', 8) > 0));
        if ($positiveKeys === [] || bccomp($quantity, '0', $scale) < 0 || count(array_filter($weights, fn (string $weight): bool => bccomp($weight, '0', 8) < 0)) > 0) {
            throw new DomainException(__('sales_issue.messages.quantity_allocation_mismatch'));
        }
        $last = $positiveKeys[array_key_last($positiveKeys)];
        $allocated = '0';
        $quantities = [];
        foreach ($weights as $index => $weight) {
            $slice = $index === $last
                ? bcsub($quantity, $allocated, $scale)
                : bcdiv(bcmul($quantity, $weight, 24), $total, $scale);
            $quantities[] = $slice;
            $allocated = bcadd($allocated, $slice, $scale);
        }

        return $quantities;
    }

    public function assertNotGreaterThan(string|int|float $value, string|int|float $maximum, string $message, int $scale = 8): void
    {
        if ($this->compare($value, $maximum, $scale) > 0) {
            throw new DomainException($message);
        }
    }
}

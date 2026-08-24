<?php

namespace Modules\Sales\Services;

use Modules\Core\Services\NumericFormatService;

class QuotationCalculationService
{
    public function __construct(
        private readonly NumericFormatService $numbers,
        private readonly SalesAmountService $amounts,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{revision: array{subtotal: string, discount_type: string|null, discount_value: string, discount_amount: string, tax_amount: string, total: string}, lines: list<array<string, mixed>>}
     */
    public function calculate(array $lines, ?string $discountType, mixed $discountValue): array
    {
        $calculatedLines = [];
        $subtotal = '0.0000';
        $lineDiscountTotal = '0.0000';
        $taxTotal = '0.0000';

        foreach ($lines as $line) {
            $quantity = $this->decimal($line['quantity'] ?? 0);
            $unitPrice = $this->decimal($line['unit_price'] ?? 0);
            $lineSubtotal = $this->amounts->multiply($quantity, $unitPrice);
            $lineDiscount = $this->discountAmount($lineSubtotal, $line['discount_type'] ?? null, $line['discount_value'] ?? 0);
            $taxBase = $this->amounts->subtract($lineSubtotal, $lineDiscount);
            $taxRate = $this->decimal($line['tax_rate'] ?? 0);
            $lineTax = $this->amounts->round($this->amounts->multiply($taxBase, bcdiv($taxRate, '100', 8), 8));
            $lineTotal = $this->amounts->add($taxBase, $lineTax);

            $subtotal = $this->amounts->add($subtotal, $lineSubtotal);
            $lineDiscountTotal = $this->amounts->add($lineDiscountTotal, $lineDiscount);
            $taxTotal = $this->amounts->add($taxTotal, $lineTax);

            $calculatedLines[] = [
                ...$line,
                'quantity' => $this->numbers->normalizeToScale($line['quantity'] ?? 0, 4) ?? '0.0000',
                'unit_price' => $this->numbers->normalizeToScale($line['unit_price'] ?? 0, 4) ?? '0.0000',
                'discount_value' => $this->numbers->normalizeToScale($line['discount_value'] ?? 0, 4) ?? '0.0000',
                'discount_amount' => $lineDiscount,
                'tax_rate' => $this->numbers->normalizeToScale($line['tax_rate'] ?? 0, 4) ?? '0.0000',
                'tax_amount' => $lineTax,
                'line_total' => $lineTotal,
            ];
        }

        $headerDiscount = $this->discountAmount($this->amounts->subtract($subtotal, $lineDiscountTotal), $discountType, $discountValue);
        $discountAmount = $this->amounts->add($lineDiscountTotal, $headerDiscount);
        $total = $this->amounts->add($this->amounts->subtract($subtotal, $discountAmount), $taxTotal);

        return [
            'revision' => [
                'subtotal' => $subtotal,
                'discount_type' => $discountType ?: null,
                'discount_value' => $this->numbers->normalizeToScale($discountValue ?? 0, 4) ?? '0.0000',
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxTotal,
                'total' => $total,
            ],
            'lines' => $calculatedLines,
        ];
    }

    private function discountAmount(string $base, mixed $type, mixed $value): string
    {
        $value = $this->decimal($value);
        if ($this->amounts->compare($value, '0') < 0) {
            $value = '0.0000';
        }

        $discount = match ($type) {
            'percentage' => $this->amounts->multiply($base, bcdiv(
                $this->amounts->compare($value, '100') > 0 ? '100' : $value,
                '100',
                8,
            ), 8),
            'fixed' => $value,
            default => '0.0000',
        };

        return $this->amounts->compare($discount, $base) > 0
            ? $base
            : $this->amounts->round($discount);
    }

    private function decimal(mixed $value): string
    {
        return $this->numbers->normalizeToScale(str_replace(',', '', (string) ($value ?? 0)), 4) ?? '0.0000';
    }
}

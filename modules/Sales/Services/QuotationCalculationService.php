<?php

namespace Modules\Sales\Services;

use Modules\Core\Services\NumericFormatService;

class QuotationCalculationService
{
    public function __construct(
        private readonly NumericFormatService $numbers,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{revision: array{subtotal: string, discount_type: string|null, discount_value: string, discount_amount: string, tax_amount: string, total: string}, lines: list<array<string, mixed>>}
     */
    public function calculate(array $lines, ?string $discountType, mixed $discountValue): array
    {
        $calculatedLines = [];
        $subtotal = 0.0;
        $lineDiscountTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($lines as $line) {
            $quantity = $this->number($line['quantity'] ?? 0);
            $unitPrice = $this->number($line['unit_price'] ?? 0);
            $lineSubtotal = $quantity * $unitPrice;
            $lineDiscount = $this->discountAmount($lineSubtotal, $line['discount_type'] ?? null, $line['discount_value'] ?? 0);
            $taxBase = max(0, $lineSubtotal - $lineDiscount);
            $lineTax = $taxBase * ($this->number($line['tax_rate'] ?? 0) / 100);
            $lineTotal = $taxBase + $lineTax;

            $subtotal += $lineSubtotal;
            $lineDiscountTotal += $lineDiscount;
            $taxTotal += $lineTax;

            $calculatedLines[] = [
                ...$line,
                'quantity' => $this->numbers->normalizeToScale($line['quantity'] ?? 0, 4) ?? '0.0000',
                'unit_price' => $this->numbers->normalizeToScale($line['unit_price'] ?? 0, 4) ?? '0.0000',
                'discount_value' => $this->numbers->normalizeToScale($line['discount_value'] ?? 0, 4) ?? '0.0000',
                'discount_amount' => $this->decimal($lineDiscount),
                'tax_rate' => $this->numbers->normalizeToScale($line['tax_rate'] ?? 0, 4) ?? '0.0000',
                'tax_amount' => $this->decimal($lineTax),
                'line_total' => $this->decimal($lineTotal),
            ];
        }

        $headerDiscount = $this->discountAmount(max(0, $subtotal - $lineDiscountTotal), $discountType, $discountValue);
        $discountAmount = $lineDiscountTotal + $headerDiscount;
        $total = max(0, $subtotal - $discountAmount + $taxTotal);

        return [
            'revision' => [
                'subtotal' => $this->decimal($subtotal),
                'discount_type' => $discountType ?: null,
                'discount_value' => $this->numbers->normalizeToScale($discountValue ?? 0, 4) ?? '0.0000',
                'discount_amount' => $this->decimal($discountAmount),
                'tax_amount' => $this->decimal($taxTotal),
                'total' => $this->decimal($total),
            ],
            'lines' => $calculatedLines,
        ];
    }

    private function discountAmount(float $base, mixed $type, mixed $value): float
    {
        $value = max(0, $this->number($value));

        return match ($type) {
            'percentage' => min($base, $base * min($value, 100) / 100),
            'fixed' => min($base, $value),
            default => 0.0,
        };
    }

    private function number(mixed $value): float
    {
        return (float) str_replace(',', '', (string) ($value ?? 0));
    }

    private function decimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}

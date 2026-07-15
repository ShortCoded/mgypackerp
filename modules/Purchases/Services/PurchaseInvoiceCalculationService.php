<?php

namespace Modules\Purchases\Services;

class PurchaseInvoiceCalculationService
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{invoice: array<string, string|null>, lines: list<array<string, mixed>>}
     */
    public function calculate(array $lines, ?string $headerDiscountType, mixed $headerDiscountValue): array
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
            $totalBeforeTax = max(0, $lineSubtotal - $lineDiscount);
            $taxRate = max(0, $this->number($line['tax_rate'] ?? 0));
            $lineTax = $totalBeforeTax * ($taxRate / 100);
            $totalAfterTax = $totalBeforeTax + $lineTax;

            $subtotal += $lineSubtotal;
            $lineDiscountTotal += $lineDiscount;
            $taxTotal += $lineTax;

            $calculatedLines[] = [
                ...$line,
                'quantity' => $this->decimal($quantity),
                'unit_price' => $this->decimal($unitPrice),
                'discount_value' => $this->decimal($this->number($line['discount_value'] ?? 0)),
                'discount_amount' => $this->decimal($lineDiscount),
                'tax_rate' => $this->decimal($taxRate),
                'tax_amount' => $this->decimal($lineTax),
                'subtotal_amount' => $this->decimal($lineSubtotal),
                'total_before_tax' => $this->decimal($totalBeforeTax),
                'total_after_tax' => $this->decimal($totalAfterTax),
            ];
        }

        $headerDiscountBase = max(0, $subtotal - $lineDiscountTotal);
        $headerDiscount = $this->discountAmount($headerDiscountBase, $headerDiscountType, $headerDiscountValue);
        $taxableAmount = max(0, $headerDiscountBase - $headerDiscount);
        $total = $taxableAmount + $taxTotal;

        return [
            'invoice' => [
                'header_discount_type' => $headerDiscountType ?: null,
                'header_discount_value' => $this->decimal($this->number($headerDiscountValue)),
                'header_discount_amount' => $this->decimal($headerDiscount),
                'subtotal_amount' => $this->decimal($subtotal),
                'line_discount_amount' => $this->decimal($lineDiscountTotal),
                'taxable_amount' => $this->decimal($taxableAmount),
                'tax_amount' => $this->decimal($taxTotal),
                'total_amount' => $this->decimal($total),
            ],
            'lines' => $calculatedLines,
        ];
    }

    public function discountAmount(float $base, mixed $type, mixed $value): float
    {
        $value = max(0, $this->number($value));

        return match ($type) {
            'percentage' => min($base, $base * min($value, 100) / 100),
            'fixed' => min($base, $value),
            default => 0.0,
        };
    }

    public function number(mixed $value): float
    {
        return (float) str_replace(',', '', (string) ($value ?? 0));
    }

    public function decimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    public function toUnits(mixed $value): int
    {
        $value = trim(str_replace(',', '', (string) $value));
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = preg_replace('/\D/', '', $whole) ?: '0';
        $fraction = substr(str_pad(preg_replace('/\D/', '', $fraction) ?: '', 4, '0'), 0, 4);
        $units = ((int) $whole * 10000) + (int) $fraction;

        return $negative ? -$units : $units;
    }

    public function fromUnits(int $units): string
    {
        $negative = $units < 0;
        $units = abs($units);
        $whole = intdiv($units, 10000);
        $fraction = $units % 10000;
        $formatted = $whole.'.'.str_pad((string) $fraction, 4, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').(rtrim(rtrim($formatted, '0'), '.') ?: '0');
    }
}

<?php

namespace Modules\Purchases\Services;

use Modules\Core\Services\NumericFormatService;

class PurchaseOrderCalculationService
{
    public function __construct(
        private readonly NumericFormatService $numbers,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{order: array<string, string>, lines: list<array<string, mixed>>}
     */
    public function calculate(array $lines, mixed $freightAmount = 0): array
    {
        $calculatedLines = [];
        $totalOrderedQuantity = 0.0;
        $totalReceivedQuantity = 0.0;
        $totalRemainingQuantity = 0.0;
        $subtotalAmount = 0.0;
        $totalAmount = 0.0;
        $freightAmount = max(0, $this->number($freightAmount));

        foreach (array_values($lines) as $line) {
            $orderedQuantityInput = $line['ordered_quantity'] ?? 0;
            $receivedQuantityInput = $line['received_quantity'] ?? 0;
            $unitPriceInput = $line['unit_price'] ?? 0;
            $orderedQuantity = $this->number($orderedQuantityInput);
            $receivedQuantity = max(0, $this->number($receivedQuantityInput));
            $remainingQuantity = max(0, $orderedQuantity - $receivedQuantity);
            $unitPrice = $this->number($unitPriceInput);
            $subtotal = $orderedQuantity * $unitPrice;
            $discountType = in_array($line['discount_type'] ?? null, ['fixed', 'percentage'], true)
                ? $line['discount_type']
                : 'fixed';
            $discountValue = max(0, $this->number($line['discount_value'] ?? 0));
            $discountAmount = $discountType === 'percentage'
                ? $subtotal * min(100, $discountValue) / 100
                : min($subtotal, $discountValue);
            $totalBeforeTax = max(0, $subtotal - $discountAmount);
            $taxRate = min(100, max(0, $this->number($line['tax_rate'] ?? 0)));
            $taxAmount = $totalBeforeTax * $taxRate / 100;
            $lineTotal = $totalBeforeTax + $taxAmount;

            $calculatedLines[] = [
                ...$line,
                'ordered_quantity' => $this->formatQuantity($orderedQuantityInput),
                'received_quantity' => $receivedQuantity > 0
                    ? $this->formatQuantity($receivedQuantityInput)
                    : $this->formatQuantity(0),
                'remaining_quantity' => $this->decimalQuantity($remainingQuantity),
                'unit_price' => $this->formatAmount($unitPriceInput),
                'discount_type' => $discountType,
                'discount_value' => $this->formatAmount($discountValue),
                'discount_amount' => $this->decimalAmount($discountAmount),
                'tax_rate' => $this->formatAmount($taxRate),
                'tax_amount' => $this->decimalAmount($taxAmount),
                'subtotal_amount' => $this->decimalAmount($subtotal),
                'total_before_tax' => $this->decimalAmount($totalBeforeTax),
                'total_after_tax' => $this->decimalAmount($lineTotal),
                'line_total' => $this->decimalAmount($lineTotal),
            ];

            $totalOrderedQuantity += $orderedQuantity;
            $totalReceivedQuantity += $receivedQuantity;
            $totalRemainingQuantity += $remainingQuantity;
            $subtotalAmount += $subtotal;
            $totalAmount += $lineTotal;
        }

        return [
            'order' => [
                'total_ordered_quantity' => $this->decimalQuantity($totalOrderedQuantity),
                'total_received_quantity' => $this->decimalQuantity($totalReceivedQuantity),
                'total_remaining_quantity' => $this->decimalQuantity($totalRemainingQuantity),
                'subtotal_amount' => $this->decimalAmount($subtotalAmount),
                'freight_amount' => $this->formatAmount($freightAmount),
                'total_amount' => $this->decimalAmount($totalAmount + $freightAmount),
            ],
            'lines' => $calculatedLines,
        ];
    }

    public function number(mixed $value): float
    {
        $value = trim(str_replace(',', '', (string) ($value ?? '')));

        return is_numeric($value) ? (float) $value : 0.0;
    }

    public function formatQuantity(mixed $value): string
    {
        return $this->numbers->normalizeToScale($value ?? 0, 8) ?? '0.00000000';
    }

    public function formatAmount(mixed $value): string
    {
        return $this->numbers->normalizeToScale($value ?? 0, 4) ?? '0.0000';
    }

    private function decimalQuantity(float $value): string
    {
        return number_format($value, 8, '.', '');
    }

    private function decimalAmount(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}

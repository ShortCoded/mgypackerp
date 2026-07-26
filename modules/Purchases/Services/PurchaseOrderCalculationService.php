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
    public function calculate(array $lines): array
    {
        $calculatedLines = [];
        $totalOrderedQuantity = 0.0;
        $totalReceivedQuantity = 0.0;
        $totalRemainingQuantity = 0.0;
        $subtotalAmount = 0.0;

        foreach (array_values($lines) as $line) {
            $orderedQuantityInput = $line['ordered_quantity'] ?? 0;
            $receivedQuantityInput = $line['received_quantity'] ?? 0;
            $unitPriceInput = $line['unit_price'] ?? 0;
            $orderedQuantity = $this->number($orderedQuantityInput);
            $receivedQuantity = max(0, $this->number($receivedQuantityInput));
            $remainingQuantity = max(0, $orderedQuantity - $receivedQuantity);
            $unitPrice = $this->number($unitPriceInput);
            $lineTotal = $orderedQuantity * $unitPrice;

            $calculatedLines[] = [
                ...$line,
                'ordered_quantity' => $this->formatQuantity($orderedQuantityInput),
                'received_quantity' => $receivedQuantity > 0
                    ? $this->formatQuantity($receivedQuantityInput)
                    : $this->formatQuantity(0),
                'remaining_quantity' => $this->decimalQuantity($remainingQuantity),
                'unit_price' => $this->formatAmount($unitPriceInput),
                'line_total' => $this->decimalAmount($lineTotal),
            ];

            $totalOrderedQuantity += $orderedQuantity;
            $totalReceivedQuantity += $receivedQuantity;
            $totalRemainingQuantity += $remainingQuantity;
            $subtotalAmount += $lineTotal;
        }

        return [
            'order' => [
                'total_ordered_quantity' => $this->decimalQuantity($totalOrderedQuantity),
                'total_received_quantity' => $this->decimalQuantity($totalReceivedQuantity),
                'total_remaining_quantity' => $this->decimalQuantity($totalRemainingQuantity),
                'subtotal_amount' => $this->decimalAmount($subtotalAmount),
                'total_amount' => $this->decimalAmount($subtotalAmount),
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

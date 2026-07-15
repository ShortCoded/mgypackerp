<?php

namespace Modules\Purchases\Services;

class PurchaseOrderCalculationService
{
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
            $orderedQuantity = $this->number($line['ordered_quantity'] ?? 0);
            $receivedQuantity = max(0, $this->number($line['received_quantity'] ?? 0));
            $remainingQuantity = max(0, $orderedQuantity - $receivedQuantity);
            $unitPrice = $this->number($line['unit_price'] ?? 0);
            $lineTotal = $orderedQuantity * $unitPrice;

            $calculatedLines[] = [
                ...$line,
                'ordered_quantity' => $this->formatQuantity($orderedQuantity),
                'received_quantity' => $this->formatQuantity($receivedQuantity),
                'remaining_quantity' => $this->formatQuantity($remainingQuantity),
                'unit_price' => $this->formatAmount($unitPrice),
                'line_total' => $this->formatAmount($lineTotal),
            ];

            $totalOrderedQuantity += $orderedQuantity;
            $totalReceivedQuantity += $receivedQuantity;
            $totalRemainingQuantity += $remainingQuantity;
            $subtotalAmount += $lineTotal;
        }

        return [
            'order' => [
                'total_ordered_quantity' => $this->formatQuantity($totalOrderedQuantity),
                'total_received_quantity' => $this->formatQuantity($totalReceivedQuantity),
                'total_remaining_quantity' => $this->formatQuantity($totalRemainingQuantity),
                'subtotal_amount' => $this->formatAmount($subtotalAmount),
                'total_amount' => $this->formatAmount($subtotalAmount),
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
        return number_format($this->number($value), 8, '.', '');
    }

    public function formatAmount(mixed $value): string
    {
        return number_format($this->number($value), 4, '.', '');
    }
}

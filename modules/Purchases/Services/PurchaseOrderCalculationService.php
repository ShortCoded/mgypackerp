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
    public function calculate(array $lines, mixed $freightAmount = 0, ?string $headerDiscountType = null, mixed $headerDiscountValue = 0, array $bookedAllocations = []): array
    {
        $calculatedLines = [];
        $totalOrderedQuantity = '0.00000000';
        $totalReceivedQuantity = '0.00000000';
        $totalRemainingQuantity = '0.00000000';
        $subtotalAmount = '0.0000';
        $totalAmount = '0.0000';
        $freightAmount = $this->nonNegative($this->formatAmount($freightAmount));

        foreach (array_values($lines) as $line) {
            $orderedQuantityInput = $line['ordered_quantity'] ?? 0;
            $receivedQuantityInput = $line['received_quantity'] ?? 0;
            $unitPriceInput = $line['unit_price'] ?? 0;
            $orderedQuantity = $this->formatQuantity($orderedQuantityInput);
            $receivedQuantity = $this->nonNegative($this->formatQuantity($receivedQuantityInput), 8);
            $remainingQuantity = $this->nonNegative(bcsub($orderedQuantity, $receivedQuantity, 8), 8);
            $unitPrice = $this->formatUnitPrice($unitPriceInput);
            $subtotal = bcround(bcmul($orderedQuantity, $unitPrice, 16), 4);
            $discountType = in_array($line['discount_type'] ?? null, ['fixed', 'percentage'], true)
                ? $line['discount_type']
                : 'fixed';
            $discountValue = $this->nonNegative($this->formatAmount($line['discount_value'] ?? 0));
            $discountAmount = $discountType === 'percentage'
                ? bcround(bcdiv(bcmul($subtotal, $this->boundedRate($discountValue), 12), '100', 12), 4)
                : (bccomp($discountValue, $subtotal, 4) > 0 ? $subtotal : $discountValue);
            $totalBeforeTax = $this->nonNegative(bcsub($subtotal, $discountAmount, 4));
            $taxRate = $this->boundedRate($this->formatAmount($line['tax_rate'] ?? 0));
            $taxAmount = bcround(bcdiv(bcmul($totalBeforeTax, $taxRate, 12), '100', 12), 4);
            $lineTotal = bcadd($totalBeforeTax, $taxAmount, 4);

            $calculatedLines[] = [
                ...$line,
                'ordered_quantity' => $orderedQuantity,
                'received_quantity' => $receivedQuantity,
                'remaining_quantity' => $remainingQuantity,
                'unit_price' => $unitPrice,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'subtotal_amount' => $subtotal,
                'total_before_tax' => $totalBeforeTax,
                'total_after_tax' => $lineTotal,
                'line_total' => $lineTotal,
            ];

            $totalOrderedQuantity = bcadd($totalOrderedQuantity, $orderedQuantity, 8);
            $totalReceivedQuantity = bcadd($totalReceivedQuantity, $receivedQuantity, 8);
            $totalRemainingQuantity = bcadd($totalRemainingQuantity, $remainingQuantity, 8);
            $subtotalAmount = bcadd($subtotalAmount, $subtotal, 4);
            $totalAmount = bcadd($totalAmount, $lineTotal, 4);
        }

        $headerBase = bcsub($subtotalAmount, array_reduce($calculatedLines, fn (string $sum, array $line): string => bcadd($sum, $line['discount_amount'], 4), '0.0000'), 4);
        if ($bookedAllocations !== []) {
            $headerBase = array_reduce($bookedAllocations, fn (string $sum, array $row): string => bcadd($sum, bcsub($row['gross'], $row['discount'], 4), 4), '0.0000');
        }
        $headerValue = $this->numbers->normalize($headerDiscountValue ?? 0);
        if (! in_array($headerDiscountType, [null, 'fixed', 'percentage'], true) || ! is_string($headerValue)
            || ! preg_match('/^\d{1,14}(?:\.\d{1,4})?$/D', $headerValue)
            || ($headerDiscountType === null && bccomp($headerValue, '0', 4) > 0)
            || ($headerDiscountType === 'percentage' && bccomp($headerValue, '100', 4) > 0)
            || ($headerDiscountType === 'fixed' && bccomp($headerValue, $headerBase, 4) > 0)) {
            throw new \DomainException(__('purchase_orders.header_discount_invalid'));
        }

        $commercial = app(PurchaseInvoiceCalculationService::class)->calculate(array_map(fn (array $line): array => [...$line, 'quantity' => $line['ordered_quantity']], $calculatedLines), $headerDiscountType, $headerDiscountValue, $freightAmount, 0, $bookedAllocations);
        $calculatedLines = array_map(fn (array $line): array => [...$line, 'line_total' => $line['total_after_tax']], $commercial['lines']);

        return [
            'order' => [
                'total_ordered_quantity' => $totalOrderedQuantity,
                'total_received_quantity' => $totalReceivedQuantity,
                'total_remaining_quantity' => $totalRemainingQuantity,
                'subtotal_amount' => $commercial['invoice']['subtotal_amount'],
                'freight_amount' => $freightAmount,
                'header_discount_type' => $commercial['invoice']['header_discount_type'],
                'header_discount_value' => $commercial['invoice']['header_discount_value'],
                'header_discount_amount' => $commercial['invoice']['header_discount_amount'],
                'total_amount' => $commercial['invoice']['total_amount'],
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

    public function formatUnitPrice(mixed $value): string
    {
        $this->numbers->normalizeToScale($value ?? 0, 8);

        return $this->numbers->normalize($value ?? 0) ?? '0';
    }

    private function nonNegative(string $value, int $scale = 4): string
    {
        return bccomp($value, '0', $scale) < 0 ? $this->numbers->normalizeToScale(0, $scale) : $value;
    }

    private function boundedRate(string $rate): string
    {
        return bccomp($rate, '100', 4) > 0 ? '100.0000' : $rate;
    }
}

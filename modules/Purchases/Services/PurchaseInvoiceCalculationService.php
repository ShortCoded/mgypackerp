<?php

namespace Modules\Purchases\Services;

use Modules\Core\Services\NumericFormatService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;

class PurchaseInvoiceCalculationService
{
    private const AmountScale = 4;

    private const QuantityScale = 8;

    private const IntermediateScale = 12;

    public function __construct(
        private readonly NumericFormatService $numbers,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{invoice: array<string, string|null>, lines: list<array<string, mixed>>}
     */
    public function calculate(
        array $lines,
        ?string $headerDiscountType,
        mixed $headerDiscountValue,
        mixed $freightAmount = 0,
        mixed $freightTaxRate = 0,
    ): array {
        $calculatedLines = [];
        $subtotal = $this->zero();
        $lineDiscountTotal = $this->zero();

        foreach ($lines as $line) {
            $quantity = $this->normalize($line['quantity'] ?? 0, self::QuantityScale);
            $unitPrice = $this->normalize($line['unit_price'] ?? 0);
            $lineSubtotal = $this->round(bcmul($quantity, $unitPrice, self::IntermediateScale));
            $lineDiscount = $this->discountAmount($lineSubtotal, $line['discount_type'] ?? null, $line['discount_value'] ?? 0);
            $totalBeforeTax = $this->nonNegative(bcsub($lineSubtotal, $lineDiscount, self::AmountScale));
            $taxRate = $this->nonNegative($this->normalize($line['tax_rate'] ?? 0));
            $lineTax = $this->percentageOf($totalBeforeTax, $taxRate);
            $totalAfterTax = bcadd($totalBeforeTax, $lineTax, self::AmountScale);

            $subtotal = bcadd($subtotal, $lineSubtotal, self::AmountScale);
            $lineDiscountTotal = bcadd($lineDiscountTotal, $lineDiscount, self::AmountScale);

            $calculatedLines[] = [
                ...$line,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_value' => $this->normalize($line['discount_value'] ?? 0),
                'discount_amount' => $lineDiscount,
                'tax_rate' => $taxRate,
                'tax_amount' => $lineTax,
                'subtotal_amount' => $lineSubtotal,
                'total_before_tax' => $totalBeforeTax,
                'total_after_tax' => $totalAfterTax,
            ];
        }

        $headerDiscountBase = $this->nonNegative(bcsub($subtotal, $lineDiscountTotal, self::AmountScale));
        $headerDiscount = $this->discountAmount($headerDiscountBase, $headerDiscountType, $headerDiscountValue);
        $itemTaxableAmount = $this->nonNegative(bcsub($headerDiscountBase, $headerDiscount, self::AmountScale));
        $taxTotal = $this->zero();
        $allocatedHeaderDiscount = $this->zero();
        $lastLineIndex = max(0, count($calculatedLines) - 1);

        foreach ($calculatedLines as $index => &$line) {
            $lineBase = $this->normalize($line['total_before_tax']);
            $headerDiscountShare = $this->allocationShare(
                $headerDiscount,
                $allocatedHeaderDiscount,
                $lineBase,
                $headerDiscountBase,
                $index === $lastLineIndex,
            );
            $allocatedHeaderDiscount = bcadd($allocatedHeaderDiscount, $headerDiscountShare, self::AmountScale);
            $lineTaxableAmount = $this->nonNegative(bcsub($lineBase, $headerDiscountShare, self::AmountScale));
            $lineTax = $this->percentageOf($lineTaxableAmount, $this->nonNegative($line['tax_rate']));
            $line['tax_amount'] = $lineTax;
            $line['total_after_tax'] = bcadd($lineTaxableAmount, $lineTax, self::AmountScale);
            $taxTotal = bcadd($taxTotal, $lineTax, self::AmountScale);
        }
        unset($line);

        $freightAmount = $this->nonNegative($this->normalize($freightAmount));
        $freightTaxRate = $this->boundedPercentage($freightTaxRate);
        $freightTaxAmount = $this->percentageOf($freightAmount, $freightTaxRate);
        $taxableAmount = bcadd($itemTaxableAmount, $freightAmount, self::AmountScale);
        $taxTotal = bcadd($taxTotal, $freightTaxAmount, self::AmountScale);
        $total = bcadd($taxableAmount, $taxTotal, self::AmountScale);

        return [
            'invoice' => [
                'header_discount_type' => $headerDiscountType ?: null,
                'header_discount_value' => $this->normalize($headerDiscountValue ?? 0),
                'header_discount_amount' => $headerDiscount,
                'subtotal_amount' => $subtotal,
                'line_discount_amount' => $lineDiscountTotal,
                'freight_amount' => $freightAmount,
                'freight_tax_rate' => $freightTaxRate,
                'freight_tax_amount' => $freightTaxAmount,
                'taxable_amount' => $taxableAmount,
                'tax_amount' => $taxTotal,
                'total_amount' => $total,
            ],
            'lines' => $calculatedLines,
        ];
    }

    public function discountAmount(string|int|float $base, mixed $type, mixed $value): string
    {
        $base = $this->nonNegative($this->normalize($base));
        $value = $this->nonNegative($this->normalize($value));

        $discount = match ($type) {
            'percentage' => $this->percentageOf($base, $this->boundedPercentage($value)),
            'fixed' => $value,
            default => $this->zero(),
        };

        return bccomp($discount, $base, self::AmountScale) > 0 ? $base : $discount;
    }

    public function number(mixed $value): string
    {
        return $this->normalize($value);
    }

    public function decimal(mixed $value): string
    {
        return $this->round($this->normalize($value, self::IntermediateScale));
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

    /** @return array<int, string> */
    public function netAmountsByLine(PurchaseInvoice $invoice): array
    {
        $invoice->loadMissing('lines');
        $lineBaseTotal = $invoice->lines->reduce(
            fn (string $total, PurchaseInvoiceLine $line): string => bcadd(
                $total,
                $this->normalize($line->total_before_tax),
                self::AmountScale,
            ),
            $this->zero(),
        );
        $headerDiscount = $this->nonNegative($this->normalize($invoice->header_discount_amount));
        $allocatedHeaderDiscount = $this->zero();
        $lastIndex = max(0, $invoice->lines->count() - 1);
        $amounts = [];

        foreach ($invoice->lines->values() as $index => $line) {
            $lineBase = $this->normalize($line->total_before_tax);
            $share = $this->allocationShare(
                $headerDiscount,
                $allocatedHeaderDiscount,
                $lineBase,
                $lineBaseTotal,
                $index === $lastIndex,
            );
            $allocatedHeaderDiscount = bcadd($allocatedHeaderDiscount, $share, self::AmountScale);
            $amounts[$line->getKey()] = $this->nonNegative(bcsub($lineBase, $share, self::AmountScale));
        }

        return $amounts;
    }

    private function normalize(mixed $value, int $scale = self::AmountScale): string
    {
        return $this->numbers->normalizeToScale($value ?? 0, $scale)
            ?? $this->numbers->normalizeToScale(0, $scale);
    }

    private function zero(int $scale = self::AmountScale): string
    {
        return $this->normalize(0, $scale);
    }

    private function nonNegative(string $value): string
    {
        return bccomp($value, '0', self::AmountScale) < 0 ? $this->zero() : $value;
    }

    private function boundedPercentage(mixed $value): string
    {
        $percentage = $this->nonNegative($this->normalize($value));

        return bccomp($percentage, '100', self::AmountScale) > 0
            ? $this->normalize(100)
            : $percentage;
    }

    private function percentageOf(string $base, string $rate): string
    {
        return $this->round(bcdiv(
            bcmul($base, $rate, self::IntermediateScale),
            '100',
            self::IntermediateScale,
        ));
    }

    private function allocationShare(
        string $total,
        string $allocated,
        string $lineBase,
        string $baseTotal,
        bool $isLast,
    ): string {
        $remaining = $this->nonNegative(bcsub($total, $allocated, self::AmountScale));

        if ($isLast) {
            return $remaining;
        }

        if (bccomp($baseTotal, '0', self::AmountScale) <= 0) {
            return $this->zero();
        }

        $share = $this->round(bcdiv(
            bcmul($total, $lineBase, self::IntermediateScale),
            $baseTotal,
            self::IntermediateScale,
        ));

        return bccomp($share, $remaining, self::AmountScale) > 0 ? $remaining : $share;
    }

    private function round(string $value, int $scale = self::AmountScale): string
    {
        $increment = '0.'.str_repeat('0', $scale).'5';
        $adjusted = bccomp($value, '0', $scale + 1) < 0
            ? bcsub($value, $increment, $scale + 1)
            : bcadd($value, $increment, $scale + 1);

        return bcadd($adjusted, '0', $scale);
    }
}

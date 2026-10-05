<?php

namespace Modules\Sales\Services;

use DomainException;

class SalesOrderDiscountService
{
    public function __construct(private readonly SalesAmountService $amounts) {}

    public function amount(string $base, ?string $type, mixed $value): string
    {
        if (! is_scalar($value) || ! preg_match('/^\d{1,16}(?:\.\d{1,4})?$/D', (string) $value)
            || ! in_array($type, [null, 'fixed', 'percentage'], true)
            || ($type === null && bccomp((string) $value, '0', 4) > 0)
            || ($type === 'percentage' && bccomp((string) $value, '100', 4) > 0)) {
            throw new DomainException(__('sales_ui.discount_invalid'));
        }
        $discount = match ($type) {
            'percentage' => $this->amounts->round(bcdiv(bcmul($base, (string) $value, 16), '100', 12)),
            'fixed' => bcadd((string) $value, '0', 4),
            default => '0.0000',
        };
        if (bccomp($discount, $base, 4) > 0) {
            throw new DomainException(__('sales_ui.discount_exceeds_base'));
        }

        return $discount;
    }

    /** @param array<string, mixed> $line */
    public function lineAmount(array $line, ?string $unitPrice = null): string
    {
        $base = $this->amounts->unitPriceTotal($line['quantity'], $unitPrice ?? $line['unit_price']);
        if (filled($line['discount_type'] ?? null)) {
            return $this->amount($base, $line['discount_type'], $line['discount_value'] ?? '0');
        }
        $this->amount($base, null, $line['discount_value'] ?? '0');

        return $this->amount($base, 'fixed', $line['discount_amount'] ?? '0');
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>, discount_type: ?string, discount_value: ?string, header_discount_amount: string}
     */
    public function calculate(array $lines, ?string $type, mixed $value): array
    {
        $bases = [];
        foreach ($lines as &$line) {
            $ownDiscount = $this->lineAmount($line);
            $bases[] = $this->amounts->subtract($this->amounts->unitPriceTotal($line['quantity'], $line['unit_price']), $ownDiscount);
            $line['discount_amount'] = $ownDiscount;
            $line['discount_type'] = $line['discount_type'] ?? null;
            $line['discount_value'] = $line['discount_type'] ? bcadd((string) ($line['discount_value'] ?? 0), '0', 4) : null;
        }
        unset($line);
        $headerDiscount = $this->amount($this->amounts->sum($bases), $type, $value ?? '0');
        $shares = bccomp($headerDiscount, '0', 4) === 0
            ? array_fill(0, count($lines), '0.0000')
            : $this->amounts->splitQuantityByWeights($headerDiscount, $bases, 4);
        foreach ($lines as $index => &$line) {
            $line['header_discount_amount'] = $shares[$index];
            $line['discount_amount'] = $this->amounts->add($line['discount_amount'], $shares[$index]);
            $line['line_total'] = $this->amounts->add($this->amounts->subtract($this->amounts->unitPriceTotal($line['quantity'], $line['unit_price']), $line['discount_amount']), $line['tax_amount'] ?? '0');
            if (($type !== null || $line['discount_type'] !== null) && ! empty($line['price_list_line_id'])) {
                app(PriceListPricingService::class)->assertOrderDiscountWithinSnapshot($line);
            }
        }
        unset($line);

        return ['lines' => $lines, 'discount_type' => $type, 'discount_value' => $type ? bcadd((string) ($value ?? 0), '0', 4) : null, 'header_discount_amount' => $headerDiscount];
    }
}

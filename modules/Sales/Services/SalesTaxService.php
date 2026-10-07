<?php

namespace Modules\Sales\Services;

use DomainException;

class SalesTaxService
{
    public const Rate = 'rate';

    public const SourceAllocation = 'source_allocation';

    public const LegacyAmount = 'legacy_amount';

    public function __construct(private readonly SalesAmountService $amounts) {}

    public function rate(mixed $value): string
    {
        if (! is_scalar($value) || ! preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D', (string) $value)
            || bccomp((string) $value, '100', 4) > 0) {
            throw new DomainException(__('sales_ui.tax_rate_invalid'));
        }

        return bcadd((string) $value, '0', 4);
    }

    public function amount(string $taxableAmount, mixed $rate): string
    {
        $rate = $this->rate($rate);
        if (bccomp($taxableAmount, '0', 4) < 0) {
            throw new DomainException(__('sales_ui.discount_exceeds_base'));
        }

        return $this->amounts->round(bcdiv(bcmul($taxableAmount, $rate, 16), '100', 12));
    }

    /** @param array<string, mixed> $line @return array{tax_rate: ?string, tax_calculation_basis: string, tax_amount: string} */
    public function calculate(array $line, string $taxableAmount): array
    {
        $rate = filled($line['tax_rate'] ?? null) ? $this->rate($line['tax_rate']) : null;
        $basis = $line['tax_calculation_basis'] ?? ($rate !== null ? self::Rate : self::LegacyAmount);
        if (! in_array($basis, [self::Rate, self::SourceAllocation, self::LegacyAmount], true)
            || ($basis === self::Rate && $rate === null)) {
            throw new DomainException(__('sales_ui.tax_rate_invalid'));
        }

        return ['tax_rate' => $rate, 'tax_calculation_basis' => $basis,
            'tax_amount' => $basis === self::Rate ? $this->amount($taxableAmount, $rate) : $this->amounts->round((string) ($line['tax_amount'] ?? '0'))];
    }
}

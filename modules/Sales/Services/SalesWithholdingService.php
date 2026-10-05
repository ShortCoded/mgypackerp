<?php

namespace Modules\Sales\Services;

use DomainException;

class SalesWithholdingService
{
    public const GrossIncludingTax = 'gross_including_tax';

    public const EtaNetExcludingTax = 'eta_t4_net_excluding_tax';

    public function __construct(private readonly SalesAmountService $amounts) {}

    /** @return array{withholding_basis: string, withholding_rate: string, withholding_basis_amount: string, withholding_amount: string, net_payable_amount: string} */
    public function calculate(string $grossIncludingTax, mixed $rate = '0', ?string $basis = null, ?string $netExcludingTax = null): array
    {
        $basis ??= self::GrossIncludingTax;
        $basisAmount = $basis === self::EtaNetExcludingTax ? $netExcludingTax : $grossIncludingTax;
        $rate ??= '0';
        if (! in_array($basis, [self::GrossIncludingTax, self::EtaNetExcludingTax], true) || $basisAmount === null
            || ! is_scalar($rate) || ! preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D', (string) $rate) || bccomp((string) $rate, '100', 4) > 0
            || bccomp($grossIncludingTax, '0', 4) < 0 || bccomp($basisAmount, '0', 4) < 0 || bccomp($basisAmount, $grossIncludingTax, 4) > 0) {
            throw new DomainException(__('sales_ui.withholding_invalid'));
        }
        $amount = $this->amounts->round(bcdiv(bcmul($basisAmount, (string) $rate, 16), '100', 12));

        return ['withholding_basis' => $basis, 'withholding_rate' => bcadd((string) $rate, '0', 4),
            'withholding_basis_amount' => bcadd($basisAmount, '0', 4), 'withholding_amount' => $amount,
            'net_payable_amount' => $this->amounts->subtract($grossIncludingTax, $amount)];
    }
}

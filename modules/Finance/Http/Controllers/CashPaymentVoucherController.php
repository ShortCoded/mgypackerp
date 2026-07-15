<?php

namespace Modules\Finance\Http\Controllers;

use Modules\Finance\Models\CashVoucher;

class CashPaymentVoucherController extends AbstractCashVoucherController
{
    protected function voucherType(): string
    {
        return CashVoucher::TypePayment;
    }

    protected function permissionPrefix(): string
    {
        return 'cash_payment_vouchers';
    }

    protected function routePrefix(): string
    {
        return 'admin.finance.cash-payment-vouchers';
    }

    protected function translationKey(): string
    {
        return 'cash_payment_vouchers';
    }
}

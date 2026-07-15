<?php

namespace Modules\Finance\Http\Controllers;

use Modules\Finance\Models\CashVoucher;

class CashReceiptVoucherController extends AbstractCashVoucherController
{
    protected function voucherType(): string
    {
        return CashVoucher::TypeReceipt;
    }

    protected function permissionPrefix(): string
    {
        return 'cash_receipt_vouchers';
    }

    protected function routePrefix(): string
    {
        return 'admin.finance.cash-receipt-vouchers';
    }

    protected function translationKey(): string
    {
        return 'cash_receipt_vouchers';
    }
}

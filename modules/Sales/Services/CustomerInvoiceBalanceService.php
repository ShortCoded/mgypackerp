<?php

namespace Modules\Sales\Services;

use DomainException;
use Modules\Sales\Models\CustomerInvoice;

final class CustomerInvoiceBalanceService
{
    public function remaining(CustomerInvoice $invoice, ?string $paid = null, ?string $credited = null): string
    {
        return bcsub(bcsub(bcsub((string) $invoice->total_amount, $paid ?? (string) $invoice->paid_amount, 4),
            $credited ?? (string) $invoice->credited_amount, 4), (string) ($invoice->actual_withholding_amount ?? '0'), 4);
    }

    public function refresh(CustomerInvoice $invoice): void
    {
        $paid = bcadd((string) $invoice->paymentSchedules()->sum('collected_amount'), '0', 4);
        $remaining = $this->remaining($invoice, $paid);
        if (bccomp($remaining, '0', 4) < 0) {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }
        $invoice->update(['paid_amount' => $paid, 'remaining_amount' => $remaining]);
    }

    public function assertNoActiveWithholding(CustomerInvoice $invoice): void
    {
        if (bccomp((string) ($invoice->actual_withholding_amount ?? '0'), '0', 4) !== 0
            || $invoice->withholdingSettlements()->where('status', 'approved')->exists()) {
            throw new DomainException(__('sales_ui.wht.recovery_required'));
        }
    }
}

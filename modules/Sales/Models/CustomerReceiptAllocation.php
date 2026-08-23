<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReceiptAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:4', 'applied_at' => 'datetime'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function invoiceSchedule(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoicePaymentSchedule::class, 'customer_invoice_payment_schedule_id');
    }

    public function orderSchedule(): BelongsTo
    {
        return $this->belongsTo(SalesOrderPaymentSchedule::class, 'sales_order_payment_schedule_id');
    }
}

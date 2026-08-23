<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupplierPaymentAllocation extends Model
{
    protected $fillable = [
        'public_id', 'supplier_payment_context_id', 'purchase_invoice_id', 'payment_schedule_id',
        'amount', 'allocated_by', 'allocated_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $allocation): void {
            $allocation->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'allocated_at' => 'datetime'];
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SupplierPaymentContext::class, 'supplier_payment_context_id');
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function paymentSchedule(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoicePaymentSchedule::class, 'payment_schedule_id');
    }
}

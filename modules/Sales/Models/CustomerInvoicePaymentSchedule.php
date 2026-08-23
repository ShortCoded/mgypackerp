<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CustomerInvoicePaymentSchedule extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $schedule) => $schedule->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'decimal:4', 'collected_amount' => 'decimal:4', 'credited_amount' => 'decimal:4'];
    }

    protected function outstandingAmount(): Attribute
    {
        return Attribute::get(function (): string {
            $outstanding = bcsub(bcsub((string) $this->amount, (string) $this->collected_amount, 4), (string) $this->credited_amount, 4);

            return bccomp($outstanding, '0', 4) < 0 ? '0.0000' : $outstanding;
        });
    }

    protected function paymentStatus(): Attribute
    {
        return Attribute::get(function (): string {
            if (bccomp($this->outstanding_amount, '0', 4) <= 0) {
                return 'collected';
            }
            if (bccomp(bcadd((string) $this->collected_amount, (string) $this->credited_amount, 4), '0', 4) > 0) {
                return 'partially_collected';
            }

            return $this->due_date?->isPast() ? 'overdue' : 'pending';
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerReceiptAllocation::class);
    }
}

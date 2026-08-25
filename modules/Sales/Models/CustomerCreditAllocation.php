<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCreditAllocation extends Model
{
    public const StatusApplied = 'applied';

    public const StatusReversed = 'reversed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allocation_date' => 'date', 'amount' => 'decimal:4', 'applied_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'credit_note_id');
    }

    public function targetInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'target_invoice_id');
    }

    public function targetPaymentSchedule(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoicePaymentSchedule::class, 'target_payment_schedule_id');
    }
}

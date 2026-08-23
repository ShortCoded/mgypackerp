<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderPaymentSchedule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['percentage' => 'decimal:4', 'amount' => 'decimal:4', 'collected_amount' => 'decimal:4', 'remaining_amount' => 'decimal:4', 'due_date' => 'date'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerReceiptAllocation::class);
    }
}

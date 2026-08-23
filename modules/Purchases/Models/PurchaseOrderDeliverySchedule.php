<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PurchaseOrderDeliverySchedule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id', 'purchase_order_id', 'purchase_order_line_id', 'company_id', 'financial_period_id',
        'sequence', 'scheduled_date', 'scheduled_quantity', 'received_quantity', 'status', 'notes',
        'created_by', 'updated_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $schedule): void {
            $schedule->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date', 'scheduled_quantity' => 'decimal:8', 'received_quantity' => 'decimal:8',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }
}

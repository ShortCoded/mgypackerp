<?php

namespace Modules\Sales\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderRemainderClosureLine extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $closure = SalesOrderRemainderClosure::query()->find($line->sales_order_remainder_closure_id);
            if (! $closure instanceof SalesOrderRemainderClosure
                || $closure->status !== SalesOrderRemainderClosure::StatusApplying) {
                throw new DomainException(__('sales_ui.remainder.messages.line_immutable'));
            }
        });
        static::updating(fn () => throw new DomainException(__('sales_ui.remainder.messages.line_immutable')));
        static::deleting(fn () => throw new DomainException(__('sales_ui.remainder.messages.line_not_deletable')));
    }

    protected function casts(): array
    {
        return [
            'declined_quantity' => 'decimal:8', 'declined_base_quantity' => 'decimal:8',
            'delivered_quantity_snapshot' => 'decimal:8', 'delivered_base_quantity_snapshot' => 'decimal:8',
            'released_reservation_quantity' => 'decimal:8', 'released_reservation_base_quantity' => 'decimal:8',
            'released_production_quantity' => 'decimal:8', 'released_production_base_quantity' => 'decimal:8',
            'credited_remainder_quantity' => 'decimal:8', 'credited_remainder_base_quantity' => 'decimal:8',
        ];
    }

    public function closure(): BelongsTo
    {
        return $this->belongsTo(SalesOrderRemainderClosure::class, 'sales_order_remainder_closure_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }
}

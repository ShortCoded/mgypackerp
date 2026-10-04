<?php

namespace Modules\Inventory\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReceiptCostBasis extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $basis): void {
            if ($basis->isDirty(['inventory_value_adjustment_id', 'inventory_receipt_layer_id', 'original_total_cost', 'completed_total_cost'])) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_immutable'));
            }
        });
        static::deleting(fn (): never => throw new DomainException(__('inventory.movements.messages.receipt_completion_immutable')));
    }

    protected function casts(): array
    {
        return ['original_total_cost' => 'decimal:8', 'completed_total_cost' => 'decimal:8',
            'remaining_quantity' => 'decimal:8', 'remaining_value' => 'decimal:8'];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryValueAdjustment::class, 'inventory_value_adjustment_id');
    }
}

<?php

namespace Modules\Inventory\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAllocationCostCompletion extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new DomainException(__('inventory.movements.messages.receipt_completion_immutable')));
        static::deleting(fn (): never => throw new DomainException(__('inventory.movements.messages.receipt_completion_immutable')));
    }

    protected function casts(): array
    {
        return ['original_total_cost' => 'decimal:8', 'completed_total_cost' => 'decimal:8'];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryValueAdjustment::class, 'inventory_value_adjustment_id');
    }
}

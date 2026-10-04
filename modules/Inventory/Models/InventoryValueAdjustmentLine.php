<?php

namespace Modules\Inventory\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryValueAdjustmentLine extends Model
{
    public const EffectStock = 'stock';

    public const EffectExpense = 'expense';

    public const EffectWip = 'wip';

    public const EffectCounterpart = 'counterpart';

    public const EffectGlPrecision = 'gl_precision';

    public const EffectStandardVariance = 'standard_variance';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new DomainException(__('inventory.movements.messages.receipt_completion_immutable')));
        static::deleting(fn (): never => throw new DomainException(__('inventory.movements.messages.receipt_completion_immutable')));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:8', 'unvalued_quantity_delta' => 'decimal:8', 'production_cost_delta' => 'decimal:8', 'source_snapshot' => 'array'];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryValueAdjustment::class, 'inventory_value_adjustment_id');
    }

    public function sourceTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'source_transaction_id');
    }

    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class);
    }
}

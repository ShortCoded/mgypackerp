<?php

namespace Modules\Inventory\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCostPolicyTransitionBasis extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'original_quantity' => 'decimal:8',
            'original_book_value' => 'decimal:8',
            'basis_unit_cost' => 'decimal:8',
            'remaining_quantity' => 'decimal:8',
            'remaining_book_value' => 'decimal:8',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $basis): void {
            $immutable = [
                'inventory_cost_policy_transition_id', 'inventory_receipt_layer_id',
                'branch_store_id', 'product_id', 'warehouse_location_id', 'production_run_id',
                'stock_status', 'batch_lot', 'original_quantity', 'original_book_value', 'basis_unit_cost',
            ];
            if ($basis->isDirty($immutable)) {
                throw new DomainException(__('inventory_cost_policy.transition_errors.immutable'));
            }
        });
        static::deleting(fn (): never => throw new DomainException(__('inventory_cost_policy.transition_errors.immutable')));
    }

    public function transition(): BelongsTo
    {
        return $this->belongsTo(InventoryCostPolicyTransition::class, 'inventory_cost_policy_transition_id');
    }

    public function receiptLayer(): BelongsTo
    {
        return $this->belongsTo(InventoryReceiptLayer::class, 'inventory_receipt_layer_id');
    }
}

<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLayerAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8',
            'cost_unit_snapshot' => 'decimal:8',
            'cost_total_snapshot' => 'decimal:8',
        ];
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryReceiptLayer::class, 'inventory_receipt_layer_id');
    }

    public function issueTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'issue_transaction_id');
    }

    public function transitionBasis(): BelongsTo
    {
        return $this->belongsTo(InventoryCostPolicyTransitionBasis::class, 'inventory_cost_policy_transition_basis_id');
    }

    public function costCompletions(): HasMany
    {
        return $this->hasMany(InventoryAllocationCostCompletion::class, 'inventory_layer_allocation_id');
    }

    public function completedTotalCost(): ?string
    {
        $completion = InventoryAllocationCostCompletion::query()->where('inventory_layer_allocation_id', $this->id)
            ->whereHas('adjustment', fn ($query) => $query->where('status', InventoryValueAdjustment::StatusPosted))
            ->latest('id')->first();

        return $completion?->completed_total_cost ?? $this->cost_total_snapshot;
    }
}

<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;

class InventoryReceiptLayer extends Model
{
    protected $guarded = ['id'];

    public function serialIdentity(): BelongsTo
    {
        return $this->belongsTo(InventorySerialIdentity::class, 'inventory_serial_identity_id');
    }

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date', 'original_receipt_date' => 'date',
            'manufacture_date' => 'date', 'expiry_date' => 'date',
            'original_quantity' => 'decimal:8', 'remaining_quantity' => 'decimal:8',
            'unit_cost' => 'decimal:8',
            'source_allocation_cost_snapshot' => 'decimal:8',
        ];
    }

    public function receiptTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'receipt_transaction_id');
    }

    public function sourceAllocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLayerAllocation::class, 'source_allocation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class);
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryLayerAllocation::class);
    }

    public function transitionBases(): HasMany
    {
        return $this->hasMany(InventoryCostPolicyTransitionBasis::class);
    }

    public function scopeWithBookCostBasis(Builder $query, ?int $policyId): Builder
    {
        return $query->with(['transitionBases' => fn ($bases) => $bases
            ->with('transition')->whereHas('transition', fn (Builder $transition): Builder => $transition
            ->where('status', InventoryCostPolicyTransition::StatusActivated)
            ->where('inventory_cost_policy_id', $policyId ?? 0))]);
    }

    public function bookUnitCostForPolicy(?int $policyId): ?string
    {
        $completion = $this->activeCostCompletion($policyId);
        if ($completion && bccomp((string) $completion->remaining_quantity, '0', 8) > 0) {
            return bcdiv((string) $completion->remaining_value, (string) $completion->remaining_quantity, 8);
        }
        if ($policyId === null) {
            return $this->unit_cost;
        }
        $bases = $this->relationLoaded('transitionBases') ? $this->transitionBases
            : $this->transitionBases()->with('transition')->get();
        $basis = $bases->first(fn (InventoryCostPolicyTransitionBasis $basis): bool => $basis->transition->status === InventoryCostPolicyTransition::StatusActivated
            && (int) $basis->transition->inventory_cost_policy_id === $policyId
            && bccomp((string) $basis->remaining_quantity, '0', 8) > 0);

        return $basis?->basis_unit_cost ?? $this->unit_cost;
    }

    public function scopeWithAuthoritativeCost(Builder $query): Builder
    {
        return $query->where(fn (Builder $priced) => $priced->whereNotNull('unit_cost')->orWhereHas('costCompletionBases',
            fn (Builder $bases) => $bases->whereHas('adjustment', fn ($adjustment) => $adjustment->where('status', InventoryValueAdjustment::StatusPosted))));
    }

    public function costCompletionBases(): HasMany
    {
        return $this->hasMany(InventoryReceiptCostBasis::class);
    }

    public function activeCostCompletion(?int $policyId = null): ?InventoryReceiptCostBasis
    {
        $basis = InventoryReceiptCostBasis::query()->with('adjustment')->where('inventory_receipt_layer_id', $this->id)
            ->whereHas('adjustment', fn ($query) => $query->where('status', InventoryValueAdjustment::StatusPosted))
            ->latest('id')->first();
        if ($basis !== null && $policyId !== null) {
            $transition = InventoryCostPolicyTransition::query()->where('inventory_cost_policy_id', $policyId)
                ->where('status', InventoryCostPolicyTransition::StatusActivated)->first();
            if ($transition && $transition->effective_from->gt($basis->adjustment->posting_date)) {
                return null;
            }
        }

        return $basis;
    }
}

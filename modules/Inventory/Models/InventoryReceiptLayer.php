<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;

class InventoryReceiptLayer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date', 'original_receipt_date' => 'date',
            'manufacture_date' => 'date', 'expiry_date' => 'date',
            'original_quantity' => 'decimal:8', 'remaining_quantity' => 'decimal:8',
            'unit_cost' => 'decimal:8',
        ];
    }

    public function receiptTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'receipt_transaction_id');
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
}

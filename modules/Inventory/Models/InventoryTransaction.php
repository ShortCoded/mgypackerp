<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Product;

class InventoryTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'quantity_in' => 'decimal:8', 'quantity_out' => 'decimal:8', 'unit_cost' => 'decimal:8', 'total_cost' => 'decimal:8', 'is_reversal' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class);
    }
}

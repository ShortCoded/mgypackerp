<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLayerAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:8'];
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryReceiptLayer::class, 'inventory_receipt_layer_id');
    }

    public function issueTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'issue_transaction_id');
    }
}

<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventorySerialIdentity extends Model
{
    protected $guarded = ['id'];

    public function currentReceiptLayer(): BelongsTo
    {
        return $this->belongsTo(InventoryReceiptLayer::class, 'current_receipt_layer_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $identity): void {
            if ($identity->isDirty(['company_id', 'product_id', 'serial_number', 'normalized_serial'])) {
                throw new \DomainException(__('inventory_serial.identity_immutable'));
            }
        });
        static::deleting(fn () => throw new \DomainException(__('inventory_serial.identity_immutable')));
    }
}

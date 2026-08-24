<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Production\Models\ProductionRun;

class InventoryDocumentLine extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $line) => $line->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['reference_quantity' => 'decimal:8', 'previous_quantity' => 'decimal:8', 'quantity' => 'decimal:8', 'base_quantity' => 'decimal:8', 'transaction_quantity' => 'decimal:8', 'conversion_factor' => 'decimal:8', 'rejected_quantity' => 'decimal:8', 'unit_cost' => 'decimal:8', 'total_cost' => 'decimal:8', 'product_snapshot' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'inventory_document_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }

    public function transactionUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'transaction_unit_id')->withTrashed();
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function destinationWarehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'destination_warehouse_location_id');
    }

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id');
    }
}

<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

class InventoryReservation extends Model
{
    public const StatusActive = 'active';

    public const StatusConsumed = 'consumed';

    public const StatusReleased = 'released';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $reservation) => $reservation->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:8', 'transaction_quantity' => 'decimal:8', 'conversion_factor' => 'decimal:8', 'consumed_quantity' => 'decimal:8', 'released_quantity' => 'decimal:8', 'released_at' => 'datetime'];
    }

    protected function remainingQuantity(): Attribute
    {
        return Attribute::get(function (): string {
            $remaining = bcsub(bcsub((string) $this->quantity, (string) $this->consumed_quantity, 8), (string) $this->released_quantity, 8);

            return bccomp($remaining, '0', 8) < 0 ? '0.00000000' : $remaining;
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    public function productionMaterialRequirement(): BelongsTo
    {
        return $this->belongsTo(ProductionMaterialRequirement::class);
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function transactionUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'transaction_unit_id')->withTrashed();
    }
}

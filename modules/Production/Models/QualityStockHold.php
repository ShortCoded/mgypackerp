<?php

namespace Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\WarehouseLocation;

class QualityStockHold extends Model
{
    public const StatusActive = 'active';

    public const StatusReleased = 'released';

    public const StatusDispositioned = 'dispositioned';

    protected $guarded = ['id'];

    protected $attributes = [
        'held_stock_status' => 'qc_hold',
        'status' => self::StatusActive,
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $hold) => $hold->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'base_quantity' => 'decimal:8',
            'activated_at' => 'datetime',
            'released_at' => 'datetime',
            'dispositioned_at' => 'datetime',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ProductionQualityInspection::class, 'quality_inspection_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class)->withTrashed();
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class)->withTrashed();
    }

    public function holdInventoryDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'hold_inventory_document_id');
    }

    public function dispositionInventoryDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'disposition_inventory_document_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}

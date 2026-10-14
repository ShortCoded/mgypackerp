<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryReservation;

class ProductionMaterialRequestLine extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $line) => $line->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'planned_quantity' => 'decimal:8',
            'requested_quantity' => 'decimal:8',
            'original_requested_quantity' => 'decimal:8',
            'material_conversion_factor' => 'decimal:8',
            'approved_quantity' => 'decimal:8',
            'reserved_quantity' => 'decimal:8',
            'issued_quantity' => 'decimal:8',
            'reallocated_quantity' => 'decimal:8',
            'reallocated_original_quantity' => 'decimal:8',
            'reallocated_reserved_quantity' => 'decimal:8',
            'shortage_quantity' => 'decimal:8',
        ];
    }

    public function remainingReservedQuantity(): string
    {
        $remaining = bcsub(
            bcsub((string) $this->reserved_quantity, (string) $this->issued_quantity, 8),
            (string) $this->reallocated_reserved_quantity,
            8,
        );

        return bccomp($remaining, '0', 8) > 0 ? $remaining : '0.00000000';
    }

    public function reallocatableQuantity(): string
    {
        $remaining = bcsub(
            bcsub((string) $this->approved_quantity, (string) $this->issued_quantity, 8),
            (string) $this->reallocated_quantity,
            8,
        );

        return bccomp($remaining, '0', 8) > 0 ? $remaining : '0.00000000';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ProductionMaterialRequest::class, 'production_material_request_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ProductionMaterialRequirement::class, 'production_material_requirement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function originalProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'original_product_id')->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }

    public function originalUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'original_unit_id')->withTrashed();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class, 'production_material_request_line_id');
    }
}

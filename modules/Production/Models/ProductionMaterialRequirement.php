<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;

class ProductionMaterialRequirement extends Model
{
    protected $fillable = [
        'public_id', 'production_order_id', 'production_order_line_id', 'production_run_id',
        'allocation_root_requirement_id', 'line_number', 'product_component_id', 'original_product_id', 'product_id', 'original_unit_id',
        'unit_id', 'calculation_method', 'component_quantity_snapshot', 'original_planned_quantity',
        'material_conversion_factor', 'planned_quantity', 'reserved_quantity', 'issued_quantity',
        'additional_issued_quantity', 'returned_quantity', 'consumed_quantity', 'waste_quantity',
        'component_snapshot',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $requirement) => $requirement->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'component_quantity_snapshot' => 'decimal:8', 'planned_quantity' => 'decimal:8',
            'original_planned_quantity' => 'decimal:8', 'material_conversion_factor' => 'decimal:8',
            'reserved_quantity' => 'decimal:8', 'issued_quantity' => 'decimal:8',
            'additional_issued_quantity' => 'decimal:8', 'returned_quantity' => 'decimal:8',
            'consumed_quantity' => 'decimal:8', 'waste_quantity' => 'decimal:8',
            'component_snapshot' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(ProductComponent::class, 'product_component_id')->withTrashed();
    }

    public function allocationRoot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'allocation_root_requirement_id');
    }

    public function materialAllocations(): HasMany
    {
        return $this->hasMany(self::class, 'allocation_root_requirement_id');
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

    public function conversionFactor(): string
    {
        $factor = (string) ($this->material_conversion_factor ?? '1');

        return bccomp($factor, '0', 8) > 0 ? $factor : '1.00000000';
    }

    public function originalPlannedQuantity(): string
    {
        if ($this->original_planned_quantity !== null) {
            return (string) $this->original_planned_quantity;
        }

        return bcdiv((string) $this->planned_quantity, $this->conversionFactor(), 8);
    }

    public function toOriginalQuantity(string $actualQuantity): string
    {
        return bcdiv($actualQuantity, $this->conversionFactor(), 8);
    }

    public function toActualQuantity(string $originalQuantity): string
    {
        return bcmul($originalQuantity, $this->conversionFactor(), 8);
    }
}

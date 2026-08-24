<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;

class ProductionMaterialRequirement extends Model
{
    protected $fillable = [
        'public_id', 'production_order_id', 'production_order_line_id', 'production_run_id',
        'line_number', 'product_component_id', 'product_id', 'unit_id', 'calculation_method',
        'component_quantity_snapshot', 'planned_quantity', 'reserved_quantity', 'issued_quantity',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }
}

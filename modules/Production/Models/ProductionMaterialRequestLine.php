<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;

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
            'approved_quantity' => 'decimal:8',
            'reserved_quantity' => 'decimal:8',
            'issued_quantity' => 'decimal:8',
            'shortage_quantity' => 'decimal:8',
        ];
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

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }
}

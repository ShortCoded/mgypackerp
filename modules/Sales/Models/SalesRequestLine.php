<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;

class SalesRequestLine extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $line) => $line->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:8', 'converted_quantity' => 'decimal:8', 'base_quantity' => 'decimal:8', 'conversion_factor' => 'decimal:8', 'unit_price' => 'decimal:4', 'specifications' => 'array'];
    }

    public function remainingQuantity(): string
    {
        return bcsub((string) $this->quantity, (string) $this->converted_quantity, 8);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SalesRequest::class, 'sales_request_id');
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

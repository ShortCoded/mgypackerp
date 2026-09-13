<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Sales\Models\SalesOrderLine;

class ProductionOrderLine extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $line) => $line->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:8', 'conversion_factor' => 'decimal:8', 'base_quantity' => 'decimal:8', 'received_base_quantity' => 'decimal:8', 'specifications' => 'array', 'bom_snapshot' => 'array', 'mandatory_specs_resolved' => 'boolean'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ProductionRun::class, 'production_order_line_id')->orderBy('planned_start_at');
    }

    public function stageSnapshots(): HasMany
    {
        return $this->hasMany(ProductionOrderStageSnapshot::class, 'production_order_line_id')->orderBy('sequence');
    }
}

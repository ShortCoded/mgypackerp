<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;

class StockCountLine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'inventory_stock_count_id', 'line_number', 'product_id', 'unit_id', 'stock_status',
        'batch_lot', 'system_quantity', 'physical_quantity', 'variance_quantity',
        'variance_reason', 'notes',
        'created_by', 'updated_by', 'deleted_with_document', 'deleted_by',
    ];

    protected $table = 'inventory_stock_count_lines';

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:8', 'physical_quantity' => 'decimal:8',
            'variance_quantity' => 'decimal:8',
            'deleted_with_document' => 'boolean',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'inventory_stock_count_id');
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

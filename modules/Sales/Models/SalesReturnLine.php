<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocumentLine;

class SalesReturnLine extends Model
{
    public const DispositionSaleable = 'saleable';

    public const DispositionQuarantine = 'quarantine';

    public const DispositionRework = 'rework';

    public const DispositionScrap = 'scrap';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $line) => $line->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8', 'conversion_factor' => 'decimal:8', 'base_quantity' => 'decimal:8', 'unit_price' => 'decimal:4', 'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4', 'is_service' => 'boolean', 'saleable_quantity' => 'decimal:8',
            'saleable_base_quantity' => 'decimal:8',
            'quarantine_quantity' => 'decimal:8', 'rework_quantity' => 'decimal:8',
            'quarantine_base_quantity' => 'decimal:8', 'rework_base_quantity' => 'decimal:8',
            'scrap_quantity' => 'decimal:8', 'scrap_base_quantity' => 'decimal:8', 'original_unit_cost' => 'decimal:8', 'source_snapshot' => 'array',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoiceLine::class, 'customer_invoice_line_id');
    }

    public function deliveryLine(): BelongsTo
    {
        return $this->belongsTo(InventoryDocumentLine::class, 'delivery_line_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
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

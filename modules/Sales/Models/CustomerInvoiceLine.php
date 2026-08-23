<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocumentLine;

class CustomerInvoiceLine extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $line) => $line->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:8', 'conversion_factor' => 'decimal:8', 'base_quantity' => 'decimal:8', 'unit_price' => 'decimal:4', 'discount_amount' => 'decimal:4', 'tax_amount' => 'decimal:4', 'line_total' => 'decimal:4', 'unit_cost' => 'decimal:8', 'is_service' => 'boolean', 'source_snapshot' => 'array'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function deliveryLine(): BelongsTo
    {
        return $this->belongsTo(InventoryDocumentLine::class, 'delivery_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }

    public function returnLines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class);
    }
}

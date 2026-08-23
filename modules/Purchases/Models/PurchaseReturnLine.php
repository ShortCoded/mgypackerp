<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;

class PurchaseReturnLine extends Model
{
    protected $fillable = [
        'public_id', 'purchase_return_id', 'purchase_order_line_id', 'receipt_line_id', 'purchase_invoice_line_id',
        'product_id', 'unit_id', 'quantity', 'from_quarantine', 'unit_price', 'tax_amount', 'line_total', 'reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8', 'from_quarantine' => 'boolean', 'unit_price' => 'decimal:4',
            'tax_amount' => 'decimal:4', 'line_total' => 'decimal:4',
        ];
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(UnpricedInventoryReceiptLine::class, 'receipt_line_id');
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

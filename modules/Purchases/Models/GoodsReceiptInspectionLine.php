<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;

class GoodsReceiptInspectionLine extends Model
{
    protected $fillable = [
        'public_id', 'goods_receipt_inspection_id', 'receipt_line_id', 'product_id', 'inspected_quantity',
        'accepted_quantity', 'rejected_quantity', 'result', 'disposition', 'reason', 'measurements',
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
            'inspected_quantity' => 'decimal:8', 'accepted_quantity' => 'decimal:8',
            'rejected_quantity' => 'decimal:8', 'measurements' => 'array',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptInspection::class, 'goods_receipt_inspection_id');
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(UnpricedInventoryReceiptLine::class, 'receipt_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}

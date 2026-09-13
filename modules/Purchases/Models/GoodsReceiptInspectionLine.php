<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;

class GoodsReceiptInspectionLine extends Model
{
    protected $fillable = [
        'public_id', 'goods_receipt_inspection_id', 'receipt_line_id', 'product_id', 'inspected_quantity',
        'purchase_order_line_id', 'supply_order_line_id', 'unit_id', 'supplier_lot_number',
        'delivery_schedule_id',
        'manufacture_date', 'expiry_date', 'notes',
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
            'manufacture_date' => 'date', 'expiry_date' => 'date',
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

    public function receiptLines(): HasMany
    {
        return $this->hasMany(UnpricedInventoryReceiptLine::class, 'goods_receipt_inspection_line_id');
    }

    public function receivedQuantity(?int $exceptReceiptId = null): float
    {
        if ($exceptReceiptId === null && $this->relationLoaded('receiptLines')) {
            return (float) $this->receiptLines
                ->filter(fn (UnpricedInventoryReceiptLine $line): bool => $line->receipt !== null
                    && ! in_array($line->receipt->status, ['cancelled', 'reversed'], true))
                ->sum('delivered_quantity');
        }

        return (float) $this->receiptLines()
            ->when($exceptReceiptId !== null, fn ($query) => $query->where('receipt_id', '<>', $exceptReceiptId))
            ->whereHas('receipt', fn ($query) => $query->whereNotIn('status', ['cancelled', 'reversed']))
            ->sum('delivered_quantity');
    }

    public function remainingReceiptQuantity(?int $exceptReceiptId = null): float
    {
        return max(0, (float) $this->accepted_quantity - $this->receivedQuantity($exceptReceiptId));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function supplyOrderLine(): BelongsTo
    {
        return $this->belongsTo(SupplyOrderLine::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }

    public function deliverySchedule(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDeliverySchedule::class, 'delivery_schedule_id');
    }
}

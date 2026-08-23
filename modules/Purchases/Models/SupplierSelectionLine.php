<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;

class SupplierSelectionLine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id', 'supplier_selection_id', 'supplier_quotation_line_id', 'purchase_requisition_line_id',
        'supplier_id', 'product_id', 'unit_id', 'selected_quantity', 'unit_price', 'discount_amount',
        'tax_rate', 'tax_amount', 'line_total',
        'purchase_order_id', 'reason',
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
            'selected_quantity' => 'decimal:8',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(SupplierSelection::class, 'supplier_selection_id');
    }

    public function quotationLine(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotationLine::class, 'supplier_quotation_line_id');
    }

    public function requisitionLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionLine::class, 'purchase_requisition_line_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}

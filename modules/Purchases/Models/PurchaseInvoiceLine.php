<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;

class PurchaseInvoiceLine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'purchase_invoice_id',
        'company_id',
        'financial_period_id',
        'line_number',
        'product_id',
        'unit_id',
        'purchase_order_line_id',
        'receipt_line_id',
        'matched_quantity',
        'quantity',
        'unit_price',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'subtotal_amount',
        'total_before_tax',
        'total_after_tax',
        'product_snapshot',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseInvoiceLine $line): void {
            if (! is_string($line->public_id) || trim($line->public_id) === '') {
                $line->public_id = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'matched_quantity' => 'decimal:8',
            'unit_price' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'subtotal_amount' => 'decimal:4',
            'total_before_tax' => 'decimal:4',
            'total_after_tax' => 'decimal:4',
            'product_snapshot' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(UnpricedInventoryReceiptLine::class, 'receipt_line_id');
    }
}

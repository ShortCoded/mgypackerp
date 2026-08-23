<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;

class PurchaseOrderLine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'purchase_order_id',
        'company_id',
        'financial_period_id',
        'line_number',
        'product_id',
        'unit_id',
        'purchase_requisition_line_id',
        'request_for_quotation_line_id',
        'supplier_quotation_line_id',
        'supplier_selection_line_id',
        'ordered_quantity',
        'received_quantity',
        'remaining_quantity',
        'unit_price',
        'line_total',
        'description',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'subtotal_amount',
        'total_before_tax',
        'total_after_tax',
        'required_delivery_date',
        'specification',
        'product_snapshot',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrderLine $line): void {
            if (! is_string($line->public_id) || trim($line->public_id) === '') {
                $line->public_id = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:8',
            'received_quantity' => 'decimal:8',
            'remaining_quantity' => 'decimal:8',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'subtotal_amount' => 'decimal:4',
            'total_before_tax' => 'decimal:4',
            'total_after_tax' => 'decimal:4',
            'required_delivery_date' => 'date',
            'product_snapshot' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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

    public function requisitionLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionLine::class, 'purchase_requisition_line_id');
    }

    public function rfqLine(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotationLine::class, 'request_for_quotation_line_id');
    }

    public function quotationLine(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotationLine::class, 'supplier_quotation_line_id');
    }

    public function selectionLine(): BelongsTo
    {
        return $this->belongsTo(SupplierSelectionLine::class, 'supplier_selection_line_id');
    }

    public function deliverySchedules(): HasMany
    {
        return $this->hasMany(PurchaseOrderDeliverySchedule::class);
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(UnpricedInventoryReceiptLine::class);
    }
}

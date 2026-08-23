<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;

class SupplierQuotationLine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id', 'supplier_quotation_id', 'request_for_quotation_line_id', 'line_number', 'product_id',
        'unit_id', 'offered_quantity', 'unit_price', 'discount_amount', 'tax_rate', 'tax_amount',
        'line_total', 'delivery_date', 'notes',
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
            'offered_quantity' => 'decimal:8', 'unit_price' => 'decimal:4', 'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:4', 'line_total' => 'decimal:4', 'delivery_date' => 'date',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotation::class, 'supplier_quotation_id');
    }

    public function rfqLine(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotationLine::class, 'request_for_quotation_line_id');
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

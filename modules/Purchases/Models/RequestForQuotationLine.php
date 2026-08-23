<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;

class RequestForQuotationLine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id', 'request_for_quotation_id', 'purchase_requisition_line_id', 'line_number',
        'product_id', 'unit_id', 'quantity', 'specification', 'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:8'];
    }

    public function requestForQuotation(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotation::class);
    }

    public function requisitionLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionLine::class, 'purchase_requisition_line_id');
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

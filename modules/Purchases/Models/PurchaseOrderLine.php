<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;

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
        'ordered_quantity',
        'received_quantity',
        'remaining_quantity',
        'unit_price',
        'line_total',
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
}

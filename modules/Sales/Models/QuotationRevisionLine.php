<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;

class QuotationRevisionLine extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'quotation_revision_id',
        'line_number',
        'product_id',
        'item_id',
        'description',
        'unit_id',
        'quantity',
        'conversion_factor',
        'base_quantity',
        'unit_price',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'line_total',
        'requested_date',
        'notes',
        'product_name_snapshot',
        'unit_name_snapshot',
        'specs_snapshot',
        'specifications',
        'warehouse_notes',
        'production_notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->public_uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'conversion_factor' => 'decimal:8',
            'base_quantity' => 'decimal:8',
            'unit_price' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'requested_date' => 'date',
            'specifications' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(QuotationRevision::class, 'quotation_revision_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'unit_id')->withTrashed();
    }
}

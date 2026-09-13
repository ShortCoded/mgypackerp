<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Product;

class PriceListLine extends Model
{
    public const DiscountPercentage = 'percentage';

    public const DiscountFixed = 'fixed';

    public const DiscountTypes = [self::DiscountPercentage, self::DiscountFixed];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:4', 'allowed_discount_value' => 'decimal:4'];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}

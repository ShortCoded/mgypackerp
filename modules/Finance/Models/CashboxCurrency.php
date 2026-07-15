<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Currency;

class CashboxCurrency extends Model
{
    use SoftDeletes;

    protected $fillable = ['cashbox_id', 'currency_id', 'is_default', 'status'];

    protected $attributes = ['is_default' => false, 'status' => 'active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'deleted_at' => 'datetime'];
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}

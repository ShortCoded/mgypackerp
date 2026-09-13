<?php

namespace Modules\Maintenance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;

class MaintenanceMaterialRequestLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:8',
            'approved_quantity' => 'decimal:8',
            'issued_quantity' => 'decimal:8',
            'returned_quantity' => 'decimal:8',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceMaterialRequest::class, 'maintenance_material_request_id');
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

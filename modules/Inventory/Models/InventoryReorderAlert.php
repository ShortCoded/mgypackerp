<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryReorderAlert extends Model
{
    public const NotificationType = 'inventory.reorder_low';

    public const Permission = 'inventory.reports.operations.view';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_low' => 'boolean', 'episode' => 'integer', 'last_checked_at' => 'datetime',
            'low_since' => 'datetime', 'recovered_at' => 'datetime'];
    }
}

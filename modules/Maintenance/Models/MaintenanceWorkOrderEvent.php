<?php

namespace Modules\Maintenance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MaintenanceWorkOrderEvent extends Model
{
    public const TypePaused = 'paused';

    public const TypeResumed = 'resumed';

    public const TypeExternalDispatched = 'external_dispatched';

    public const TypeExternalReceived = 'external_received';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $event) => $event->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'details' => 'array',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'maintenance_work_order_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

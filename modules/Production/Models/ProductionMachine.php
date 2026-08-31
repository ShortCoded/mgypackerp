<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Accounting\Models\CostCenter;

class ProductionMachine extends Model
{
    use SoftDeletes;

    public const StatusAvailable = 'available';

    public const StatusMaintenance = 'maintenance';

    public const StatusUnavailable = 'unavailable';

    protected $fillable = [
        'public_id', 'company_id', 'branch_id', 'branch_hall_id', 'cost_center_id', 'code', 'name', 'status',
        'notes', 'created_by', 'updated_by',
    ];

    protected $attributes = ['status' => self::StatusAvailable];

    protected static function booted(): void
    {
        static::creating(fn (self $machine) => $machine->public_id ??= (string) Str::uuid());
    }

    public function molds(): BelongsToMany
    {
        return $this->belongsToMany(ProductionMold::class, 'production_machine_mold')->withTimestamps();
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }
}

<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\Product;

class ProductionMold extends Model
{
    use SoftDeletes;

    public const StatusAvailable = 'available';

    public const StatusMaintenance = 'maintenance';

    public const StatusUnavailable = 'unavailable';

    protected $fillable = [
        'public_id', 'company_id', 'branch_id', 'branch_hall_id', 'code', 'name', 'status',
        'notes', 'created_by', 'updated_by',
    ];

    protected $attributes = ['status' => self::StatusAvailable];

    protected static function booted(): void
    {
        static::creating(fn (self $mold) => $mold->public_id ??= (string) Str::uuid());
    }

    public function machines(): BelongsToMany
    {
        return $this->belongsToMany(ProductionMachine::class, 'production_machine_mold')->withTimestamps();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'production_mold_product')->withTimestamps();
    }
}

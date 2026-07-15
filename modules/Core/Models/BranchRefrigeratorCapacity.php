<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BranchRefrigeratorCapacity extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'branch_refrigerator_id',
        'item_unit_id',
        'max_capacity',
        'position',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (BranchRefrigeratorCapacity $capacity): void {
            if (! is_string($capacity->public_uuid) || trim($capacity->public_uuid) === '') {
                $capacity->public_uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_capacity' => 'decimal:3',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BranchRefrigerator, $this>
     */
    public function refrigerator(): BelongsTo
    {
        return $this->belongsTo(BranchRefrigerator::class, 'branch_refrigerator_id');
    }

    /**
     * @return BelongsTo<ItemUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'item_unit_id');
    }
}

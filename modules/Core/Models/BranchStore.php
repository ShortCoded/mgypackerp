<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BranchStore extends Model
{
    use SoftDeletes;

    public const ClassificationGeneral = 'general';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'branch_id',
        'name',
        'classification',
        'position',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'classification' => self::ClassificationGeneral,
    ];

    /**
     * @return list<string>
     */
    public static function classifications(): array
    {
        return [
            self::ClassificationGeneral,
            ...Product::stockableItemClassifications(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function purchasingEligibleClassifications(): array
    {
        return [
            self::ClassificationGeneral,
            ...Product::purchasableItemClassifications(),
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BranchStore $store): void {
            if (! is_string($store->public_uuid) || trim($store->public_uuid) === '') {
                $store->public_uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<BranchStore>  $query
     * @return Builder<BranchStore>
     */
    public function scopePurchasingEligible(Builder $query): Builder
    {
        return $query->whereIn($this->getTable().'.classification', self::purchasingEligibleClassifications());
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}

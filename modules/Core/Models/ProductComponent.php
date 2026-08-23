<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductComponent extends Model
{
    use SoftDeletes;

    public const CalculationDirect = 'direct';

    public const CalculationPercentage = 'percentage';

    public const CalculationQuantity = 'quantity';

    public const CalculationCount = 'count';

    public const InputWeight = 'weight';

    public const InputPercentage = 'percentage';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'product_id',
        'component_product_id',
        'unit_id',
        'calculation_method',
        'quantity',
        'percentage',
        'reference_component_id',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'calculation_method' => self::CalculationDirect,
    ];

    /**
     * @return list<string>
     */
    public static function calculationMethods(): array
    {
        return [
            self::CalculationDirect,
            self::CalculationPercentage,
            self::CalculationQuantity,
            self::CalculationCount,
        ];
    }

    /**
     * @return list<string>
     */
    public static function inputSources(): array
    {
        return [
            self::InputWeight,
            self::InputPercentage,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductComponent $component): void {
            if (blank($component->public_id)) {
                $component->public_id = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8',
            'percentage' => 'decimal:8',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    /**
     * @return BelongsTo<ItemUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'unit_id');
    }

    /**
     * @return BelongsTo<ProductComponent, $this>
     */
    public function referenceComponent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_component_id');
    }

    /**
     * @return HasMany<ProductComponent, $this>
     */
    public function dependentComponents(): HasMany
    {
        return $this->hasMany(self::class, 'reference_component_id');
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

    /**
     * @param  Builder<ProductComponent>  $query
     * @return Builder<ProductComponent>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }
}

<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    public const ClassificationRawMaterial = 'raw_material';

    public const ClassificationFinishedProduct = 'finished_product';

    public const ClassificationSemiFinished = 'semi_finished';

    public const ClassificationPackaging = 'packaging';

    public const ClassificationService = 'service';

    public const ClassificationOther = 'other';

    public const ContextProducts = 'products';

    public const ContextRawMaterials = 'raw_materials';

    public const ContextPackagingMaterials = 'packaging_materials';

    public const ImageCollection = 'product_images';

    public const MainImageRole = 'main_image';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'name',
        'image_path',
        'barcode',
        'item_classification',
        'reorder_point',
        'item_unit_id',
        'equivalent_value',
        'equivalent_unit_id',
        'item_size_id',
        'item_color_id',
        'item_decal_id',
        'item_model_id',
        'item_origin_country_id',
        'item_category_id',
        'item_group_id',
        'cost_as_inventory',
        'is_displayable',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'item_classification' => self::ClassificationFinishedProduct,
        'cost_as_inventory' => true,
        'is_displayable' => true,
        'status' => 'active',
    ];

    /**
     * @return list<string>
     */
    public static function itemClassifications(): array
    {
        return [
            self::ClassificationRawMaterial,
            self::ClassificationFinishedProduct,
            self::ClassificationSemiFinished,
            self::ClassificationPackaging,
            self::ClassificationService,
            self::ClassificationOther,
        ];
    }

    /**
     * @return list<string>
     */
    public static function nonRawItemClassifications(): array
    {
        return array_values(array_filter(
            self::itemClassifications(),
            fn (string $classification): bool => $classification !== self::ClassificationRawMaterial,
        ));
    }

    /**
     * @return list<string>
     */
    public static function productItemClassifications(): array
    {
        return array_values(array_filter(
            self::itemClassifications(),
            fn (string $classification): bool => ! in_array($classification, self::materialClassifications(), true),
        ));
    }

    /**
     * @return list<string>
     */
    public static function materialClassifications(): array
    {
        return [
            self::ClassificationRawMaterial,
            self::ClassificationPackaging,
        ];
    }

    public static function contextForClassification(?string $classification): string
    {
        return match ($classification) {
            self::ClassificationRawMaterial => self::ContextRawMaterials,
            self::ClassificationPackaging => self::ContextPackagingMaterials,
            default => self::ContextProducts,
        };
    }

    public static function classificationForContext(string $context): ?string
    {
        return match ($context) {
            self::ContextRawMaterials => self::ClassificationRawMaterial,
            self::ContextPackagingMaterials => self::ClassificationPackaging,
            default => null,
        };
    }

    public static function isMaterialContext(string $context): bool
    {
        return in_array($context, [self::ContextRawMaterials, self::ContextPackagingMaterials], true);
    }

    public function isRawMaterial(): bool
    {
        return $this->item_classification === self::ClassificationRawMaterial;
    }

    public function isPackagingMaterial(): bool
    {
        return $this->item_classification === self::ClassificationPackaging;
    }

    public function isMaterial(): bool
    {
        return in_array($this->item_classification, self::materialClassifications(), true);
    }

    protected function casts(): array
    {
        return [
            'reorder_point' => 'decimal:4',
            'equivalent_value' => 'decimal:6',
            'cost_as_inventory' => 'boolean',
            'is_displayable' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<ItemUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'item_unit_id');
    }

    /**
     * @return BelongsTo<ItemUnit, $this>
     */
    public function equivalentUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'equivalent_unit_id')->withTrashed();
    }

    /**
     * @return BelongsTo<ItemSize, $this>
     */
    public function size(): BelongsTo
    {
        return $this->belongsTo(ItemSize::class, 'item_size_id');
    }

    /**
     * @return BelongsTo<ItemColor, $this>
     */
    public function color(): BelongsTo
    {
        return $this->belongsTo(ItemColor::class, 'item_color_id');
    }

    /**
     * @return BelongsTo<ItemDecal, $this>
     */
    public function decal(): BelongsTo
    {
        return $this->belongsTo(ItemDecal::class, 'item_decal_id');
    }

    /**
     * @return BelongsTo<ItemModel, $this>
     */
    public function itemModel(): BelongsTo
    {
        return $this->belongsTo(ItemModel::class, 'item_model_id');
    }

    /**
     * @return BelongsTo<ItemOriginCountry, $this>
     */
    public function originCountry(): BelongsTo
    {
        return $this->belongsTo(ItemOriginCountry::class, 'item_origin_country_id');
    }

    /**
     * @return BelongsTo<ItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    /**
     * @return BelongsTo<ItemGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'item_group_id');
    }

    /**
     * @return HasMany<ProductComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(ProductComponent::class, 'product_id');
    }

    /**
     * @return HasMany<ProductComponent, $this>
     */
    public function usedInComponents(): HasMany
    {
        return $this->hasMany(ProductComponent::class, 'component_product_id');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function relatedFinishedProducts(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'packaging_material_finished_product',
            'packaging_material_id',
            'finished_product_id',
        )->withTimestamps();
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function relatedPackagingMaterials(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'packaging_material_finished_product',
            'finished_product_id',
            'packaging_material_id',
        )->withTimestamps();
    }

    /**
     * @return MorphMany<ArchiveFileUsage, $this>
     */
    public function archiveFileUsages(): MorphMany
    {
        return $this->morphMany(ArchiveFileUsage::class, 'usable');
    }

    /**
     * @return MorphOne<ArchiveFileUsage, $this>
     */
    public function mainImageUsage(): MorphOne
    {
        return $this->morphOne(ArchiveFileUsage::class, 'usable')
            ->where('collection', self::ImageCollection)
            ->where('role', self::MainImageRole)
            ->with('file');
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
     * @return BelongsTo<User, $this>
     */
    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', 'active');
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeRawMaterials(Builder $query): Builder
    {
        return $query->where($this->getTable().'.item_classification', self::ClassificationRawMaterial);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeFinishedProducts(Builder $query): Builder
    {
        return $query->where($this->getTable().'.item_classification', self::ClassificationFinishedProduct);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeWithoutRawMaterials(Builder $query): Builder
    {
        $column = $this->getTable().'.item_classification';

        return $query->where(function (Builder $query) use ($column): void {
            $query
                ->whereNull($column)
                ->orWhere($column, '<>', self::ClassificationRawMaterial);
        });
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopePackagingMaterials(Builder $query): Builder
    {
        return $query->where($this->getTable().'.item_classification', self::ClassificationPackaging);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeMaterialItems(Builder $query): Builder
    {
        return $query->whereIn($this->getTable().'.item_classification', self::materialClassifications());
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeProductItems(Builder $query): Builder
    {
        $column = $this->getTable().'.item_classification';

        return $query->where(function (Builder $query) use ($column): void {
            $query
                ->whereNull($column)
                ->orWhereNotIn($column, self::materialClassifications());
        });
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeForProductContext(Builder $query, string $context): Builder
    {
        return match ($context) {
            self::ContextRawMaterials => $query->rawMaterials(),
            self::ContextPackagingMaterials => $query->packagingMaterials(),
            default => $query->productItems(),
        };
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeNonService(Builder $query): Builder
    {
        $column = $this->getTable().'.item_classification';

        return $query->where(function (Builder $query) use ($column): void {
            $query
                ->whereNull($column)
                ->orWhereNotIn($column, [self::ClassificationService, 'services']);
        });
    }
}

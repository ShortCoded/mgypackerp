<?php

namespace Modules\Core\Models;

use App\Models\User;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Auth\Models\Role;
use Modules\Core\Services\BrandingService;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrGovernorate;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'name',
        'legal_name',
        'commercial_name',
        'logo',
        'favicon',
        'status',
        'is_main',
        'notes',
        'legal_form',
        'commercial_register_number',
        'commercial_register_office',
        'commercial_register_date',
        'commercial_register_expiry_date',
        'tax_card_number',
        'tax_file_number',
        'tax_office',
        'vat_registration_number',
        'industrial_register_number',
        'import_card_number',
        'export_card_number',
        'national_id',
        'phone',
        'mobile',
        'hotline',
        'whatsapp',
        'fax',
        'email',
        'website',
        'country_id',
        'governorate_id',
        'city_id',
        'area_id',
        'country',
        'governorate',
        'city',
        'area',
        'address',
        'postal_code',
        'map_url',
        'industry',
        'activity_type',
        'business_description',
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
        'status' => 'active',
        'is_main' => false,
        'country' => 'Egypt',
    ];

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

    protected static function booted(): void
    {
        $forgetBrandingCache = static function (Company $company): void {
            BrandingService::forgetPersistentCache();
        };

        static::saved($forgetBrandingCache);
        static::deleted($forgetBrandingCache);
        static::restored($forgetBrandingCache);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'commercial_register_date' => 'date',
            'commercial_register_expiry_date' => 'date',
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
     * @return BelongsToMany<Role, $this>
     */
    public function accessRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_company_access', 'company_id', 'role_id')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'company_id');
    }

    /**
     * @return BelongsTo<HrCountry, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(HrCountry::class, 'country_id');
    }

    /**
     * @return BelongsTo<HrGovernorate, $this>
     */
    public function governorate(): BelongsTo
    {
        return $this->belongsTo(HrGovernorate::class, 'governorate_id');
    }

    /**
     * @return BelongsTo<HrCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(HrCity::class, 'city_id');
    }

    /**
     * @return BelongsTo<HrArea, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(HrArea::class, 'area_id');
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', 'active');
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function scopeMain(Builder $query): Builder
    {
        return $query->where($this->getTable().'.is_main', true)->where($this->getTable().'.status', 'active');
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function scopeNotMain(Builder $query): Builder
    {
        return $query->where($this->getTable().'.is_main', false);
    }
}

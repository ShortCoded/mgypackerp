<?php

namespace Modules\Purchases\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrGovernorate;

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'account_id',
        'account_group_id',
        'name',
        'status',
        'phone',
        'mobile',
        'email',
        'tax_number',
        'commercial_register',
        'national_id',
        'contact_person',
        'address',
        'country_id',
        'governorate_id',
        'city_id',
        'area_id',
        'city',
        'governorate',
        'country',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
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

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->companyScopedRouteQuery($value, $field)->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null): ?self
    {
        return $this->companyScopedRouteQuery($value, $field)->withTrashed()->first();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function accountGroup(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_group_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(HrCountry::class, 'country_id');
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(HrGovernorate::class, 'governorate_id');
    }

    public function cityLookup(): BelongsTo
    {
        return $this->belongsTo(HrCity::class, 'city_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(HrArea::class, 'area_id');
    }

    public function creditLimits(): HasMany
    {
        return $this->hasMany(SupplierCreditLimit::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', 'active');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    private function companyScopedRouteQuery(mixed $value, ?string $field): Builder
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
        $query = $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value);

        return $companyId === null ? $query->whereRaw('1 = 0') : $query->where($this->getTable().'.company_id', $companyId);
    }
}

<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;

class PriceList extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_list_date' => 'date',
            'valid_from' => 'date',
            'valid_until' => 'date',
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
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null
            ? null
            : $this->newQuery()->forCompany($companyId)->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null
            ? null
            : $this->newQuery()->withTrashed()->forCompany($companyId)->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('valid_from', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PriceListLine::class)->orderBy('line_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }
}

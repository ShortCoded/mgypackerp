<?php

namespace Modules\Auth\Models;

use App\Models\User;
use Database\Factories\ScreenDataVisibilityRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Auth\Enums\ScreenDataVisibilityDurationUnit;
use Modules\Auth\Enums\ScreenDataVisibilityRecordScope;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingCompanyContextService;

class ScreenDataVisibilityRule extends Model
{
    /** @use HasFactory<ScreenDataVisibilityRuleFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'user_id',
        'screen_key',
        'record_scope',
        'max_visible_records',
        'duration_value',
        'duration_unit',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'record_scope' => ScreenDataVisibilityRecordScope::OwnRecords->value,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'record_scope' => ScreenDataVisibilityRecordScope::class,
            'duration_unit' => ScreenDataVisibilityDurationUnit::class,
            'max_visible_records' => 'integer',
            'duration_value' => 'integer',
            'is_active' => 'boolean',
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

    protected static function newFactory(): ScreenDataVisibilityRuleFactory
    {
        return ScreenDataVisibilityRuleFactory::new();
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->companyScopedRouteQuery($field)->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null): ?Model
    {
        return $this->companyScopedRouteQuery($field)->withTrashed()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by')->withTrashed();
    }

    /** @param Builder<ScreenDataVisibilityRule> $query */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), $companyId);
    }

    /** @return Builder<ScreenDataVisibilityRule> */
    private function companyScopedRouteQuery(?string $field): Builder
    {
        $query = $this->newQuery();
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null
            ? $query->whereRaw('1 = 0')
            : $query->where($this->qualifyColumn('company_id'), $companyId);
    }
}

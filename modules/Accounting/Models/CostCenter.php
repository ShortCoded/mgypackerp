<?php

namespace Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Company;

class CostCenter extends Model
{
    use SoftDeletes;

    public const RootProductionCode = '1';

    public const RootServiceCode = '2';

    /**
     * @var list<string>
     */
    public const ProtectedRootCodes = [self::RootProductionCode, self::RootServiceCode];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'parent_id',
        'doc_number',
        'doc_num',
        'cost_center_code',
        'name',
        'name_en',
        'is_group',
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
        'is_group' => false,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
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

    public function codeNameLabel(): string
    {
        return self::codeNameLabelFor($this->cost_center_code, $this->displayName());
    }

    public function displayName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if (config("languages.available.{$locale}.dir") === 'rtl') {
            return trim((string) $this->name);
        }

        return trim((string) $this->name_en) !== '' ? trim((string) $this->name_en) : trim((string) $this->name);
    }

    public function isProtectedRoot(): bool
    {
        return $this->parent_id === null
            && in_array((string) $this->cost_center_code, self::ProtectedRootCodes, true);
    }

    public static function codeNameLabelFor(?string $code, ?string $name): string
    {
        return trim(implode(' / ', array_filter([
            trim((string) $code),
            trim((string) $name),
        ], fn (string $value): bool => $value !== '')));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'cost_center_accounts')
            ->withTimestamps()
            ->withTrashed()
            ->orderByRaw('LENGTH(accounts.account_code), accounts.account_code');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('cost_center_code');
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', 'active');
    }

    public function scopeGroup(Builder $query): Builder
    {
        return $query->where($this->getTable().'.is_group', true);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($this->getTable().'.cost_center_code');
    }
}

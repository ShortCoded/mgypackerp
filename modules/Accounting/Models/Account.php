<?php

namespace Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Models\Company;
use Modules\Finance\Models\BankAccount;

class Account extends Model
{
    use SoftDeletes;

    public const TypeAsset = 'asset';

    public const TypeLiability = 'liability';

    public const TypeEquity = 'equity';

    public const TypeRevenue = 'revenue';

    public const TypeExpense = 'expense';

    public const StatementFinancialPosition = 'financial_position';

    public const StatementIncomeStatement = 'income_statement';

    public const BalanceDebit = 'debit';

    public const BalanceCredit = 'credit';

    /**
     * @var list<string>
     */
    public const ProtectedRootCodes = ['1', '2', '3', '4', '5'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'account_code',
        'name',
        'name_en',
        'parent_id',
        'level',
        'account_classification_id',
        'account_type',
        'statement_type',
        'normal_balance',
        'is_group',
        'is_postable',
        'is_system',
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
        'level' => 1,
        'is_group' => false,
        'is_postable' => true,
        'is_system' => false,
        'status' => 'active',
    ];

    public static function accountTypes(): array
    {
        return [self::TypeAsset, self::TypeLiability, self::TypeEquity, self::TypeRevenue, self::TypeExpense];
    }

    public static function statementTypes(): array
    {
        return [self::StatementFinancialPosition, self::StatementIncomeStatement];
    }

    public static function normalBalances(): array
    {
        return [self::BalanceDebit, self::BalanceCredit];
    }

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'is_postable' => 'boolean',
            'is_system' => 'boolean',
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

    public function displayName(?string $locale = null): string
    {
        return self::displayNameFor($this->name, $this->name_en, $locale);
    }

    public static function displayNameFor(?string $name, ?string $nameEn = null, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if (config("languages.available.{$locale}.dir") === 'rtl') {
            return trim((string) $name);
        }

        $englishName = trim((string) $nameEn);

        return $englishName !== '' ? $englishName : trim((string) $name);
    }

    public function codeNameLabel(?string $locale = null): string
    {
        return self::codeNameLabelFor($this->account_code, $this->name, $this->name_en, $locale);
    }

    public function isProtectedRoot(): bool
    {
        return (bool) $this->is_system
            && $this->parent_id === null
            && in_array((string) $this->account_code, self::ProtectedRootCodes, true);
    }

    public static function codeNameLabelFor(?string $accountCode, ?string $name, ?string $nameEn = null, ?string $locale = null): string
    {
        $accountCode = trim((string) $accountCode);
        $displayName = self::displayNameFor($name, $nameEn, $locale);

        return trim(implode(' / ', array_filter([$accountCode, $displayName], fn (string $value): bool => $value !== '')));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('account_code');
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class)->withTrashed();
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(AccountClassification::class, 'account_classification_id');
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

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull($this->getTable().'.parent_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function scopeEligibleForDirectPosting(Builder $query): Builder
    {
        return self::applyDirectPostingEligibility($query);
    }

    public static function applyDirectPostingEligibility(Builder|QueryBuilder $query): Builder|QueryBuilder
    {
        return $query
            ->where('accounts.status', 'active')
            ->where('accounts.is_postable', true)
            ->where('accounts.is_group', false)
            ->whereNull('accounts.deleted_at');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($this->getTable().'.account_code');
    }
}

<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountClassification extends Model
{
    use SoftDeletes;

    public const FixedAssets = 'fixed_assets';

    public const Expenses = 'expenses';

    /**
     * @return list<string>
     */
    public static function legacyExpenseCodes(): array
    {
        return [
            'cost_of_goods_sold',
            'salary_expense',
            'rent_expense',
            'utilities_expense',
            'depreciation_expense',
            'other_expense',
        ];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'code',
        'name',
        'name_en',
        'account_type',
        'statement_type',
        'normal_balance',
        'is_system',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected function casts(): array
    {
        return [
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
}

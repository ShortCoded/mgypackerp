<?php

namespace Modules\FixedAssets\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\OpeningBalance;
use Modules\Purchases\Models\PurchaseInvoiceLine;

class FixedAsset extends Model
{
    use SoftDeletes;

    public const EntryTypeNewAsset = 'new_asset';

    public const EntryTypeOpeningAsset = 'opening_asset';

    public const DepreciationMethodStraightLine = 'straight_line';

    public const DepreciationMethodDecliningBalance = 'declining_balance';

    public const DepreciationMethodDoubleDecliningBalance = 'double_declining_balance';

    public const DepreciationMethodSumOfYearsDigits = 'sum_of_years_digits';

    public const DepreciationMethodUnitsOfProduction = 'units_of_production';

    public const StatusDraft = 'draft';

    public const StatusActive = 'active';

    public const StatusSuspended = 'suspended';

    public const StatusFullyDepreciated = 'fully_depreciated';

    public const StatusDisposed = 'disposed';

    public const StatusSold = 'sold';

    public const StatusWrittenOff = 'written_off';

    public const StatusInactive = 'inactive';

    public const ImageCollection = 'fixed_asset_images';

    public const MainImageRole = 'main_image';

    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'branch_id',
        'branch_hall_id',
        'period_id',
        'account_id',
        'asset_group_account_id',
        'credit_account_id',
        'cost_center_id',
        'currency_id',
        'asset_date',
        'asset_name',
        'image_path',
        'entry_type',
        'source_type',
        'source_id',
        'source_doc_num',
        'description',
        'serial_number',
        'purchase_date',
        'acquisition_date',
        'operation_date',
        'purchase_value',
        'base_acquisition_value',
        'salvage_value',
        'exchange_rate',
        'previous_depreciation',
        'previous_depreciation_until_date',
        'depreciation_start_date',
        'net_value',
        'annual_depreciation_rate',
        'expected_usage_units',
        'useful_life',
        'is_depreciable',
        'depreciation_method',
        'location_address',
        'status',
        'capitalized_at',
        'capitalized_by',
        'disposed_at',
        'locked_at',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected $attributes = [
        'status' => 'active',
        'is_depreciable' => true,
        'entry_type' => self::EntryTypeNewAsset,
        'salvage_value' => '0',
        'depreciation_method' => self::DepreciationMethodStraightLine,
    ];

    protected function casts(): array
    {
        return [
            'legacy_recognition' => 'array',
            'asset_date' => 'date',
            'purchase_date' => 'date',
            'acquisition_date' => 'date',
            'operation_date' => 'date',
            'purchase_value' => 'decimal:4',
            'base_acquisition_value' => 'decimal:4',
            'salvage_value' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'previous_depreciation' => 'decimal:4',
            'previous_depreciation_until_date' => 'date',
            'depreciation_start_date' => 'date',
            'net_value' => 'decimal:4',
            'annual_depreciation_rate' => 'decimal:4',
            'expected_usage_units' => 'decimal:4',
            'useful_life' => 'decimal:2',
            'is_depreciable' => 'boolean',
            'capitalized_at' => 'datetime',
            'disposed_at' => 'date',
            'locked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function entryTypes(): array
    {
        return [
            self::EntryTypeNewAsset,
            self::EntryTypeOpeningAsset,
        ];
    }

    /**
     * @return list<string>
     */
    public static function depreciationMethods(): array
    {
        return [
            self::DepreciationMethodStraightLine,
            self::DepreciationMethodDecliningBalance,
            self::DepreciationMethodDoubleDecliningBalance,
            self::DepreciationMethodSumOfYearsDigits,
            self::DepreciationMethodUnitsOfProduction,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::StatusDraft,
            self::StatusActive,
            self::StatusSuspended,
            self::StatusFullyDepreciated,
            self::StatusDisposed,
            self::StatusSold,
            self::StatusWrittenOff,
            self::StatusInactive,
        ];
    }

    /**
     * @return list<string>
     */
    public static function dispositionStatuses(): array
    {
        return [self::StatusDisposed, self::StatusSold, self::StatusWrittenOff];
    }

    public function entryTypeLabel(): string
    {
        return __("fixed_assets.entry_types.{$this->entry_type}");
    }

    public function depreciationMethodLabel(): string
    {
        return $this->depreciation_method
            ? __("fixed_assets.depreciation_methods.{$this->depreciation_method}")
            : '';
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchHall(): BelongsTo
    {
        return $this->belongsTo(BranchHall::class, 'branch_hall_id')->withTrashed();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class, 'period_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function assetGroupAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_group_account_id')->withTrashed();
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id')->withTrashed();
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function purchaseInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceLine::class, 'source_id');
    }

    public function categoryMapping(): HasOne
    {
        return $this->hasOne(FixedAssetCategoryMapping::class, 'asset_group_account_id', 'asset_group_account_id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class)->orderBy('period_end');
    }

    public function postedDepreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class)
            ->where('status', FixedAssetDepreciation::StatusPosted)
            ->orderBy('period_end');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FixedAssetMovement::class)->orderByDesc('movement_date')->orderByDesc('id');
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(FixedAssetDisposal::class)->orderByDesc('disposal_date')->orderByDesc('id');
    }

    public function capitalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capitalized_by');
    }

    public function archiveFileUsages(): MorphMany
    {
        return $this->morphMany(ArchiveFileUsage::class, 'usable');
    }

    public function mainImageUsage(): MorphOne
    {
        return $this->morphOne(ArchiveFileUsage::class, 'usable')
            ->where('collection', self::ImageCollection)
            ->where('role', self::MainImageRole)
            ->with('file');
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
        return $query->whereIn($this->getTable().'.status', [self::StatusActive, self::StatusFullyDepreciated]);
    }

    public function scopeDepreciationEligible(Builder $query): Builder
    {
        return $query
            ->where($this->getTable().'.is_depreciable', true)
            ->whereIn($this->getTable().'.status', [self::StatusActive, self::StatusFullyDepreciated])
            ->whereNull($this->getTable().'.disposed_at');
    }

    public function isDisposed(): bool
    {
        return in_array($this->status, self::dispositionStatuses(), true) || $this->disposed_at !== null;
    }

    public function costMovements(): HasMany
    {
        return $this->hasMany(FixedAssetMovement::class)->whereIn('movement_type', FixedAssetMovement::costTypes())->orderBy('movement_date')->orderBy('id');
    }

    public function hasLegacyRecognition(): bool
    {
        return is_array($this->legacy_recognition)
            && (int) data_get($this->legacy_recognition, 'asset_id') === (int) $this->getKey()
            && (int) data_get($this->legacy_recognition, 'company_id') === (int) $this->company_id;
    }

    public function hasPostedRecognition(): bool
    {
        if ($this->hasLegacyRecognition()) {
            return true;
        }
        if ($this->relationLoaded('costMovements')) {
            return $this->costMovements->contains(fn (FixedAssetMovement $movement): bool => in_array($movement->movement_type, [FixedAssetMovement::TypeCapitalization, FixedAssetMovement::TypeOpening], true)
                && $movement->status === FixedAssetMovement::StatusPosted && $movement->journalEntry?->is_posted && $movement->journalEntry->reversed_entry_id === null);
        }

        return $this->costMovements()->whereIn('movement_type', [FixedAssetMovement::TypeCapitalization, FixedAssetMovement::TypeOpening])->where('status', FixedAssetMovement::StatusPosted)->whereHas('journalEntry', fn ($query) => $query->where('is_posted', true)->whereNull('reversed_entry_id'))->exists();
    }

    public function isOperational(): bool
    {
        return in_array($this->status, [self::StatusActive, self::StatusFullyDepreciated], true) && ! $this->isDisposed();
    }

    public function canEditMaster(): bool
    {
        return ! $this->trashed() && ! $this->isDisposed() && ! $this->isMasterLocked();
    }

    public function canEditBasicData(): bool
    {
        return ! $this->trashed() && ! $this->isDisposed();
    }

    public function isLockedForEditing(): bool
    {
        return ! $this->canEditMaster();
    }

    public function isMasterLocked(): bool
    {
        return $this->hasLegacyRecognition()
            || $this->locked_at !== null
            || $this->capitalized_at !== null
            || $this->postedDepreciations()->exists()
            || $this->movements()->where('status', 'posted')->exists()
            || $this->disposals()->exists()
            || $this->hasUnlinkedFinancialHistory();
    }

    private function hasUnlinkedFinancialHistory(): bool
    {
        if (! $this->exists || ! $this->account_id) {
            return false;
        }
        $reversed = $this->costMovements()->where('status', 'reversed')->get(['journal_entry_id', 'reversal_journal_entry_id']);
        $excluded = $reversed->pluck('journal_entry_id')->merge($reversed->pluck('reversal_journal_entry_id'))->filter()->all();

        return JournalEntry::query()->where('company_id', $this->company_id)->where('is_posted', true)->whereNotIn('id', $excluded)
            ->whereHas('lines', fn ($query) => $query->where('account_id', $this->account_id))->exists()
            || OpeningBalance::query()->where('company_id', $this->company_id)->where('is_cancelled', false)
                ->whereHas('lines', fn ($query) => $query->where('account_id', $this->account_id))->exists();
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

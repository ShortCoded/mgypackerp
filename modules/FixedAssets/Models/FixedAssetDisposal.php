<?php

namespace Modules\FixedAssets\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\Models\Customer;

class FixedAssetDisposal extends Model
{
    public const TypeSale = 'sale';

    public const TypeDisposal = 'disposal';

    public const TypeWriteOff = 'write_off';

    public const TypeScrap = 'scrap';

    public const StatusPosted = 'posted';

    public const StatusReversed = 'reversed';

    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'financial_period_id',
        'fixed_asset_id',
        'asset_status_before',
        'disposal_date',
        'disposition_type',
        'reason',
        'customer_id',
        'proceeds_account_id',
        'original_cost',
        'base_original_cost',
        'accumulated_depreciation',
        'base_accumulated_depreciation',
        'net_book_value',
        'base_net_book_value',
        'proceeds',
        'base_proceeds',
        'gain_amount',
        'base_gain_amount',
        'loss_amount',
        'base_loss_amount',
        'status',
        'journal_entry_id',
        'reversal_journal_entry_id',
        'notes',
        'approved_by',
        'approved_at',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
        'reversal_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'disposal_date' => 'date',
            'original_cost' => 'decimal:4',
            'base_original_cost' => 'decimal:4',
            'accumulated_depreciation' => 'decimal:4',
            'base_accumulated_depreciation' => 'decimal:4',
            'net_book_value' => 'decimal:4',
            'base_net_book_value' => 'decimal:4',
            'proceeds' => 'decimal:4',
            'base_proceeds' => 'decimal:4',
            'gain_amount' => 'decimal:4',
            'base_gain_amount' => 'decimal:4',
            'loss_amount' => 'decimal:4',
            'base_loss_amount' => 'decimal:4',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public static function types(): array
    {
        return [self::TypeSale, self::TypeDisposal, self::TypeWriteOff, self::TypeScrap];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId ? $this->newQuery()->where('company_id', $companyId)->where($field ?? $this->getRouteKeyName(), $value)->first() : null;
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id')->withTrashed();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function proceedsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'proceeds_account_id')->withTrashed();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withTrashed();
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id')->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}

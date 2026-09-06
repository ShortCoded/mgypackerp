<?php

namespace Modules\FixedAssets\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;

class FixedAssetDepreciation extends Model
{
    public const StatusPosted = 'posted';

    public const StatusReversed = 'reversed';

    protected $fillable = [
        'accumulated_account_id', 'expense_account_id',
        'depreciation_run_id',
        'fixed_asset_id',
        'company_id',
        'financial_period_id',
        'period_start',
        'period_end',
        'acquisition_cost',
        'base_acquisition_cost',
        'depreciation_base',
        'base_depreciation_base',
        'period_depreciation',
        'base_period_depreciation',
        'usage_units',
        'accumulated_before',
        'base_accumulated_before',
        'accumulated_after',
        'base_accumulated_after',
        'closing_net_book_value',
        'base_closing_net_book_value',
        'branch_id',
        'cost_center_id',
        'journal_entry_id',
        'status',
        'posted_at',
        'posted_by',
        'reversed_at',
        'reversed_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'acquisition_cost' => 'decimal:4',
            'base_acquisition_cost' => 'decimal:4',
            'depreciation_base' => 'decimal:4',
            'base_depreciation_base' => 'decimal:4',
            'period_depreciation' => 'decimal:4',
            'base_period_depreciation' => 'decimal:4',
            'usage_units' => 'decimal:4',
            'accumulated_before' => 'decimal:4',
            'base_accumulated_before' => 'decimal:4',
            'accumulated_after' => 'decimal:4',
            'base_accumulated_after' => 'decimal:4',
            'closing_net_book_value' => 'decimal:4',
            'base_closing_net_book_value' => 'decimal:4',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FixedAssetDepreciationRun::class, 'depreciation_run_id');
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withTrashed();
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}

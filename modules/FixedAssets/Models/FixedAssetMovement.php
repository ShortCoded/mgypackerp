<?php

namespace Modules\FixedAssets\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrEmployee;

class FixedAssetMovement extends Model
{
    public const StatusPosted = 'posted';

    public const StatusReversed = 'reversed';

    public const TypeTransfer = 'transfer';

    public const TypeCustody = 'custody';

    public const TypeCapitalization = 'capitalization';

    public const TypeOpening = 'opening';

    public const TypeAddition = 'addition';

    public static function costTypes(): array
    {
        return [self::TypeCapitalization, self::TypeOpening, self::TypeAddition];
    }

    protected $fillable = [
        'movement_type', 'financial_period_id', 'journal_entry_id', 'reversal_journal_entry_id',
        'opening_balance_id', 'counter_account_id', 'currency_id', 'exchange_rate', 'amount', 'base_amount',
        'opening_accumulated', 'base_opening_accumulated', 'revised_useful_life', 'revised_residual_value',
        'snapshot', 'source_custodian_id', 'destination_custodian_id', 'reversal_date', 'reversed_at', 'reversed_by', 'reversal_reason',
        'doc_number',
        'doc_num',
        'company_id',
        'fixed_asset_id',
        'movement_date',
        'source_branch_id',
        'destination_branch_id',
        'source_branch_hall_id',
        'destination_branch_hall_id',
        'source_location_address',
        'destination_location_address',
        'source_cost_center_id',
        'destination_cost_center_id',
        'reason',
        'notes',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'posted_by',
        'posted_at',
        'created_by',
    ];

    protected $attributes = ['status' => self::StatusPosted, 'movement_type' => self::TypeTransfer];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array', 'amount' => 'decimal:4', 'base_amount' => 'decimal:4',
            'opening_accumulated' => 'decimal:4', 'base_opening_accumulated' => 'decimal:4',
            'exchange_rate' => 'decimal:6', 'revised_useful_life' => 'decimal:2', 'revised_residual_value' => 'decimal:4',
            'reversal_date' => 'date', 'reversed_at' => 'datetime',
            'movement_date' => 'date',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
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

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withTrashed();
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id')->withTrashed();
    }

    public function sourceCustodian(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'source_custodian_id')->withTrashed();
    }

    public function destinationCustodian(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'destination_custodian_id')->withTrashed();
    }

    public function archiveFileUsages(): MorphMany
    {
        return $this->morphMany(ArchiveFileUsage::class, 'usable');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id')->withTrashed();
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id')->withTrashed();
    }

    public function sourceBranchHall(): BelongsTo
    {
        return $this->belongsTo(BranchHall::class, 'source_branch_hall_id')->withTrashed();
    }

    public function destinationBranchHall(): BelongsTo
    {
        return $this->belongsTo(BranchHall::class, 'destination_branch_hall_id')->withTrashed();
    }

    public function sourceCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'source_cost_center_id')->withTrashed();
    }

    public function destinationCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'destination_cost_center_id')->withTrashed();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
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

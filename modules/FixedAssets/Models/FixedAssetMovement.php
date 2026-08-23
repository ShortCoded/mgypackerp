<?php

namespace Modules\FixedAssets\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingCompanyContextService;

class FixedAssetMovement extends Model
{
    public const StatusPosted = 'posted';

    protected $fillable = [
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

    protected $attributes = ['status' => self::StatusPosted];

    protected function casts(): array
    {
        return [
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
}

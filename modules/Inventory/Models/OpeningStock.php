<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;

class OpeningStock extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusClosed = 'closed';

    public const StatusApproved = 'approved';

    protected $table = 'inventory_opening_stocks';

    protected $fillable = [
        'doc_number',
        'doc_num',
        'document_date',
        'company_id',
        'financial_period_id',
        'branch_id',
        'branch_hall_id',
        'branch_store_id',
        'notes',
        'is_closed',
        'approved',
        'approved_at',
        'approved_by',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected $attributes = [
        'is_closed' => true,
        'approved' => false,
        'status' => self::StatusClosed,
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'is_closed' => 'boolean',
            'approved' => 'boolean',
            'approved_at' => 'datetime',
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

    public function isLockedForEditing(): bool
    {
        return $this->isApproved() || $this->isClosed();
    }

    public function isClosed(): bool
    {
        return (bool) $this->is_closed || $this->status === self::StatusClosed;
    }

    public function isApproved(): bool
    {
        return (bool) $this->approved || $this->status === self::StatusApproved;
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
        return $this->belongsTo(Branch::class);
    }

    public function branchHall(): BelongsTo
    {
        return $this->belongsTo(BranchHall::class, 'branch_hall_id')->withTrashed();
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class, 'branch_store_id')->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningStockLine::class, 'opening_stock_id')->orderBy('line_no');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function scopeForContext(Builder $query, int $companyId, int $financialPeriodId, int $branchId): Builder
    {
        return $query
            ->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.financial_period_id', $financialPeriodId)
            ->where($this->getTable().'.branch_id', $branchId);
    }
}

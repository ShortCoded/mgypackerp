<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;

class StockCount extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusCounted = 'counted';

    public const StatusApproved = 'approved';

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id',
        'branch_store_id', 'warehouse_location_id', 'count_date', 'snapshot_at', 'status',
        'notes', 'adjustment_document_id', 'created_by', 'updated_by', 'approved_by', 'approved_at',
        'deleted_by', 'restored_by', 'restored_at',
    ];

    protected $table = 'inventory_stock_counts';

    protected $attributes = ['status' => self::StatusDraft];

    protected function casts(): array
    {
        return [
            'count_date' => 'date',
            'snapshot_at' => 'datetime',
            'approved_at' => 'datetime',
            'restored_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null
            ? null
            : $this->newQuery()
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->where('company_id', $companyId)
                ->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null
            ? null
            : $this->newQueryWithoutScopes()
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->where('company_id', $companyId)
                ->first();
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class)->withTrashed();
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

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function adjustmentDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'inventory_stock_count_id')->orderBy('line_number');
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

    public function isApproved(): bool
    {
        return $this->status === self::StatusApproved;
    }

    public function isEditable(): bool
    {
        return ! $this->trashed() && ! $this->isApproved();
    }

    public function scopeForContext(Builder $query, int $companyId, int $financialPeriodId, int $branchId): Builder
    {
        return $query
            ->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.financial_period_id', $financialPeriodId)
            ->where($this->getTable().'.branch_id', $branchId);
    }
}

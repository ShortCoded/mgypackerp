<?php

namespace Modules\Purchases\Models;

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

class PurchaseRequisition extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusApproved = 'approved';

    public const StatusPartiallyConverted = 'partially_converted';

    public const StatusFullyConverted = 'fully_converted';

    public const StatusCancelled = 'cancelled';

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id', 'branch_store_id',
        'request_date', 'required_by_date', 'department', 'priority', 'status', 'notes', 'requested_by',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason', 'cancelled_by',
        'cancelled_at', 'cancel_reason', 'created_by', 'updated_by', 'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date', 'required_by_date' => 'date', 'approved_at' => 'datetime',
            'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null ? null : $this->newQuery()
            ->where('company_id', $companyId)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function isLockedForEditing(): bool
    {
        return $this->status !== self::StatusDraft;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class)->orderBy('line_number');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
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

    public function requestsForQuotation(): HasMany
    {
        return $this->hasMany(RequestForQuotation::class);
    }

    public function scopeForContext(Builder $query, int $companyId, int $financialPeriodId): Builder
    {
        return $query->where('company_id', $companyId)->where('financial_period_id', $financialPeriodId);
    }
}

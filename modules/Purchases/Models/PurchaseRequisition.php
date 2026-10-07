<?php

namespace Modules\Purchases\Models;

use App\Models\User;
use App\Services\DocumentOwnerEffectProofService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrEmployee;

class PurchaseRequisition extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusSubmitted = 'pending_approval';

    public const StatusRejected = 'rejected';

    public const StatusClosed = 'closed';

    public const StatusApproved = 'approved';

    public const StatusPartiallyConverted = 'partially_converted';

    public const StatusFullyConverted = 'fully_converted';

    public const StatusCancelled = 'cancelled';

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id', 'branch_store_id',
        'request_date', 'required_by_date', 'department', 'priority', 'status', 'notes', 'requested_by',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason', 'cancelled_by',
        'cancelled_at', 'cancel_reason', 'created_by', 'updated_by', 'deleted_by',
        'requester_employee_id', 'suggested_supplier_id', 'lead_time_days', 'submitted_by', 'submitted_at', 'closed_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date', 'required_by_date' => 'date', 'approved_at' => 'datetime',
            'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
            'submitted_at' => 'datetime', 'closed_at' => 'datetime', 'lead_time_days' => 'integer',
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

    public function canReopenSafely(): bool
    {
        if (! in_array($this->status, [self::StatusSubmitted, self::StatusRejected, self::StatusApproved, self::StatusClosed], true)) {
            return false;
        }

        return ! $this->hasDownstreamDocuments();
    }

    public function canCancelSafely(): bool
    {
        return ! $this->trashed() && in_array($this->status, [self::StatusDraft, self::StatusSubmitted, self::StatusRejected,
            self::StatusApproved, self::StatusClosed, self::StatusPartiallyConverted, self::StatusFullyConverted], true)
            && ! app(DocumentOwnerEffectProofService::class)->requisitionHasUnsettledEffects($this);
    }

    public function hasReopenEvidence(): bool
    {
        return $this->status === self::StatusDraft && DB::table('activity_log')->where('company_id', $this->company_id)
            ->where('subject_type', self::class)->where('subject_id', $this->id)->where('event', 'purchase_requisition.reopened')
            ->when($this->approved_at, fn ($query) => $query->where('created_at', '>=', $this->approved_at))
            ->when($this->closed_at, fn ($query) => $query->where('created_at', '>=', $this->closed_at))->exists();
    }

    public function hasDownstreamDocuments(): bool
    {
        foreach (['purchase_orders', 'request_for_quotations', 'supplier_quotations', 'production_material_requests'] as $table) {
            if (DB::table($table)->where('purchase_requisition_id', $this->getKey())->exists()) {
                return true;
            }
        }

        return DB::table('purchase_order_lines')
            ->whereIn('purchase_requisition_line_id', DB::table('purchase_requisition_lines')
                ->where('purchase_requisition_id', $this->getKey())
                ->select('id'))
            ->exists();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class)->orderBy('line_number');
    }

    public function suggestedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'suggested_supplier_id')->withTrashed();
    }

    public function requesterEmployee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'requester_employee_id')->withTrashed();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function refreshOrderingStatus(): void
    {
        if (! in_array($this->status, [self::StatusApproved, self::StatusPartiallyConverted, self::StatusFullyConverted], true)) {
            return;
        }

        $lines = $this->lines()->get();
        $approved = $lines->sum(fn (PurchaseRequisitionLine $line): float => (float) $line->approved_quantity);
        $ordered = $lines->sum(fn (PurchaseRequisitionLine $line): float => $line->orderedQuantity());
        $status = match (true) {
            $ordered <= 0 => self::StatusApproved,
            $ordered >= $approved - 0.00000001 => self::StatusFullyConverted,
            default => self::StatusPartiallyConverted,
        };
        if ($this->status !== $status) {
            self::withoutTimestamps(fn () => $this->forceFill(['status' => $status])->save());
        }
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

    public function supplierQuotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class);
    }

    public function scopeForContext(Builder $query, int $companyId, int $financialPeriodId): Builder
    {
        return $query->where('company_id', $companyId)->where('financial_period_id', $financialPeriodId);
    }
}

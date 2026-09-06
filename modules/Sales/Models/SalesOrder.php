<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Concerns\SnapshotsCompanyPrintIdentity;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrEmployee;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionOrder;

class SalesOrder extends Model
{
    use SnapshotsCompanyPrintIdentity, SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusPendingApproval = 'pending_approval';

    public const StatusHeldCredit = 'held_credit';

    public const StatusApproved = 'approved';

    public const StatusPartiallyFulfilled = 'partially_fulfilled';

    public const StatusFulfilled = 'fulfilled';

    public const StatusRejected = 'rejected';

    public const StatusCancelled = 'cancelled';

    public const StatusClosed = 'closed';

    public const StatusReopened = 'reopened';

    /** @var list<string> */
    protected $guarded = ['id'];

    protected $attributes = ['exchange_rate' => 1, 'status' => self::StatusDraft, 'credit_status' => 'pending'];

    protected function casts(): array
    {
        return [
            'order_date' => 'date', 'expected_delivery_date' => 'date', 'exchange_rate' => 'decimal:6',
            'subtotal_amount' => 'decimal:4', 'discount_amount' => 'decimal:4', 'tax_amount' => 'decimal:4',
            'total_amount' => 'decimal:4', 'credit_limit_snapshot' => 'decimal:4',
            'required_advance_amount' => 'decimal:4', 'terms_snapshot' => 'array',
            'payment_terms_snapshot' => 'array', 'execution_terms_snapshot' => 'array',
            'warranty_terms_snapshot' => 'array', 'technical_notes_snapshot' => 'array',
            'delivery_terms_snapshot' => 'array', 'agreement_snapshot' => 'array',
            'print_identity_snapshot' => 'array',
            'payment_schedule_bypassed' => 'boolean', 'confirmed_at' => 'datetime',
            'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function salesRequest(): BelongsTo
    {
        return $this->belongsTo(SalesRequest::class);
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null ? null : $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)->where('company_id', $companyId)->first();
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), $companyId);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::StatusDraft, self::StatusReopened], true);
    }

    public function isApprovedForFulfillment(): bool
    {
        return in_array($this->status, [self::StatusApproved, self::StatusPartiallyFulfilled], true);
    }

    public function canReopenSafely(): bool
    {
        if (! in_array($this->status, [self::StatusApproved, self::StatusRejected, self::StatusClosed], true)) {
            return false;
        }

        return ! $this->lines()->where(function (Builder $query): void {
            $query->where('reserved_quantity', '>', 0)
                ->orWhere('production_requested_quantity', '>', 0)
                ->orWhere('produced_quantity', '>', 0)
                ->orWhere('delivered_quantity', '>', 0)
                ->orWhere('invoiced_quantity', '>', 0);
        })->exists();
    }

    public function canCancelSafely(): bool
    {
        if (in_array($this->status, [self::StatusCancelled, self::StatusClosed], true)) {
            return false;
        }

        $hasFulfilledQuantity = $this->lines()->where(function (Builder $query): void {
            $query->where('delivered_quantity', '>', 0)
                ->orWhere('invoiced_quantity', '>', 0);
        })->exists();

        return ! $hasFulfilledQuantity
            && ! $this->productionOrders()->where('status', '<>', 'cancelled')->exists();
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

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class)->withTrashed();
    }

    public function quotationRevision(): BelongsTo
    {
        return $this->belongsTo(QuotationRevision::class);
    }

    /** Legacy authentication reference retained without inferring an employee mapping. */
    public function legacySalesUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_employee_id');
    }

    public function salesEmployee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'business_employee_id')->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('line_number');
    }

    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(SalesOrderPaymentSchedule::class)->orderBy('line_number');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(SalesOrderStatusHistory::class)->orderBy('changed_at');
    }

    public function creditOverrides(): HasMany
    {
        return $this->hasMany(SalesOrderCreditOverride::class)->orderByDesc('overridden_at');
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(InventoryDocument::class, 'source_document_id')->where('source_document_type', self::class);
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

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}

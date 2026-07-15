<?php

namespace Modules\Purchases\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusApproved = 'approved';

    public const StatusClosed = 'closed';

    public const StatusCancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::StatusDraft,
            self::StatusApproved,
            self::StatusClosed,
            self::StatusCancelled,
        ];
    }

    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'financial_period_id',
        'branch_id',
        'branch_store_id',
        'supplier_id',
        'currency_id',
        'document_date',
        'exchange_rate',
        'expected_delivery_date',
        'supplier_reference',
        'total_ordered_quantity',
        'total_received_quantity',
        'total_remaining_quantity',
        'subtotal_amount',
        'total_amount',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'approved_by',
        'approved_at',
        'closed_by',
        'closed_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected $attributes = [
        'exchange_rate' => 1,
        'status' => self::StatusDraft,
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'expected_delivery_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'total_ordered_quantity' => 'decimal:8',
            'total_received_quantity' => 'decimal:8',
            'total_remaining_quantity' => 'decimal:8',
            'subtotal_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->companyScopedRouteQuery($value, $field)->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null): ?self
    {
        return $this->companyScopedRouteQuery($value, $field)->withTrashed()->first();
    }

    public function isDraft(): bool
    {
        return $this->status === self::StatusDraft;
    }

    public function isApproved(): bool
    {
        return $this->status === self::StatusApproved;
    }

    public function isClosed(): bool
    {
        return $this->status === self::StatusClosed;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::StatusCancelled;
    }

    public function hasReceipts(): bool
    {
        return (float) $this->total_received_quantity > 0;
    }

    public function isLockedForEditing(): bool
    {
        return ! $this->isDraft() || $this->hasReceipts();
    }

    public function isDeletable(): bool
    {
        return $this->isDraft() && ! $this->hasReceipts();
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_number');
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

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
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

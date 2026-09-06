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
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\UnpricedInventoryReceipt;

class SupplyOrder extends Model
{
    use SoftDeletes;

    public const SourcePurchaseOrder = 'purchase_order';

    public const SourcePurchaseInvoice = 'purchase_invoice';

    public const StatusDraft = 'draft';

    public const StatusIssued = 'issued';

    public const StatusPartiallyReceived = 'partially_received';

    public const StatusFullyReceived = 'fully_received';

    public const StatusClosed = 'closed';

    public const StatusCancelled = 'cancelled';

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id', 'branch_store_id',
        'supplier_id', 'source_type', 'source_id', 'source_doc_num', 'purchase_order_id', 'purchase_invoice_id',
        'issue_date', 'expected_delivery_date', 'status', 'total_ordered_quantity', 'notes',
        'issued_by', 'issued_at', 'closed_by', 'closed_at', 'cancelled_by', 'cancelled_at', 'cancel_reason',
        'created_by', 'updated_by',
    ];

    protected $attributes = ['status' => self::StatusDraft];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expected_delivery_date' => 'date',
            'total_ordered_quantity' => 'decimal:8',
            'issued_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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

        return $companyId === null ? null : $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->where('company_id', $companyId)
            ->first();
    }

    public function fulfillmentStatus(): string
    {
        if (in_array($this->status, [self::StatusDraft, self::StatusCancelled, self::StatusClosed], true)) {
            return $this->status;
        }

        $ordered = (float) $this->lines()->sum('ordered_quantity');
        $received = $this->lines()->get()->sum(fn (SupplyOrderLine $line): float => $line->receivedQuantity());

        return match (true) {
            $received <= 0 => self::StatusIssued,
            $received + 0.00000001 >= $ordered => self::StatusFullyReceived,
            default => self::StatusPartiallyReceived,
        };
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

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplyOrderLine::class)->orderBy('line_number');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(UnpricedInventoryReceipt::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }
}

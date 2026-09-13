<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplyOrder;

class UnpricedInventoryReceipt extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusReversed = 'reversed';

    public const StatusApproved = 'approved';

    public const StatusClosed = 'closed';

    public const StatusCancelled = 'cancelled';

    public const PricingStatusUnpriced = 'unpriced';

    public const PricingStatusPriced = 'priced';

    protected $table = 'unpriced_inventory_receipts';

    protected $fillable = [
        'reversed_by', 'reversed_at', 'reversal_reason',
        'doc_number',
        'doc_num',
        'document_date',
        'company_id',
        'financial_period_id',
        'branch_id',
        'branch_hall_id',
        'branch_store_id',
        'supplier_id',
        'purchase_order_id',
        'supply_order_id',
        'goods_receipt_inspection_id',
        'supplier_delivery_note',
        'received_at',
        'qc_status',
        'posting_status',
        'grni_journal_entry_id',
        'received_by',
        'posted_by',
        'posted_at',
        'reference_number',
        'reference_date',
        'notes',
        'approved',
        'is_closed',
        'status',
        'pricing_status',
        'approved_at',
        'approved_by',
        'closed_at',
        'closed_by',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected $attributes = [
        'approved' => false,
        'is_closed' => false,
        'status' => self::StatusDraft,
        'pricing_status' => self::PricingStatusUnpriced,
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'reference_date' => 'date',
            'approved' => 'boolean',
            'is_closed' => 'boolean',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
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

    public function isLockedForEditing(): bool
    {
        return $this->isApproved() || $this->isClosed() || $this->isCancelled() || $this->status === self::StatusReversed;
    }

    public function isApproved(): bool
    {
        return (bool) $this->approved || $this->status === self::StatusApproved;
    }

    public function isClosed(): bool
    {
        return ! $this->isCancelled() && ((bool) $this->is_closed || $this->status === self::StatusClosed);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::StatusCancelled || $this->cancelled_at !== null;
    }

    public function hasQuantityEffect(): bool
    {
        return $this->isApproved() && ! $this->isCancelled();
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplyOrder(): BelongsTo
    {
        return $this->belongsTo(SupplyOrder::class);
    }

    public function inspection(): HasOne
    {
        return $this->hasOne(GoodsReceiptInspection::class, 'receipt_id');
    }

    public function sourceInspection(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptInspection::class, 'goods_receipt_inspection_id');
    }

    public function hasBlockingInspection(): bool
    {
        $inspection = $this->inspection();

        if ($this->goods_receipt_inspection_id !== null) {
            $inspection->whereKeyNot($this->goods_receipt_inspection_id);
        }

        return $inspection->exists();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(UnpricedInventoryReceiptLine::class, 'receipt_id')->orderBy('line_no');
    }

    public function grniJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'grni_journal_entry_id');
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

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function scopeForContext(Builder $query, int $companyId, int $financialPeriodId): Builder
    {
        return $query
            ->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.financial_period_id', $financialPeriodId);
    }
}

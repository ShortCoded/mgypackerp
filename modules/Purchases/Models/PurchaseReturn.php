<?php

namespace Modules\Purchases\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\UnpricedInventoryReceipt;

class PurchaseReturn extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusPosted = 'posted';

    public const StatusReversed = 'reversed';

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id', 'branch_store_id',
        'supplier_id', 'purchase_order_id', 'receipt_id', 'purchase_invoice_id', 'return_date', 'reason_code',
        'status', 'total_quantity', 'total_amount', 'journal_entry_id', 'notes', 'created_by', 'updated_by',
        'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancel_reason',
        'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date', 'total_quantity' => 'decimal:8', 'total_amount' => 'decimal:4',
            'approved_at' => 'datetime', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null ? null : $this->newQuery()->where('company_id', $companyId)
            ->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function isLockedForEditing(): bool
    {
        return $this->status !== 'draft';
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(UnpricedInventoryReceipt::class, 'receipt_id');
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}

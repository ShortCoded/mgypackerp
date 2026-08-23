<?php

namespace Modules\Purchases\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;

class SupplierPaymentContext extends Model
{
    public const MethodCash = 'cash';

    public const MethodBank = 'bank';

    public const MethodCheque = 'cheque';

    public const StatusDraft = 'draft';

    public const StatusApproved = 'approved';

    public const StatusCancelled = 'cancelled';

    protected $fillable = [
        'doc_number', 'doc_num', 'cash_voucher_id', 'company_id', 'financial_period_id', 'branch_id', 'supplier_id',
        'purchase_order_id', 'journal_entry_id', 'payment_method', 'payment_date', 'amount', 'currency_id',
        'exchange_rate', 'bank_account_id', 'cheque_id', 'status', 'is_advance', 'allocated_amount', 'reason',
        'notes', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $attributes = [
        'payment_method' => self::MethodCash,
        'exchange_rate' => 1,
        'status' => self::StatusDraft,
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'is_advance' => 'boolean',
            'allocated_amount' => 'decimal:4',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function methods(): array
    {
        return [self::MethodCash, self::MethodBank, self::MethodCheque];
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

    public function isDraft(): bool
    {
        return $this->status === self::StatusDraft;
    }

    public function isApproved(): bool
    {
        return $this->status === self::StatusApproved;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::StatusCancelled;
    }

    public function scopeEffectiveApproved(Builder $query): Builder
    {
        return $query->where('status', self::StatusApproved);
    }

    public function cashVoucher(): BelongsTo
    {
        return $this->belongsTo(CashVoucher::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}

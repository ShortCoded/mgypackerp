<?php

namespace Modules\Purchases\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;

class PurchaseInvoice extends Model
{
    use SoftDeletes;

    public const PaymentTypeCash = 'cash';

    public const PaymentTypeCredit = 'credit';

    public const PaymentTypePartial = 'partial';

    public const SourceScheduled = 'scheduled';

    public const SourceCashbox = 'cashbox';

    public const SourceBank = 'bank';

    public const StatusDraft = 'draft';

    public const StatusApproved = 'approved';

    public const StatusClosed = 'closed';

    public const StatusCancelled = 'cancelled';

    public const PaymentStatusUnpaid = 'unpaid';

    public const PaymentStatusPartiallyPaid = 'partially_paid';

    public const PaymentStatusPaid = 'paid';

    /**
     * @return list<string>
     */
    public static function paymentTypes(): array
    {
        return [
            self::PaymentTypeCash,
            self::PaymentTypeCredit,
            self::PaymentTypePartial,
        ];
    }

    /**
     * @return list<string>
     */
    public static function scheduleSourceTypes(): array
    {
        return [
            self::SourceScheduled,
            self::SourceCashbox,
            self::SourceBank,
        ];
    }

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

    /**
     * @return list<string>
     */
    public static function paymentStatuses(): array
    {
        return [
            self::PaymentStatusUnpaid,
            self::PaymentStatusPartiallyPaid,
            self::PaymentStatusPaid,
        ];
    }

    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'financial_period_id',
        'branch_id',
        'supplier_id',
        'invoice_date',
        'supplier_invoice_number',
        'supplier_invoice_date',
        'currency_id',
        'exchange_rate',
        'payment_type',
        'payment_source_type',
        'cashbox_id',
        'bank_account_id',
        'header_discount_type',
        'header_discount_value',
        'header_discount_amount',
        'subtotal_amount',
        'line_discount_amount',
        'taxable_amount',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'status',
        'payment_status',
        'notes',
        'internal_notes',
        'journal_entry_id',
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
        'payment_type' => self::PaymentTypeCredit,
        'status' => self::StatusDraft,
        'payment_status' => self::PaymentStatusUnpaid,
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'supplier_invoice_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'header_discount_value' => 'decimal:4',
            'header_discount_amount' => 'decimal:4',
            'subtotal_amount' => 'decimal:4',
            'line_discount_amount' => 'decimal:4',
            'taxable_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'remaining_amount' => 'decimal:4',
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

    public function isLockedForEditing(): bool
    {
        return ! $this->isDraft();
    }

    public function isDeletable(): bool
    {
        return $this->isDraft() || $this->isCancelled();
    }

    public function refreshPaymentTotals(): self
    {
        $paidAmount = (float) $this->paymentSchedules()
            ->whereNull('purchase_invoice_payment_schedules.deleted_at')
            ->whereHas('cashVoucher', fn ($query) => $query
                ->where('status', \Modules\Finance\Models\CashVoucher::StatusApproved)
                ->whereNull('deleted_at'))
            ->sum('amount');
        $totalAmount = (float) $this->total_amount;
        $remainingAmount = max(0, $totalAmount - $paidAmount);
        $paymentStatus = match (true) {
            $totalAmount > 0 && $paidAmount >= $totalAmount - 0.0001 => self::PaymentStatusPaid,
            $paidAmount > 0 => self::PaymentStatusPartiallyPaid,
            default => self::PaymentStatusUnpaid,
        };

        $this->forceFill([
            'paid_amount' => number_format($paidAmount, 4, '.', ''),
            'remaining_amount' => number_format($remainingAmount, 4, '.', ''),
            'payment_status' => $paymentStatus,
        ])->save();

        return $this->refresh();
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class)->withTrashed();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class)->orderBy('line_number');
    }

    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PurchaseInvoicePaymentSchedule::class)->orderBy('line_number');
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

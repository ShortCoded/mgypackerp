<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Company;
use Modules\Core\Models\Concerns\SnapshotsCompanyPrintIdentity;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\InventoryDocument;

class CustomerInvoice extends Model
{
    use SnapshotsCompanyPrintIdentity, SoftDeletes;

    public const TypeInvoice = 'invoice';

    public const TypeCreditNote = 'credit_note';

    public const StatusDraft = 'draft';

    public const StatusPosted = 'posted';

    public const StatusReopened = 'reopened';

    public const StatusCancelled = 'cancelled';

    protected $guarded = ['id'];

    protected $attributes = ['document_type' => self::TypeInvoice, 'status' => self::StatusDraft, 'posting_status' => 'unposted'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date', 'due_date' => 'date', 'exchange_rate' => 'decimal:6',
            'subtotal_amount' => 'decimal:4', 'discount_amount' => 'decimal:4', 'taxable_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4', 'total_amount' => 'decimal:4', 'applied_advance_amount' => 'decimal:4',
            'paid_amount' => 'decimal:4', 'credited_amount' => 'decimal:4', 'remaining_amount' => 'decimal:4',
            'credit_available_amount' => 'decimal:4', 'credit_allocated_amount' => 'decimal:4',
            'credit_refunded_amount' => 'decimal:4',
            'payment_terms_snapshot' => 'array', 'is_closed' => 'boolean', 'issued_at' => 'datetime',
            'posting_revision' => 'integer',
            'cancelled_at' => 'datetime', 'reopened_at' => 'datetime',
            'print_identity_snapshot' => 'array', 'electronic_invoice_response' => 'array',
            'electronic_invoice_submitted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null ? null : $this->newQuery()->where($field ?? 'doc_num', $value)->where('company_id', $companyId)->first();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::StatusDraft, self::StatusReopened], true) && ! $this->is_closed;
    }

    public static function allowsFullCrud(): bool
    {
        return (bool) config('erp_features.sales.allow_full_invoice_crud', true);
    }

    public function canAmend(): bool
    {
        return $this->document_type === self::TypeInvoice
            && ($this->isEditable() || (self::allowsFullCrud() && $this->canReopenSafely()));
    }

    public function canDeleteDraft(): bool
    {
        return self::allowsFullCrud()
            && $this->document_type === self::TypeInvoice
            && $this->status === self::StatusDraft
            && $this->posting_status === 'unposted'
            && ! $this->is_closed;
    }

    public function canReopenSafely(): bool
    {
        if ($this->document_type !== self::TypeInvoice
            || $this->posting_status !== 'posted'
            || bccomp((string) $this->paid_amount, '0', 4) > 0
            || bccomp((string) $this->credited_amount, '0', 4) > 0
            || filled($this->electronic_invoice_uuid)
            || ! in_array($this->electronic_invoice_status, ['not_configured', 'draft', 'rejected'], true)) {
            return false;
        }

        return ! $this->deliveries()->exists()
            && ! $this->returns()->where('status', '<>', 'cancelled')->exists()
            && ! $this->creditNotes()->exists()
            && ! $this->allocations()->whereHas('receipt', fn ($query) => $query->where('status', 'approved'))->exists();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'delivery_document_id');
    }

    public function deliveries(): BelongsToMany
    {
        return $this->belongsToMany(InventoryDocument::class, 'customer_invoice_deliveries')->withTimestamps();
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerInvoiceLine::class)->orderBy('line_number');
    }

    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(CustomerInvoicePaymentSchedule::class)->orderBy('sequence');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerReceiptAllocation::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id');
    }

    public function creditAllocations(): HasMany
    {
        return $this->hasMany(CustomerCreditAllocation::class, 'credit_note_id');
    }

    public function appliedCredits(): HasMany
    {
        return $this->hasMany(CustomerCreditAllocation::class, 'target_invoice_id');
    }

    public function creditRefunds(): HasMany
    {
        return $this->hasMany(CustomerCreditRefund::class, 'credit_note_id');
    }

    public function electronicInvoiceSubmissions(): HasMany
    {
        return $this->hasMany(ElectronicInvoiceSubmission::class);
    }
}

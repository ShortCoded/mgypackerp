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
}

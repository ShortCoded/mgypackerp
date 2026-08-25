<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Company;
use Modules\Core\Models\Concerns\SnapshotsCompanyPrintIdentity;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\InventoryDocument;

class SalesReturn extends Model
{
    use SnapshotsCompanyPrintIdentity, SoftDeletes;

    public const StatusPendingAuthorization = 'pending_authorization';

    public const StatusAuthorized = 'authorized';

    public const StatusReceived = 'received';

    public const StatusInspected = 'inspected';

    public const StatusClosed = 'closed';

    public const StatusCancelled = 'cancelled';

    public const ReasonExcess = 'excess_quantity';

    public const ReasonOrderEntry = 'order_entry_mistake';

    public const ReasonWrongItem = 'wrong_item';

    public const ReasonWrongSpecification = 'wrong_specification';

    public const ReasonManufacturingDefect = 'manufacturing_defect';

    public const ReasonDamaged = 'damaged_goods';

    public const ReasonProductionDefect = 'production_defect';

    public const ReasonCustomerRejection = 'customer_rejection';

    public const ReasonOther = 'other';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusPendingAuthorization];

    protected function casts(): array
    {
        return [
            'return_date' => 'date', 'subtotal_amount' => 'decimal:4', 'tax_amount' => 'decimal:4',
            'total_amount' => 'decimal:4', 'authorized_at' => 'datetime', 'received_at' => 'datetime',
            'inspected_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'print_identity_snapshot' => 'array',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'credit_note_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'delivery_document_id');
    }

    public function returnInventoryDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'return_inventory_document_id');
    }

    public function quarantineJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'quarantine_journal_entry_id');
    }

    public function dispositionJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'disposition_journal_entry_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class)->orderBy('line_number');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(SalesReturnStatusHistory::class)->orderBy('changed_at');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}

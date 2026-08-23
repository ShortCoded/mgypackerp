<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Concerns\SnapshotsCompanyPrintIdentity;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;

class InventoryDocument extends Model
{
    use SnapshotsCompanyPrintIdentity, SoftDeletes;

    public const TypeSalesDelivery = 'sales_delivery';

    public const TypeSalesReturnReceipt = 'sales_return_receipt';

    public const TypeProductionReceipt = 'production_receipt';

    public const StatusDraft = 'draft';

    public const StatusPosted = 'posted';

    public const StatusCancelled = 'cancelled';

    public const StatusReversed = 'reversed';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusDraft, 'is_closed' => false];

    protected function casts(): array
    {
        return ['document_date' => 'date', 'approved_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime', 'reversed_at' => 'datetime', 'is_closed' => 'boolean', 'print_identity_snapshot' => 'array'];
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

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'source_document_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryDocumentLine::class)->orderBy('line_number');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'source_id')->where('source_type', self::class);
    }
}

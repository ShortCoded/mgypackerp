<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Concerns\SnapshotsCompanyPrintIdentity;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionRunBatch;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesDeliveryReceipt;
use Modules\Sales\Models\SalesIssueOrder;
use Modules\Sales\Models\SalesOrder;

class InventoryDocument extends Model
{
    use SnapshotsCompanyPrintIdentity, SoftDeletes;

    public const TypeSalesDelivery = 'sales_delivery';

    public const TypeSalesReturnReceipt = 'sales_return_receipt';

    public const TypeProductionReceipt = 'production_receipt';

    public const TypeMaterialIssue = 'production_material_issue';

    public const TypeAdditionalMaterialIssue = 'production_additional_material_issue';

    public const TypeMaterialReturn = 'production_material_return';

    public const TypeMaterialConsumption = 'production_material_consumption';

    public const TypeMaintenanceMaterialIssue = 'maintenance_material_issue';

    public const TypeMaintenanceMaterialReturn = 'maintenance_material_return';

    public const TypeProductionWaste = 'production_waste';

    public const TypeTransfer = 'inventory_transfer';

    public const TypeReceipt = 'inventory_receipt';

    public const TypeIssue = 'inventory_issue';

    public const TypeReturn = 'inventory_return';

    public const TypeAdjustmentIn = 'inventory_adjustment_in';

    public const TypeAdjustmentOut = 'inventory_adjustment_out';

    public const TypeDamage = 'inventory_damage';

    public const TypeScrap = 'inventory_scrap';

    /** @return list<string> */
    public static function manualMovementTypes(): array
    {
        return [
            self::TypeReceipt,
            self::TypeIssue,
            self::TypeReturn,
            self::TypeTransfer,
            self::TypeAdjustmentIn,
            self::TypeAdjustmentOut,
            self::TypeDamage,
            self::TypeScrap,
        ];
    }

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

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function destinationBranchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class, 'destination_branch_store_id')->withTrashed();
    }

    public function destinationWarehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'destination_warehouse_location_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'source_document_id');
    }

    public function customerInvoices(): BelongsToMany
    {
        return $this->belongsToMany(CustomerInvoice::class, 'customer_invoice_deliveries')->withTimestamps();
    }

    public function salesIssueOrder(): BelongsTo
    {
        return $this->belongsTo(SalesIssueOrder::class, 'sales_issue_order_id');
    }

    public function customerDeliveryReceipt(): HasOne
    {
        return $this->hasOne(SalesDeliveryReceipt::class, 'inventory_document_id');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    public function productionRunBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionRunBatch::class, 'production_run_batch_id');
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
        return $this->hasMany(InventoryDocumentLine::class)->orderBy('line_number');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'source_id')->where('source_type', self::class);
    }
}

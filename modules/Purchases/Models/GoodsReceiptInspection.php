<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Models\Branch;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\UnpricedInventoryReceipt;

class GoodsReceiptInspection extends Model
{
    use SoftDeletes;

    public const AttachmentCollection = 'goods_receipt_inspection_attachments';

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id', 'receipt_id',
        'purchase_order_id', 'supply_order_id', 'source_type', 'source_id', 'source_doc_num',
        'inspection_at', 'result', 'status', 'observations', 'inspected_by', 'finalized_by',
        'finalized_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['inspection_at' => 'datetime', 'finalized_at' => 'datetime'];
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

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(UnpricedInventoryReceipt::class, 'receipt_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(UnpricedInventoryReceipt::class, 'goods_receipt_inspection_id');
    }

    public function hasReceiptableQuantity(): bool
    {
        if ($this->trashed() || $this->status !== 'finalized') {
            return false;
        }

        $this->loadMissing('lines.receiptLines.receipt');

        return $this->lines->contains(
            fn (GoodsReceiptInspectionLine $line): bool => $line->remainingReceiptQuantity() > 0.00000001,
        );
    }

    public function hasReceiptEvidence(): bool
    {
        return $this->receipt_id !== null
            || $this->receipts()->withTrashed()->exists()
            || $this->lines()->whereNotNull('receipt_line_id')->exists()
            || UnpricedInventoryReceipt::withTrashed()->whereHas('lines', fn ($query) => $query->withTrashed()
                ->whereIn('goods_receipt_inspection_line_id', $this->lines()->select('id')))->exists();
    }

    public function canCancelSafely(): bool
    {
        return ! $this->trashed() && in_array($this->status, ['draft', 'finalized'], true) && ! $this->hasReceiptEvidence();
    }

    public function canEditSafely(): bool
    {
        return $this->canCancelSafely() && in_array($this->source_type, ['purchase_order', 'supply_order'], true);
    }

    public function canDeleteDraft(): bool
    {
        return ! $this->trashed() && $this->status === 'draft' && $this->finalized_at === null && $this->finalized_by === null && ! $this->hasReceiptEvidence();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplyOrder(): BelongsTo
    {
        return $this->belongsTo(SupplyOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptInspectionLine::class);
    }

    public function archiveFileUsages(): MorphMany
    {
        return $this->morphMany(ArchiveFileUsage::class, 'usable');
    }

    public function attachmentUsages(): MorphMany
    {
        return $this->archiveFileUsages()
            ->where('collection', self::AttachmentCollection)
            ->whereNull('role')
            ->with('file');
    }
}

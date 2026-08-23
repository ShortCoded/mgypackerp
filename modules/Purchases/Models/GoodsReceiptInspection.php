<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\UnpricedInventoryReceipt;

class GoodsReceiptInspection extends Model
{
    use SoftDeletes;

    public const AttachmentCollection = 'goods_receipt_inspection_attachments';

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id', 'receipt_id',
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

<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingCompanyContextService;

class StockCount extends Model
{
    public const StatusDraft = 'draft';

    public const StatusCounted = 'counted';

    public const StatusApproved = 'approved';

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id',
        'branch_store_id', 'warehouse_location_id', 'count_date', 'snapshot_at', 'status',
        'notes', 'adjustment_document_id', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $table = 'inventory_stock_counts';

    protected $attributes = ['status' => self::StatusDraft];

    protected function casts(): array
    {
        return ['count_date' => 'date', 'snapshot_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null
            ? null
            : $this->newQuery()
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->where('company_id', $companyId)
                ->first();
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function adjustmentDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'inventory_stock_count_id')->orderBy('line_number');
    }
}

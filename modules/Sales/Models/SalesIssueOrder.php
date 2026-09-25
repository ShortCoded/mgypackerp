<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\InventoryDocument;

class SalesIssueOrder extends Model
{
    public const StatusPending = 'pending';

    public const StatusIssued = 'issued';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class)->withTrashed();
    }

    public function issues(): HasMany
    {
        return $this->hasMany(InventoryDocument::class, 'sales_issue_order_id');
    }
}

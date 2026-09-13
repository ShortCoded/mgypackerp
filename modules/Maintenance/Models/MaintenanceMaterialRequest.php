<?php

namespace Modules\Maintenance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\BranchStore;
use Modules\Inventory\Models\InventoryDocument;

class MaintenanceMaterialRequest extends Model
{
    use SoftDeletes;

    public const StatusSubmitted = 'submitted';

    public const StatusApproved = 'approved';

    public const StatusIssued = 'issued';

    public const StatusPartiallyReturned = 'partially_returned';

    public const StatusReturned = 'returned';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusSubmitted];

    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'maintenance_work_order_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class, 'branch_store_id')->withTrashed();
    }

    public function issueDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'inventory_issue_document_id');
    }

    public function returnDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'inventory_return_document_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaintenanceMaterialRequestLine::class)->orderBy('line_number');
    }

    public function scopeForContext(Builder $query, int $companyId, int $periodId, int $branchId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.financial_period_id', $periodId)
            ->where($this->getTable().'.branch_id', $branchId);
    }
}

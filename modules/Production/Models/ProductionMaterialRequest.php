<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\BranchStore;
use Modules\Purchases\Models\PurchaseRequisition;

class ProductionMaterialRequest extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusSubmitted = 'submitted';

    public const StatusApproved = 'approved';

    public const StatusPartiallyIssued = 'partially_issued';

    public const StatusIssued = 'issued';

    public const StatusShortage = 'shortage';

    public const StatusRejected = 'rejected';

    public const StatusCancelled = 'cancelled';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusDraft, 'request_type' => 'planned'];

    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'required_by_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class, 'branch_store_id')->withTrashed();
    }

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionMaterialRequestLine::class)->orderBy('line_number');
    }

    public function scopeForContext(Builder $query, int $companyId, int $periodId, int $branchId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.financial_period_id', $periodId)
            ->where($this->getTable().'.branch_id', $branchId);
    }
}

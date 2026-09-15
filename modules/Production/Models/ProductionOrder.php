<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Concerns\SnapshotsCompanyPrintIdentity;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\Models\SalesOrder;

class ProductionOrder extends Model
{
    use SnapshotsCompanyPrintIdentity, SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusPlanned = 'planned';

    public const StatusReleased = 'released';

    public const StatusInProgress = 'in_progress';

    public const StatusPartiallyCompleted = 'partially_completed';

    public const StatusCompleted = 'completed';

    public const StatusShortClosed = 'short_closed';

    public const StatusCancelled = 'cancelled';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusDraft];

    protected function casts(): array
    {
        return ['production_order_date' => 'date', 'expected_start_date' => 'date', 'expected_finish_date' => 'date', 'expected_delivery_date' => 'date', 'overproduction_tolerance_percent' => 'decimal:4', 'released_at' => 'datetime', 'cancelled_at' => 'datetime', 'short_closed_at' => 'datetime', 'restored_at' => 'datetime', 'print_identity_snapshot' => 'array'];
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionOrderLine::class)->orderBy('line_number');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ProductionRun::class)->orderBy('planned_start_at');
    }

    public function stageSnapshots(): HasMany
    {
        return $this->hasMany(ProductionOrderStageSnapshot::class)->orderBy('sequence');
    }
}

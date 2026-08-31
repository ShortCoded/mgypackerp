<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\InventoryDocument;

class ProductionRun extends Model
{
    public const StatusPlanned = 'planned';

    public const StatusSetup = 'setup';

    public const StatusReady = 'ready';

    public const StatusRunning = 'running';

    public const StatusHeld = 'held';

    public const StatusCompleted = 'completed';

    public const StatusCancelled = 'cancelled';

    protected $fillable = [
        'public_id', 'run_number', 'company_id', 'financial_period_id', 'branch_id',
        'production_order_id', 'production_order_line_id', 'product_id', 'unit_id',
        'cost_center_id',
        'conversion_factor', 'planned_quantity', 'planned_base_quantity', 'good_base_quantity',
        'rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity',
        'received_base_quantity', 'planned_start_at', 'planned_end_at', 'actual_start_at',
        'actual_end_at', 'production_shift_id', 'production_machine_id', 'production_mold_id',
        'batch_lot', 'status', 'setup_status', 'setup_started_at', 'setup_completed_at',
        'notes', 'created_by', 'updated_by', 'started_by', 'completed_by',
    ];

    protected $attributes = ['status' => self::StatusPlanned, 'setup_status' => 'pending'];

    protected static function booted(): void
    {
        static::creating(fn (self $run) => $run->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:8', 'planned_quantity' => 'decimal:8',
            'planned_base_quantity' => 'decimal:8', 'good_base_quantity' => 'decimal:8',
            'rejected_base_quantity' => 'decimal:8', 'rework_base_quantity' => 'decimal:8',
            'scrap_base_quantity' => 'decimal:8', 'received_base_quantity' => 'decimal:8',
            'planned_start_at' => 'datetime', 'planned_end_at' => 'datetime',
            'actual_start_at' => 'datetime', 'actual_end_at' => 'datetime',
            'setup_started_at' => 'datetime', 'setup_completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
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

    protected function totalOutputBaseQuantity(): Attribute
    {
        return Attribute::get(fn (): string => bcadd(
            bcadd((string) $this->good_base_quantity, (string) $this->rejected_base_quantity, 8),
            bcadd((string) $this->rework_base_quantity, (string) $this->scrap_base_quantity, 8),
            8,
        ));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderLine::class, 'production_order_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ProductionMachine::class, 'production_machine_id')->withTrashed();
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }

    public function mold(): BelongsTo
    {
        return $this->belongsTo(ProductionMold::class, 'production_mold_id')->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(ProductionShift::class, 'production_shift_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ProductionMaterialRequirement::class)->orderBy('line_number');
    }

    public function progressEntries(): HasMany
    {
        return $this->hasMany(ProductionProgressEntry::class)->orderBy('recorded_at');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(ProductionQualityInspection::class, 'production_run_id')->orderBy('sampled_at');
    }

    public function inventoryDocuments(): HasMany
    {
        return $this->hasMany(InventoryDocument::class, 'production_run_id')->orderBy('document_date')->orderBy('id');
    }
}

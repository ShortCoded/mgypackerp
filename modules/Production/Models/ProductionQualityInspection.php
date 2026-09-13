<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;

class ProductionQualityInspection extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusReceived = 'received';

    public const StatusInProgress = 'in_progress';

    public const StatusSubmitted = 'submitted';

    public const StatusApproved = 'approved';

    public const StatusRejected = 'rejected';

    public const StatusClosed = 'closed';

    public const SubjectProductionRun = 'production_run';

    public const SubjectProduct = 'product';

    public const SubjectInventoryStock = 'inventory_stock';

    protected $table = 'quality_inspections';

    protected $fillable = [
        'doc_number', 'doc_num', 'parent_inspection_id', 'root_inspection_id', 'company_id', 'financial_period_id', 'branch_id',
        'production_order_id', 'production_run_id', 'production_order_stage_id', 'subject_type',
        'product_id', 'branch_store_id', 'stock_status', 'batch_lot', 'source_reference',
        'quality_inspection_type_id', 'version', 'reinspection_number', 'inspection_date', 'sampled_at', 'status',
        'result', 'disposition', 'defect_code', 'affected_base_quantity', 'inspector_id', 'notes',
        'rework_notes', 'corrective_action', 'evidence', 'submitted_by', 'submitted_at',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
        'reviewed_by', 'reviewed_at', 'released_by', 'released_at', 'created_by', 'updated_by',
        'requested_by', 'requested_at', 'received_by', 'received_at', 'started_by', 'started_at',
        'closed_by', 'closed_at', 'close_notes', 'deleted_by', 'restored_by', 'restored_at',
    ];

    protected $attributes = [
        'status' => self::StatusDraft,
        'result' => 'pending',
        'version' => 1,
        'reinspection_number' => 0,
    ];

    protected function casts(): array
    {
        return [
            'inspection_date' => 'date', 'sampled_at' => 'datetime', 'requested_at' => 'datetime',
            'version' => 'integer', 'reinspection_number' => 'integer',
            'received_at' => 'datetime', 'started_at' => 'datetime', 'closed_at' => 'datetime',
            'affected_base_quantity' => 'decimal:8', 'evidence' => 'array',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime',
            'reviewed_at' => 'datetime', 'released_at' => 'datetime', 'restored_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class)->withTrashed();
    }

    public function stageSnapshot(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderStageSnapshot::class, 'production_order_stage_id');
    }

    public function qualityType(): BelongsTo
    {
        return $this->belongsTo(QualityInspectionType::class, 'quality_inspection_type_id')->withTrashed();
    }

    public function results(): HasMany
    {
        return $this->hasMany(ProductionQualityInspectionResult::class, 'quality_inspection_id')->orderBy('sequence');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ProductionQualityInspectionReport::class, 'quality_inspection_id')->orderBy('sequence');
    }

    public function parentInspection(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_inspection_id');
    }

    public function rootInspection(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_inspection_id');
    }

    public function reinspections(): HasMany
    {
        return $this->hasMany(self::class, 'parent_inspection_id')->orderBy('reinspection_number');
    }
}

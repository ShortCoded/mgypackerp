<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionQualityInspection extends Model
{
    protected $table = 'quality_inspections';

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id',
        'production_order_id', 'production_run_id', 'production_order_stage_id',
        'quality_inspection_type_id', 'version', 'inspection_date', 'sampled_at', 'status',
        'result', 'defect_code', 'affected_base_quantity', 'inspector_id', 'notes',
        'rework_notes', 'corrective_action', 'evidence', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'inspection_date' => 'date', 'sampled_at' => 'datetime',
            'affected_base_quantity' => 'decimal:8', 'evidence' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ProductionQualityInspectionResult::class, 'quality_inspection_id')->orderBy('sequence');
    }
}

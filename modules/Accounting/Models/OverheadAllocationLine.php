<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Production\Models\ProductionRun;

class OverheadAllocationLine extends Model
{
    protected $table = 'cost_overhead_allocation_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'machine_hours' => 'decimal:8',
            'labor_hours' => 'decimal:8',
            'direct_material_cost' => 'decimal:4',
            'basis_value' => 'decimal:8',
            'allocation_percent' => 'decimal:8',
            'allocated_amount' => 'decimal:4',
        ];
    }

    public function allocationRun(): BelongsTo
    {
        return $this->belongsTo(OverheadAllocationRun::class, 'allocation_run_id');
    }

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}

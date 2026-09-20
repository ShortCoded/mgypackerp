<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrEmployeeShiftAssignment extends Model
{
    use SoftDeletes;

    protected $table = 'hr_employee_shift_assignments';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'shift_id', 'effective_from', 'effective_to', 'created_by', 'updated_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return BelongsTo<HrEmployee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    /** @return BelongsTo<HrShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(HrShift::class, 'shift_id')->withTrashed();
    }
}

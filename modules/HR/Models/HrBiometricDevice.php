<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Branch;

class HrBiometricDevice extends HrCompanyFoundationModel
{
    protected $table = 'hr_biometric_devices';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'branch_id',
        'device_uid',
        'name',
        'serial_number',
        'location',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}

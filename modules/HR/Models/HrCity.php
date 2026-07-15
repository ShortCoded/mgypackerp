<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrCity extends HrLookupModel
{
    protected $table = 'hr_cities';

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(HrGovernorate::class, 'governorate_id');
    }
}

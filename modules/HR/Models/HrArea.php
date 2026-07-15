<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrArea extends HrLookupModel
{
    protected $table = 'hr_areas';

    public function city(): BelongsTo
    {
        return $this->belongsTo(HrCity::class, 'city_id');
    }
}

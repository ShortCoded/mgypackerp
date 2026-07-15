<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrGovernorate extends HrLookupModel
{
    protected $table = 'hr_governorates';

    public function country(): BelongsTo
    {
        return $this->belongsTo(HrCountry::class, 'country_id');
    }
}

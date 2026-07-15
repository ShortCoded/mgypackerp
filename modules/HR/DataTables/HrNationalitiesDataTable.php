<?php

namespace Modules\HR\DataTables;

use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrNationalitiesDataTable extends HrLookupDataTable
{
    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('nationalities');
    }
}

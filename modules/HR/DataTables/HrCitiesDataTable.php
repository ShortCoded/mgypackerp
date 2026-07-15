<?php

namespace Modules\HR\DataTables;

use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrCitiesDataTable extends HrLookupDataTable
{
    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('cities');
    }
}

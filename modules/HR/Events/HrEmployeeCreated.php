<?php

namespace Modules\HR\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\HR\Models\HrEmployee;

class HrEmployeeCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public HrEmployee $employee,
    ) {}
}

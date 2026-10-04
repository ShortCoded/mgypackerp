<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\HR\Services\HrEmployeeWageVersionService;

final class ProjectEffectiveHrWagesCommand extends Command
{
    protected $signature = 'hr:wages:project-current';

    protected $description = 'Apply due, verified wage versions to employee cards';

    public function handle(HrEmployeeWageVersionService $wages): int
    {
        $result = $wages->projectEffectiveRates();
        $this->info("Checked {$result['employees_checked']} employee cards; updated {$result['cards_updated']}.");

        return self::SUCCESS;
    }
}

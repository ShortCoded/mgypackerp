<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inventory\Services\InventoryReorderNotificationService;

class NotifyReorderShortages extends Command
{
    protected $signature = 'inventory:notify-reorder-shortages {--company= : Restrict monitoring to one company ID}';

    protected $description = 'Notify scoped inventory users once per reorder-shortage episode.';

    public function handle(InventoryReorderNotificationService $monitor): int
    {
        $company = $this->option('company');
        if ($company !== null && (! ctype_digit((string) $company) || (int) $company < 1)) {
            $this->error('Company must be a positive integer ID.');

            return self::INVALID;
        }
        $this->info('Created '.$monitor->monitor($company === null ? null : (int) $company).' reorder notifications.');

        return self::SUCCESS;
    }
}

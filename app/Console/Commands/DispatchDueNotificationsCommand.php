<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Services\NotificationService;

class DispatchDueNotificationsCommand extends Command
{
    protected $signature = 'notifications:dispatch-due {--limit=200 : Maximum due notifications to dispatch per category}';

    protected $description = 'Dispatch due task and calendar notifications.';

    public function handle(NotificationService $notifications): int
    {
        $count = $notifications->dispatchDue((int) $this->option('limit'));

        $this->info("Dispatched {$count} due notifications.");

        return self::SUCCESS;
    }
}

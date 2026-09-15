<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Services\NotificationService;
use Throwable;

class DispatchDueNotificationsCommand extends Command
{
    protected $signature = 'notifications:dispatch-due {--limit=200 : Maximum due notifications to dispatch per category}';

    protected $description = 'Dispatch due task reminders and scheduled notifications.';

    public function handle(NotificationService $notifications): int
    {
        $runtime = [
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'status' => 'running',
            'created_count' => 0,
            'error_code' => null,
        ];
        Cache::put('notifications.runtime.last_dispatch', $runtime, now()->addDays(7));

        try {
            $count = $notifications->dispatchDue((int) $this->option('limit'));
        } catch (Throwable $exception) {
            Cache::put('notifications.runtime.last_dispatch', [
                ...$runtime,
                'finished_at' => now()->toIso8601String(),
                'status' => 'failed',
                'error_code' => class_basename($exception),
            ], now()->addDays(7));

            throw $exception;
        }

        Cache::put('notifications.runtime.last_dispatch', [
            ...$runtime,
            'finished_at' => now()->toIso8601String(),
            'status' => 'successful',
            'created_count' => $count,
        ], now()->addDays(7));

        $this->info("Dispatched {$count} due notifications.");

        return self::SUCCESS;
    }
}

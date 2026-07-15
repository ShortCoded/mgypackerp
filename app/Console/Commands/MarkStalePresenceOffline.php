<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Auth\Services\UserPresenceService;

class MarkStalePresenceOffline extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'presence:mark-stale-offline';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark stale user presence sessions offline.';

    /**
     * Execute the console command.
     */
    public function handle(UserPresenceService $presence): int
    {
        $counts = $presence->markStaleSessionsOffline();

        $this->info(sprintf(
            'Marked %d presence session(s) offline and %d idle.',
            $counts['offline'],
            $counts['idle']
        ));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\Services\AccountClassificationRegistry;
use Throwable;

class VerifyAccountClassificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'account-classifications:verify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify the canonical account classification master data without writing to the database.';

    /**
     * Execute the console command.
     */
    public function handle(AccountClassificationRegistry $registry): int
    {
        try {
            $result = $registry->verification();
        } catch (Throwable $exception) {
            $this->error('Account classification verification failed.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'current=%d, current_with_trashed=%d, canonical=%d',
            $result['current_count'],
            $result['current_with_trashed_count'],
            $result['canonical_count'],
        ));

        foreach (['missing_codes', 'duplicate_codes', 'trashed_conflict_codes', 'structural_conflict_codes', 'label_update_codes', 'label_conflict_codes'] as $key) {
            $this->line($key.'='.($result[$key] === [] ? '0' : implode(',', $result[$key])));
        }

        if (! $result['valid']) {
            $this->error('Account classification verification failed. No database writes were performed.');

            return self::FAILURE;
        }

        $this->info('Account classification verification passed. No database writes were performed.');

        return self::SUCCESS;
    }
}

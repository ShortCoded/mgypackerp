<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\Services\AccountClassificationRegistry;
use Throwable;

class SyncAccountClassificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'account-classifications:sync
        {--dry-run : Preview the synchronization without writing to the database}
        {--force : Apply the synchronization, including in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely synchronize the canonical account classification master data.';

    /**
     * Execute the console command.
     */
    public function handle(AccountClassificationRegistry $registry): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($dryRun && $force) {
            $this->error('Choose either --dry-run or --force, not both.');

            return self::FAILURE;
        }

        if (! $dryRun && app()->isProduction() && ! $force) {
            $this->error('Production synchronization requires --force.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $force) {
            $this->error('No changes were made. Use --dry-run to preview or --force to apply.');

            return self::FAILURE;
        }

        try {
            $result = $dryRun ? $registry->plan() : $registry->synchronize();
        } catch (Throwable $exception) {
            $this->error('Account classification synchronization failed; the transaction was rolled back.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'inserted=%d, unchanged=%d, label_updates=%d, label_conflicts=%d, deleted=%d',
            $result['inserted'],
            $result['unchanged'],
            $result['label_updates'],
            $result['label_conflicts'],
            $result['deleted'],
        ));

        foreach (['inserted_codes', 'label_update_codes', 'label_conflict_codes', 'trashed_conflict_codes', 'structural_conflict_codes', 'duplicate_codes'] as $key) {
            if ($result[$key] !== []) {
                $this->line($key.'='.implode(',', $result[$key]));
            }
        }

        if (
            $result['label_conflict_codes'] !== []
            || $result['trashed_conflict_codes'] !== []
            || $result['structural_conflict_codes'] !== []
            || $result['duplicate_codes'] !== []
        ) {
            $this->error('No changes were made because classification conflicts require manual review.');

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run complete; no database writes were performed.' : 'Account classifications synchronized successfully.');

        return self::SUCCESS;
    }
}

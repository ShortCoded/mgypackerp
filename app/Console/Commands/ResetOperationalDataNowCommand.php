<?php

namespace App\Console\Commands;

use App\Services\OperationalDataResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ResetOperationalDataNowCommand extends Command
{
    protected $signature = 'erp:reset-operational-data-now';

    protected $description = 'Reset operational ERP data using an operator-managed backup.';

    public function handle(OperationalDataResetService $reset): int
    {
        $enteredMaintenance = false;
        $applyStarted = false;
        $completed = false;
        $gateClosed = false;
        $admin = null;
        $database = null;

        try {
            if (config('app.maintenance.driver') !== 'file') {
                throw new RuntimeException('This command requires file-based Laravel maintenance mode.');
            }

            $database = DB::connection()->getDatabaseName();
            $this->assertDatabaseName($database);
            $before = $reset->review();
            $this->assertReady($before);
            foreach ($before['planned_deleted_master_links'] as $reference) {
                $this->line($reference['action'].': '.$reference['reference'].' ('.$reference['active_rows'].' rows)');
            }

            $adminConfig = array_replace(DB::connection()->getConfig(), ['database' => 'postgres']);
            unset($adminConfig['url'], $adminConfig['name']);
            config(['database.connections.operational_reset_automatic_admin' => $adminConfig]);
            $admin = DB::connection('operational_reset_automatic_admin');
            $admin->selectOne('SELECT current_database()');

            if (! app()->isDownForMaintenance()) {
                if ($this->call('down') !== self::SUCCESS) {
                    throw new RuntimeException('Could not enter maintenance mode.');
                }
                $enteredMaintenance = true;
            }

            $this->assertNoOtherClients();
            $review = $reset->review();
            if (! hash_equals($before['review_token'], $review['review_token'])) {
                throw new RuntimeException('Data changed after the initial review. The reset was not started.');
            }

            $admin->statement('ALTER DATABASE "'.$database.'" WITH ALLOW_CONNECTIONS false');
            $gateClosed = true;
            $this->assertNoOtherClients();

            $operator = (function_exists('posix_geteuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user())
                : get_current_user()).'@'.(gethostname() ?: 'unknown-host');
            $applyStarted = true;
            $result = $reset->runWithManualBackup($review['review_token'], $operator);

            $after = $reset->review();
            if (array_sum($after['delete_rows']) !== 0
                || array_sum($after['purge_soft_deleted']) !== 0
                || $after['blocked_master_references'] !== []
                || $after['planned_deleted_master_links'] !== []
                || $after['unclassified_operational_notifications'] !== 0) {
                throw new RuntimeException('Post-reset verification did not find a clean operational database.');
            }

            $admin->statement('ALTER DATABASE "'.$database.'" WITH ALLOW_CONNECTIONS true');
            $gateClosed = false;

            if ($enteredMaintenance && $this->call('up') !== self::SUCCESS) {
                throw new RuntimeException('Reset completed, but Laravel could not leave maintenance mode.');
            }

            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info('Operational data reset completed and verified. No automatic backup was created.');
            $completed = true;

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Automatic operational reset stopped: '.$exception->getMessage());
            if ($applyStarted) {
                $this->warn('Keep Laravel in maintenance mode and inspect the database before reopening the application.');
            }

            return self::FAILURE;
        } finally {
            if (! $completed && $admin !== null && $database !== null) {
                if ($applyStarted) {
                    try {
                        if (! app()->isDownForMaintenance()) {
                            $this->call('down');
                        }
                        $admin->statement('ALTER DATABASE "'.$database.'" WITH ALLOW_CONNECTIONS false');
                    } catch (Throwable $recoveryError) {
                        $this->warn('Could not keep the database closed after a failed reset: '.$recoveryError->getMessage());
                    }
                } elseif ($gateClosed) {
                    try {
                        $admin->statement('ALTER DATABASE "'.$database.'" WITH ALLOW_CONNECTIONS true');
                    } catch (Throwable $recoveryError) {
                        $this->warn('Could not reopen the database after a failed preflight: '.$recoveryError->getMessage());
                    }
                }
            }

            if (! $applyStarted && $enteredMaintenance) {
                $this->call('up');
            }
            DB::disconnect('operational_reset_automatic_admin');
        }
    }

    private function assertDatabaseName(string $database): void
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $database) !== 1) {
            throw new RuntimeException('The database name cannot safely be used for the connection gate.');
        }
    }

    private function assertReady(array $review): void
    {
        if ($review['blocked_master_references'] !== []) {
            foreach ($review['blocked_master_references'] as $name => $reference) {
                $this->warn($name.': '.$reference['reference'].' ('.$reference['active_rows'].' active rows)');
            }
        }
        if ($review['unclassified_operational_notifications'] !== 0) {
            $this->warn($review['unclassified_operational_notifications'].' operational notifications have no document subject.');
        }
        if ($review['blocked_master_references'] !== []
            || $review['unclassified_operational_notifications'] !== 0) {
            throw new RuntimeException('Read-only review found data that needs a cleanup rule. Run erp:reset-operational-data to see the full details. No data was changed.');
        }
    }

    private function assertNoOtherClients(): void
    {
        $otherConnections = (int) DB::selectOne("SELECT COUNT(*) AS total FROM pg_stat_activity
            WHERE datname = current_database() AND pid <> pg_backend_pid()
                AND backend_type = 'client backend'")->total;
        if ($otherConnections > 0) {
            throw new RuntimeException("{$otherConnections} other database clients are connected. Stop Octane, queues, and the scheduler before retrying.");
        }
    }
}

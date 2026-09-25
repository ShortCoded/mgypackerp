<?php

namespace App\Console\Commands;

use App\Services\OperationalDataResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class ResetOperationalDataNowCommand extends Command
{
    protected $signature = 'erp:reset-operational-data-now';

    protected $description = 'Back up, verify, and reset operational ERP data in one guarded command.';

    public function handle(OperationalDataResetService $reset): int
    {
        $enteredMaintenance = false;
        $applyStarted = false;
        $applied = false;
        $completed = false;
        $restoredDatabase = null;
        $admin = null;
        $backup = null;
        $proof = null;

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

            $connection = DB::connection()->getConfig();
            $adminConfig = array_replace($connection, ['database' => 'postgres']);
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
            $directoryName = now()->format('YmdHis').'-'.bin2hex(random_bytes(6));
            $directory = storage_path('app/private/operational-resets/'.$directoryName);
            try {
                $this->createPrivateBackupDirectory($directory);
            } catch (RuntimeException $exception) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    throw $exception;
                }
                $directory = rtrim(sys_get_temp_dir(), '/').'/mgypack-operational-resets/'.$directoryName;
                $this->createPrivateBackupDirectory($directory);
            }

            $backup = $directory.'/backup.dump';
            $handle = fopen($backup, 'x');
            if ($handle === false) {
                throw new RuntimeException('Could not create the backup file.');
            }
            fclose($handle);
            if (DIRECTORY_SEPARATOR !== '\\') {
                if (! chmod($backup, 0600)) {
                    throw new RuntimeException('Could not restrict access to the backup file.');
                }
                clearstatcache(true, $backup);
                $permissions = fileperms($backup);
                if ($permissions === false || ($permissions & 0077) !== 0) {
                    throw new RuntimeException('Could not restrict access to the backup file.');
                }
            }

            $environment = $this->postgresEnvironment($connection);
            $this->runProcess(['pg_dump', '--format=custom', '--file='.$backup, '--dbname='.$database], $environment);
            if (filesize($backup) === 0) {
                throw new RuntimeException('The PostgreSQL backup is empty.');
            }
            $digest = hash_file('sha256', $backup);
            if ($digest === false) {
                throw new RuntimeException('Could not hash the PostgreSQL backup.');
            }

            $review = $reset->review();
            if (! hash_equals($before['review_token'], $review['review_token'])) {
                throw new RuntimeException('Data changed during backup. The reset was not started.');
            }

            $newRestoredDatabase = 'reset_verify_'.bin2hex(random_bytes(8));
            $admin->statement('CREATE DATABASE "'.$newRestoredDatabase.'" TEMPLATE template0');
            $restoredDatabase = $newRestoredDatabase;
            $this->runProcess([
                'pg_restore', '--exit-on-error', '--no-owner', '--no-privileges',
                '--dbname='.$restoredDatabase, $backup,
            ], $environment);

            $proof = $directory.'/restore-proof.json';
            if ($this->call('erp:verify-operational-reset-backup', [
                '--restored-database' => $restoredDatabase,
                '--backup-file' => $backup,
                '--backup-sha256' => $digest,
                '--proof-file' => $proof,
            ]) !== self::SUCCESS) {
                throw new RuntimeException('The restored backup did not match the source.');
            }

            $admin->statement('DROP DATABASE "'.$restoredDatabase.'"');
            $restoredDatabase = null;
            $this->assertNoOtherClients();

            $operator = (function_exists('posix_geteuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user())
                : get_current_user()).'@'.(gethostname() ?: 'unknown-host');
            $applyStarted = true;
            if ($this->call('erp:reset-operational-data', [
                '--apply' => true,
                '--database' => $database,
                '--operator' => $operator,
                '--review-token' => $review['review_token'],
                '--policy-hash' => $review['policy_hash'],
                '--backup-file' => $backup,
                '--backup-sha256' => $digest,
                '--restore-proof-file' => $proof,
            ]) !== self::SUCCESS) {
                throw new RuntimeException('The guarded reset did not complete. Keep maintenance mode active and inspect its error.');
            }
            $applied = true;

            $after = $reset->review();
            if (array_sum($after['delete_rows']) !== 0
                || array_sum($after['purge_soft_deleted']) !== 0
                || $after['blocked_master_references'] !== []
                || $after['planned_deleted_master_links'] !== []
                || $after['unclassified_operational_notifications'] !== 0) {
                throw new RuntimeException('Post-reset verification did not find a clean operational database.');
            }
            $admin->statement('ALTER DATABASE "'.$database.'" WITH ALLOW_CONNECTIONS true');

            if ($enteredMaintenance && $this->call('up') !== self::SUCCESS) {
                throw new RuntimeException('Reset completed, but Laravel could not leave maintenance mode.');
            }
            $this->info('Operational data reset completed and verified.');
            $this->line('Backup: '.$backup);
            $this->line('Restore proof: '.$proof);
            $completed = true;

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Automatic operational reset stopped: '.$exception->getMessage());
            if ($backup !== null && is_file($backup)) {
                $this->warn('Backup file retained; verify it before recovery: '.$backup);
            }
            if ($proof !== null && is_file($proof)) {
                $this->warn('Restore proof retained at: '.$proof);
            }
            if ($applyStarted) {
                $this->warn('Keep Laravel in maintenance mode. Review the backup and database state before reopening the application.');
            }

            return self::FAILURE;
        } finally {
            if ($restoredDatabase !== null && $admin !== null) {
                try {
                    $admin->statement('DROP DATABASE "'.$restoredDatabase.'"');
                } catch (Throwable $cleanupError) {
                    $this->warn('Could not remove the temporary restored database '.$restoredDatabase.': '.$cleanupError->getMessage());
                }
            }

            if ($applied && ! $completed && $admin !== null) {
                try {
                    if (! app()->isDownForMaintenance() && $this->call('down') !== self::SUCCESS) {
                        $this->warn('Could not restore Laravel maintenance mode after a failed final step.');
                    }
                } catch (Throwable $maintenanceError) {
                    $this->warn('Could not restore Laravel maintenance mode: '.$maintenanceError->getMessage());
                }
                try {
                    $admin->statement('ALTER DATABASE "'.$database.'" WITH ALLOW_CONNECTIONS false');
                } catch (Throwable $gateError) {
                    $this->warn('Could not close the database connection gate after a failed final step: '.$gateError->getMessage());
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
            throw new RuntimeException('The database name cannot safely be used for a backup and restore.');
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

    private function postgresEnvironment(array $connection): array
    {
        $environment = [];
        foreach (['host' => 'PGHOST', 'port' => 'PGPORT', 'username' => 'PGUSER',
            'password' => 'PGPASSWORD', 'sslmode' => 'PGSSLMODE'] as $setting => $variable) {
            if (isset($connection[$setting]) && (string) $connection[$setting] !== '') {
                $environment[$variable] = (string) $connection[$setting];
            }
        }

        return $environment;
    }

    private function createPrivateBackupDirectory(string $directory): void
    {
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the private backup directory.');
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            if (! chmod($directory, 0700)) {
                throw new RuntimeException('Could not restrict access to the backup directory.');
            }
            clearstatcache(true, $directory);
            $permissions = fileperms($directory);
            if ($permissions === false || ($permissions & 0077) !== 0) {
                throw new RuntimeException('Could not restrict access to the backup directory.');
            }

            return;
        }

        $absoluteDirectory = realpath($directory);
        if ($absoluteDirectory === false) {
            throw new RuntimeException('Could not resolve the backup directory.');
        }

        $identity = new Process(['whoami', '/user', '/fo', 'csv', '/nh']);
        $identity->mustRun();
        if (preg_match('/\bS-\d-\d+(?:-\d+)+\b/', $identity->getOutput(), $matches) !== 1) {
            throw new RuntimeException('Could not identify the Windows account for the backup ACL.');
        }

        $this->runProcess([
            'icacls', $absoluteDirectory, '/inheritance:r', '/grant:r',
            '*'.$matches[0].':(OI)(CI)F',
            '*S-1-5-18:(OI)(CI)F',
            '*S-1-5-32-544:(OI)(CI)F',
        ], []);
        $this->runProcess(['icacls', $absoluteDirectory, '/verify'], []);
    }

    private function runProcess(array $command, array $environment): void
    {
        $process = new Process($command, null, $environment);
        $process->setTimeout(null);
        $process->mustRun();
    }
}
